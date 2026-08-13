<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Host-owned secure lookup boundary for deferred signed execution material. */
interface PanelAgentWorkflowJobResolver {
	/** Stable digest of resolver schema, tenancy, key, and storage configuration. */
	public function fingerprint():string;
	public function resolve(PanelAgentWorkflowJob $job,PanelAgentWorkflowWorkerContext $context):PanelAgentDeferredExecution;
}
