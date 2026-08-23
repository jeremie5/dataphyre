<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Framework-internal canonical scheduler evidence shared by release preflight and PID 1. */
final class DataphyreApplicationSchedulerDefinitionEvidence
{
	public const MAX_DEFINITIONS=256;
	public const MAX_DEPENDENCIES=128;

	/**
	 * Converts one normalized scheduler registration into path-independent evidence.
	 * Dependency order is preserved because it is also the task bootstrap order.
	 *
	 * @param array<string,mixed> $scheduler
	 * @return ?array{name:string,task_sha256:string,dependency_sha256:list<string>,frequency_milliseconds:int,timeout_milliseconds:int,memory_limit:string}
	 */
	public static function definition(array $scheduler): ?array
	{
		$name=$scheduler['name'] ?? null;
		$task=$scheduler['file_path'] ?? null;
		$dependencies=$scheduler['dependencies'] ?? null;
		$frequency=$scheduler['frequency'] ?? null;
		$timeout=$scheduler['timeout'] ?? null;
		$memoryLimit=$scheduler['memory_limit'] ?? null;
		if(!is_string($name) || in_array($name,['.','..'],true)
			|| preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$name)!==1
			|| !is_string($task) || $task==='' || is_link($task) || !is_file($task)
			|| !is_array($dependencies) || !array_is_list($dependencies)
			|| count($dependencies)>self::MAX_DEPENDENCIES
			|| (!is_int($frequency) && !is_float($frequency)) || !is_finite((float)$frequency)
			|| (!is_int($timeout) && !is_float($timeout)) || !is_finite((float)$timeout)
			|| !is_string($memoryLimit) || trim($memoryLimit)==='' || strlen($memoryLimit)>64
			|| preg_match('/[\x00-\x1F\x7F]/D',$memoryLimit)===1){
			return null;
		}
		$taskHash=hash_file('sha256',$task);
		if(!is_string($taskHash) || preg_match('/^[a-f0-9]{64}$/D',$taskHash)!==1) return null;
		$dependencyHashes=[];
		foreach($dependencies as $dependency){
			if(!is_string($dependency) || $dependency==='' || is_link($dependency) || !is_file($dependency)) return null;
			$hash=hash_file('sha256',$dependency);
			if(!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D',$hash)!==1) return null;
			$dependencyHashes[]='sha256:'.$hash;
		}
		return [
			'name'=>$name,
			'task_sha256'=>'sha256:'.$taskHash,
			'dependency_sha256'=>$dependencyHashes,
			'frequency_milliseconds'=>max(0,min(2147483647,(int)ceil(((float)$frequency)*1000))),
			'timeout_milliseconds'=>max(1000,min(300000,(int)ceil(((float)$timeout)*1000))),
			'memory_limit'=>$memoryLimit,
		];
	}

	/**
	 * Sorts and hashes the one canonical definition inventory consumed by preflight and runtime.
	 *
	 * @param list<array<string,mixed>> $definitions
	 * @return ?array{definitions:list<array<string,mixed>>,definition_count:int,definition_sha256:string}
	 */
	public static function inventory(array $definitions): ?array
	{
		if(!array_is_list($definitions) || count($definitions)>self::MAX_DEFINITIONS) return null;
		foreach($definitions as $definition){
			if(!is_array($definition) || !self::validDefinition($definition)) return null;
		}
		usort($definitions,static fn(array $left,array $right): int=>$left['name']<=>$right['name']);
		$previous=null;
		foreach($definitions as $definition){
			if($previous!==null && strcmp($definition['name'],$previous)<=0) return null;
			$previous=$definition['name'];
		}
		$encoded=json_encode($definitions,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		return [
			'definitions'=>$definitions,
			'definition_count'=>count($definitions),
			'definition_sha256'=>'sha256:'.hash('sha256',$encoded),
		];
	}

	/** @param array<string,mixed> $definition */
	private static function validDefinition(array $definition): bool
	{
		if(array_keys($definition)!==[
			'name','task_sha256','dependency_sha256','frequency_milliseconds','timeout_milliseconds','memory_limit',
		] || !is_string($definition['name'] ?? null)
			|| in_array($definition['name'],['.','..'],true)
			|| preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$definition['name'])!==1
			|| !is_string($definition['task_sha256'] ?? null)
			|| preg_match('/^sha256:[a-f0-9]{64}$/D',$definition['task_sha256'])!==1
			|| !is_array($definition['dependency_sha256'] ?? null)
			|| !array_is_list($definition['dependency_sha256'])
			|| count($definition['dependency_sha256'])>self::MAX_DEPENDENCIES
			|| !is_int($definition['frequency_milliseconds'] ?? null)
			|| $definition['frequency_milliseconds']<0 || $definition['frequency_milliseconds']>2147483647
			|| !is_int($definition['timeout_milliseconds'] ?? null)
			|| $definition['timeout_milliseconds']<1000 || $definition['timeout_milliseconds']>300000
			|| !is_string($definition['memory_limit'] ?? null)
			|| trim($definition['memory_limit'])==='' || strlen($definition['memory_limit'])>64
			|| preg_match('/[\x00-\x1F\x7F]/D',$definition['memory_limit'])===1){
			return false;
		}
		foreach($definition['dependency_sha256'] as $sha){
			if(!is_string($sha) || preg_match('/^sha256:[a-f0-9]{64}$/D',$sha)!==1) return false;
		}
		return true;
	}
}
