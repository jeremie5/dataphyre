<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
ini_set('memory_limit','1024M');
$tracelog_assets_support=__DIR__.'/assets_support.php';
if(is_file($tracelog_assets_support)){
	require_once($tracelog_assets_support);
}
 
$file_path = ROOTPATH['dataphyre'] . 'cache/tracelog_plotting.dat';
if(file_exists($file_path)){
	$lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$all_traces = [];
	foreach ($lines as $line) {
		$decoded_line = json_decode($line, true);
		if (!empty($decoded_line)) {
			if(count($all_traces)>10000)break;
			$all_traces[] = $decoded_line;
		}
	}
	$tracelog_json = json_encode($all_traces);
	echo "<script>var tracelogData = $tracelogJson;</script>";
}
if(empty($tracelog_json)){
	echo'No plotting data available. Load a page on the application to generate.';
	die();
}

unlink($file_path);
?>
<!DOCTYPE html>
<html>
<head>
    <script src="https://d3js.org/d3.v6.min.js"></script>
    <link rel="stylesheet" href="<?=htmlspecialchars(dataphyre_tracelog_asset_url('plotter.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');?>">
</head>
<body>

<div id="tooltip"></div>

    <svg width="100%" height="100%"></svg>
	<script src="<?=htmlspecialchars(dataphyre_tracelog_asset_url('plotter.js'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');?>" defer></script>
</body>
</html>
