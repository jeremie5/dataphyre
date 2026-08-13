<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Injected HTTP seam. Implementations own credentials, TLS, DNS, proxy, and egress policy. */
interface PanelHttpDataSourceTransport {
	public function send(PanelHttpDataSourceTransportRequest $request): PanelHttpDataSourceTransportResponse;
}
