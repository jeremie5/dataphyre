<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Cross-platform subprocess probe using managed files instead of blocking pipes. */
final class ProcessProbe {

	/**
	 * @param list<string> $command
	 * @param array<string,string|int|float|bool|null> $environment
	 */
	public static function run(
		TempWorkspace $workspace,
		array $command,
		string $stdin='',
		?string $working_directory=null,
		array $environment=[],
		int $timeout_millis=10000
	): ProcessResult {
		return self::start($workspace, $command, $stdin, $working_directory, $environment, $timeout_millis)->wait();
	}

	/**
	 * @param list<string> $command
	 * @param array<string,string|int|float|bool|null> $environment
	 */
	public static function start(
		TempWorkspace $workspace,
		array $command,
		string $stdin='',
		?string $working_directory=null,
		array $environment=[],
		int $timeout_millis=10000
	): RunningProcess {
		self::validate($command, $stdin, $working_directory, $timeout_millis);
		$stdin_path=$workspace->path('stdin.log');
		$stdout_path=$workspace->path('stdout.log');
		$stderr_path=$workspace->path('stderr.log');
		$written=file_put_contents($stdin_path,$stdin);
		if($written===false || $written!==strlen($stdin)){
			throw new \RuntimeException('Unable to prepare managed test subprocess input.');
		}
		$descriptors=[
			0=>['file', $stdin_path, 'rb'],
			1=>['file', $stdout_path, 'wb'],
			2=>['file', $stderr_path, 'wb'],
		];
		$pipes=[];
		$started=hrtime(true);
		$process=@proc_open(
			$command,
			$descriptors,
			$pipes,
			$working_directory,
			self::environment($environment),
			['bypass_shell'=>true, 'suppress_errors'=>true]
		);
		if(!is_resource($process)){
			throw new \RuntimeException('Unable to start test subprocess: '.$command[0]);
		}
		return new RunningProcess(
			$process,
			$command,
			$stdout_path,
			$stderr_path,
			$started,
			$timeout_millis
		);
	}

	/** @param list<string> $command */
	private static function validate(array $command, string $stdin, ?string $working_directory, int $timeout_millis): void {
		if($command===[] || !array_is_list($command)){
			throw new \InvalidArgumentException('Test subprocess command must be a non-empty list.');
		}
		foreach($command as $argument){
			if(!is_string($argument) || $argument==='' || str_contains($argument, "\0")){
				throw new \InvalidArgumentException('Test subprocess command arguments must be non-empty strings without null bytes.');
			}
		}
		if(strlen($stdin)>4194304){
			throw new \LengthException('Test subprocess stdin may not exceed 4 MiB.');
		}
		if($working_directory!==null && !is_dir($working_directory)){
			throw new \InvalidArgumentException('Test subprocess working directory does not exist: '.$working_directory);
		}
		if($timeout_millis<1 || $timeout_millis>300000){
			throw new \InvalidArgumentException('Test subprocess timeout must be between 1 and 300000 milliseconds.');
		}
	}

	/** @param array<string,string|int|float|bool|null> $overrides @return array<string,string> */
	private static function environment(array $overrides): array {
		$environment=getenv();
		$environment=is_array($environment) ? array_map('strval', $environment) : [];
		foreach($overrides as $name=>$value){
			$name=trim((string)$name);
			if($name==='' || str_contains($name, '=') || str_contains($name, "\0")){
				throw new \InvalidArgumentException('Test subprocess environment variable name is invalid.');
			}
			if($value===null){
				unset($environment[$name]);
				continue;
			}
			$environment[$name]=is_bool($value) ? ($value ? '1' : '0') : (string)$value;
		}
		return $environment;
	}

}
