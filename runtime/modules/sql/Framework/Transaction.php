<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class Transaction {

	private bool $active=false;
	private bool $begun=false;
	private bool $committed=false;
	private bool $rolled_back=false;
	private ?string $cluster;

	public function __construct(?string $cluster=null){
		$this->cluster=$cluster!==null ? trim($cluster) : null;
		if($this->cluster===''){
			$this->cluster=null;
		}
	}

	public function cluster(): ?string {
		return $this->cluster;
	}

	public function isActive(): bool {
		return $this->active;
	}

	public function begun(): bool {
		return $this->begun;
	}

	public function committed(): bool {
		return $this->committed;
	}

	public function rolledBack(): bool {
		return $this->rolled_back;
	}

	public function begin(): self {
		if($this->active){
			throw SqlError::transactionException(
				'Cannot begin a transaction that is already active.',
				$this->cluster,
				'Create a new transaction instance, or commit or roll back the current one before beginning again.'
			);
		}
		if(sql_begin($this->cluster)===false){
			throw SqlError::transactionException(
				'Failed to begin the SQL transaction.',
				$this->cluster,
				'Check the cluster name, connection health, and SQL logs for the engine-level failure.'
			);
		}
		$this->active=true;
		$this->begun=true;
		$this->committed=false;
		$this->rolled_back=false;
		return $this;
	}

	public function commit(): self {
		if(!$this->active){
			throw SqlError::transactionException(
				'Cannot commit an inactive transaction.',
				$this->cluster,
				'Only call commit() after begin(), and only once per transaction.'
			);
		}
		if(sql_commit($this->cluster)===false){
			throw SqlError::transactionException(
				'Failed to commit the SQL transaction.',
				$this->cluster,
				'Check the SQL logs for commit-time failures such as connection drops or engine-level transaction errors.'
			);
		}
		$this->active=false;
		$this->committed=true;
		return $this;
	}

	public function rollback(): self {
		if(!$this->active){
			throw SqlError::transactionException(
				'Cannot roll back an inactive transaction.',
				$this->cluster,
				'Only call rollback() after begin(), and only while the transaction is still active.'
			);
		}
		if(sql_rollback($this->cluster)===false){
			throw SqlError::transactionException(
				'Failed to roll back the SQL transaction.',
				$this->cluster,
				'Check the SQL logs for rollback-time failures such as lost connections or engine-level transaction errors.'
			);
		}
		$this->active=false;
		$this->rolled_back=true;
		return $this;
	}

	public function run(callable $callback): mixed {
		$this->begin();
		try{
			$value=$callback();
			if($this->active){
				$this->commit();
			}
			return $value;
		}catch(\Throwable $exception){
			if($this->active){
				try{
					$this->rollback();
				}catch(\Throwable $rollback_exception){
					throw SqlError::transactionException(
						'Rollback failed after the transaction callback threw an exception: '.$rollback_exception->getMessage(),
						$this->cluster,
						'Inspect both the original callback exception and the rollback failure. The SQL logs should contain the engine-level details.',
						$exception
					);
				}
			}
			throw $exception;
		}
	}

	public function attempt(callable $callback): TransactionResult {
		try{
			$value=$this->run($callback);
			return TransactionResult::success(
				$this->cluster,
				$this->begun,
				$this->committed,
				$value
			);
		}catch(\Throwable $exception){
			return TransactionResult::failure(
				$this->cluster,
				$this->begun,
				$this->committed,
				$this->rolled_back,
				$exception
			);
		}
	}
}
