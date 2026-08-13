<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Checksummed, lock-protected, atomic JSON workflow persistence adapter.
 */
final class FilesystemWorkflowStore implements WorkflowStore, \JsonSerializable {
	private string $directory;

	public function __construct(string $directory) {
		$directory=rtrim(trim($directory), '/\\');
		if($directory===''){
			throw new \InvalidArgumentException('Filesystem workflow storage requires a directory.');
		}
		if(!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)){
			throw new \RuntimeException("Unable to create workflow storage directory '{$directory}'.");
		}
		$real=realpath($directory);
		$this->directory=$real!==false ? $real : $directory;
	}

	public function create(WorkflowRecord $record): bool {
		return $this->locked($record->definition(), $record->id(), LOCK_EX, function(string $file) use($record): bool {
			if(is_file($file)){
				return false;
			}
			$this->write($file, $record);
			return true;
		});
	}

	public function load(string $definition, string $id): ?WorkflowRecord {
		return $this->locked($definition, $id, LOCK_SH, fn(string $file): ?WorkflowRecord=>$this->read($file));
	}

	public function compareAndSwap(WorkflowRecord $record, int $expectedVersion): bool {
		return $this->locked($record->definition(), $record->id(), LOCK_EX, function(string $file) use($record, $expectedVersion): bool {
			$current=$this->read($file);
			if(!$current instanceof WorkflowRecord || $current->version()!==$expectedVersion){
				return false;
			}
			$this->write($file, $record);
			return true;
		});
	}

	public function all(?string $definition=null): array {
		$definition=$definition===null ? null : WorkflowState::normalize($definition);
		$result=[];
		foreach(glob($this->directory.DIRECTORY_SEPARATOR.'*.workflow.json') ?: [] as $file){
			$lockFile=substr($file, 0, -strlen('.workflow.json')).'.lock';
			$handle=@fopen($lockFile, 'c+b');
			if($handle===false){
				continue;
			}
			try{
				if(!flock($handle, LOCK_SH)){
					continue;
				}
				$record=$this->read($file);
				if($record instanceof WorkflowRecord && ($definition===null || $record->definition()===$definition)){
					$result[]=$record;
				}
			}finally{
				flock($handle, LOCK_UN);
				fclose($handle);
			}
		}
		usort($result, static fn(WorkflowRecord $left, WorkflowRecord $right): int=>strcmp($left->id(), $right->id()));
		return $result;
	}

	public function jsonSerialize(): array {
		return ['type'=>'filesystem_workflow_store', 'directory'=>$this->directory, 'format_version'=>1];
	}

	/** @template T @param callable(string):T $callback @return T */
	private function locked(string $definition, string $id, int $mode, callable $callback): mixed {
		[$file, $lockFile]=$this->paths($definition, $id);
		$handle=@fopen($lockFile, 'c+b');
		if($handle===false){
			throw new \RuntimeException('Unable to open workflow record lock.');
		}
		try{
			if(!flock($handle, $mode)){
				throw new \RuntimeException('Unable to acquire workflow record lock.');
			}
			return $callback($file); }finally{
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/** @return array{string,string} */
	private function paths(string $definition, string $id): array {
		$key=hash('sha256', WorkflowState::normalize($definition)."\0".trim($id));
		$base=$this->directory.DIRECTORY_SEPARATOR.$key;
		return [$base.'.workflow.json', $base.'.lock'];
	}

	private function read(string $file): ?WorkflowRecord {
		if(!is_file($file)){
			return null;
		}
		$contents=@file_get_contents($file);
		if(!is_string($contents) || $contents===''){
			throw new \RuntimeException('Workflow record is unreadable or empty.');
		}
		try{
			$envelope=json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
		}catch(\JsonException $exception){
			throw new \RuntimeException('Workflow record contains invalid JSON.', 0, $exception);
		}
		if(!is_array($envelope) || ($envelope['format'] ?? null)!=='dataphyre.panel.workflow.v1' || !is_array($envelope['record'] ?? null)){
			throw new \RuntimeException('Workflow record has an unsupported envelope.');
		}
		$checksum=hash('sha256', WorkflowEvent::canonicalJson($envelope['record']));
		if(!is_string($envelope['checksum'] ?? null) || !hash_equals($envelope['checksum'], $checksum)){
			throw new \RuntimeException('Workflow record checksum verification failed.');
		}
		$record=WorkflowRecord::fromArray($envelope['record']);
		if(!$record->historyValid()){
			throw new \RuntimeException('Workflow audit history verification failed.');
		}
		return $record;
	}

	private function write(string $file, WorkflowRecord $record): void {
		$payload=$record->jsonSerialize();
		$envelope=[
			'format'=>'dataphyre.panel.workflow.v1',
			'checksum'=>hash('sha256', WorkflowEvent::canonicalJson($payload)),
			'record'=>$payload,
		];
		$json=json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
		$temp=$file.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(6));
		$handle=@fopen($temp, 'xb');
		if($handle===false){
			throw new \RuntimeException('Unable to create temporary workflow record.');
		}
		try{
			try{
				$written=fwrite($handle, $json);
				if($written!==strlen($json) || !fflush($handle)){
					throw new \RuntimeException('Unable to flush temporary workflow record.');
				}
				if(function_exists('fsync')){
					@fsync($handle);
				}
			}finally{
				fclose($handle);
			}
		}catch(\Throwable $exception){
			@unlink($temp);
			throw $exception;
		}
		if(!@rename($temp, $file)){
			@unlink($temp);
			throw new \RuntimeException('Unable to atomically replace workflow record.');
		}
	}
}
