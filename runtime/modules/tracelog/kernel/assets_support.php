<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_TRACELOG_ASSETS_SUPPORT_LOADED')){
	return;
}
define('DATAPHYRE_TRACELOG_ASSETS_SUPPORT_LOADED', true);

/**
 * Normalizes a requested tracelog asset name.
 *
 * Only basename-safe alphanumeric, dot, underscore, and dash characters are
 * accepted. Invalid input returns an empty name so callers cannot traverse the
 * filesystem or request arbitrary embedded assets.
 *
 * @param string $asset Raw asset path or name from the request.
 * @return string Safe basename, or an empty string when invalid.
 */
function dataphyre_tracelog_asset_name(string $asset): string {
	$name=basename(str_replace('\\', '/', trim($asset)));
	return preg_match('/^[a-z0-9._-]+$/i', $name)===1 ? $name : '';
}

/**
 * Builds a versioned URL for an embedded tracelog asset.
 *
 * The URL points at the tracelog asset route and includes a short content hash
 * so browser caches invalidate when embedded CSS or JavaScript changes.
 *
 * @param string $asset Raw asset path or name.
 * @return string Public tracelog asset URL with version query parameter.
 */
function dataphyre_tracelog_asset_url(string $asset): string {
	$name=dataphyre_tracelog_asset_name($asset);
	return '/dataphyre/tracelog/assets/'.$name.'?v='.dataphyre_tracelog_asset_version($name);
}

/**
 * Calculates a cache version for an embedded asset.
 *
 * Existing embedded assets use the first sixteen characters of a SHA-1 over
 * the response body. Missing or invalid assets return the literal "missing",
 * making broken URLs obvious while avoiding filesystem disclosure.
 *
 * @param string $asset Raw asset path or name.
 * @return string Short content hash or "missing".
 */
function dataphyre_tracelog_asset_version(string $asset): string {
	$content=dataphyre_tracelog_asset_content($asset);
	return is_array($content) ? substr(sha1((string)$content['body']), 0, 16) : 'missing';
}

/**
 * Returns embedded tracelog asset response content.
 *
 * Assets are embedded directly in PHP so the diagnostic viewer can be served
 * without a separate public asset directory. Unknown names return null and
 * should be handled by the route layer as a missing asset.
 *
 * @param string $asset Raw asset path or name.
 * @return ?array{content_type:string,body:string} Response payload for a known embedded asset.
 */
function dataphyre_tracelog_asset_content(string $asset): ?array {
	return match(dataphyre_tracelog_asset_name($asset)){
		'viewer.css'=>[
			'content_type'=>'text/css; charset=UTF-8',
			'body'=>'body{background-color:#000;color:#fff}b,i,body{color:#fff}',
		],
		'plotter.css'=>[
			'content_type'=>'text/css; charset=UTF-8',
			'body'=>'.link{stroke:#999;stroke-opacity:.6}.node text{pointer-events:none;font:10px sans-serif}html,body{margin:0;padding:0;width:100%;height:100%}.link-labels text{font-size:10px;fill:#555}#tooltip{position:absolute;visibility:hidden;background-color:#fff;padding:10px;border:1px solid #ddd}',
		],
		'plotter.js'=>[
			'content_type'=>'application/javascript; charset=UTF-8',
			'body'=>dataphyre_tracelog_plotter_javascript(),
		],
		default=>null,
	};
}

/**
 * Returns the embedded JavaScript for the tracelog call graph viewer.
 *
 * The script consumes window.tracelogData, builds D3 nodes/links from adjacent
 * trace entries, and wires zoom, force simulation, labels, and tooltips for the
 * diagnostic graph view.
 *
 * @return string JavaScript source for the plotter asset.
 */
function dataphyre_tracelog_plotter_javascript(): string {
	return <<<'JS'
(function(){
	var links=[];
	var nodes=[];
	var nodeMap={};
	var traces=Array.isArray(window.tracelogData) ? window.tracelogData : [];
	traces.forEach(function(trace, index){
		if(trace.length>0){
			var traceEntry=trace[0];
			var nodeId=traceEntry.file+":"+traceEntry.line+":"+traceEntry.function;
			if(!nodeMap[nodeId]){
				nodeMap[nodeId]={
					id:nodeId,
					label:traceEntry.function,
					file:traceEntry.file,
					line:traceEntry.line,
					class:traceEntry.class || "N/A",
					args:traceEntry.args || []
				};
				nodes.push(nodeMap[nodeId]);
			}
			if(index>0){
				var prevTrace=traces[index - 1][0];
				var prevNodeId=prevTrace.file+":"+prevTrace.line+":"+prevTrace.function;
				if(nodeMap[nodeId] && nodeMap[prevNodeId]){
					links.push({source:prevNodeId,target:nodeId,time:traceEntry.time});
				}
			}
		}
	});
	var color=d3.scaleOrdinal(d3.schemeCategory10);
	var svg=d3.select("svg"),
		width=+svg.node().getBoundingClientRect().width,
		height=+svg.node().getBoundingClientRect().height;
	var zoom=d3.zoom().scaleExtent([0.1,10]).on("zoom", zoomed);
	svg.call(zoom);
	var g=svg.append("g");
	/**
	 * Applies the current D3 zoom transform to the graph container.
	 *
	 * The SVG itself remains fixed while the inner group is panned and scaled.
	 */
	function zoomed(event){g.attr("transform", event.transform);}
	svg.append("defs").selectAll("marker")
		.data(["end"])
		.enter().append("marker")
		.attr("id", String)
		.attr("viewBox", "0 -5 10 10")
		.attr("refX", 15)
		.attr("refY", -1.5)
		.attr("markerWidth", 6)
		.attr("markerHeight", 6)
		.attr("orient", "auto")
		.append("path")
		.attr("d", "M0,-5L10,0L0,5");
	var link=g.append("g")
		.attr("class", "links")
		.selectAll("line")
		.data(links)
		.enter().append("line")
		.attr("stroke", "#999")
		.attr("stroke-opacity", .6)
		.attr("stroke-width", 2)
		.attr("marker-end", "url(#end)");
	var linkLabels=g.append("g")
		.attr("class", "link-labels")
		.selectAll("text")
		.data(links)
		.enter()
		.append("text")
		.text(function(d){return d.time;});
	var node=g.append("g")
		.attr("class", "nodes")
		.selectAll("g")
		.data(nodes)
		.enter().append("g");
	node.append("title")
		.text(function(d){
			return "Function: "+d.label+"\nFile: "+d.file+"\nLine: "+d.line+"\nClass: "+d.class+"\nArgs: "+d.args.join(", ");
		});
	node.append("circle")
		.attr("r", 5)
		.attr("fill", function(d){return color(d.group);});
	node.append("text")
		.text(function(d){return d.label;});
	node.append("title")
		.text(function(d){return d.id;});
	var tooltip=d3.select("#tooltip");
	node.on("mouseover", function(event, d){
		var argsString=Array.isArray(d.args) ? d.args.join(", ") : "N/A";
		tooltip.html("Function: "+d.label+"<br>File: "+d.file+"<br>Line: "+d.line+"<br>Class: "+d.class+"<br>Args: "+argsString)
			.style("visibility", "visible")
			.style("top", (event.pageY - 10)+"px")
			.style("left", (event.pageX + 10)+"px");
	})
	.on("mousemove", function(event){
		tooltip.style("top", (event.pageY - 10)+"px").style("left", (event.pageX + 10)+"px");
	})
	.on("mouseout", function(){
		tooltip.style("visibility", "hidden");
	});
	var simulation=d3.forceSimulation(nodes)
		.force("link", d3.forceLink(links).id(function(d){return d.id;}).distance(7))
		.force("charge", d3.forceManyBody().strength(-100))
		.force("collide", d3.forceCollide().radius(function(){return 50;}))
		.force("center", d3.forceCenter(width / 2, height / 2));
	simulation.nodes(nodes).on("tick", ticked);
	simulation.force("link").links(links);
	/**
	 * Repositions graph links, nodes, and labels during force simulation ticks.
	 *
	 * D3 mutates node coordinates in place; this function reflects those values
	 * into the rendered SVG elements.
	 */
	function ticked(){
		link.attr("x1", function(d){return d.source.x;})
			.attr("y1", function(d){return d.source.y;})
			.attr("x2", function(d){return d.target.x;})
			.attr("y2", function(d){return d.target.y;});
		node.attr("transform", function(d){return "translate("+d.x+","+d.y+")";});
		linkLabels.attr("x", function(d){return (d.source.x+d.target.x)/2;})
			.attr("y", function(d){return (d.source.y+d.target.y)/2;});
	}
})();
JS;
}
