<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Host-owned transport for signed declarative deployment requests. */
interface PanelReleaseDeploymentTransport extends \JsonSerializable {
	/** @param array<string,mixed> $request @return array<string,mixed> */
	public function dispatch(array $request):array;
}
