<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Network boundary for a federation wire request and its signed acknowledgement. */
interface PanelFederationTransport extends \JsonSerializable {
	/** @param array<string,mixed> $wire @return array<string,mixed> */
	public function deliver(array $wire):array;
}
