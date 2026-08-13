<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Host-owned proof boundary for an authenticated human confirmation gesture. */
interface PanelAgentConfirmationVerifier {
	/** Stable configuration fingerprint; never include a secret or raw evidence. */
	public function fingerprint(): string;
	/** Evidence must be authenticated, short-lived, one-purpose, and bound to this exact plan and context. */
	public function verify(PanelAgentRequestContext $context, PanelAgentPlan $plan, string $evidence): bool;
}
