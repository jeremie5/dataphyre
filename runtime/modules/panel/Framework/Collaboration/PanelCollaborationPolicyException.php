<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Fail-closed collaboration policy rejection. */
final class PanelCollaborationPolicyException extends \DomainException {
	public function __construct(public readonly string $operation, public readonly ?string $actor, string $reason='') {
		parent::__construct($reason!=='' ? $reason : 'Panel collaboration policy denied '.$operation.'.');
	}
}
