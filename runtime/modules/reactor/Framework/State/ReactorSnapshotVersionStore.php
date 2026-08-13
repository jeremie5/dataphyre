<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Atomic version ledger for scoped Reactor snapshot instances.
 *
 * Implementations store metadata only; component state and signatures must
 * never be persisted by this contract. `reserve()` is the pre-hydration CAS gate
 * that makes stale and concurrent dispatch deterministic within the adapter's
 * declared coordination scope.
 */
interface ReactorSnapshotVersionStore {
	public const CLAIMED='claimed';
	public const BUSY='busy';
	public const RESERVATION_EXPIRED='reservation_expired';
	public const STALE='stale';
	public const FUTURE='future';
	public const MISSING='missing';
	public const EXPIRED='expired';
	public const MISMATCH='mismatch';
	public const UNAVAILABLE='unavailable';

	/** Registers a newly issued snapshot instance without overwriting another instance. */
	public function register(string $snapshotId, string $scopeHash, string $component, int $version, int $expiresAt): bool;

	/**
	 * Atomically reserves expectedVersion before any component callback executes.
	 * Implementations must reject leases beyond their short declared maximum or
	 * beyond the registered snapshot expiry.
	 *
	 * @return string One of this interface's CLAIMED/STALE/FUTURE/MISSING/EXPIRED/MISMATCH/UNAVAILABLE constants.
	 */
	public function reserve(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId, int $reservationExpiresAt): string;

	/** Finalizes a live reservation and advances the monotonic version. */
	public function finalize(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, int $nextVersion, int $nextExpiresAt, string $reservationId): string;

	/** Releases a live reservation after a post-reservation failure or denial. */
	public function abort(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId): bool;

	/** Removes an unreachable, unreserved issued snapshot (for mount/response rollback). */
	public function revoke(string $snapshotId, string $scopeHash, string $component, int $version): bool;

	/** Secret-free, truthful adapter capabilities for the Reactor manifest. */
	public function manifest(): array;
}
