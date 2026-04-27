<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class DateValue implements \JsonSerializable {

	public function __construct(
		private readonly \DateTimeImmutable $datetime
	){}

	public static function fromDateTime(\DateTimeInterface $datetime): self {
		if($datetime instanceof \DateTimeImmutable){
			return new self($datetime);
		}
		return new self(\DateTimeImmutable::createFromMutable($datetime));
	}

	public static function fromValue(string|int $date, ?string $timezone=null): self {
		$timezone_object=new \DateTimeZone(Date::normalizeTimezone($timezone));
		if(is_int($date) || (is_string($date) && is_numeric($date))){
			$datetime=(new \DateTimeImmutable('@'.(int)$date))->setTimezone($timezone_object);
			return new self($datetime);
		}
		return new self(new \DateTimeImmutable((string)$date, $timezone_object));
	}

	public function datetime(): \DateTimeImmutable {
		return $this->datetime;
	}

	public function timezone(): string {
		return $this->datetime->getTimezone()->getName();
	}

	public function timestamp(): int {
		return $this->datetime->getTimestamp();
	}

	public function format(string $format='Y-m-d H:i:s'): string {
		return $this->datetime->format($format);
	}

	public function translated(string $format='n/j/Y g:i A', bool $translation=true): string {
		$result=$this->format($format);
		if(
			$translation===true
			&& class_exists('dataphyre\\date_translation')
		){
			return \dataphyre\date_translation::translate_date(
				$result,
				\dataphyre\core::$display_language,
				$format
			);
		}
		return $result;
	}

	public function inTimezone(string $timezone): self {
		return new self($this->datetime->setTimezone(new \DateTimeZone(Date::normalizeTimezone($timezone))));
	}

	public function toUser(string $user_timezone): self {
		return $this->inTimezone(Date::normalizeUserTimezone($user_timezone));
	}

	public function toServer(): self {
		return $this->inTimezone(Date::serverTimezone());
	}

	public function iso8601(): string {
		return $this->datetime->format(\DateTimeInterface::ATOM);
	}

	public function sql(bool $microseconds=false): string {
		return $this->datetime->format($microseconds ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s');
	}

	public function date(): string {
		return $this->datetime->format('Y-m-d');
	}

	public function time(bool $microseconds=false): string {
		return $this->datetime->format($microseconds ? 'H:i:s.u' : 'H:i:s');
	}

	public function toArray(): array {
		return [
			'timezone'=>$this->timezone(),
			'timestamp'=>$this->timestamp(),
			'iso8601'=>$this->iso8601(),
			'sql'=>$this->sql(),
			'sql_microseconds'=>$this->sql(true),
			'date'=>$this->date(),
			'time'=>$this->time(),
			'time_microseconds'=>$this->time(true),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
