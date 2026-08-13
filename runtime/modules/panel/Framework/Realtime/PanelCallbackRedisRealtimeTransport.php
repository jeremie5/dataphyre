<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Host-owned Redis client bridge without a package dependency. */
final class PanelCallbackRedisRealtimeTransport implements PanelRedisRealtimeTransport {
	private readonly \Closure $evaluator;

	/** @param callable(string,list<string>,list<string>):mixed $evaluator */
	public function __construct(callable $evaluator){$this->evaluator=\Closure::fromCallable($evaluator);}

	public function evaluate(string $script, array $keys, array $arguments=[]): mixed {
		self::input($script,$keys,$arguments);
		return ($this->evaluator)($script,array_values($keys),array_values($arguments));
	}

	public function jsonSerialize(): array {
		return ['type'=>'panel_callback_redis_realtime_transport','version'=>1,'client'=>'host_callback','binary_safe_required'=>true,'fixed_scripts_only'=>true,'callback_serialized'=>false,'connection_serialized'=>false,'credentials_serialized'=>false];
	}

	/** @param list<string> $keys @param list<string> $arguments */
	public static function input(string $script, array $keys, array $arguments): void {
		if($script==='' || strlen($script)>65536){throw new \InvalidArgumentException('Panel Redis realtime script is outside its byte bound.');}
		if($keys===[] || count($keys)>16 || !array_is_list($keys) || !array_is_list($arguments) || count($arguments)>64){throw new \InvalidArgumentException('Panel Redis realtime transport arguments are invalid.');}
		foreach([...$keys,...$arguments] as $value){if(!is_string($value)){throw new \InvalidArgumentException('Panel Redis realtime transport values must be strings.');}}
		foreach($keys as $key){if($key==='' || strlen($key)>512){throw new \InvalidArgumentException('Panel Redis realtime key is outside its byte bound.');}}
	}
}
