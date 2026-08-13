<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Application-owned model transport; Panel owns routing, policy, validation, and audit. */
interface PanelOperatorModelAdapter {
	/** @param array<string,mixed> $toolManifest @return PanelOperatorProposal|array<string,mixed> */
	public function propose(PanelOperatorTask $task,PanelOperatorModel $model,array $toolManifest):PanelOperatorProposal|array;
}
