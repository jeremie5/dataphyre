<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Prepared immutable plan and its client-carried authorization. */
final class PanelAgentPlanEnvelope implements \JsonSerializable {
	public function __construct(private readonly PanelAgentPlan $plan, private readonly PanelAgentSignedIntent $intent, private readonly int $storeRevision) {
		if($intent->audience()!=='dp-panel-agent-plan'){ throw new \InvalidArgumentException('Panel agent plan envelope requires a plan intent.'); }
		if($storeRevision<0){ throw new \InvalidArgumentException('Panel agent store revision cannot be negative.'); }
	}
	public function plan(): PanelAgentPlan { return $this->plan; }
	public function intent(): PanelAgentSignedIntent { return $this->intent; }
	public function storeRevision(): int { return $this->storeRevision; }
	public function jsonSerialize(): array { return ['type'=>'panel_agent_plan_envelope','version'=>1,'plan'=>$this->plan,'intent'=>$this->intent,'store_revision'=>$this->storeRevision]; }
}
