<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Token-stream syntax adapter; implementations never return trusted HTML. */
interface PanelEditorSyntaxAdapter {
	public function name(): string;
	public function ready(): bool;
	public function supports(string $language): bool;
	/** @return list<array{type:string,text:string}> */
	public function tokens(string $code, string $language, PanelEditorContext $context): array;
	/** @return array<string,mixed> */
	public function manifest(): array;
}
