<?php
/*************************************************************************
*  2020-2022 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the
* property of Shopiro Ltd. and its suppliers, if any. The
* intellectual and technical concepts contained herein are
* proprietary to Shopiro Ltd. and its suppliers and may be
* covered by Canadian and Foreign Patents, patents in process, and
* are protected by trade secret or copyright law. Dissemination of
* this information or reproduction of this material is strictly
* forbidden unless prior written permission is obtained from Shopiro Ltd.
*/

 ini_set('display_errors', 1);
 ini_set('display_startup_errors', 1);
 error_reporting(E_ALL);
 
 ini_set('memory_limit','1024M');
 
$filePath = $rootpath['dataphyre'] . 'tracelog/plotting.dat';
if(file_exists($filePath)){
	$lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$allTraces = [];
	foreach ($lines as $line) {
		$decodedLine = json_decode($line, true);
		if (!empty($decodedLine)) {
			if(count($allTraces)>10000)break;
			$allTraces[] = $decodedLine;
		}
	}
	$tracelogJson = json_encode($allTraces);
	echo "<script>var tracelogData = $tracelogJson;</script>";
}
if(empty($tracelogJson)){
	echo'No plotting data available. Load a page on the application to generate.';
	die();
}

unlink($filePath);
?>
<!DOCTYPE html>
<html>
<head>
    <script src="https://d3js.org/d3.v6.min.js"></script>
    <style>
        .link { stroke: #999; stroke-opacity: 0.6; }
        .node text { pointer-events: none; font: 10px sans-serif; }
html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    height: 100%;
}
.link-labels text {
    font-size: 10px;
    fill: #555;
}
    </style>
</head>
<body>

<div id="tooltip" style="position: absolute; visibility: hidden; background-color: white; padding: 10px; border: 1px solid #ddd;"></div>

    <svg width="100%" height="100%"></svg>
	
<script>
	var links = [];
	var nodes = [];
	var nodeMap = {};

tracelogData.forEach(function(trace, index) {
    if(trace.length > 0) {
        var traceEntry = trace[0];
        var nodeId = traceEntry.file + ":" + traceEntry.line + ":" + traceEntry.function;

        // Add node if it doesn't exist
		if(!nodeMap[nodeId]) {
			nodeMap[nodeId] = {
				id: nodeId,
				label: traceEntry.function,
				file: traceEntry.file,
				line: traceEntry.line,
				class: traceEntry.class || "N/A", // Add default value for class
				args: traceEntry.args || [] // Ensure args is always an array
			};
			nodes.push(nodeMap[nodeId]);
		}

        // Create links based on sequence
        if(index > 0) {
            var prevTrace = tracelogData[index - 1][0];
            var prevNodeId = prevTrace.file + ":" + prevTrace.line + ":" + prevTrace.function;
			if (nodeMap[nodeId] && nodeMap[prevNodeId]) {
				links.push({
					source: prevNodeId,
					target: nodeId,
					time: traceEntry.time
				});
			}
        }
    }
});

var color = d3.scaleOrdinal(d3.schemeCategory10);

var svg = d3.select("svg"),
    width = +svg.node().getBoundingClientRect().width,
    height = +svg.node().getBoundingClientRect().height;

var zoom = d3.zoom()
    .scaleExtent([0.1, 10])  // This controls the zoom levels (min, max)
    .on("zoom", zoomed);

svg.call(zoom);

function zoomed(event) {
    g.attr("transform", event.transform);
}

var g = svg.append("g");

svg.append("defs").selectAll("marker")
    .data(["end"])      // Different link/path types can be defined here
  .enter().append("marker")
    .attr("id", String)
    .attr("viewBox", "0 -5 10 10")
    .attr("refX", 15)   // Controls the distance of the marker from the node
    .attr("refY", -1.5)
    .attr("markerWidth", 6)
    .attr("markerHeight", 6)
    .attr("orient", "auto")
  .append("path")
    .attr("d", "M0,-5L10,0L0,5");

var link = g.append("g")
    .attr("class", "links")
    .selectAll("line")
    .data(links)
    .enter().append("line")
    .attr("stroke", "#999")
    .attr("stroke-opacity", 0.6)
    .attr("stroke-width", 2)
    .attr("marker-end", "url(#end)");

links.forEach(link => {
    if (!nodeMap[link.source] || !nodeMap[link.target]) {
        console.log("Missing node for link: ", link);
    }
});

// Add labels for the links
var linkLabels = g.append("g")
    .attr("class", "link-labels")
    .selectAll("text")
    .data(links)
    .enter()
    .append("text")
    .text(function(d) { return d.time; });

var node = g.append("g")
	.attr("class", "nodes")
	.selectAll("g")
	.data(nodes)
	.enter().append("g");
	
node.append("title")
    .text(function(d) { 
        return "Function: " + d.label + "\nFile: " + d.file + "\nLine: " + d.line +
               "\nClass: " + d.class + "\nArgs: " + d.args.join(", ");
    });
		
var circles = node.append("circle")
	.attr("r", 5)
	.attr("fill", function(d) { return color(d.group); });

var labels = node.append("text")
    .text(function(d) { return d.label; });

node.append("title")
	.text(function(d) { return d.id; });

var tooltip = d3.select("#tooltip");

node.on("mouseover", function(event, d) {
    var argsString = Array.isArray(d.args) ? d.args.join(", ") : "N/A";
    tooltip.html("Function: " + d.label + "<br>File: " + d.file + "<br>Line: " + d.line +
                 "<br>Class: " + d.class + "<br>Args: " + argsString)
          .style("visibility", "visible")
          .style("top", (event.pageY - 10) + "px")
          .style("left", (event.pageX + 10) + "px");
})
.on("mousemove", function(event) {
    tooltip.style("top", (event.pageY - 10) + "px")
           .style("left", (event.pageX + 10) + "px");
})
.on("mouseout", function() {
    tooltip.style("visibility", "hidden");
});

var linkForce = d3.forceLink(links)
    .id(function(d) { return d.id; })
    .distance(7);

var chargeForce = d3.forceManyBody().strength(-100); // Increase the magnitude further

var collideForce = d3.forceCollide().radius(function(d) { return 50; }); // Adjust radius as needed

// Initialize the simulation with all forces
var simulation = d3.forceSimulation(nodes)
    .force("link", linkForce)
    .force("charge", chargeForce)
    .force("collide", collideForce)
    .force("center", d3.forceCenter(width / 2, height / 2));

simulation.nodes(nodes).on("tick", ticked);
simulation.force("link").links(links);

function ticked() {
    link
        .attr("x1", d => d.source.x)
        .attr("y1", d => d.source.y)
        .attr("x2", d => d.target.x)
        .attr("y2", d => d.target.y);

    node
        .attr("transform", function(d) { return "translate(" + d.x + "," + d.y + ")"; });

    // Update positions of the link labels
    linkLabels
        .attr("x", function(d) { return (d.source.x + d.target.x) / 2; })
        .attr("y", function(d) { return (d.source.y + d.target.y) / 2; });
}

    </script>
</body>
</html>