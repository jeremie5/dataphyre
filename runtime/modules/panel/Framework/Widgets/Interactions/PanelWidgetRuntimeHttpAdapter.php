<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Optional HTTP route-resolution contract for widget runtime adapters.
 *
 * Route keys and surfaces remain untrusted input. Implementations return only
 * definitions registered by the host; a request body can never choose a PHP
 * class, runtime component, adapter, or authoritative Panel scope.
 */
interface PanelWidgetRuntimeHttpAdapter extends PanelWidgetRuntimeAdapter {
	public function definitionForHttpRoute(string $bindingKey, string $surface): ?PanelWidgetInteractionDefinition;
}
