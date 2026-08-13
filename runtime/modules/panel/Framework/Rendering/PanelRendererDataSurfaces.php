<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** PanelRenderer facade seam for typed DataSurface SSR. */
trait PanelRendererDataSurfaces {
	/** @param array<string,mixed> $options */
	public static function dataSurface(
		PanelDataSurfaceDefinition $definition,
		PanelDataSurfaceWindowResult $window,
		?PanelDataSurfaceWindowIntent $refresh=null,
		array $options=[]
	): string { return PanelDataSurfaceRenderer::render($definition,$window,$refresh,$options); }
}
