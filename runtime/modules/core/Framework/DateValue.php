<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Immutable date/time value with Dataphyre timezone and serialization helpers.
 *
 * DateValue wraps DateTimeImmutable so date transformations return new objects instead of mutating the current instance.
 * It centralizes common runtime projections: Unix timestamps, ISO-8601 strings, SQL date/time strings, display translation,
 * server timezone conversion, user timezone conversion, and documentation-friendly array output.
 *
 * Parsing and timezone normalization are delegated to PHP DateTimeImmutable and the Dataphyre Date helper. Invalid date
 * strings surface through DateTimeImmutable; invalid timezone names are normalized before construction.
 */
final class DateValue implements \JsonSerializable {

	/** @var array{timezone:string, timestamp:int, iso8601:string, sql:string, sql_microseconds:string, date:string, time:string, time_microseconds:string}|null */
	private ?array $arrayPayload=null;

	/**
	 * Stores an immutable date/time object.
	 *
	 * @param \DateTimeImmutable $datetime Date/time value retained by this wrapper.
	 */
	public function __construct(
		private readonly \DateTimeImmutable $datetime
	){}

	/**
	 * Creates a DateValue from any PHP DateTimeInterface.
	 *
	 * Mutable DateTime instances are converted to immutable values so later changes to the original object cannot alter this
	 * wrapper's state.
	 *
	 * @param \DateTimeInterface $datetime Source date/time.
	 * @return self Immutable wrapper for the supplied instant and timezone.
	 */
	public static function fromDateTime(\DateTimeInterface $datetime): self {
		if($datetime instanceof \DateTimeImmutable){
			return new self($datetime);
		}
		return new self(\DateTimeImmutable::createFromMutable($datetime));
	}

	/**
	 * Parses a timestamp or date string into a DateValue.
	 *
	 * Integer and numeric-string input is treated as a Unix timestamp, created in UTC with the @timestamp form, then shifted
	 * into the requested/default timezone. Non-numeric strings are parsed by DateTimeImmutable with the normalized timezone.
	 *
	 * @param string|int $date Unix timestamp, numeric timestamp string, or parseable date string.
	 * @param ?string $timezone Optional timezone passed through Date::normalizeTimezone().
	 * @return self Parsed immutable date value.
	 */
	public static function fromValue(string|int $date, ?string $timezone=null): self {
		$timezoneObject=new \DateTimeZone(Date::normalizeTimezone($timezone));
		if(is_int($date) || (is_string($date) && is_numeric($date))){
			$datetime=(new \DateTimeImmutable('@'.(int)$date))->setTimezone($timezoneObject);
			return new self($datetime);
		}
		return new self(new \DateTimeImmutable((string)$date, $timezoneObject));
	}

	/**
	 * Returns the wrapped immutable date/time object.
	 *
	 * @return \DateTimeImmutable Stored date/time value.
	 */
	public function datetime(): \DateTimeImmutable {
		return $this->datetime;
	}

	/**
	 * Returns the timezone name attached to the wrapped value.
	 *
	 * @return string PHP timezone identifier.
	 */
	public function timezone(): string {
		return $this->datetime->getTimezone()->getName();
	}

	/**
	 * Returns the Unix timestamp for the wrapped instant.
	 *
	 * @return int Seconds since the Unix epoch.
	 */
	public function timestamp(): int {
		return $this->datetime->getTimestamp();
	}

	/**
	 * Formats the wrapped value with PHP date format tokens.
	 *
	 * @param string $format DateTimeInterface format string.
	 * @return string Formatted date/time string.
	 */
	public function format(string $format='Y-m-d H:i:s'): string {
		return $this->datetime->format($format);
	}

	/**
	 * Formats the value for display and optionally runs Dataphyre date translation.
	 *
	 * Translation is used only when requested and dataphyre\date_translation is available. The display language is read from
	 * dataphyre\core::$displayLanguage.
	 *
	 * @param string $format Date/time display format.
	 * @param bool $translation Whether to apply Dataphyre date translation.
	 * @return string Display date/time string.
	 */
	public function translated(string $format='n/j/Y g:i A', bool $translation=true): string {
		$result=$this->format($format);
		if(
			$translation===true
			&& class_exists('dataphyre\\date_translation')
		){
			return \dataphyre\date_translation::translate_date(
				$result,
				\dataphyre\core::$displayLanguage,
				$format
			);
		}
		return $result;
	}

	/**
	 * Returns the same instant represented in another timezone.
	 *
	 * @param string $timezone Timezone passed through Date::normalizeTimezone().
	 * @return self New value shifted to the requested timezone.
	 */
	public function inTimezone(string $timezone): self {
		return new self($this->datetime->setTimezone(new \DateTimeZone(Date::normalizeTimezone($timezone))));
	}

	/**
	 * Returns the value in a user-supplied timezone.
	 *
	 * @param string $userTimezone User timezone passed through Date::normalizeUserTimezone().
	 * @return self New value shifted to the normalized user timezone.
	 */
	public function toUser(string $userTimezone): self {
		return $this->inTimezone(Date::normalizeUserTimezone($userTimezone));
	}

	/**
	 * Returns the value in the configured server timezone.
	 *
	 * @return self New value shifted to Date::serverTimezone().
	 */
	public function toServer(): self {
		return $this->inTimezone(Date::serverTimezone());
	}

	/**
	 * Formats the value as an ISO-8601/ATOM timestamp.
	 *
	 * @return string ISO-8601 timestamp including timezone offset.
	 */
	public function iso8601(): string {
		return $this->datetime->format(\DateTimeInterface::ATOM);
	}

	/**
	 * Formats the value for SQL datetime columns.
	 *
	 * @param bool $microseconds Include fractional seconds when true.
	 * @return string SQL datetime string in the value's current timezone.
	 */
	public function sql(bool $microseconds=false): string {
		return $this->datetime->format($microseconds ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s');
	}

	/**
	 * Formats the calendar date portion.
	 *
	 * @return string Date in Y-m-d format.
	 */
	public function date(): string {
		return $this->datetime->format('Y-m-d');
	}

	/**
	 * Formats the time portion.
	 *
	 * @param bool $microseconds Include fractional seconds when true.
	 * @return string Time in H:i:s or H:i:s.u format.
	 */
	public function time(bool $microseconds=false): string {
		return $this->datetime->format($microseconds ? 'H:i:s.u' : 'H:i:s');
	}

	/**
	 * Serializes the value into common date/time projections.
	 *
	 * @return array{timezone:string, timestamp:int, iso8601:string, sql:string, sql_microseconds:string, date:string, time:string, time_microseconds:string} Date/time diagnostic data.
	 */
	public function toArray(): array {
		if($this->arrayPayload!==null){
			return $this->arrayPayload;
		}
		return $this->arrayPayload=[
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

	/**
	 * Serializes the value for json_encode().
	 *
	 * @return array<string, int|string> Date/time diagnostic data.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
