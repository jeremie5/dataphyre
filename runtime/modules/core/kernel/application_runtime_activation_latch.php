<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Durable, framework-owned activation bit for the fixed container runtime. */
final class DataphyreApplicationRuntimeActivationLatch
{
	private const ROOT='/var/lib/dataphyre';
	private const DIRECTORY=self::ROOT.'/runtime-control';
	private const FILE=self::DIRECTORY.'/activation';

	/** Restores the exact durable state; a new container starts inactive. */
	public static function restore(): bool
	{
		self::prepareDirectory();
		clearstatcache(true,self::FILE);
		if(!file_exists(self::FILE) && !is_link(self::FILE)) return false;
		$contents=self::readExactFile(self::FILE);
		return match($contents){
			"active\n"=>true,
			"inactive\n"=>false,
			default=>throw new RuntimeException('Runtime activation latch has invalid contents.'),
		};
	}

	/** Atomically commits one exact state before the supervisor exposes it. */
	public static function persist(bool $active): void
	{
		$directoryStat=self::prepareDirectory();
		self::validateExistingTarget();
		$temporary=self::DIRECTORY.'/.activation.'.bin2hex(random_bytes(16)).'.tmp';
		$handle=null;
		try{
			$handle=@fopen($temporary,'x+b');
			if(!is_resource($handle) || !function_exists('chmod') || !@chmod($temporary,0600)){
				throw new RuntimeException('Runtime activation temporary file could not be created.');
			}
			$bytes=$active ? "active\n" : "inactive\n";
			$offset=0;
			while($offset<strlen($bytes)){
				$written=function_exists('fwrite') ? @fwrite($handle,substr($bytes,$offset)) : false;
				if(!is_int($written) || $written<1){
					throw new RuntimeException('Runtime activation latch write failed.');
				}
				$offset+=$written;
			}
			if(!@fflush($handle) || !function_exists('fsync') || !@fsync($handle)){
				throw new RuntimeException('Runtime activation latch could not be synchronized.');
			}
			$temporaryStat=function_exists('fstat') ? @fstat($handle) : false;
			$temporaryPathStat=@lstat($temporary);
			if(!self::validFileStat($temporaryStat) || !self::validFileStat($temporaryPathStat)
				|| ($temporaryStat['dev'] ?? null)!==($temporaryPathStat['dev'] ?? null)
				|| ($temporaryStat['ino'] ?? null)!==($temporaryPathStat['ino'] ?? null)
				|| ($temporaryStat['dev'] ?? null)!==($directoryStat['dev'] ?? null)){
				throw new RuntimeException('Runtime activation temporary file identity is invalid.');
			}
			@fclose($handle);
			$handle=null;
			if(!function_exists('rename') || !@rename($temporary,self::FILE)){
				throw new RuntimeException('Runtime activation latch replacement failed.');
			}
			self::syncDirectory(self::DIRECTORY);
		}catch(Throwable $failure){
			self::removeOwnedTemporary($temporary,$handle);
			if(is_resource($handle)) @fclose($handle);
			throw $failure;
		}
	}

	/** @return array<string,int> */
	private static function prepareDirectory(): array
	{
		self::validateDirectory('/var',false);
		self::validateDirectory('/var/lib',false);
		self::createDirectory(self::ROOT,0755,'/var/lib');
		self::validateDirectory(self::ROOT,false);
		self::createDirectory(self::DIRECTORY,0700,self::ROOT);
		return self::validateDirectory(self::DIRECTORY,true);
	}

	private static function createDirectory(string $path,int $mode,string $parent): void
	{
		clearstatcache(true,$path);
		if(!file_exists($path) && !is_link($path)){
			if(!function_exists('mkdir') || !@mkdir($path,$mode)
				|| !function_exists('chmod') || !@chmod($path,$mode)){
				throw new RuntimeException('Runtime activation directory could not be created.');
			}
			self::syncDirectory($parent);
		}
	}

	/** @return array<string,int> */
	private static function validateDirectory(string $path,bool $private): array
	{
		clearstatcache(true,$path);
		$stat=@lstat($path);
		$resolved=@realpath($path);
		$permissions=is_array($stat) ? (($stat['mode'] ?? 0)&0777) : -1;
		if(is_link($path) || !is_array($stat) || (($stat['mode'] ?? 0)&0170000)!==0040000
			|| ($stat['uid'] ?? -1)!==0 || ($stat['gid'] ?? -1)!==0
			|| ($permissions&0022)!==0 || ($private && $permissions!==0700)
			|| !is_string($resolved) || $resolved!==$path){
			throw new RuntimeException('Runtime activation directory identity is invalid.');
		}
		return $stat;
	}

	private static function validateExistingTarget(): void
	{
		clearstatcache(true,self::FILE);
		if(!file_exists(self::FILE) && !is_link(self::FILE)) return;
		$stat=@lstat(self::FILE);
		if(is_link(self::FILE) || !self::validFileStat($stat)){
			throw new RuntimeException('Runtime activation latch identity is invalid.');
		}
	}

	private static function readExactFile(string $path): string
	{
		if(is_link($path)) throw new RuntimeException('Runtime activation latch cannot be a symbolic link.');
		$handle=@fopen($path,'rb');
		if(!is_resource($handle)) throw new RuntimeException('Runtime activation latch could not be opened.');
		try{
			$handleStat=@fstat($handle);
			$pathStat=@lstat($path);
			$contents=stream_get_contents($handle,16);
			$extra=fread($handle,1);
			if(!self::validFileStat($handleStat) || !self::validFileStat($pathStat)
				|| ($handleStat['dev'] ?? null)!==($pathStat['dev'] ?? null)
				|| ($handleStat['ino'] ?? null)!==($pathStat['ino'] ?? null)
				|| !is_string($contents) || $extra!==''
				|| !in_array($contents,["active\n","inactive\n"],true)){
				throw new RuntimeException('Runtime activation latch is not an exact owned file.');
			}
			return $contents;
		}finally{
			@fclose($handle);
		}
	}

	private static function validFileStat(mixed $stat): bool
	{
		return is_array($stat)
			&& (($stat['mode'] ?? 0)&0170000)===0100000
			&& (($stat['mode'] ?? 0)&0777)===0600
			&& ($stat['uid'] ?? -1)===0
			&& ($stat['gid'] ?? -1)===0
			&& ($stat['nlink'] ?? 0)===1;
	}

	private static function syncDirectory(string $path): void
	{
		if(!function_exists('fsync')) throw new RuntimeException('Runtime activation directory sync is unavailable.');
		$handle=@fopen($path,'rb');
		if(!is_resource($handle)) throw new RuntimeException('Runtime activation directory could not be opened.');
		try{
			if(!@fsync($handle)) throw new RuntimeException('Runtime activation directory sync failed.');
		}finally{
			@fclose($handle);
		}
	}

	/** Removes only the exclusive temporary inode created by this persist attempt. */
	private static function removeOwnedTemporary(string $path,mixed $handle=null): void
	{
		if(is_resource($handle) && function_exists('fstat')){
			$handleStat=@fstat($handle);$pathStat=@lstat($path);
			if(self::validOwnedTemporaryFileStat($handleStat)
				&& self::validOwnedTemporaryFileStat($pathStat)
				&& ($handleStat['dev'] ?? null)===($pathStat['dev'] ?? null)
				&& ($handleStat['ino'] ?? null)===($pathStat['ino'] ?? null)){
				if(function_exists('unlink')) @unlink($path);
				return;
			}
		}
		$stat=@lstat($path);
		if(self::validFileStat($stat) && function_exists('unlink')) @unlink($path);
	}

	private static function validOwnedTemporaryFileStat(mixed $stat): bool
	{
		return is_array($stat)
			&& (($stat['mode'] ?? 0)&0170000)===0100000
			&& ($stat['uid'] ?? -1)===0
			&& ($stat['gid'] ?? -1)===0
			&& ($stat['nlink'] ?? 0)===1;
	}
}
