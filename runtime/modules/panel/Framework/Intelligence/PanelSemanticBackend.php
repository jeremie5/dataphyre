<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Negotiated semantic execution backend. */
interface PanelSemanticBackend {
	public function name():string;
	/** @return array<string,mixed> */public function capabilities():array;
	/** @return list<string> */public function unsupported(PanelSemanticExecutionPlan $plan):array;
	public function execute(PanelSemanticExecutionPlan $plan):PanelSemanticQueryResult;
}
