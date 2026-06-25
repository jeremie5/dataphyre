<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Scheduling;

$kernelEntry=dirname(__DIR__).'/kernel/scheduling.main.php';
if(is_file($kernelEntry)){
	require_once($kernelEntry);
}

foreach(['Period.php', 'ScheduledTask.php', 'Scheduling.php'] as $frameworkFile){
	$frameworkPath=__DIR__.'/'.$frameworkFile;
	if(is_file($frameworkPath)){
		require_once($frameworkPath);
	}
}
