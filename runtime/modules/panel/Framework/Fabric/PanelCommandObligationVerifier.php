<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Host boundary for cryptographically or contextually verifying policy obligations. */
interface PanelCommandObligationVerifier extends \JsonSerializable {
	public function verify(PanelCommandEnvelope $command,PanelPolicyDecision $decision):PanelCommandObligationResult;
}
