<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Checksummed atomic JSON receipt store with a process-safe idempotency lock.
 */
final class FilesystemAutomationStore implements AutomationStore, \JsonSerializable {
	private string $directory;

	public function __construct(string $directory) {
		$directory=rtrim(trim($directory), '/\\');
		if($directory===''){
			throw new \InvalidArgumentException('Filesystem automation storage requires a directory.');
		}
		if(!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)){
			throw new \RuntimeException("Unable to create automation storage directory '{$directory}'.");
		}
		$this->directory=realpath($directory) ?: $directory;
	}

	public function save(AutomationReceipt $receipt): bool {
		return $this->locked(LOCK_EX, function() use($receipt): bool {
			if($this->getWithoutLock($receipt->id()) instanceof AutomationReceipt){ return false; }
			if($receipt->idempotencyHash()!==null){
				foreach($this->scan() as $existing){
					if($existing->action()===$receipt->action() && $existing->idempotencyHash()===$receipt->idempotencyHash()){
						return false;
					}
				}
			}
			$file=$this->file($receipt->id());
			$payload=$receipt->jsonSerialize();
			$envelope=['format'=>'dataphyre.panel.automation.v1', 'checksum'=>hash('sha256', WorkflowEvent::canonicalJson($payload)), 'receipt'=>$payload];
			$json=json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
			$temp=$file.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(6));
			$handle=@fopen($temp, 'xb');
			if($handle===false){ throw new \RuntimeException('Unable to create temporary automation receipt.'); }
			try{
				try{
					if(fwrite($handle, $json)!==strlen($json) || !fflush($handle)){ throw new \RuntimeException('Unable to flush automation receipt.'); }
					if(function_exists('fsync')){ @fsync($handle); }
				}finally{ fclose($handle); }
			}catch(\Throwable $exception){
				@unlink($temp);
				throw $exception;
			}
			if(!@rename($temp, $file)){
				@unlink($temp);
				throw new \RuntimeException('Unable to atomically publish automation receipt.');
			}
			return true;
		});
	}

	public function get(string $receiptId): ?AutomationReceipt {
		return $this->locked(LOCK_SH, fn(): ?AutomationReceipt=>$this->getWithoutLock($receiptId));
	}

	public function findByIdempotency(string $action, string $idempotencyKey): ?AutomationReceipt {
		$action=WorkflowState::normalize($action);
		$hash=hash('sha256', trim($idempotencyKey));
		return $this->locked(LOCK_SH, function() use($action, $hash): ?AutomationReceipt {
			foreach($this->scan() as $receipt){
				if($receipt->action()===$action && $receipt->idempotencyHash()===$hash){ return $receipt; }
			}
			return null;
		});
	}

	public function all(?string $action=null): array {
		$action=$action===null ? null : WorkflowState::normalize($action);
		return $this->locked(LOCK_SH, function() use($action): array {
			$receipts=array_values(array_filter($this->scan(), static fn(AutomationReceipt $receipt): bool=>$action===null || $receipt->action()===$action));
			usort($receipts, static fn(AutomationReceipt $left, AutomationReceipt $right): int=>strcmp($left->startedAt(), $right->startedAt()) ?: strcmp($left->id(), $right->id()));
			return $receipts;
		});
	}

	public function jsonSerialize(): array { return ['type'=>'filesystem_automation_store', 'directory'=>$this->directory, 'format_version'=>1]; }

	/** @template T @param callable():T $callback @return T */
	private function locked(int $mode, callable $callback): mixed {
		$handle=@fopen($this->directory.DIRECTORY_SEPARATOR.'.automation.lock', 'c+b');
		if($handle===false){ throw new \RuntimeException('Unable to open automation store lock.'); }
		try{
			if(!flock($handle, $mode)){ throw new \RuntimeException('Unable to acquire automation store lock.'); }
			return $callback();
		}finally{ flock($handle, LOCK_UN); fclose($handle); }
	}

	private function file(string $receiptId): string { return $this->directory.DIRECTORY_SEPARATOR.hash('sha256', trim($receiptId)).'.receipt.json'; }

	private function getWithoutLock(string $receiptId): ?AutomationReceipt {
		$file=$this->file($receiptId);
		$receipt=$this->read($file);
		return $receipt instanceof AutomationReceipt && hash_equals($receipt->id(), trim($receiptId)) ? $receipt : null;
	}

	/** @return list<AutomationReceipt> */
	private function scan(): array {
		$result=[];
		foreach(glob($this->directory.DIRECTORY_SEPARATOR.'*.receipt.json') ?: [] as $file){
			$receipt=$this->read($file);
			if($receipt instanceof AutomationReceipt){ $result[]=$receipt; }
		}
		return $result;
	}

	private function read(string $file): ?AutomationReceipt {
		if(!is_file($file)){ return null; }
		$contents=@file_get_contents($file);
		if(!is_string($contents) || $contents===''){ throw new \RuntimeException('Automation receipt is unreadable or empty.'); }
		try{ $envelope=json_decode($contents, true, 64, JSON_THROW_ON_ERROR); }
		catch(\JsonException $exception){ throw new \RuntimeException('Automation receipt contains invalid JSON.', 0, $exception); }
		if(!is_array($envelope) || ($envelope['format'] ?? null)!=='dataphyre.panel.automation.v1' || !is_array($envelope['receipt'] ?? null)){
			throw new \RuntimeException('Automation receipt has an unsupported envelope.');
		}
		$checksum=hash('sha256', WorkflowEvent::canonicalJson($envelope['receipt']));
		if(!is_string($envelope['checksum'] ?? null) || !hash_equals($envelope['checksum'], $checksum)){
			throw new \RuntimeException('Automation receipt checksum verification failed.');
		}
		return AutomationReceipt::fromArray($envelope['receipt']);
	}
}
