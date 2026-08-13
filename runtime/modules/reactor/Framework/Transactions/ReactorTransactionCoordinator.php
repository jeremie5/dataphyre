<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Authorizes, validates, rebases, commits, retries, streams, and rolls back
 * Reactor state transactions.
 */
final class ReactorTransactionCoordinator {
	private $authorizer=null;
	private $streamAuthorizer=null;
	private $validator=null;
	private $mutator=null;
	private bool $allowUnauthenticatedTransactions=false;
	private bool $allowUnauthenticatedStreams=false;

	public function __construct(private readonly ReactorTransactionStore $store) {}

	public function authorize(?callable $authorizer): self { $clone=clone $this; $clone->authorizer=$authorizer; return $clone; }
	public function authorizeStream(?callable $authorizer): self { $clone=clone $this; $clone->streamAuthorizer=$authorizer; return $clone; }
	public function validate(?callable $validator): self { $clone=clone $this; $clone->validator=$validator; return $clone; }
	public function mutate(?callable $mutator): self { $clone=clone $this; $clone->mutator=$mutator; return $clone; }

	/**
	 * Explicit legacy/internal opt-in. Never enable this on a request-facing
	 * coordinator: the secure default is to deny when no authorizer exists.
	 */
	public function allowUnauthenticatedTransactions(bool $allow=true): self {
		$clone=clone $this;
		$clone->allowUnauthenticatedTransactions=$allow;
		return $clone;
	}

	/** Explicit legacy/internal opt-in for event reads without an authorizer. */
	public function allowUnauthenticatedStreams(bool $allow=true): self {
		$clone=clone $this;
		$clone->allowUnauthenticatedStreams=$allow;
		return $clone;
	}

	public function dispatch(ReactorStateTransaction $transaction, bool $online=true, array $securityContext=[]): ReactorTransactionResult {
		if(!$online){
			if(!$transaction->offlineCapableValue()){
				return $this->result('offline_rejected', $transaction, $transaction->baseVersion(), [], [], ['Transaction is not offline-capable.'], ['error_code'=>'offline_not_capable']);
			}
			$current=$this->store->load($transaction->component());
			if(($denied=$this->authorizationFailure($transaction, $current['state'], (int)$current['version'], $securityContext))!==null){ return $denied; }
			$this->store->enqueue($transaction);
			return $this->result('queued', $transaction, $transaction->baseVersion(), [], [], [], ['offline'=>true]);
		}
		return $this->execute($transaction, $securityContext);
	}

	public function execute(ReactorStateTransaction $transaction, array $securityContext=[]): ReactorTransactionResult {
		if($transaction->expired()){
			return $this->result('expired', $transaction, $transaction->baseVersion(), [], [], ['Transaction has expired.'], ['error_code'=>'transaction_expired']);
		}
		if($transaction->patches()===[]){
			return $this->result('invalid', $transaction, $transaction->baseVersion(), [], [], ['Transaction contains no patches.'], ['error_code'=>'transaction_empty']);
		}

		$lastConflict=null;
		for($attempt=1;$attempt<=$transaction->retryPolicy()->attempts();$attempt++){
			$current=$this->store->load($transaction->component());
			$version=(int)$current['version'];
			if(($denied=$this->authorizationFailure($transaction, $current['state'], $version, $securityContext))!==null){ return $denied; }
			if($attempt===1){
				$duplicate=$this->store->receipt($transaction->component(), $transaction->idempotencyKeyValue());
				if(is_array($duplicate)){
					$duplicate['status']='duplicate';
					return ReactorTransactionResult::fromArray($duplicate);
				}
			}
			if($version!==$transaction->baseVersion() && $transaction->conflictStrategyValue()==='reject'){
				return $this->result('conflict', $transaction, $version, $current['state'], [], ['State version changed.'], ['error_code'=>'version_conflict', 'expected_version'=>$transaction->baseVersion(), 'actual_version'=>$version]);
			}
			if($version!==$transaction->baseVersion() && $transaction->conflictStrategyValue()==='server_wins'){
				return $this->result('server_wins', $transaction, $version, $current['state'], [], [], ['expected_version'=>$transaction->baseVersion(), 'actual_version'=>$version]);
			}

			$state=$current['state'];
			$inverse=[];
			try {
				foreach($transaction->patches() as $patch){
					$application=$patch->apply($state);
					$state=$application['state'];
					array_unshift($inverse, $application['inverse']);
				}
			} catch(\Throwable){
				return $this->result('invalid', $transaction, $version, $current['state'], [], ['Transaction patches are invalid.'], ['error_code'=>'patch_invalid']);
			}

			if($this->mutator!==null){
				try { $mutated=($this->mutator)($state, $current['state'], $transaction, $version, $securityContext); }
				catch(\Throwable){ return $this->result('failed', $transaction, $version, $current['state'], $inverse, ['Transaction mutation failed.'], ['error_code'=>'mutation_failed']); }
				if(is_array($mutated)){ $state=$mutated; }
			}
			if($this->validator!==null){
				try { $validation=($this->validator)($state, $current['state'], $transaction, $securityContext); }
				catch(\Throwable){ return $this->result('failed', $transaction, $version, $current['state'], $inverse, ['Transaction validation is unavailable.'], ['error_code'=>'validation_unavailable']); }
				if($validation!==true && $validation!==[] && $validation!==null){
					$errors=is_array($validation) ? $validation : [(string)$validation];
					return $this->result('invalid', $transaction, $version, $current['state'], $inverse, $errors, ['error_code'=>'validation_failed']);
				}
			}

			try {
				$checksum=hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
			} catch(\Throwable){
				return $this->result('failed', $transaction, $version, $current['state'], $inverse, ['Transaction state could not be serialized.'], ['error_code'=>'serialization_failed']);
			}
			$receipt=$this->result('committed', $transaction, $version+1, $state, $inverse, [], [
				'attempt'=>$attempt,
				'rebased'=>$version!==$transaction->baseVersion(),
				'checksum'=>$checksum,
			]);
			$serialized=$receipt->jsonSerialize();
			$event=[
				'type'=>'transaction.committed',
				'transaction_id'=>$transaction->idValue(),
				'version'=>$version+1,
				'patches'=>array_map(static fn(ReactorStatePatch $patch): array => $patch->jsonSerialize(), $transaction->patches()),
				'metadata'=>$transaction->metadataValue(),
			];
			if($this->store->commit($transaction->component(), $version, $state, $transaction->idempotencyKeyValue(), $serialized, [$event])){
				return $receipt;
			}
			$lastConflict=$this->result('conflict', $transaction, $version, $current['state'], [], ['Concurrent commit detected.'], ['error_code'=>'concurrent_commit', 'attempt'=>$attempt]);
		}
		return $lastConflict ?? $this->result('failed', $transaction, $transaction->baseVersion(), [], [], ['Transaction could not be committed.'], ['error_code'=>'commit_failed']);
	}

	/** @return list<ReactorTransactionResult> */
	public function drain(string $component, int $limit=100, array $securityContext=[]): array {
		$results=[];
		foreach($this->store->queued($component, $limit) as $payload){
			try {
				$transaction=ReactorStateTransaction::fromArray($payload);
				$result=$this->execute($transaction, $securityContext);
				$results[]=$result;
				$errorCode=(string)($result->metadata()['error_code'] ?? '');
				$terminalDenial=$result->status()==='denied' && $errorCode==='authorization_denied';
				if($result->ok() || in_array($result->status(), ['expired', 'invalid'], true) || $terminalDenial){
					$this->store->dequeue($component, $transaction->idValue());
				}
			} catch(\Throwable){
				$results[]=new ReactorTransactionResult('failed', (string)($payload['id'] ?? ''), $component, 0, [], [], ['Queued transaction could not be read.'], ['error_code'=>'queue_payload_invalid']);
			}
		}
		return $results;
	}

	public function stream(string $component, int $afterSequence=0, int $limit=100, array $securityContext=[]): array {
		if(!$this->allowUnauthenticatedStreams){
			if($this->streamAuthorizer===null){ throw new \RuntimeException('Reactor stream authorization is required.', 403); }
			try { $decision=($this->streamAuthorizer)($component, $securityContext, $afterSequence, $limit); }
			catch(\Throwable){ throw new \RuntimeException('Reactor stream authorization is unavailable.', 403); }
			if($decision!==true){ throw new \RuntimeException('Reactor stream authorization denied.', 403); }
		}
		return $this->store->events($component, $afterSequence, $limit);
	}

	private function authorizationFailure(ReactorStateTransaction $transaction, array $state, int $version, array $securityContext): ?ReactorTransactionResult {
		if($this->allowUnauthenticatedTransactions){ return null; }
		if($this->authorizer===null){
			return $this->result('denied', $transaction, $version, [], [], ['Transaction authorization is required.'], ['error_code'=>'authorization_required']);
		}
		try { $decision=($this->authorizer)($transaction, $state, $version, $securityContext); }
		catch(\Throwable){
			return $this->result('denied', $transaction, $version, [], [], ['Transaction authorization is unavailable.'], ['error_code'=>'authorization_unavailable']);
		}
		if($decision===true){ return null; }
		$errors=is_array($decision) ? $decision : [(string)($decision ?: 'Transaction is not authorized.')];
		return $this->result('denied', $transaction, $version, [], [], $errors, ['error_code'=>'authorization_denied']);
	}

	private function result(string $status, ReactorStateTransaction $transaction, int $version, array $state, array $inverse, array $errors=[], array $metadata=[]): ReactorTransactionResult {
		return new ReactorTransactionResult($status, $transaction->idValue(), $transaction->component(), $version, $state, $inverse, array_values($errors), $metadata);
	}
}
