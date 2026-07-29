<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use JsonSerializable;

/** Describes what a developer is observing and which actor classes must remain human-controlled. */
final class SimulationPerspective implements JsonSerializable {
	private string $surface;
	/** @var array<int,string> */
	private array $interests;
	/** @var array<int,string> */
	private array $blockedOrigins;

	/** @param array<int,string> $interests @param array<int,string> $blockedOrigins */
	public function __construct(string $surface, array $interests=[], array $blockedOrigins=[]) {
		$this->surface=self::name($surface, 'unknown');
		$this->interests=self::names($interests);
		$this->blockedOrigins=self::names(array_merge([$this->surface], $blockedOrigins));
	}

	public static function forSurface(string $surface, array $interests=[], array $blockedOrigins=[]): self {
		return new self($surface, $interests, $blockedOrigins);
	}

	public function surface(): string {
		return $this->surface;
	}

	/** @return array<int,string> */
	public function interests(): array {
		return $this->interests;
	}

	/** @return array<int,string> */
	public function blockedOrigins(): array {
		return $this->blockedOrigins;
	}

	/** @param array<int,string> $affects */
	public function allows(string $origin, array $affects): bool {
		$origin=self::name($origin, 'unknown');
		if(in_array($origin, $this->blockedOrigins, true)) return false;
		$affects=self::names($affects);
		return $this->interests===[] || array_intersect($this->interests, $affects)!==[];
	}

	public function jsonSerialize(): array {
		return ['surface'=>$this->surface, 'interests'=>$this->interests, 'blocked_origins'=>$this->blockedOrigins];
	}

	/** @param array<int,mixed> $values @return array<int,string> */
	private static function names(array $values): array {
		$normalized=[];
		foreach($values as $value){
			$name=self::name((string)$value, '');
			if($name!=='') $normalized[]=$name;
		}
		return array_values(array_unique($normalized));
	}

	private static function name(string $value, string $fallback): string {
		$value=strtolower(trim($value));
		$value=preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?? '';
		$value=trim($value, '_.-');
		return $value!=='' ? substr($value, 0, 96) : $fallback;
	}
}
