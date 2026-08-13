<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** A started child process that can run concurrently with sibling probes. */
final class RunningProcess {

	private ?ProcessResult $result=null;

	/** @param list<string> $command */
	public function __construct(
		private mixed $process,
		private array $command,
		private string $stdout_path,
		private string $stderr_path,
		private int $started,
		private int $default_timeout_millis
	) {}

	public function wait(?int $timeout_millis=null): ProcessResult {
		if($this->result!==null){
			return $this->result;
		}
		$timeout_millis=$timeout_millis ?? $this->default_timeout_millis;
		if($timeout_millis<1 || $timeout_millis>300000){
			throw new \InvalidArgumentException('Test subprocess timeout must be between 1 and 300000 milliseconds.');
		}
		$deadline=hrtime(true)+($timeout_millis*1000000);
		$timed_out=false;
		$observed_exit=-1;
		do{
			$status=proc_get_status($this->process);
			if(($status['running'] ?? false)!==true){
				$observed_exit=(int)($status['exitcode'] ?? -1);
				break;
			}
			if(hrtime(true)>=$deadline){
				$timed_out=true;
				proc_terminate($this->process);
				break;
			}
			usleep(10000);
		}while(true);
		$closed_exit=proc_close($this->process);
		$exit_code=$timed_out ? 124 : ($observed_exit>=0 ? $observed_exit : $closed_exit);
		$this->result=new ProcessResult(
			$this->command,
			$exit_code,
			self::contents($this->stdout_path),
			self::contents($this->stderr_path),
			$timed_out,
			(hrtime(true)-$this->started)/1000000000
		);
		return $this->result;
	}

	public function terminate(): void {
		if($this->result===null){
			$this->wait(1);
		}
	}

	private static function contents(string $path): string {
		$contents=is_file($path) ? file_get_contents($path) : '';
		return is_string($contents) ? $contents : '';
	}
}
