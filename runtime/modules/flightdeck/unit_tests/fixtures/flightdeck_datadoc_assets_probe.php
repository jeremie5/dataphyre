<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

function dataphyre_datadoc_ui_asset_content(string $asset): ?array {
	return $asset==='datadoc-ui.js'
		? ['content_type'=>'application/javascript; charset=UTF-8','content'=>'window.datadocProbe=true;']
		: null;
}
