<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Host-owned identity/permission resolver. Panel ships no ambient authorization. */
interface PanelAgentPolicyResolver {
	/** @param array<string,mixed> $arguments */
	public function decide(PanelAgentRequestContext $context, PanelAgentTool $tool, array $arguments): PanelAgentPolicyDecision;
	/** Independently authorizes a human principal to approve this exact plan. */
	public function approve(PanelAgentRequestContext $approver, PanelAgentPlan $plan): PanelAgentPolicyDecision;
	/** Stable SHA-256 of the host policy configuration, never the policy or a secret. */
	public function fingerprint(): string;
}
