<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Scheduling;

/**
 * Immutable scheduler period expressed in seconds.
 *
 * Period accepts numeric second values, DateInterval instances, and compact
 * strings such as `5 minutes`, `2h`, `daily`, or `weekly`.
 */
final class Period {

	private float $seconds;

	public function __construct(float|int|string|\DateInterval $period){
		$this->seconds=self::normalize($period);
	}

	/** Creates a period from any supported input. */
	public static function make(float|int|string|\DateInterval|self $period): self {
		return $period instanceof self ? $period : new self($period);
	}

	/** Creates a period from seconds. */
	public static function seconds(float|int $seconds): self {
		return new self($seconds);
	}

	/** Creates a period from minutes. */
	public static function minutes(float|int $minutes): self {
		return new self((float)$minutes * 60);
	}

	/** Creates a period from hours. */
	public static function hours(float|int $hours): self {
		return new self((float)$hours * 3600);
	}

	/** Creates a period from days. */
	public static function days(float|int $days): self {
		return new self((float)$days * 86400);
	}

	/** Creates a period from weeks. */
	public static function weeks(float|int $weeks): self {
		return new self((float)$weeks * 604800);
	}

	/** Returns the period as scheduler-compatible seconds. */
	public function secondsValue(): float {
		return $this->seconds;
	}

	/** Returns the period as an integer second ceiling for timeout APIs. */
	public function ceilSeconds(): int {
		return (int)ceil($this->seconds);
	}

	/** Returns a stable array representation for manifests and tests. */
	public function toArray(): array {
		return [
			'seconds'=>$this->seconds,
		];
	}

	private static function normalize(float|int|string|\DateInterval $period): float {
		if(is_int($period) || is_float($period)){
			return max(0.0, (float)$period);
		}
		if($period instanceof \DateInterval){
			$reference=new \DateTimeImmutable('@0');
			return max(0.0, (float)($reference->add($period)->getTimestamp() - $reference->getTimestamp()));
		}
		$value=strtolower(trim($period));
		if($value===''){
			return 0.0;
		}
		$aliases=[
			'secondly'=>1,
			'minutely'=>60,
			'hourly'=>3600,
			'daily'=>86400,
			'weekly'=>604800,
			'monthly'=>2592000,
		];
		if(isset($aliases[$value])){
			return (float)$aliases[$value];
		}
		if(is_numeric($value)){
			return max(0.0, (float)$value);
		}
		if(preg_match('/^(\d+(?:\.\d+)?)\s*([a-z]+)$/', $value, $matches)!==1){
			return 0.0;
		}
		$amount=(float)$matches[1];
		$unit=$matches[2];
		$multipliers=[
			's'=>1,
			'sec'=>1,
			'secs'=>1,
			'second'=>1,
			'seconds'=>1,
			'm'=>60,
			'min'=>60,
			'mins'=>60,
			'minute'=>60,
			'minutes'=>60,
			'h'=>3600,
			'hr'=>3600,
			'hrs'=>3600,
			'hour'=>3600,
			'hours'=>3600,
			'd'=>86400,
			'day'=>86400,
			'days'=>86400,
			'w'=>604800,
			'week'=>604800,
			'weeks'=>604800,
		];
		return isset($multipliers[$unit]) ? max(0.0, $amount * $multipliers[$unit]) : 0.0;
	}
}
