<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class FakeClock {

	private int $timestamp;

	public function __construct(int|string|\DateTimeInterface $now='now') {
		$this->timestamp=$this->normalize($now);
	}

	public function now(): \DateTimeImmutable {
		return (new \DateTimeImmutable('@'.$this->timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
	}

	public function timestamp(): int {
		return $this->timestamp;
	}

	public function freeze(int|string|\DateTimeInterface $now): self {
		$this->timestamp=$this->normalize($now);
		return $this;
	}

	public function travelTo(int|string|\DateTimeInterface $now): self {
		return $this->freeze($now);
	}

	public function advance(int $seconds): self {
		$this->timestamp+=$seconds;
		return $this;
	}

	public function travel(int $seconds): self {
		return $this->advance($seconds);
	}

	public function rewind(int $seconds): self {
		$this->timestamp-=$seconds;
		return $this;
	}

	private function normalize(int|string|\DateTimeInterface $value): int {
		if(is_int($value)){
			return $value;
		}
		if($value instanceof \DateTimeInterface){
			return $value->getTimestamp();
		}
		$timestamp=strtotime($value);
		if($timestamp===false){
			throw new \InvalidArgumentException('FakeClock could not parse timestamp value.');
		}
		return $timestamp;
	}
}
