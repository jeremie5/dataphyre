<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Canonical lifecycle and transition policy for persistent Panel operations. */
final class PanelOperationStatus {

	public const QUEUED='queued';
	public const RUNNING='running';
	public const PAUSE_REQUESTED='pause_requested';
	public const PAUSED='paused';
	public const CANCEL_REQUESTED='cancel_requested';
	public const RETRY_WAIT='retry_wait';
	public const COMPLETED='completed';
	public const COMPLETED_WITH_FAILURES='completed_with_failures';
	public const FAILED='failed';
	public const CANCELLED='cancelled';

	/** @return list<string> */
	public static function all(): array {
		return [
			self::QUEUED, self::RUNNING, self::PAUSE_REQUESTED, self::PAUSED,
			self::CANCEL_REQUESTED, self::RETRY_WAIT, self::COMPLETED,
			self::COMPLETED_WITH_FAILURES, self::FAILED, self::CANCELLED,
		];
	}

	public static function normalize(string $status): string {
		$status=strtolower(trim(str_replace([' ', '-'], '_', $status)));
		if(!in_array($status, self::all(), true)){
			throw new \InvalidArgumentException("Unknown Panel operation status '{$status}'.");
		}
		return $status;
	}

	public static function terminal(string $status): bool {
		return in_array(self::normalize($status), [self::COMPLETED, self::COMPLETED_WITH_FAILURES, self::FAILED, self::CANCELLED], true);
	}

	public static function active(string $status): bool {
		return in_array(self::normalize($status), [self::RUNNING, self::PAUSE_REQUESTED, self::CANCEL_REQUESTED], true);
	}

	public static function canTransition(string $from, string $to): bool {
		$from=self::normalize($from);
		$to=self::normalize($to);
		if($from===$to){
			return true;
		}
		$allowed=[
			self::QUEUED=>[self::RUNNING, self::CANCELLED],
			self::RUNNING=>[self::PAUSE_REQUESTED, self::PAUSED, self::CANCEL_REQUESTED, self::CANCELLED, self::RETRY_WAIT, self::COMPLETED, self::COMPLETED_WITH_FAILURES, self::FAILED],
			self::PAUSE_REQUESTED=>[self::RUNNING, self::PAUSED, self::CANCEL_REQUESTED, self::CANCELLED, self::COMPLETED, self::COMPLETED_WITH_FAILURES, self::FAILED],
			self::PAUSED=>[self::QUEUED, self::CANCELLED],
			self::CANCEL_REQUESTED=>[self::CANCELLED, self::COMPLETED, self::COMPLETED_WITH_FAILURES, self::FAILED],
			self::RETRY_WAIT=>[self::QUEUED, self::RUNNING, self::CANCELLED, self::FAILED],
			self::FAILED=>[self::QUEUED],
			self::COMPLETED=>[],
			self::COMPLETED_WITH_FAILURES=>[],
			self::CANCELLED=>[],
		];
		return in_array($to, $allowed[$from] ?? [], true);
	}

	public static function assertTransition(string $from, string $to): void {
		if(!self::canTransition($from, $to)){
			throw new \LogicException("Panel operation cannot transition from '{$from}' to '{$to}'.");
		}
	}
}
