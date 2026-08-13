<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Validates and normalizes media references embedded by an editor. */
interface PanelEditorMediaAdapter {
	public function name(): string;
	public function ready(): bool;
	public function normalizeReference(string $url, PanelEditorContext $context): ?string;
	/** @return array<string,mixed> */
	public function manifest(): array;
}
