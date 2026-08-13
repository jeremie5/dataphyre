<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Supplies current and historical navigation signing keys for rotation. */
interface PanelNavigationKeyProvider {
	public function current(int $timestamp): ?PanelNavigationSigningKey;
	public function find(string $keyId): ?PanelNavigationSigningKey;
	/** @return array<string,mixed> Secret-free provider capabilities. */
	public function manifest(): array;
}
