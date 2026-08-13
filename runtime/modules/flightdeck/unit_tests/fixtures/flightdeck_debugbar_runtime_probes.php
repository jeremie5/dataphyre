<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre;

final class sql {
	private static array $observers=[];

	public static function add_observer(callable $observer): void {
		self::$observers[]=$observer;
	}

	public static function observerCount(): int {
		return count(self::$observers);
	}
}

final class tracelog {
	public static bool $enable=false;
	public static bool $plotting=false;
	public static string $tracelog='';
	private static bool $fail_while_setting_plotting=false;
	private static string $handoff_trace='';

	public static function failWhileSettingPlotting(bool $fail): void {
		self::$fail_while_setting_plotting=$fail;
	}

	public static function handoffTrace(string $trace): void {
		self::$handoff_trace=$trace;
	}

	public static function last_handoff_trace(string $token): string {
		return self::$handoff_trace;
	}

	public static function set_plotting(bool $enabled): void {
		if(self::$fail_while_setting_plotting){
			throw new \RuntimeException('Deterministic plotting failure.');
		}
		self::$plotting=$enabled;
	}
}
