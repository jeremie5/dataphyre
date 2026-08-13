<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Optional atomic nonce-consumption hook for single-use navigation intents. */
interface PanelNavigationReplayGuard {
	/**
	 * Returns true only when the nonce is accepted for this verification.
	 * Implementations that enforce one-time use must atomically store the nonce.
	 *
	 * @param array<string,mixed> $context Bounded, secret-free verification context.
	 */
	public function accept(string $nonce, int $expiresAt, array $context=[]): bool;
}
