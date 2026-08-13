<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre {
	if(!class_exists(Runtime::class,false)){
		final class Runtime {
			public static bool $tracing=true;
			public static function tracingEnabled(): bool {
				return self::$tracing;
			}
		}
	}
}

namespace Dataphyre\Database {
	if(!class_exists(RepositoryQuery::class,false)){
		class RepositoryQuery {}
	}
	if(!class_exists(DB::class,false)){
		final class DB {
			public static function withTraceContext(array $context,callable $callback): mixed {
				\Dataphyre\Test\TestState::channel('templating.manager')->append('database_trace_contexts',$context);
				return $callback();
			}
		}
	}
}

namespace Dataphyre\FulltextEngine {
	if(!class_exists(Query::class,false)){
		class Query {}
	}
}

namespace dataphyre\async {
	if(!class_exists(promise::class,false)){
		class promise {
			public mixed $value=null;
			public mixed $reason=null;
			public function __construct(callable $executor) {
				$executor(
					function(mixed $value): void { $this->value=$value; },
					function(mixed $reason): void { $this->reason=$reason; },
				);
			}
		}
	}
}

namespace Dataphyre\Templating {
	if(!function_exists(__NAMESPACE__.'\\random_bytes')){
		function random_bytes(int $length): string {
			if(\Dataphyre\Test\TestState::channel('templating.manager')->get('random_failure',false)===true){
				throw new \RuntimeException('random failure');
			}
			return \random_bytes($length);
		}
	}

	if(!function_exists(__NAMESPACE__.'\\json_encode')){
		function json_encode(mixed $value,int $flags=0,int $depth=512): string|false {
			$state=\Dataphyre\Test\TestState::channel('templating.manager');
			$failures=(int)$state->get('json_failures',0);
			if($failures>0){
				$state->put('json_failures',$failures-1);
				throw new \RuntimeException('json failure');
			}
			return \json_encode($value,$flags,$depth);
		}
	}

	if(!function_exists(__NAMESPACE__.'\\glob')){
		function glob(string $pattern,int $flags=0): array|false {
			if(\Dataphyre\Test\TestState::channel('templating.manager')->get('glob_failure',false)===true){
				return false;
			}
			return \glob($pattern,$flags);
		}
	}
}
