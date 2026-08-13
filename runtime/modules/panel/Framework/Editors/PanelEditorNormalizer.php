<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Editor content normalization adapter. */
interface PanelEditorNormalizer {
	public function name(): string;
	public function normalize(string $content, PanelEditorContext $context): PanelEditorContentResult;
	/** @return array<string,mixed> */
	public function manifest(): array;
}
