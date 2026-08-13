<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Signed human approval plus the optimistic store revision it created. */
final class PanelAgentApprovalEnvelope implements \JsonSerializable {
	public function __construct(private readonly PanelAgentSignedIntent $intent, private readonly int $storeRevision) {
		if($intent->audience()!=='dp-panel-agent-approval'){ throw new \InvalidArgumentException('Panel agent approval envelope requires an approval intent.'); }
		if($storeRevision<0){ throw new \InvalidArgumentException('Panel agent store revision cannot be negative.'); }
	}
	public function intent(): PanelAgentSignedIntent { return $this->intent; }
	public function storeRevision(): int { return $this->storeRevision; }
	public function jsonSerialize(): array { return ['type'=>'panel_agent_approval_envelope','version'=>1,'intent'=>$this->intent,'store_revision'=>$this->storeRevision]; }
}
