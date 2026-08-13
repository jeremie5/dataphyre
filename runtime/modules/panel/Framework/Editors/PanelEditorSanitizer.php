<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Server-side rich HTML sanitizer adapter contract. */
interface PanelEditorSanitizer {
	public function name(): string;
	public function ready(): bool;
	public function sanitize(string $content, PanelEditorSanitizationPolicy $policy, PanelEditorContext $context, ?PanelEditorMediaAdapter $media=null): PanelEditorContentResult;
	/** @return array<string,mixed> */
	public function manifest(): array;
}
