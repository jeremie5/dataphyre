<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** PDO-backed executor with stable failures and no connection-detail serialization. */
final class PanelPdoSqlExecutor implements PanelSqlMutationExecutor {
	private readonly string $driver;
	private int $savepointSequence=0;
	private bool $manualSqliteTransaction=false;

	public function __construct(private readonly \PDO $pdo) {
		try{ $driver=strtolower(trim((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME))); }
		catch(\Throwable $error){ throw new \InvalidArgumentException('Panel PDO executor could not determine its driver.', 0, $error); }
		if(!in_array($driver, ['mysql','pgsql','sqlite'], true)){
			throw new \InvalidArgumentException("Panel PDO executor does not support driver '{$driver}'.");
		}
		$this->driver=$driver;
	}

	public function driver(): string { return $this->driver; }

	public function rows(string $sql, array $parameters=[]): array {
		$statement=$this->prepare($sql, $parameters, 'rows');
		try{
			$rows=$statement->fetchAll(\PDO::FETCH_ASSOC);
			if(!is_array($rows)){ throw new \UnexpectedValueException('PDO did not return a row list.'); }
			$out=[];
			foreach($rows as $row){
				if(!is_array($row) || array_is_list($row)){ throw new \UnexpectedValueException('PDO returned a non-object row.'); }
				$out[]=$row;
			}
			return $out;
		}
		catch(PanelSqlExecutionException $error){ throw $error; }
		catch(\Throwable $error){ throw new PanelSqlExecutionException('rows', $error); }
		finally{ try{ $statement->closeCursor(); }catch(\Throwable){} }
	}

	public function scalar(string $sql, array $parameters=[]): mixed {
		$statement=$this->prepare($sql, $parameters, 'scalar');
		try{ return $statement->fetchColumn(); }
		catch(\Throwable $error){ throw new PanelSqlExecutionException('scalar', $error); }
		finally{ try{ $statement->closeCursor(); }catch(\Throwable){} }
	}

	public function execute(string $sql, array $parameters=[]): int {
		$statement=$this->prepare($sql, $parameters, 'execute');
		try{
			$count=$statement->rowCount();
			if($count<0){ throw new \UnexpectedValueException('PDO returned an invalid affected-row count.'); }
			return $count;
		}
		catch(PanelSqlExecutionException $error){ throw $error; }
		catch(\Throwable $error){ throw new PanelSqlExecutionException('execute', $error); }
		finally{ try{ $statement->closeCursor(); }catch(\Throwable){} }
	}

	public function transaction(callable $callback): mixed {
		$nested=$this->activeTransaction();
		if($nested){
			$savepoint='dp_panel_mutation_'.(++$this->savepointSequence);
			try{
				$this->control('SAVEPOINT '.$savepoint);
				$result=$callback();
				$this->control('RELEASE SAVEPOINT '.$savepoint);
				return $result;
			}
			catch(\Throwable $error){
				try{ $this->control('ROLLBACK TO SAVEPOINT '.$savepoint); $this->control('RELEASE SAVEPOINT '.$savepoint); }catch(\Throwable){}
				throw $error;
			}
		}
		try{
			if($this->driver==='sqlite'){
				if($this->pdo->exec('BEGIN IMMEDIATE')===false){ throw new \RuntimeException('PDO transaction begin failed.'); }
				$this->manualSqliteTransaction=true;
			}elseif(!$this->pdo->beginTransaction()){
				throw new \RuntimeException('PDO transaction begin failed.');
			}
			$result=$callback();
			$this->commit();
			return $result;
		}
		catch(\Throwable $error){ $this->rollback(); throw $error; }
	}

	public function manifest(): array {
		return [
			'type'=>'panel_sql_executor', 'adapter'=>'pdo', 'driver'=>$this->driver,
			'prepared_statements'=>true, 'connection_details_serialized'=>false,
			'parameters_serialized'=>false, 'transaction_owned'=>true,
			'write_execution'=>true, 'nested_transaction_savepoints'=>true,
			'host_transaction_participation'=>true,
		];
	}

	private function activeTransaction(): bool { return $this->manualSqliteTransaction || $this->pdo->inTransaction(); }
	private function control(string $sql): void { if($this->pdo->exec($sql)===false){ throw new PanelSqlExecutionException('transaction_control'); } }
	private function commit(): void {
		if($this->manualSqliteTransaction){
			if($this->pdo->exec('COMMIT')===false){ throw new PanelSqlExecutionException('transaction_commit'); }
			$this->manualSqliteTransaction=false;
			return;
		}
		if(!$this->pdo->commit()){ throw new PanelSqlExecutionException('transaction_commit'); }
	}
	private function rollback(): void {
		try{
			if($this->manualSqliteTransaction){ $this->pdo->exec('ROLLBACK'); }
			elseif($this->pdo->inTransaction()){ $this->pdo->rollBack(); }
		}catch(\Throwable){}finally{$this->manualSqliteTransaction=false;}
	}

	/** @param array<string, null|bool|int|float|string> $parameters */
	private function prepare(string $sql, array $parameters, string $operation): \PDOStatement {
		if(trim($sql)==='' || strlen($sql)>262144){ throw new \InvalidArgumentException('Panel SQL statements must contain between 1 and 262144 bytes.'); }
		try{
			$statement=$this->pdo->prepare($sql);
			if(!$statement instanceof \PDOStatement){ throw new \RuntimeException('PDO prepare returned no statement.'); }
			foreach($parameters as $name=>$value){
				if(preg_match('/^p[1-9][0-9]{0,5}$/D', $name)!==1){ throw new \InvalidArgumentException('Panel SQL parameter names are invalid.'); }
				$statement->bindValue(':'.$name, $value, self::pdoType($value));
			}
			if(!$statement->execute()){ throw new \RuntimeException('PDO statement execution failed.'); }
			return $statement;
		}
		catch(\InvalidArgumentException $error){ throw $error; }
		catch(\Throwable $error){ throw new PanelSqlExecutionException($operation, $error); }
	}

	private static function pdoType(null|bool|int|float|string $value): int {
		return match(true){
			$value===null=>\PDO::PARAM_NULL,
			is_bool($value)=>\PDO::PARAM_BOOL,
			is_int($value)=>\PDO::PARAM_INT,
			default=>\PDO::PARAM_STR,
		};
	}
}
