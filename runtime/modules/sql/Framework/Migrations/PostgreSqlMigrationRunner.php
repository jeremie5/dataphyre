<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Migrations;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PostgreSQL migration state machine.
 *
 * Dataphyre owns database inspection, compatibility policy, journalling,
 * transactions, advisory locking, and reversible migration certification.
 * Applications supply only a migration profile, immutable manifest, database
 * connection, and (when applicable) externally verified release identity.
 */
final class PostgreSqlMigrationRunner {
	private PostgreSqlSchemaInspector $inspector;

	public function __construct(
		private PDO $pdo,
		private PostgreSqlMigrationProfile $profile,
		?PostgreSqlSchemaInspector $inspector=null
	) {
		try{
			$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
		}catch(Throwable $exception){
			throw new InvalidArgumentException(
				'Dataphyre could not inspect the PostgreSQL migration connection.',
				0,
				$exception
			);
		}
		if($driver!=='pgsql'){
			throw new InvalidArgumentException(
				'Dataphyre PostgreSQL migrations require a pgsql PDO connection.'
			);
		}
		$this->inspector=$inspector ?? new PostgreSqlSchemaInspector($profile);
	}

	/**
	 * Inspect journal identity, ordered history, immutable checksums, and the
	 * live schema contract without mutating the database.
	 *
	 * @return array<string,mixed>
	 */
	public function status(PostgreSqlMigrationManifest $manifest): array {
		$entries=$manifest->entries();
		$journalExists=$this->tableExists($this->profile->journalRegclass());
		$appliedRows=[];
		if($journalExists){
			$rows=$this->query(
				'SELECT migration_name, checksum_sha256, applied_at::text AS applied_at '.
				'FROM '.$this->profile->journalQualified().' ORDER BY migration_name'
			)->fetchAll(PDO::FETCH_ASSOC);
			foreach($rows as $row){
				if(!is_array($row)){
					continue;
				}
				$name=trim((string)($row['migration_name'] ?? ''));
				$appliedRows[$name]=[
					'sha256'=>strtolower(trim((string)($row['checksum_sha256'] ?? ''))),
					'applied_at'=>(string)($row['applied_at'] ?? ''),
				];
			}
		}

		$projection=self::projectJournalRows($appliedRows, $entries);
		$applied=$projection['applied'];
		$unmanifested=$projection['unmanifested'];
		$migrations=[];
		$pending=0;
		$pendingContract=0;
		$appliedCount=0;
		$drift=0;
		$appliedEntries=[];
		$pendingSeen=false;
		$appliedHead=null;
		$compatibilityFloor=null;
		foreach($entries as $entry){
			$id=$entry['id'];
			$row=$applied[$id] ?? null;
			$status='pending';
			$actual=null;
			$appliedAt=null;
			$journalName=null;
			if(is_array($row)){
				$actual=(string)$row['sha256'];
				$appliedAt=(string)$row['applied_at'];
				$journalName=(string)$row['journal_name'];
				if(hash_equals($entry['up']['sha256'], $actual)){
					$status='applied';
					$appliedCount++;
					$appliedEntries[]=[
						'name'=>$id,
						'phase'=>$entry['phase'],
						'sql'=>$entry['up']['sql'],
					];
					if($pendingSeen){
						$status='history_gap';
						$drift++;
					}else{
						$appliedHead=$id;
						if($entry['phase']==='rolling_contract'){
							$floor=$entry['minimum_compatible_release'];
							if(
								is_string($floor)
								&& (
									$compatibilityFloor===null
									|| PostgreSqlMigrationProfile::compareVersions(
										$floor,
										$compatibilityFloor
									)>0
								)
							){
								$compatibilityFloor=$floor;
							}
						}
					}
				}else{
					$status='checksum_drift';
					$drift++;
				}
			}else{
				$pendingSeen=true;
				$pending++;
				if($entry['phase']==='rolling_contract'){
					$pendingContract++;
				}
			}
			$migrations[]=[
				'id'=>$id,
				'phase'=>$entry['phase'],
				'status'=>$status,
				'expected_up_sha256'=>$entry['up']['sha256'],
				'applied_sha256'=>$actual,
				'applied_at'=>$appliedAt,
				'journal_name'=>$journalName,
			];
		}
		foreach($unmanifested as $name=>$row){
			$drift++;
			$migrations[]=[
				'id'=>$name,
				'phase'=>null,
				'status'=>'unmanifested_applied',
				'expected_up_sha256'=>null,
				'applied_sha256'=>$row['sha256'],
				'applied_at'=>$row['applied_at'],
				'journal_name'=>$name,
			];
		}

		$schemaIssues=$this->inspector->schemaIssues(
			$this->pdo,
			$this->inspector->expectedSchema(
				self::journalNativeSchemaEntries($appliedEntries)
			)
		);
		$schemaDrift=count($schemaIssues);
		$drift+=$schemaDrift;

		return [
			'journal_exists'=>$journalExists,
			'event_journal_exists'=>$this->tableExists($this->profile->eventRegclass()),
			'applied_count'=>$appliedCount,
			'applied_head'=>$appliedHead,
			'pending_count'=>$pending,
			'pending_contract_count'=>$pendingContract,
			'minimum_compatible_release'=>$compatibilityFloor,
			'drift_count'=>$drift,
			'schema_drift_count'=>$schemaDrift,
			'schema_issues'=>$schemaIssues,
			'migrations'=>$migrations,
		];
	}

	/**
	 * Legacy bootstrap migrations predate Dataphyre's schema-contract parser.
	 * Their immutable checksums and successful one-time replay establish the
	 * adopted boundary; exact catalog certification begins with journal-native
	 * rolling migrations.
	 *
	 * @param list<array{name:string,phase:string,sql:string}> $entries
	 * @return list<array{name:string,sql:string}>
	 */
	private static function journalNativeSchemaEntries(array $entries): array {
		$contract=[];
		foreach($entries as $entry){
			if(($entry['phase'] ?? null)==='bootstrap'){
				continue;
			}
			$contract[]=[
				'name'=>(string)($entry['name'] ?? ''),
				'sql'=>(string)($entry['sql'] ?? ''),
			];
		}
		return $contract;
	}

	/**
	 * Describe whether the pending set is eligible for one deployment mode.
	 * Mutation callers compute this again after taking the advisory lock.
	 *
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	public function deploymentEvidence(
		PostgreSqlMigrationManifest $manifest,
		array $state,
		?string $mode,
		?string $verifiedMinimumActiveRelease=null
	): array {
		if($mode!==null && !in_array($mode, ['bootstrap', 'rolling', 'maintenance'], true)){
			throw new InvalidArgumentException(
				'Migration deployment mode must be bootstrap, rolling, or maintenance.'
			);
		}
		$verifiedMinimumActiveRelease=self::verifiedMinimumActiveRelease(
			$mode,
			$verifiedMinimumActiveRelease
		);
		$errors=[];
		$manifestIds=array_fill_keys(array_column($manifest->entries(), 'id'), true);
		$statuses=[];
		$stateMigrations=$state['migrations'] ?? null;
		if(!is_array($stateMigrations) || !array_is_list($stateMigrations)){
			$errors[]='migration_state_entries_invalid';
			$stateMigrations=[];
		}
		$invalidEntry=false;
		$duplicateEntry=false;
		$unmanifestedEntry=false;
		foreach($stateMigrations as $migration){
			if(!is_array($migration) || !is_string($migration['id'] ?? null)){
				$invalidEntry=true;
				continue;
			}
			$id=$migration['id'];
			if(!isset($manifestIds[$id])){
				$unmanifestedEntry=true;
				continue;
			}
			if(array_key_exists($id, $statuses)){
				$duplicateEntry=true;
				continue;
			}
			$statuses[$id]=$migration['status'] ?? null;
		}
		if($invalidEntry){
			$errors[]='migration_state_entries_invalid';
		}
		if($duplicateEntry){
			$errors[]='migration_state_entries_duplicate';
		}
		if($unmanifestedEntry){
			$errors[]='migration_state_entries_unmanifested';
		}
		$driftCount=$state['drift_count'] ?? null;
		if(!is_int($driftCount) || $driftCount<0){
			$errors[]='migration_state_drift_count_invalid';
		}elseif($driftCount>0){
			$errors[]='migration_state_has_drift';
		}
		foreach($manifestIds as $id=>$_present){
			if(!array_key_exists($id, $statuses)){
				$errors[]='migration_state_status_missing:'.$id;
			}elseif(!in_array($statuses[$id], ['pending', 'applied'], true)){
				$errors[]='migration_state_status_not_deployable:'.$id;
			}
		}
		$pending=array_values(array_filter(
			$manifest->entries(),
			static fn(array $entry): bool=>($statuses[$entry['id']] ?? null)==='pending'
		));
		$pendingIds=array_column($pending, 'id');
		$pendingPhases=array_count_values(array_column($pending, 'phase'));
		ksort($pendingPhases, SORT_STRING);
		$cutoffStatus=$statuses[$manifest->bootstrapCutoff()] ?? 'missing';
		$rollingIssues=[];
		$selected=[];
		$deferred=[];
		$requiredMinimumActiveRelease=null;
		foreach($pending as $entry){
			if($entry['phase']==='rolling_contract'){
				$floor=$entry['minimum_compatible_release'];
				if(
					is_string($floor)
					&& (
						$requiredMinimumActiveRelease===null
						|| PostgreSqlMigrationProfile::compareVersions(
							$floor,
							$requiredMinimumActiveRelease
						)>0
					)
				){
					$requiredMinimumActiveRelease=$floor;
				}
				if($mode===null){
					$errors[]='pending_contract_requires_compatibility_finalization:'.$entry['id'];
				}
			}
		}

		if($mode==='bootstrap'){
			if($cutoffStatus==='applied' && $pending!==[]){
				$errors[]='bootstrap_cutoff_already_applied_with_pending_rolling_migrations';
				$deferred=$pending;
			}else{
				$contractReached=false;
				foreach($pending as $entry){
					if($contractReached || $entry['phase']==='rolling_contract'){
						$contractReached=true;
						$deferred[]=$entry;
						continue;
					}
					$selected[]=$entry;
				}
				foreach($manifest->entries() as $entry){
					if(
						$entry['phase']!=='bootstrap'
						&& ($statuses[$entry['id']] ?? null)==='applied'
					){
						$errors[]='bootstrap_history_is_out_of_order';
						break;
					}
				}
			}
		}elseif($mode==='rolling'){
			if($cutoffStatus!=='applied'){
				$errors[]='bootstrap_cutoff_not_applied';
			}
			$nonExpandReached=false;
			foreach($pending as $entry){
				if($nonExpandReached || $entry['phase']!=='rolling_expand'){
					$nonExpandReached=true;
					$deferred[]=$entry;
					continue;
				}
				$selected[]=$entry;
			}
			foreach($selected as $entry){
				foreach(self::rollingSqlIssues($entry['up']['sql']) as $issue){
					$rollingIssues[]=[
						'migration'=>$entry['id'],
						'code'=>$issue['code'],
						'statement'=>$issue['statement'],
					];
				}
			}
			if($rollingIssues!==[]){
				$errors[]='pending_rolling_migrations_contain_incompatible_sql';
			}
		}elseif($mode==='maintenance'){
			if($cutoffStatus!=='applied'){
				$errors[]='bootstrap_cutoff_not_applied';
			}
			foreach($pending as $entry){
				if(!in_array($entry['phase'], ['rolling_expand', 'rolling_contract'], true)){
					$errors[]='pending_migration_is_not_maintenance_phase:'.$entry['id'];
					$deferred[]=$entry;
					continue;
				}
				$selected[]=$entry;
				if($entry['phase']!=='rolling_contract'){
					continue;
				}
				$floor=$entry['minimum_compatible_release'];
				if($verifiedMinimumActiveRelease===null){
					$errors[]=
						'pending_contract_requires_verified_minimum_active_release:'.
						$entry['id'].':'.$floor;
				}elseif(
					PostgreSqlMigrationProfile::compareVersions(
						$verifiedMinimumActiveRelease,
						$floor
					)<0
				){
					$errors[]=
						'verified_minimum_active_release_below_contract_floor:'.
						$entry['id'].':'.$floor;
				}
			}
		}else{
			$deferred=$pending;
		}
		if($mode!==null && $selected===[] && $pending!==[]){
			$head=$pending[0];
			if($head['phase']==='rolling_contract'){
				$errors[]=
					'pending_contract_requires_compatibility_finalization:'.$head['id'];
			}
			if($mode==='rolling'){
				$errors[]='pending_migration_is_not_rolling_expand:'.$head['id'];
			}
		}
		$selectedIds=array_column($selected, 'id');
		$deferredIds=array_column($deferred, 'id');
		$selectedPhases=array_count_values(array_column($selected, 'phase'));
		ksort($selectedPhases, SORT_STRING);

		return [
			'mode'=>$mode,
			'bootstrap_cutoff'=>$manifest->bootstrapCutoff(),
			'bootstrap_cutoff_status'=>$cutoffStatus,
			'pending_migrations'=>$pendingIds,
			'pending_phases'=>$pendingPhases,
			'selected_migrations'=>$selectedIds,
			'selected_phases'=>$selectedPhases,
			'deferred_migrations'=>$deferredIds,
			'eligible'=>$mode===null ? null : $errors===[],
			'errors'=>$errors,
			'required_minimum_active_release'=>$requiredMinimumActiveRelease,
			'verified_minimum_active_release'=>$verifiedMinimumActiveRelease,
			'compatibility_floor_satisfied'=>$mode==='maintenance'
				? (
					$requiredMinimumActiveRelease===null
					|| (
						$verifiedMinimumActiveRelease!==null
						&& PostgreSqlMigrationProfile::compareVersions(
							$verifiedMinimumActiveRelease,
							$requiredMinimumActiveRelease
						)>=0
					)
				)
				: null,
			'rolling_scan'=>[
				'performed'=>$mode==='rolling',
				'migration_count'=>$mode==='rolling' ? count($selected) : 0,
				'issue_count'=>count($rollingIssues),
				'issues'=>$rollingIssues,
			],
		];
	}

	/**
	 * Apply the deployment mode's selected pending migration set.
	 *
	 * Bootstrap history is committed one migration at a time while one
	 * session-scoped advisory lock protects the complete batch. This preserves
	 * each migration's SQL+journal atomicity without retaining PostgreSQL
	 * trigger events across unrelated legacy files. Rolling and maintenance
	 * batches retain their single-transaction contract.
	 *
	 * @param ?array{release_version:?string,release_sha256:?string} $releaseIdentity
	 * @param ?string $verifiedMinimumActiveRelease Caller-verified fleet floor
	 * accepted only for maintenance deployment.
	 * @return array<string,mixed>
	 */
	public function apply(
		PostgreSqlMigrationManifest $manifest,
		string $mode,
		bool $dryRun=false,
		?array $releaseIdentity=null,
		?string $verifiedMinimumActiveRelease=null
	): array {
		if(!in_array($mode, ['bootstrap', 'rolling', 'maintenance'], true)){
			throw new InvalidArgumentException(
				'Migration deployment mode must be bootstrap, rolling, or maintenance.'
			);
		}
		$verifiedMinimumActiveRelease=self::verifiedMinimumActiveRelease(
			$mode,
			$verifiedMinimumActiveRelease
		);
		$identity=self::releaseIdentity($releaseIdentity);
		$executed=[];
		$pendingValidation=null;
		$operationId=bin2hex(random_bytes(16));
		$normalizedAliases=[];
		$bootstrapTransactions=$mode==='bootstrap' && !$dryRun;
		$sessionLockHeld=false;
		try{
			if($bootstrapTransactions){
				$this->acquireSessionLock();
				$sessionLockHeld=true;
			}
			$this->begin();
			$this->configureTransaction();
			if(!$bootstrapTransactions){
				$this->acquireLock();
			}
			$normalizedAliases=$this->normalizeJournalAliases($manifest);
			$state=$this->status($manifest);
			if(($state['drift_count'] ?? 0)>0){
				throw new RuntimeException(
					'Migration state changed or drifted while acquiring the migration lock.'
				);
			}
			$pendingValidation=$this->deploymentEvidence(
				$manifest,
				$state,
				$mode,
				$verifiedMinimumActiveRelease
			);
			if($pendingValidation['eligible']!==true){
				throw new RuntimeException(
					'Pending migrations are not eligible for '.$mode.' deployment: '.
					implode(', ', $pendingValidation['errors'])
				);
			}
			$this->ensureJournal();
			$statuses=[];
			foreach($state['migrations'] as $migration){
				if(is_string($migration['id'] ?? null)){
					$statuses[$migration['id']]=$migration['status'] ?? null;
				}
			}
			$selected=array_fill_keys(
				$pendingValidation['selected_migrations'],
				true
			);
			$insert=$this->prepare(
				'INSERT INTO '.$this->profile->journalQualified().
					' (migration_name, checksum_sha256, applied_at) '.
					'VALUES (?, ?, CURRENT_TIMESTAMP)'
			);
			if($bootstrapTransactions){
				$this->commit();
			}
			foreach($manifest->entries() as $entry){
				if(
					($statuses[$entry['id']] ?? null)==='applied'
					|| !isset($selected[$entry['id']])
				){
					continue;
				}
				if($bootstrapTransactions){
					$this->begin();
					$this->configureTransaction();
				}
				$this->executeSql($entry['up']['sql'], 'Migration up SQL failed: '.$entry['id'].'.');
				$this->executeStatement(
					$insert,
					[$entry['id'], $entry['up']['sha256']],
					'Migration journal insert failed: '.$entry['id'].'.'
				);
				$this->recordEvent($operationId, $entry, 'up', $identity);
				$executed[]=$entry['id'];
				if($bootstrapTransactions){
					$this->commit();
				}
			}
			$after=$this->status($manifest);
			if(($after['drift_count'] ?? 0)>0){
				throw new RuntimeException(
					'Pending migrations did not produce the schema declared by the immutable manifest.'
				);
			}
			if($dryRun){
				$this->rollbackTransaction();
			}elseif(!$bootstrapTransactions){
				$this->commit();
			}
			if($sessionLockHeld){
				$this->releaseSessionLock();
				$sessionLockHeld=false;
			}
		}catch(Throwable $exception){
			if($this->pdo->inTransaction()){
				$this->pdo->rollBack();
			}
			if($sessionLockHeld){
				$this->releaseSessionLock();
			}
			throw $exception;
		}

		return [
			'transaction'=>$dryRun ? 'rolled_back' : 'committed',
			'transaction_scope'=>$bootstrapTransactions ? 'per_migration' : 'deployment',
			'migrations'=>$executed,
			'deployment_mode'=>$mode,
			'direction'=>'up',
			'operation_id'=>$operationId,
			'release_version'=>$identity['release_version'],
			'release_sha256'=>$identity['release_sha256'],
			'required_minimum_active_release'=>
				$pendingValidation['required_minimum_active_release'],
			'verified_minimum_active_release'=>
				$pendingValidation['verified_minimum_active_release'],
			'normalized_legacy_aliases'=>$normalizedAliases,
			'bootstrap_cutoff'=>$manifest->bootstrapCutoff(),
			'lock'=>$bootstrapTransactions
				? 'pg_advisory_lock:'.$this->profile->advisoryLock()
				: $this->profile->lockEvidence(),
			'pending_validation'=>$pendingValidation,
		];
	}

	/**
	 * Roll back the applied suffix after an application has established any
	 * environment-specific drain/barrier authority.
	 *
	 * @param ?array{release_version:?string,release_sha256:?string} $releaseIdentity
	 * @return array<string,mixed>
	 */
	public function rollback(
		PostgreSqlMigrationManifest $manifest,
		string $targetId,
		bool $acceptDataLoss=false,
		?array $releaseIdentity=null
	): array {
		if(
			preg_match(PostgreSqlMigrationProfile::MIGRATION_ID_PATTERN, $targetId)!==1
			|| !in_array($targetId, array_column($manifest->entries(), 'id'), true)
		){
			throw new InvalidArgumentException(
				'Rollback target is not present in the selected manifest.'
			);
		}
		$identity=self::releaseIdentity($releaseIdentity);
		$this->begin();
		$operationId=bin2hex(random_bytes(16));
		$rolledBack=[];
		$rollbackSafety=[];
		$normalizedAliases=[];
		try{
			$this->configureTransaction();
			$this->acquireLock();
			$normalizedAliases=$this->normalizeJournalAliases($manifest);
			$state=$this->status($manifest);
			if(($state['drift_count'] ?? 0)>0){
				throw new RuntimeException(
					'Migration state changed or drifted while acquiring the migration lock.'
				);
			}
			$statuses=[];
			$journalNames=[];
			foreach($state['migrations'] as $migration){
				$id=$migration['id'] ?? null;
				if(is_string($id)){
					$statuses[$id]=$migration['status'] ?? null;
					$journalNames[$id]=$migration['journal_name'] ?? null;
				}
			}
			$tail=self::rollbackTail($manifest->entries(), $statuses, $targetId);
			$rollbackSafety=self::assertRollbackSafety($tail, $acceptDataLoss);
			$this->ensureJournal();
			$delete=$this->prepare(
				'DELETE FROM '.$this->profile->journalQualified().
				' WHERE migration_name = ? AND checksum_sha256 = ?'
			);
			foreach($tail as $entry){
				$this->inspector->certifyDown($this->pdo, $entry);
				$journalName=$journalNames[$entry['id']] ?? $entry['id'];
				$this->executeStatement(
					$delete,
					[$journalName, $entry['up']['sha256']],
					'Migration journal delete failed: '.$entry['id'].'.'
				);
				if($delete->rowCount()!==1){
					throw new RuntimeException(
						'Rollback could not remove the exact current journal row: '.$entry['id'].'.'
					);
				}
				$this->recordEvent($operationId, $entry, 'down', $identity);
				$rolledBack[]=$entry['id'];
			}
			$after=$this->status($manifest);
			if(
				($after['drift_count'] ?? 0)>0
				|| ($after['applied_head'] ?? null)!==$targetId
			){
				throw new RuntimeException(
					'Rollback did not produce the exact requested migration head.'
				);
			}
			$this->commit();
		}catch(Throwable $exception){
			$this->rollbackAfterFailure($exception);
		}

		return [
			'transaction'=>'committed',
			'migrations'=>$rolledBack,
			'direction'=>'down',
			'operation_id'=>$operationId,
			'release_version'=>$identity['release_version'],
			'release_sha256'=>$identity['release_sha256'],
			'rollback_to'=>$targetId,
			'rollback_safety'=>$rollbackSafety,
			'data_loss_accepted'=>$acceptDataLoss,
			'deployment_mode'=>null,
			'bootstrap_cutoff'=>$manifest->bootstrapCutoff(),
			'lock'=>$this->profile->lockEvidence(),
			'normalized_legacy_aliases'=>$normalizedAliases,
		];
	}

	/**
	 * Pure, comment/string-aware rolling compatibility scan.
	 *
	 * @return list<array{code:string,statement:int}>
	 */
	public static function rollingSqlIssues(string $sql): array {
		$issues=[];
		foreach(self::sqlStatements($sql) as $index=>$statement){
			$normalized=trim((string)preg_replace('/\s+/', ' ', $statement));
			$code=null;
			if(preg_match('/\bDROP\b/i', $normalized)===1){
				$code='drop_object';
			}elseif(preg_match('/\bTRUNCATE\b/i', $normalized)===1){
				$code='truncate_rows';
			}elseif(preg_match('/\bDELETE\s+FROM\b|\bTHEN\s+DELETE\b/i', $normalized)===1){
				$code='delete_rows';
			}elseif(preg_match('/^CREATE\s+OR\s+REPLACE\b/i', $normalized)===1){
				$code='replace_object';
			}elseif(preg_match('/^CREATE\s+(?:UNIQUE\s+)?(?:NULLS\s+(?:NOT\s+)?DISTINCT\s+)?INDEX\b/i', $normalized)===1){
				$code='create_index_requires_concurrent_autocommit_protocol';
			}elseif(preg_match('/^CREATE\s+(?:CONSTRAINT\s+)?TRIGGER\b/i', $normalized)===1){
				$code='create_trigger';
			}elseif(preg_match('/^REVOKE\b/i', $normalized)===1){
				$code='revoke_privilege';
			}elseif(preg_match('/^(?:DO\b|CALL\b|EXECUTE\b|CREATE\s+(?:OR\s+REPLACE\s+)?(?:FUNCTION|PROCEDURE)\b)/i', $normalized)===1){
				$code='dynamic_sql';
			}elseif(preg_match('/^CREATE\s+TABLE\b/i', $normalized)===1){
				if(!self::isSafeTableAddition($normalized)){
					$code='unsafe_create_table';
				}
			}elseif(preg_match('/^COMMENT\s+ON\b/i', $normalized)===1){
				if(!self::isSafeComment($normalized)){
					$code='unsafe_comment';
				}
			}elseif(preg_match('/^ALTER\s+TABLE\b/i', $normalized)===1){
				if(preg_match('/\bALTER\s+(?:COLUMN\s+)?[^ ]+\s+SET\s+NOT\s+NULL\b/i', $normalized)===1){
					$code='set_not_null';
				}elseif(preg_match('/\bADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?[^ ]+\s+.+\bNOT\s+NULL\b/i', $normalized)===1){
					$code='add_not_null_column';
				}elseif(!self::isNullableColumnAddition($normalized)){
					$code='incompatible_alter_table';
				}
			}elseif(preg_match('/^ALTER\b/i', $normalized)===1){
				$code='incompatible_alter';
			}elseif(preg_match('/^(?:INSERT|UPDATE|MERGE)\b/i', $normalized)===1){
				$code='data_mutation_not_allowlisted';
			}elseif(preg_match('/^GRANT\b/i', $normalized)===1){
				$code='privilege_change_not_allowlisted';
			}else{
				$code='unapproved_statement';
			}
			if($code!==null){
				$issues[]=['code'=>$code, 'statement'=>$index+1];
			}
		}
		return $issues;
	}

	/**
	 * @param list<array<string,mixed>> $entries
	 * @param array<string,mixed> $statuses
	 * @return list<array<string,mixed>>
	 */
	public static function rollbackTail(
		array $entries,
		array $statuses,
		string $targetId
	): array {
		if(preg_match(PostgreSqlMigrationProfile::MIGRATION_ID_PATTERN, $targetId)!==1){
			throw new InvalidArgumentException(
				'Rollback target must be one exact stable migration id.'
			);
		}
		$targetIndex=null;
		foreach($entries as $index=>$entry){
			if(($entry['id'] ?? null)===$targetId){
				$targetIndex=$index;
				break;
			}
		}
		if($targetIndex===null){
			throw new InvalidArgumentException(
				'Rollback target is not present in the selected manifest.'
			);
		}
		if(($statuses[$targetId] ?? null)!=='applied'){
			throw new RuntimeException(
				'Rollback target must be the applied head or an earlier applied migration.'
			);
		}
		$tail=[];
		$pendingSeen=false;
		foreach(array_slice($entries, $targetIndex+1) as $entry){
			$status=$statuses[$entry['id']] ?? null;
			if($status==='applied'){
				if($pendingSeen){
					throw new RuntimeException(
						'Rollback requires one contiguous applied migration tail.'
					);
				}
				$tail[]=$entry;
				continue;
			}
			$pendingSeen=true;
		}
		return array_reverse($tail);
	}

	/**
	 * @param list<array<string,mixed>> $tail
	 * @return list<array{id:string,safety:string}>
	 */
	public static function assertRollbackSafety(array $tail, bool $acceptDataLoss): array {
		$evidence=[];
		foreach($tail as $entry){
			if(!is_array($entry['down'] ?? null)){
				throw new RuntimeException(
					'Rollback crosses irreversible migration '.$entry['id'].': '.
					($entry['irreversible_reason'] ?? 'no down migration')
				);
			}
			$safety=$entry['down']['safety'];
			if($safety==='data_loss' && !$acceptDataLoss){
				throw new InvalidArgumentException(
					'Rollback crosses data-loss migration '.$entry['id'].
					'; --accept-data-loss is required.'
				);
			}
			$evidence[]=['id'=>$entry['id'], 'safety'=>$safety];
		}
		return $evidence;
	}

	/**
	 * @param array<string,array{row_count:string,hash_sum_a:string,hash_sum_b:string}> $before
	 * @param array<string,array{row_count:string,hash_sum_a:string,hash_sum_b:string}> $afterDown
	 */
	public static function assertLosslessDownRows(
		array $before,
		array $afterDown,
		string $migrationId
	): void {
		foreach($before as $table=>$evidence){
			$beforeCount=(string)($evidence['row_count'] ?? '');
			$downCount=array_key_exists($table, $afterDown)
				? (string)($afterDown[$table]['row_count'] ?? '')
				: null;
			if(
				($downCount===null && $beforeCount!=='0')
				|| ($downCount!==null && $downCount!==$beforeCount)
			){
				throw new RuntimeException(
					'Migration labelled lossless removes application rows in its down direction: '.
					$migrationId.' ('.$table.').'
				);
			}
		}
	}

	/**
	 * Recognize stable IDs and their immutable legacy up-filename aliases.
	 *
	 * @param array<string,array{sha256:string,applied_at:string}> $appliedRows
	 * @param list<array<string,mixed>> $entries
	 * @return array{
	 *   applied:array<string,array{sha256:string,applied_at:string,journal_name:string}>,
	 *   unmanifested:array<string,array<string,mixed>>
	 * }
	 */
	public static function projectJournalRows(array $appliedRows, array $entries): array {
		$aliases=[];
		foreach($entries as $entry){
			$aliases[$entry['id']]=$entry['id'];
			$aliases[$entry['up']['public_path']]=$entry['id'];
		}
		$applied=[];
		$unmanifested=[];
		foreach($appliedRows as $storedName=>$row){
			$id=$aliases[$storedName] ?? null;
			if($id===null){
				$unmanifested[$storedName]=$row;
				continue;
			}
			if(isset($applied[$id])){
				$unmanifested[$storedName]=$row+['duplicate_alias_for'=>$id];
				continue;
			}
			$applied[$id]=$row+['journal_name'=>$storedName];
		}
		return ['applied'=>$applied, 'unmanifested'=>$unmanifested];
	}

	/** @return array{release_version:?string,release_sha256:?string} */
	private static function releaseIdentity(?array $identity): array {
		if($identity===null){
			return ['release_version'=>null, 'release_sha256'=>null];
		}
		$keys=array_keys($identity);
		sort($keys, SORT_STRING);
		if($keys!==['release_sha256', 'release_version']){
			throw new InvalidArgumentException(
				'Migration release identity requires exact release_version and release_sha256 keys.'
			);
		}
		$releaseVersion=$identity['release_version'];
		$releaseSha256=$identity['release_sha256'];
		if(
			(($releaseVersion===null)!==($releaseSha256===null))
			|| (
				$releaseVersion!==null
				&& (
					!is_string($releaseVersion)
					|| !PostgreSqlMigrationProfile::validVersion($releaseVersion)
				)
			)
			|| (
				$releaseSha256!==null
				&& (
					!is_string($releaseSha256)
					|| preg_match('/^[a-f0-9]{64}$/D', $releaseSha256)!==1
				)
			)
		){
			throw new InvalidArgumentException('Migration release identity is invalid.');
		}
		return [
			'release_version'=>$releaseVersion,
			'release_sha256'=>$releaseSha256,
		];
	}

	private static function verifiedMinimumActiveRelease(
		?string $mode,
		?string $release
	): ?string {
		if($mode!=='maintenance'){
			if($release!==null){
				throw new InvalidArgumentException(
					'Verified minimum active release is accepted only for maintenance deployment.'
				);
			}
			return null;
		}
		if($release!==null && !PostgreSqlMigrationProfile::validVersion($release)){
			throw new InvalidArgumentException(
				'Verified minimum active release must be one exact semantic version.'
			);
		}
		return $release;
	}

	private function begin(): void {
		if(!$this->pdo->beginTransaction()){
			throw new RuntimeException('Dataphyre could not begin the migration transaction.');
		}
	}

	private function configureTransaction(): void {
		$this->executeSql(
			"SET LOCAL lock_timeout='".$this->profile->lockTimeout()."'",
			'Dataphyre could not configure the migration lock timeout.'
		);
		$this->executeSql(
			"SET LOCAL statement_timeout='".$this->profile->statementTimeout()."'",
			'Dataphyre could not configure the migration statement timeout.'
		);
	}

	private function acquireLock(): void {
		$statement=$this->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
		$this->executeStatement(
			$statement,
			[$this->profile->advisoryLock()],
			'Dataphyre could not acquire the migration advisory lock.'
		);
		$statement->fetchColumn();
	}

	private function acquireSessionLock(): void {
		$statement=$this->prepare('SELECT pg_advisory_lock(hashtext(?))');
		$this->executeStatement(
			$statement,
			[$this->profile->advisoryLock()],
			'Dataphyre could not acquire the bootstrap migration advisory lock.'
		);
		$statement->fetchColumn();
	}

	private function releaseSessionLock(): void {
		$statement=$this->prepare('SELECT pg_advisory_unlock(hashtext(?))');
		$this->executeStatement(
			$statement,
			[$this->profile->advisoryLock()],
			'Dataphyre could not release the bootstrap migration advisory lock.'
		);
		if($statement->fetchColumn()!==true){
			throw new RuntimeException(
				'Dataphyre did not hold the bootstrap migration advisory lock.'
			);
		}
	}

	private function tableExists(string $regclass): bool {
		$statement=$this->prepare(
			'SELECT CASE WHEN to_regclass(?) IS NULL THEN 0 ELSE 1 END'
		);
		$this->executeStatement(
			$statement,
			[$regclass],
			'Dataphyre could not inspect the migration journal.'
		);
		return (int)$statement->fetchColumn()===1;
	}

	/** @return list<array{from:string,to:string}> */
	private function normalizeJournalAliases(PostgreSqlMigrationManifest $manifest): array {
		if(!$this->tableExists($this->profile->journalRegclass())){
			return [];
		}
		$rows=$this->query(
			'SELECT migration_name FROM '.$this->profile->journalQualified().
			' ORDER BY migration_name'
		)->fetchAll(PDO::FETCH_ASSOC);
		$present=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$name=trim((string)($row['migration_name'] ?? ''));
			if($name!==''){
				$present[$name]=true;
			}
		}
		$normalized=[];
		$statement=$this->prepare(
			'UPDATE '.$this->profile->journalQualified().
			' SET migration_name = ? WHERE migration_name = ?'
		);
		foreach($manifest->entries() as $entry){
			$id=$entry['id'];
			$legacy=$entry['up']['public_path'];
			if($legacy===$id || !isset($present[$legacy])){
				continue;
			}
			if(isset($present[$id])){
				throw new RuntimeException(
					'Migration journal contains both stable and legacy identities for '.$id.'.'
				);
			}
			$this->executeStatement(
				$statement,
				[$id, $legacy],
				'Migration journal alias normalization failed: '.$id.'.'
			);
			$present[$id]=true;
			unset($present[$legacy]);
			$normalized[]=['from'=>$legacy, 'to'=>$id];
		}
		return $normalized;
	}

	private function ensureJournal(): void {
		$schema='"'.str_replace('"', '""', $this->profile->schema()).'"';
		$releaseDigestColumn=$this->quotedIdentifier(
			$this->profile->releaseDigestColumn()
		);
		$this->executeSql(
			'CREATE SCHEMA IF NOT EXISTS '.$schema,
			'Dataphyre could not create the migration schema.'
		);
		$this->executeSql(
			'CREATE TABLE IF NOT EXISTS '.$this->profile->journalQualified().' ('.
			'migration_name TEXT PRIMARY KEY, '.
			'checksum_sha256 CHAR(64) NOT NULL, '.
			'applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, '.
			'CONSTRAINT '.$this->quotedConstraint('schema_migrations_checksum').' '.
			"CHECK (checksum_sha256 ~ '^[a-f0-9]{64}$')".
			')',
			'Dataphyre could not create the migration journal.'
		);
		$this->executeSql(
			'CREATE TABLE IF NOT EXISTS '.$this->profile->eventQualified().' ('.
			'event_id BIGSERIAL PRIMARY KEY, '.
			'operation_id CHAR(32) NOT NULL, '.
			'migration_id TEXT NOT NULL, '.
			'direction TEXT NOT NULL, '.
			'up_checksum_sha256 CHAR(64) NOT NULL, '.
			'down_checksum_sha256 CHAR(64), '.
			'release_version TEXT, '.
			$releaseDigestColumn.' CHAR(64), '.
			'occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, '.
			'CONSTRAINT '.$this->quotedConstraint('schema_migration_events_operation').' '.
				"CHECK (operation_id ~ '^[a-f0-9]{32}$'), ".
			'CONSTRAINT '.$this->quotedConstraint('schema_migration_events_direction').' '.
				"CHECK (direction IN ('up', 'down')), ".
			'CONSTRAINT '.$this->quotedConstraint('schema_migration_events_id').' '.
				"CHECK (migration_id ~ '^[0-9]{3}_[a-z0-9_]+$'), ".
			'CONSTRAINT '.$this->quotedConstraint('schema_migration_events_up_checksum').' '.
				"CHECK (up_checksum_sha256 ~ '^[a-f0-9]{64}$'), ".
			'CONSTRAINT '.$this->quotedConstraint('schema_migration_events_down_checksum').' '.
				"CHECK (down_checksum_sha256 IS NULL OR ".
					"down_checksum_sha256 ~ '^[a-f0-9]{64}$'), ".
			'CONSTRAINT '.$this->quotedConstraint('schema_migration_events_down_identity').' '.
				"CHECK (direction <> 'down' OR down_checksum_sha256 IS NOT NULL), ".
			'CONSTRAINT '.$this->quotedConstraint('schema_migration_events_release_identity').' '.
				'CHECK ((release_version IS NULL) = ('.$releaseDigestColumn.' IS NULL)), '.
			'CONSTRAINT '.$this->quotedConstraint('schema_migration_events_release_digest').' '.
				'CHECK ('.$releaseDigestColumn." IS NULL OR ".$releaseDigestColumn.
					" ~ '^[a-f0-9]{64}$')".
			')',
			'Dataphyre could not create the migration event journal.'
		);
	}

	/**
	 * @param array<string,mixed> $entry
	 * @param array{release_version:?string,release_sha256:?string} $identity
	 */
	private function recordEvent(
		string $operationId,
		array $entry,
		string $direction,
		array $identity
	): void {
		if(
			preg_match('/^[a-f0-9]{32}$/D', $operationId)!==1
			|| !in_array($direction, ['up', 'down'], true)
		){
			throw new InvalidArgumentException('Migration event identity is invalid.');
		}
		$releaseDigestColumn=$this->quotedIdentifier(
			$this->profile->releaseDigestColumn()
		);
		$statement=$this->prepare(
			'INSERT INTO '.$this->profile->eventQualified().
			' (operation_id, migration_id, direction, up_checksum_sha256, '.
			'down_checksum_sha256, release_version, '.$releaseDigestColumn.', occurred_at) '.
			'VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
		);
		$this->executeStatement(
			$statement,
			[
				$operationId,
				$entry['id'],
				$direction,
				$entry['up']['sha256'],
				is_array($entry['down']) ? $entry['down']['sha256'] : null,
				$identity['release_version'],
				$identity['release_sha256'],
			],
			'Migration event journal insert failed: '.$entry['id'].'.'
		);
	}

	private function quotedConstraint(string $suffix): string {
		return '"'.str_replace('"', '""', $this->profile->constraintName($suffix)).'"';
	}

	private function quotedIdentifier(string $identifier): string {
		return '"'.str_replace('"', '""', $identifier).'"';
	}

	private function query(string $sql): PDOStatement {
		$statement=$this->pdo->query($sql);
		if(!$statement instanceof PDOStatement){
			throw new RuntimeException('Dataphyre PostgreSQL migration query failed.');
		}
		return $statement;
	}

	private function prepare(string $sql): PDOStatement {
		$statement=$this->pdo->prepare($sql);
		if(!$statement instanceof PDOStatement){
			throw new RuntimeException('Dataphyre could not prepare a migration statement.');
		}
		return $statement;
	}

	/** @param list<mixed> $parameters */
	private function executeStatement(
		PDOStatement $statement,
		array $parameters,
		string $error
	): void {
		if(!$statement->execute($parameters)){
			throw new RuntimeException($error);
		}
	}

	private function executeSql(string $sql, string $error): void {
		if($this->pdo->exec($sql)===false){
			throw new RuntimeException($error);
		}
	}

	private function commit(): void {
		if(!$this->pdo->commit()){
			throw new RuntimeException('Dataphyre could not commit the migration transaction.');
		}
	}

	private function rollbackTransaction(): void {
		if(!$this->pdo->rollBack()){
			throw new RuntimeException('Dataphyre could not roll back the migration transaction.');
		}
	}

	/** @return never */
	private function rollbackAfterFailure(Throwable $exception): never {
		if($this->pdo->inTransaction()){
			$this->pdo->rollBack();
		}
		throw $exception;
	}

	private static function isSafeTableAddition(string $statement): bool {
		$identifier='(?:"[x]+"|[A-Za-z_][A-Za-z0-9_$]*)';
		$qualified=$identifier.'(?:\s*\.\s*'.$identifier.')?';
		if(preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?'.$qualified.'\s*\(.+\)$/i', $statement)!==1){
			return false;
		}
		return preg_match('/\b(?:AS|LIKE|PARTITION|INHERITS|OF|REFERENCES|FOREIGN\s+KEY|EXCLUDE|DEFAULT|GENERATED|IDENTITY|SMALLSERIAL|SERIAL|BIGSERIAL|WITH|ON\s+COMMIT|SERVER)\b/i', $statement)!==1;
	}

	private static function isSafeComment(string $statement): bool {
		return preg_match('/^COMMENT\s+ON\s+.+\s+IS(?:\s+(?:NULL|E|U&))?$/i', $statement)===1;
	}

	private static function isNullableColumnAddition(string $statement): bool {
		$identifier='(?:"[x]+"|[A-Za-z_][A-Za-z0-9_$]*)';
		$qualified=$identifier.'(?:\s*\.\s*'.$identifier.')?';
		if(preg_match('/^ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:ONLY\s+)?'.$qualified.'\s+ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?'.$identifier.'\s+(.+)$/i', $statement, $matches)!==1){
			return false;
		}
		if(preg_match('/\b(?:DEFAULT|NOT\s+NULL|PRIMARY\s+KEY|UNIQUE|REFERENCES|CHECK|CONSTRAINT|GENERATED|IDENTITY|SMALLSERIAL|SERIAL|BIGSERIAL)\b/i', $statement)===1){
			return false;
		}
		if(preg_match('/,\s*(?:ADD|ALTER|DROP|RENAME)\b/i', $statement)===1){
			return false;
		}
		$type=trim((string)($matches[1] ?? ''));
		$scalar='(?:'.implode('|', [
			'SMALLINT', 'INT2', 'INTEGER', 'INT', 'INT4', 'BIGINT', 'INT8',
			'DECIMAL(?:\s*\(\s*[0-9]+(?:\s*,\s*[0-9]+)?\s*\))?',
			'NUMERIC(?:\s*\(\s*[0-9]+(?:\s*,\s*[0-9]+)?\s*\))?',
			'REAL', 'FLOAT4', 'DOUBLE\s+PRECISION', 'FLOAT8', 'MONEY',
			'CHARACTER\s+VARYING(?:\s*\(\s*[0-9]+\s*\))?',
			'VARCHAR(?:\s*\(\s*[0-9]+\s*\))?',
			'CHARACTER(?:\s*\(\s*[0-9]+\s*\))?',
			'CHAR(?:\s*\(\s*[0-9]+\s*\))?', 'TEXT', 'BYTEA',
			'TIMESTAMP(?:\s*\(\s*[0-9]+\s*\))?(?:\s+WITH(?:OUT)?\s+TIME\s+ZONE)?',
			'TIMESTAMPTZ', 'DATE',
			'TIME(?:\s*\(\s*[0-9]+\s*\))?(?:\s+WITH(?:OUT)?\s+TIME\s+ZONE)?',
			'TIMETZ', 'INTERVAL', 'BOOLEAN', 'BOOL',
			'POINT', 'LINE', 'LSEG', 'BOX', 'PATH', 'POLYGON', 'CIRCLE',
			'CIDR', 'INET', 'MACADDR', 'MACADDR8',
			'BIT(?:\s*\(\s*[0-9]+\s*\))?',
			'BIT\s+VARYING(?:\s*\(\s*[0-9]+\s*\))?',
			'VARBIT(?:\s*\(\s*[0-9]+\s*\))?',
			'TSVECTOR', 'TSQUERY', 'UUID', 'XML', 'JSON', 'JSONB', 'PG_LSN',
		]).')';
		return preg_match(
			'/^'.$scalar.'(?:\s*\[\s*\])*'.
			'(?:\s+COLLATE\s+'.$qualified.')?(?:\s+NULL)?$/i',
			$type
		)===1;
	}

	/** @return list<string> */
	private static function sqlStatements(string $sql): array {
		$statements=[];
		foreach(explode(';', self::sqlCode($sql)) as $statement){
			$statement=trim($statement);
			if($statement!==''){
				$statements[]=$statement;
			}
		}
		return $statements;
	}

	/** Strip comments and literal contents while retaining executable shape. */
	private static function sqlCode(string $sql): string {
		$code='';
		$length=strlen($sql);
		for($index=0; $index<$length;){
			if(substr($sql, $index, 2)==='--'){
				$end=strpos($sql, "\n", $index+2);
				if($end===false){
					$code.=str_repeat(' ', $length-$index);
					break;
				}
				$code.=str_repeat(' ', $end-$index)."\n";
				$index=$end+1;
				continue;
			}
			if(substr($sql, $index, 2)==='/*'){
				$start=$index;
				$depth=1;
				$index+=2;
				while($index<$length && $depth>0){
					if(substr($sql, $index, 2)==='/*'){
						$depth++;
						$index+=2;
						continue;
					}
					if(substr($sql, $index, 2)==='*/'){
						$depth--;
						$index+=2;
						continue;
					}
					$index++;
				}
				$masked=substr($sql, $start, $index-$start);
				$code.=(string)preg_replace('/[^\r\n]/', ' ', $masked);
				continue;
			}
			$character=$sql[$index];
			if($character==="'"){
				$start=$index++;
					while($index<$length){
					if($sql[$index]==="'" && ($sql[$index+1] ?? null)==="'"){
						$index+=2;
						continue;
					}
					if($sql[$index]==="'"){
						$index++;
						break;
					}
					if($sql[$index]==='\\' && $index+1<$length){
						$index+=2;
						continue;
					}
					$index++;
				}
				$masked=substr($sql, $start, $index-$start);
				$code.=(string)preg_replace('/[^\r\n]/', ' ', $masked);
				continue;
			}
			if(
				$character==='$'
				&& preg_match(
					'/^\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/',
					substr($sql, $index),
					$tag
				)===1
			){
				$start=$index;
				$index+=strlen($tag[0]);
				$end=strpos($sql, $tag[0], $index);
				$index=$end===false ? $length : $end+strlen($tag[0]);
				$masked=substr($sql, $start, $index-$start);
				$code.=(string)preg_replace('/[^\r\n]/', ' ', $masked);
				continue;
			}
			if($character==='"'){
				$code.='"';
				$index++;
				while($index<$length){
					if($sql[$index]==='"' && ($sql[$index+1] ?? null)==='"'){
						$code.='xx';
						$index+=2;
						continue;
					}
					if($sql[$index]==='"'){
						$code.='"';
						$index++;
						break;
					}
						$code.=ctype_space($sql[$index]) ? $sql[$index] : 'x';
						$index++;
					}
				}else{
					$code.=$character;
					$index++;
				}
			}
		return $code;
	}
}
