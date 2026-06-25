<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace DataphyreUnitTests;

require_once rtrim((string)ROOTPATH['common_dataphyre_runtime'], '/\\').'/modules/tracelog/kernel/assets_support.php';

function tracelog_asset_name_summary_json(): string {
	return json_encode([
		'plain'=>\dataphyre_tracelog_asset_name('viewer.css'),
		'windows_path'=>\dataphyre_tracelog_asset_name('C:\\tmp\\plotter.js'),
		'traversal_basename'=>\dataphyre_tracelog_asset_name('../plotter.css'),
		'invalid_space'=>\dataphyre_tracelog_asset_name('bad asset.css'),
		'invalid_extension_chars'=>\dataphyre_tracelog_asset_name('viewer.css?x=1'),
	], JSON_UNESCAPED_SLASHES);
}

function tracelog_asset_content_summary_json(): string {
	$viewer=\dataphyre_tracelog_asset_content('viewer.css');
	$plotter=\dataphyre_tracelog_asset_content('plotter.js');
	return json_encode([
		'viewer_type'=>$viewer['content_type'] ?? null,
		'viewer_contains_body_rule'=>str_contains((string)($viewer['body'] ?? ''), 'body{background-color:#000;color:#fff}'),
		'plotter_type'=>$plotter['content_type'] ?? null,
		'plotter_contains_force_simulation'=>str_contains((string)($plotter['body'] ?? ''), 'd3.forceSimulation(nodes)'),
		'missing'=>\dataphyre_tracelog_asset_content('missing.css'),
	], JSON_UNESCAPED_SLASHES);
}

function tracelog_asset_url_summary_json(): string {
	$viewer_version=\dataphyre_tracelog_asset_version('viewer.css');
	$missing_version=\dataphyre_tracelog_asset_version('missing.css');
	return json_encode([
		'viewer_version_is_sha16'=>preg_match('/^[a-f0-9]{16}$/', $viewer_version)===1,
		'missing_version'=>$missing_version,
		'viewer_url'=>\dataphyre_tracelog_asset_url('../viewer.css'),
		'missing_url'=>\dataphyre_tracelog_asset_url('missing.css'),
	], JSON_UNESCAPED_SLASHES);
}

function tracelog_asset_plotter_css_summary_json(): string {
	$content=\dataphyre_tracelog_asset_content('./nested/plotter.css');
	$version=\dataphyre_tracelog_asset_version('plotter.css');
	return json_encode([
		'name_from_nested_path'=>\dataphyre_tracelog_asset_name('./nested/plotter.css'),
		'content_type'=>$content['content_type'] ?? null,
		'contains_tooltip_rule'=>str_contains((string)($content['body'] ?? ''), '#tooltip{position:absolute;visibility:hidden'),
		'version_is_sha16'=>preg_match('/^[a-f0-9]{16}$/', $version)===1,
		'url_uses_plotter_css'=>str_contains(\dataphyre_tracelog_asset_url('./nested/plotter.css'), '/dataphyre/tracelog/assets/plotter.css?v='),
	], JSON_UNESCAPED_SLASHES);
}

function tracelog_asset_invalid_input_summary_json(): string {
	return json_encode([
		'empty_name'=>\dataphyre_tracelog_asset_name(''),
		'blank_name'=>\dataphyre_tracelog_asset_name('   '),
		'uppercase_kept'=>\dataphyre_tracelog_asset_name('Viewer.CSS'),
		'directory_traversal_without_file'=>\dataphyre_tracelog_asset_name('../'),
		'unsafe_url'=>\dataphyre_tracelog_asset_url('bad asset.css'),
	], JSON_UNESCAPED_SLASHES);
}

function tracelog_plotter_javascript_contract_summary_json(): string {
	$body=\dataphyre_tracelog_plotter_javascript();
	return json_encode([
		'wraps_in_iife'=>str_starts_with(trim($body), '(function(){') && str_ends_with(trim($body), '})();'),
		'reads_window_data'=>str_contains($body, 'window.tracelogData'),
		'creates_links'=>str_contains($body, 'links.push({source:prevNodeId,target:nodeId,time:traceEntry.time})'),
		'creates_nodes'=>str_contains($body, 'nodes.push(nodeMap[nodeId])'),
		'has_zoom_handler'=>str_contains($body, 'd3.zoom().scaleExtent([0.1,10]).on("zoom", zoomed)'),
		'has_tooltip_events'=>str_contains($body, '.on("mouseover", function(event, d)') && str_contains($body, '.on("mouseout", function(){'),
		'uses_force_link_ids'=>str_contains($body, 'd3.forceLink(links).id(function(d){return d.id;})'),
	], JSON_UNESCAPED_SLASHES);
}
