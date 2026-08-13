<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Storage-neutral upload adapter exposed to editor integrations. */
interface PanelEditorUploadAdapter {
	public function name(): string;
	public function ready(): bool;
	public function validateUpload(array $upload, PanelEditorContext $context): PanelEditorContentResult;
	/** @return array<string,mixed> */
	public function manifest(): array;
}
