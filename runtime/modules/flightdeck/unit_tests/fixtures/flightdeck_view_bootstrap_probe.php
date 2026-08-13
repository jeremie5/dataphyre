<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$viewFile=(string)($argv[1] ?? '');
$mode=(string)($argv[2] ?? 'missing');
$fixtures=(string)($argv[3] ?? '');
if($viewFile==='' || !is_file($viewFile)){
	throw new InvalidArgumentException('Flightdeck view file is required.');
}

require $fixtures.'/flightdeck_view_tracelog_probe.php';

if($mode==='candidate'){
	$GLOBALS['dp_flightdeck_templating_facade']=$fixtures.'/flightdeck_view_templating_facade_probe.php'; // dataphyre-test-architecture: exempt[raw-global-variable] reason="View bootstrap fixture publishes its standalone templating facade path."
	require $fixtures.'/flightdeck_view_module_helpers_probe.php';
}

require $viewFile;
include $viewFile;
try{
	if($mode==='missing'){
		$root=dirname($viewFile,5);
		require_once $root.'/runtime/modules/testing/tooling/bootstrap.php';
		$context=new \Dataphyre\Test\Context('Flightdeck view bootstrap probe');
		$context->nonPublic(dataphyre_flightdeck_view::class)->invoke('ensure_ready',[]);
		$html='';
	}else{
		$html=dataphyre_flightdeck_view::layout('Probe','<main>ready</main>');
	}
	echo json_encode([
		'rendered'=>str_contains($html,'ready'),
		'contracts'=>class_exists('dataphyre\\templating',false) ? count(\dataphyre\templating::$contracts) : 0,
	],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $failure){
	echo json_encode([
		'rendered'=>false,
		'exception'=>$failure::class,
		'message'=>$failure->getMessage(),
	],JSON_THROW_ON_ERROR)."\n";
}
