<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Fail-closed authorization boundary for Studio reads, edits, and promotion. */
interface PanelStudioAuthorization extends \JsonSerializable {
	public function allows(string $action,string $tenantId,string $principalId,string $documentId):bool;
	/** @return array<string,mixed> */ public function manifest():array;
}
