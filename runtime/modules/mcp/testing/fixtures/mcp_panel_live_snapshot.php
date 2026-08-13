<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Panel\SourcePanelCapabilityIndex;

require_once dirname(__DIR__,2).'/kernel/dataphyre_mcp.contract.source.php';
require_once dirname(__DIR__,2).'/kernel/dataphyre_mcp.contract.index.php';
require_once dirname(__DIR__,2).'/kernel/dataphyre_mcp.contract.catalog.php';
require_once dirname(__DIR__,2).'/kernel/dataphyre_mcp.panel.source.php';
require_once dirname(__DIR__,2).'/kernel/dataphyre_mcp.panel.index.php';

$root=(string)($argv[1]??'');
if($root===''){
	fwrite(STDERR,"Panel source root is required.\n");
	exit(2);
}

echo json_encode((new SourcePanelCapabilityIndex($root))->snapshot(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
