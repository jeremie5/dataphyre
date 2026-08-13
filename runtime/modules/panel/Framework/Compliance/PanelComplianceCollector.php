<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Host-owned, side-effect-declared source of one bounded compliance observation. */
interface PanelComplianceCollector {
	public function id():string;
	public function fingerprint():string;
	/** @return array<string,mixed> */
	public function capabilities():array;
	public function collect(PanelComplianceCollectionContext $context):PanelComplianceObservation;
}
