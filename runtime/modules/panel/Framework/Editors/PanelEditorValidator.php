<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Editor content validation adapter. */
interface PanelEditorValidator {
	public function name(): string;
	/** @return list<string> */
	public function validate(string $content, PanelEditorContext $context): array;
	/** @return array<string,mixed> */
	public function manifest(): array;
}
