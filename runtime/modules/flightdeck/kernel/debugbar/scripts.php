<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_SCRIPTS_TRAIT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_SCRIPTS_TRAIT_LOADED', true);

/**
 * Emits JavaScript assets for the Flightdeck debugbar.
 *
 * The trait is included by the debugbar renderer and returns nonce-aware inline
 * scripts for the live toolbar, static snapshot history, and client telemetry
 * probe. Browser scripts are scoped to Flightdeck DOM targets and use snapshot
 * tokens before posting events back to the debugbar endpoint.
 */
trait dataphyre_flightdeck_debugbar_scripts {

	/**
	 * Builds the script that controls the live debugbar layout.
	 *
	 * The browser code manages docking, collapse state, panel filtering,
	 * keyboard navigation, resize behavior, and trace-cloud interaction. Its
	 * persistent side effect is limited to localStorage layout preferences.
	 *
	 * @return string Inline script tag for live debugbar UI controls.
	 */
	private static function layout_script(): string {
		$nonce=defined('NONCE') ? ' nonce="'.self::e((string)NONCE).'"' : '';
		$script=<<<'JS'
(function(){
	/**
	 * Finds or derives debugbar DOM state for the toolbar root helper.
	 */
	function toolbarRoot(){
		var host=document.getElementById("dataphyre-flightdeck-debugbar-host");
		if(host && host.shadowRoot){return host.shadowRoot;}
		return document;
	}
	var root=toolbarRoot();
	var bar=root.querySelector ? root.querySelector("#dataphyre-flightdeck-debugbar") : null;
	if(!bar){return;}
	var key="dataphyre.flightdeck.debugbar.layout";
	var state={dock:"bottom", size:"normal", collapsed:false, maximized:false, height:0, focused:false, activePanel:""};
	var filterText="";
	try{
		var saved=JSON.parse(localStorage.getItem(key) || "{}");
		if(saved && typeof saved==="object"){
			if(saved.dock==="top" || saved.dock==="bottom"){state.dock=saved.dock;}
			if(saved.size==="short" || saved.size==="normal" || saved.size==="tall" || saved.size==="custom"){state.size=saved.size;}
			state.collapsed=saved.collapsed===true;
			state.maximized=saved.maximized===true;
			if(Number(saved.height)>0){state.height=Math.max(24, Math.min(90, Number(saved.height)));}
			state.focused=saved.focused===true;
			if(typeof saved.activePanel==="string"){state.activePanel=saved.activePanel;}
		}
	}catch(readError){}
	/**
	 * Supports Flightdeck debugbar browser behavior through the button helper.
	 */
	function button(action){
		return bar.querySelector('[data-dfd-action="'+action+'"]');
	}
	/**
	 * Keeps the live debugbar interface synchronized through the set action button helper.
	 */
	function setActionButton(item, label, icon){
		if(!item){return;}
		var labelNode=item.querySelector(".dfd-action-label");
		var iconNode=item.querySelector(".dfd-action-icon");
		if(labelNode){labelNode.textContent=label;}else{item.textContent=label;}
		if(iconNode && icon){iconNode.innerHTML=icon;}
		item.setAttribute("title", label);
		item.setAttribute("aria-label", label);
	}
	/**
	 * Finds or derives debugbar DOM state for the body element helper.
	 */
	function bodyElement(){
		return bar.querySelector(".dfd-body");
	}
	/**
	 * Finds or derives debugbar DOM state for the panels helper.
	 */
	function panels(){
		return Array.prototype.slice.call(bar.querySelectorAll("details.dfd-panel[data-dfd-panel]"));
	}
	/**
	 * Finds or derives debugbar DOM state for the visible panels helper.
	 */
	function visiblePanels(){
		return panels().filter(function(panel){return panel.getAttribute("data-dfd-filter-hidden")!=="1";});
	}
	/**
	 * Finds or derives debugbar DOM state for the panel named helper.
	 */
	function panelNamed(panel){
		return panel ? bar.querySelector('details.dfd-panel[data-dfd-panel="'+panel+'"]') : null;
	}
	/**
	 * Finds or derives debugbar DOM state for the filter input helper.
	 */
	function filterInput(){
		return bar.querySelector("[data-dfd-filter]");
	}
	/**
	 * Finds or derives debugbar DOM state for the filter status helper.
	 */
	function filterStatus(){
		return bar.querySelector("[data-dfd-filter-status]");
	}
	/**
	 * Supports Flightdeck debugbar browser behavior through the save helper.
	 */
	function save(){
		try{localStorage.setItem(key, JSON.stringify(state));}catch(writeError){}
	}
	/**
	 * Keeps the live debugbar interface synchronized through the set active panel helper.
	 */
	function setActivePanel(panel, persist){
		var target=panelNamed(panel);
		if(!target || target.getAttribute("data-dfd-filter-hidden")==="1"){
			target=visiblePanels().filter(function(item){return item.open;})[0] || visiblePanels()[0] || bar.querySelector("details.dfd-panel[data-dfd-panel]");
			panel=target ? (target.getAttribute("data-dfd-panel") || "") : "";
		}
		state.activePanel=panel;
		Array.prototype.forEach.call(bar.querySelectorAll("[data-dfd-panel-target]"), function(item){
			item.classList.toggle("dfd-active", item.getAttribute("data-dfd-panel-target")===panel);
		});
		panels().forEach(function(item){
			item.classList.toggle("dfd-active-panel", item.getAttribute("data-dfd-panel")===panel);
		});
		if(persist){save();}
	}
	/**
	 * Finds or derives debugbar DOM state for the filter terms helper.
	 */
	function filterTerms(value){
		return String(value || "").toLowerCase().split(/\s+/).filter(Boolean).slice(0, 6);
	}
	/**
	 * Finds or derives debugbar DOM state for the panel matches helper.
	 */
	function panelMatches(panel, terms){
		if(!terms.length){return true;}
		var haystack=((panel.getAttribute("data-dfd-panel") || "")+" "+(panel.textContent || "")).toLowerCase();
		return terms.every(function(term){return haystack.indexOf(term)!==-1;});
	}
	/**
	 * Keeps the live debugbar interface synchronized through the apply filter helper.
	 */
	function applyFilter(openMatches){
		var terms=filterTerms(filterText);
		var list=panels();
		var visible=[];
		list.forEach(function(panel){
			var matched=panelMatches(panel, terms);
			if(matched){
				panel.removeAttribute("data-dfd-filter-hidden");
				visible.push(panel);
				if(terms.length && openMatches){panel.open=true;}
			}else{
				panel.setAttribute("data-dfd-filter-hidden", "1");
			}
		});
		Array.prototype.forEach.call(bar.querySelectorAll("[data-dfd-panel-target]"), function(item){
			var target=panelNamed(item.getAttribute("data-dfd-panel-target") || "");
			if(target && target.getAttribute("data-dfd-filter-hidden")==="1"){
				item.setAttribute("data-dfd-filter-hidden", "1");
			}else{
				item.removeAttribute("data-dfd-filter-hidden");
			}
		});
		var status=filterStatus();
		if(status){
			status.textContent=terms.length ? (visible.length+"/"+list.length) : "";
		}
		if(terms.length){
			state.focused=false;
			if(visible.length>0){
				var active=visible.some(function(panel){return panel.getAttribute("data-dfd-panel")===state.activePanel;});
				if(!active){setActivePanel(visible[0].getAttribute("data-dfd-panel") || "", false);}
			}else{
				setActivePanel("", false);
			}
		}
	}
	/**
	 * Keeps the live debugbar interface synchronized through the sync active from scroll helper.
	 */
	function syncActiveFromScroll(){
		var body=bodyElement();
		if(!body || state.collapsed){return;}
		var list=visiblePanels();
		if(!list.length){return;}
		var bodyRect=body.getBoundingClientRect();
		var probeY=bodyRect.top + 72;
		var best=list[0];
		var bestDistance=Number.POSITIVE_INFINITY;
		list.forEach(function(panel){
			var rect=panel.getBoundingClientRect();
			if(rect.bottom<bodyRect.top+24 || rect.top>bodyRect.bottom){return;}
			var distance=Math.abs(rect.top - probeY);
			if(rect.top<=probeY && rect.bottom>=probeY){distance=-1;}
			if(distance<bestDistance){
				bestDistance=distance;
				best=panel;
			}
		});
		if(best){setActivePanel(best.getAttribute("data-dfd-panel") || "", false);}
	}
	/**
	 * Keeps the live debugbar interface synchronized through the jump to panel helper.
	 */
	function jumpToPanel(panel){
		var target=panelNamed(panel);
		if(!target || target.getAttribute("data-dfd-filter-hidden")==="1"){return;}
		state.collapsed=false;
		target.open=true;
		setActivePanel(panel, false);
		save();
		apply();
		try{target.scrollIntoView({block:"start", behavior:"smooth"});}catch(scrollError){target.scrollIntoView(true);}
	}
	/**
	 * Keeps the live debugbar interface synchronized through the move panel helper.
	 */
	function movePanel(direction){
		var list=visiblePanels();
		if(!list.length){return;}
		var current=state.activePanel || (list[0].getAttribute("data-dfd-panel") || "");
		var index=list.findIndex(function(item){return item.getAttribute("data-dfd-panel")===current;});
		if(index<0){index=0;}
		var next=list[(index + direction + list.length) % list.length];
		jumpToPanel(next.getAttribute("data-dfd-panel") || "");
	}
	/**
	 * Keeps the live debugbar interface synchronized through the init trace clouds helper.
	 */
	function initTraceClouds(){
		var scope=root && root.querySelectorAll ? root : document;
		Array.prototype.forEach.call(scope.querySelectorAll(".dfd-trace-plot[data-dfd-trace-cloud]"), function(plot){
			if(plot.getAttribute("data-dfd-cloud-ready")==="1"){return;}
			var id=plot.getAttribute("data-dfd-trace-cloud") || "";
			var dataNode=plot.querySelector("[data-dfd-trace-cloud-data]") || plot.querySelector('script[type="application/json"]');
			if(!dataNode && id && scope.getElementById){
				dataNode=scope.getElementById(id);
			}
			var svg=plot.querySelector("svg.dfd-trace-plot-svg");
			if(!dataNode || !svg){return;}
			var payload;
			try{payload=JSON.parse(dataNode.textContent || dataNode.innerHTML || "{}");}catch(parseError){return;}
			var width=Number(payload.width) || 920;
			var height=Number(payload.height) || 340;
			var sourceNodes=Array.isArray(payload.nodes) ? payload.nodes : [];
			var nodes=sourceNodes.map(function(node, index){
				var angle=(index / Math.max(1, sourceNodes.length)) * Math.PI * 2;
				var ring=18 + Math.sqrt(index + 1) * 24;
				var copy={};
				Object.keys(node || {}).forEach(function(key){copy[key]=node[key];});
				copy.x=width / 2 + Math.cos(angle) * ring;
				copy.y=height / 2 + Math.sin(angle) * ring;
				copy.vx=0;
				copy.vy=0;
				copy.fx=null;
				copy.fy=null;
				return copy;
			});
			var byId={};
			nodes.forEach(function(node){byId[String(node.id || "")]=node;});
			var links=(Array.isArray(payload.links) ? payload.links : []).map(function(link){
				var count=Number(link.count || 1);
				return {
					source:byId[String(link.source || "")],
					target:byId[String(link.target || "")],
					count:count,
					desired:52 + Math.min(110, 16 * Math.log(Math.max(2, count + 1))),
					strokeWidth:Math.min(5, 1.1 + Math.log(Math.max(1, count)) / Math.LN2 * .45)
				};
			}).filter(function(link){return link.source && link.target;});
			if(!nodes.length){return;}
			var ns="http://www.w3.org/2000/svg";
			while(svg.firstChild){svg.removeChild(svg.firstChild);}
			svg.setAttribute("viewBox", "0 0 "+width+" "+height);
			var defs=document.createElementNS(ns, "defs");
			var marker=document.createElementNS(ns, "marker");
			marker.setAttribute("id", id+"-arrow");
			marker.setAttribute("viewBox", "0 -5 10 10");
			marker.setAttribute("refX", "13");
			marker.setAttribute("refY", "0");
			marker.setAttribute("markerWidth", "5");
			marker.setAttribute("markerHeight", "5");
			marker.setAttribute("orient", "auto");
			var markerPath=document.createElementNS(ns, "path");
			markerPath.setAttribute("d", "M0,-5L10,0L0,5");
			markerPath.setAttribute("fill", "#7dd3fc");
			marker.appendChild(markerPath);
			defs.appendChild(marker);
			svg.appendChild(defs);
			var viewport=document.createElementNS(ns, "g");
			var linkLayer=document.createElementNS(ns, "g");
			var nodeLayer=document.createElementNS(ns, "g");
			viewport.appendChild(linkLayer);
			viewport.appendChild(nodeLayer);
			svg.appendChild(viewport);
			var view={x:0, y:0, scale:1};
			/**
			 * Keeps the live debugbar interface synchronized through the apply view helper.
			 */
			function applyView(){
				viewport.setAttribute("transform", "translate("+view.x.toFixed(1)+" "+view.y.toFixed(1)+") scale("+view.scale.toFixed(3)+")");
			}
			/**
			 * Keeps the live debugbar interface synchronized through the zoom at helper.
			 */
			function zoomAt(clientX, clientY, factor){
				var rect=svg.getBoundingClientRect();
				var sx=(clientX-rect.left) * width / Math.max(1, rect.width);
				var sy=(clientY-rect.top) * height / Math.max(1, rect.height);
				var nextScale=Math.max(.25, Math.min(4, view.scale * factor));
				var worldX=(sx-view.x) / view.scale;
				var worldY=(sy-view.y) / view.scale;
				view.x=sx-worldX*nextScale;
				view.y=sy-worldY*nextScale;
				view.scale=nextScale;
				applyView();
			}
			applyView();
			var linkEls=links.map(function(link){
				var path=document.createElementNS(ns, "path");
				path.setAttribute("stroke-width", String(link.strokeWidth));
				path.setAttribute("marker-end", "url(#"+id+"-arrow)");
				linkLayer.appendChild(path);
				return {link:link, el:path};
			});
			var nodeEls=nodes.map(function(node){
				var group=document.createElementNS(ns, "g");
				group.setAttribute("class", "dfd-trace-plot-node");
				var title=document.createElementNS(ns, "title");
				title.textContent=String(node.call || node.label || "frame")+"\n"+String(node.file || "")+":"+String(node.line || "")+"\n"+String(node.count || 0)+" hits";
				var circle=document.createElementNS(ns, "circle");
				var nodeRadius=Math.min(16, 5 + Math.log(Math.max(1, Number(node.count || 1))) / Math.LN2);
				circle.setAttribute("r", String(nodeRadius));
				circle.setAttribute("fill", String(node.color || "#7dd3fc"));
				var text=document.createElementNS(ns, "text");
				text.setAttribute("x", String(nodeRadius + 1.8));
				text.setAttribute("y", "1.4");
				text.textContent=String(node.label || "frame").slice(0, 28);
				group.appendChild(title);
				group.appendChild(circle);
				group.appendChild(text);
				nodeLayer.appendChild(group);
				return {node:node, el:group};
			});
			var centerX=width / 2;
			var centerY=height / 2;
			var cellSize=110;
			var cooling=.98;
			var settledFrames=0;
			var neighborOffsets=[[-1,-1],[0,-1],[1,-1],[-1,0],[0,0],[1,0],[-1,1],[0,1],[1,1]];
			/**
			 * Keeps the live debugbar interface synchronized through the tick physics helper.
			 */
			function tickPhysics(){
				links.forEach(function(link){
					var dx=link.target.x-link.source.x;
					var dy=link.target.y-link.source.y;
					var distance=Math.sqrt(dx*dx+dy*dy) || 1;
					var force=(distance-link.desired) * .018;
					var fx=dx / distance * force;
					var fy=dy / distance * force;
					if(link.source.fx===null){link.source.vx+=fx;link.source.vy+=fy;}
					if(link.target.fx===null){link.target.vx-=fx;link.target.vy-=fy;}
				});
				var grid={};
				nodes.forEach(function(node, index){
					node.__index=index;
					var gx=Math.floor(node.x / cellSize);
					var gy=Math.floor(node.y / cellSize);
					var key=gx+":"+gy;
					if(!grid[key]){grid[key]=[];}
					grid[key].push(node);
				});
				nodes.forEach(function(a){
					var gx=Math.floor(a.x / cellSize);
					var gy=Math.floor(a.y / cellSize);
					neighborOffsets.forEach(function(offset){
						var bucket=grid[(gx+offset[0])+":"+(gy+offset[1])];
						if(!bucket){return;}
						bucket.forEach(function(b){
							if((b.__index || 0)<=(a.__index || 0)){return;}
							var dx=a.x-b.x;
							var dy=a.y-b.y;
							var distanceSq=Math.max(49, dx*dx+dy*dy);
							if(distanceSq>cellSize*cellSize*2.25){return;}
							var distance=Math.sqrt(distanceSq);
							var force=1800 / distanceSq;
							var fx=dx / distance * force;
							var fy=dy / distance * force;
							if(a.fx===null){a.vx+=fx;a.vy+=fy;}
							if(b.fx===null){b.vx-=fx;b.vy-=fy;}
						});
					});
				});
				nodes.forEach(function(node){
					if(node.fx!==null){
						node.x=node.fx;
						node.y=node.fy;
						node.vx=0;
						node.vy=0;
					}else{
						node.vx+=(centerX-node.x)*.004;
						node.vy+=(centerY-node.y)*.004;
						var margin=34;
						if(node.x<margin){node.vx+=(margin-node.x)*.035;}
						if(node.x>width-margin){node.vx-=(node.x-(width-margin))*.035;}
						if(node.y<margin){node.vy+=(margin-node.y)*.035;}
						if(node.y>height-margin){node.vy-=(node.y-(height-margin))*.035;}
						node.vx*=.84 * cooling;
						node.vy*=.84 * cooling;
						if(Math.abs(node.vx)<.025){node.vx=0;}
						if(Math.abs(node.vy)<.025){node.vy=0;}
						node.x+=node.vx;
						node.y+=node.vy;
					}
				});
				cooling=Math.max(.72, cooling*.994);
			}
			/**
			 * Keeps the live debugbar interface synchronized through the render cloud helper.
			 */
			function renderCloud(){
				linkEls.forEach(function(item){
					var s=item.link.source;
					var t=item.link.target;
					var mx=(s.x+t.x)/2;
					var my=(s.y+t.y)/2 - Math.min(42, Math.abs(t.x-s.x)*.08);
					item.el.setAttribute("d", "M"+s.x.toFixed(1)+","+s.y.toFixed(1)+" Q"+mx.toFixed(1)+","+my.toFixed(1)+" "+t.x.toFixed(1)+","+t.y.toFixed(1));
				});
				nodeEls.forEach(function(item){
					item.el.setAttribute("transform", "translate("+item.node.x.toFixed(1)+" "+item.node.y.toFixed(1)+")");
				});
			}
			var frame=0;
			var animationQueued=false;
			/**
			 * Supports Flightdeck debugbar browser behavior through the is settled helper.
			 */
			function isSettled(){
				var moving=nodes.some(function(node){return Math.abs(node.vx)+Math.abs(node.vy)>.08;});
				settledFrames=moving ? 0 : settledFrames+1;
				return settledFrames>8;
			}
			/**
			 * Keeps the live debugbar interface synchronized through the animate helper.
			 */
			function animate(){
				animationQueued=false;
				for(var pass=0; pass<4; pass++){tickPhysics();}
				renderCloud();
				frame++;
				if(frame<80 || !isSettled()){
					queueAnimation();
				}
			}
			/**
			 * Keeps the live debugbar interface synchronized through the queue animation helper.
			 */
			function queueAnimation(){
				if(animationQueued){return;}
				animationQueued=true;
				(window.requestAnimationFrame || function(callback){return window.setTimeout(callback, 16);})(animate);
			}
			/**
			 * Supports Flightdeck debugbar browser behavior through the pointer helper.
			 */
			function pointer(event){
				var rect=svg.getBoundingClientRect();
				var sx=(event.clientX-rect.left) * width / Math.max(1, rect.width);
				var sy=(event.clientY-rect.top) * height / Math.max(1, rect.height);
				return {x:(sx-view.x) / view.scale, y:(sy-view.y) / view.scale};
			}
			svg.addEventListener("wheel", function(event){
				zoomAt(event.clientX, event.clientY, event.deltaY<0 ? 1.12 : .89);
				event.preventDefault();
			}, {passive:false});
			svg.addEventListener("pointerdown", function(event){
				if(event.button!==undefined && event.button!==0){return;}
				if(event.target && event.target.closest && event.target.closest(".dfd-trace-plot-node")){return;}
				var startX=event.clientX;
				var startY=event.clientY;
				var startViewX=view.x;
				var startViewY=view.y;
				try{svg.setPointerCapture(event.pointerId);}catch(captureError){}
				/**
				 * Keeps the live debugbar interface synchronized through the move helper.
				 */
				function move(moveEvent){
					var rect=svg.getBoundingClientRect();
					view.x=startViewX + (moveEvent.clientX-startX) * width / Math.max(1, rect.width);
					view.y=startViewY + (moveEvent.clientY-startY) * height / Math.max(1, rect.height);
					applyView();
					moveEvent.preventDefault();
				}
				/**
				 * Supports Flightdeck debugbar browser behavior through the done helper.
				 */
				function done(){
					document.removeEventListener("pointermove", move);
					document.removeEventListener("pointerup", done);
					document.removeEventListener("pointercancel", done);
				}
				document.addEventListener("pointermove", move);
				document.addEventListener("pointerup", done);
				document.addEventListener("pointercancel", done);
				event.preventDefault();
			});
			svg.addEventListener("dblclick", function(event){
				view.x=0;
				view.y=0;
				view.scale=1;
				applyView();
				event.preventDefault();
				event.stopPropagation();
			});
			nodeEls.forEach(function(item){
				item.el.addEventListener("pointerdown", function(event){
					var point=pointer(event);
					item.node.fx=point.x;
					item.node.fy=point.y;
					try{item.el.setPointerCapture(event.pointerId);}catch(captureError){}
					/**
					 * Keeps the live debugbar interface synchronized through the move helper.
					 */
					function move(moveEvent){
						var next=pointer(moveEvent);
						item.node.fx=next.x;
						item.node.fy=next.y;
						renderCloud();
						moveEvent.preventDefault();
					}
					/**
					 * Supports Flightdeck debugbar browser behavior through the done helper.
					 */
					function done(){
						item.node.x=item.node.fx;
						item.node.y=item.node.fy;
						item.node.fx=null;
						item.node.fy=null;
						item.node.vx=0;
						item.node.vy=0;
						document.removeEventListener("pointermove", move);
						document.removeEventListener("pointerup", done);
						document.removeEventListener("pointercancel", done);
						frame=0;
						cooling=.98;
						settledFrames=0;
						queueAnimation();
					}
					document.addEventListener("pointermove", move);
					document.addEventListener("pointerup", done);
					document.addEventListener("pointercancel", done);
					event.preventDefault();
				});
			});
			plot.setAttribute("data-dfd-cloud-ready", "1");
			queueAnimation();
		});
	}
	/**
	 * Keeps the live debugbar interface synchronized through the apply helper.
	 */
	function apply(){
		if(filterTerms(filterText).length){state.focused=false;}
		bar.setAttribute("data-dfd-dock", state.dock);
		bar.setAttribute("data-dfd-size", state.height>0 ? "custom" : state.size);
		bar.setAttribute("data-dfd-collapsed", state.collapsed ? "1" : "0");
		bar.setAttribute("data-dfd-maximized", state.maximized ? "1" : "0");
		bar.setAttribute("data-dfd-focused", state.focused ? "1" : "0");
		if(state.height>0){
			bar.style.setProperty("--dfd-body-max", state.height.toFixed(1)+"vh");
		}else{
			bar.style.removeProperty("--dfd-body-max");
		}
		var dock=button("dock");
		var maximize=button("maximize");
		var size=button("size");
		var collapse=button("collapse");
		var focus=button("focus");
		if(dock){
			setActionButton(dock, state.dock==="top" ? "Dock bottom" : "Dock top", state.dock==="top" ? "&#8595;" : "&#8593;");
		}
		if(maximize){
			setActionButton(maximize, state.maximized ? "Overlay view" : "Full view", state.maximized ? "&#9635;" : "&#9974;");
			maximize.setAttribute("aria-pressed", state.maximized ? "true" : "false");
		}
		if(size){
			var sizeLabel=state.height>0 ? "Normal height" : (state.size==="normal" ? "Tall height" : (state.size==="tall" ? "Short height" : "Normal height"));
			setActionButton(size, sizeLabel, "&#8597;");
		}
		if(collapse){
			setActionButton(collapse, state.collapsed ? "Expand Flightdeck" : "Collapse Flightdeck", state.collapsed ? "&#43;" : "&#8722;");
			collapse.setAttribute("aria-expanded", state.collapsed ? "false" : "true");
		}
		if(focus){
			setActionButton(focus, state.focused ? "Show all panels" : "Focus active panel", state.focused ? "&#9638;" : "&#9673;");
			focus.setAttribute("aria-pressed", state.focused ? "true" : "false");
		}
		applyFilter(false);
		setActivePanel(state.activePanel || "", false);
	}
	bar.addEventListener("click", function(event){
		var nav=event.target && event.target.closest ? event.target.closest("[data-dfd-panel-target]") : null;
		if(nav && bar.contains(nav)){
			jumpToPanel(nav.getAttribute("data-dfd-panel-target") || "");
			event.preventDefault();
			return;
		}
		var target=event.target && event.target.closest ? event.target.closest("[data-dfd-action]") : null;
		if(!target || !bar.contains(target)){return;}
		var action=target.getAttribute("data-dfd-action");
		if(action==="dock"){
			state.dock=state.dock==="top" ? "bottom" : "top";
		}else if(action==="maximize"){
			state.collapsed=false;
			state.maximized=!state.maximized;
		}else if(action==="size"){
			if(state.maximized){state.maximized=false;}
			if(state.height>0){
				state.height=0;
				state.size="normal";
			}else{
				state.size=state.size==="normal" ? "tall" : (state.size==="tall" ? "short" : "normal");
			}
			state.collapsed=false;
		}else if(action==="collapse"){
			state.collapsed=!state.collapsed;
		}else if(action==="focus"){
			state.collapsed=false;
			state.focused=!state.focused;
			setActivePanel(state.activePanel || "", false);
		}else if(action==="clear-filter"){
			filterText="";
			var input=filterInput();
			if(input){input.value="";}
			applyFilter(false);
		}else if(action==="expand-panels"){
			state.collapsed=false;
			state.focused=false;
			panels().forEach(function(panel){panel.open=true;});
			var first=panels()[0];
			setActivePanel(first ? (first.getAttribute("data-dfd-panel") || "") : "", false);
		}else if(action==="collapse-panels"){
			state.collapsed=false;
			state.focused=false;
			panels().forEach(function(panel){panel.open=false;});
			setActivePanel("", false);
		}else{
			return;
		}
		save();
		apply();
	});
	bar.addEventListener("dblclick", function(event){
		if(event.target && event.target.closest && event.target.closest("a,button,summary,details,pre,code")){return;}
		state.collapsed=!state.collapsed;
		save();
		apply();
	});
	bar.addEventListener("toggle", function(event){
		var target=event.target;
		if(target && target.matches && target.matches("details.dfd-panel[data-dfd-panel]") && target.open){
			setActivePanel(target.getAttribute("data-dfd-panel") || "", true);
		}
	}, true);
	var resizer=bar.querySelector("[data-dfd-resizer]");
	if(resizer){
		resizer.addEventListener("dblclick", function(event){
			state.height=0;
			state.size="normal";
			state.collapsed=false;
			save();
			apply();
			event.preventDefault();
		});
		resizer.addEventListener("pointerdown", function(event){
			if(event.button!==undefined && event.button!==0){return;}
			var body=bar.querySelector(".dfd-body");
			if(!body){return;}
			state.collapsed=false;
			var startY=event.clientY;
			var startHeight=body.getBoundingClientRect().height;
			var viewport=Math.max(320, window.innerHeight || document.documentElement.clientHeight || 900);
			/**
			 * Keeps the live debugbar interface synchronized through the clamp height helper.
			 */
			function clampHeight(px){
				var min=Math.min(260, viewport * .42);
				var max=viewport * .9;
				return Math.max(min, Math.min(max, px));
			}
			/**
			 * Keeps the live debugbar interface synchronized through the move helper.
			 */
			function move(moveEvent){
				var delta=state.dock==="top" ? moveEvent.clientY-startY : startY-moveEvent.clientY;
				var next=clampHeight(startHeight + delta);
				state.height=Math.round((next / viewport) * 1000) / 10;
				state.size="custom";
				apply();
				moveEvent.preventDefault();
			}
			/**
			 * Supports Flightdeck debugbar browser behavior through the done helper.
			 */
			function done(){
				bar.classList.remove("dfd-resizing");
				document.removeEventListener("pointermove", move);
				document.removeEventListener("pointerup", done);
				document.removeEventListener("pointercancel", done);
				save();
			}
			bar.classList.add("dfd-resizing");
			try{resizer.setPointerCapture(event.pointerId);}catch(captureError){}
			document.addEventListener("pointermove", move);
			document.addEventListener("pointerup", done);
			document.addEventListener("pointercancel", done);
			event.preventDefault();
		});
	}
	var input=filterInput();
	if(input){
		input.addEventListener("input", function(){
			filterText=input.value || "";
			state.collapsed=false;
			apply();
			applyFilter(true);
		});
		input.addEventListener("keydown", function(event){
			if(event.key==="Escape"){
				filterText="";
				input.value="";
				applyFilter(false);
				event.preventDefault();
			}
			if(event.key==="Enter"){
				var first=visiblePanels()[0];
				if(first){jumpToPanel(first.getAttribute("data-dfd-panel") || "");}
				event.preventDefault();
			}
		});
	}
	var body=bodyElement();
	if(body){
		var scrollQueued=false;
		body.addEventListener("scroll", function(){
			if(scrollQueued){return;}
			scrollQueued=true;
			(window.requestAnimationFrame || function(callback){return window.setTimeout(callback, 16);})(function(){
				scrollQueued=false;
				syncActiveFromScroll();
			});
		}, {passive:true});
	}
	document.addEventListener("keydown", function(event){
		var key=String(event.key || "").toLowerCase();
		if(event.altKey && event.shiftKey && key==="d"){
			state.collapsed=!state.collapsed;
			save();
			apply();
			event.preventDefault();
		}
		if(event.altKey && event.shiftKey && key==="f"){
			state.collapsed=false;
			state.focused=!state.focused;
			setActivePanel(state.activePanel || "", false);
			save();
			apply();
			event.preventDefault();
		}
		if(event.altKey && event.shiftKey && key==="m"){
			state.collapsed=false;
			state.maximized=!state.maximized;
			save();
			apply();
			event.preventDefault();
		}
		if(event.altKey && event.shiftKey && key==="s"){
			state.collapsed=false;
			apply();
			var search=filterInput();
			if(search){search.focus();search.select();}
			event.preventDefault();
		}
		if(event.altKey && event.shiftKey && (event.key==="ArrowRight" || event.key==="ArrowLeft")){
			state.collapsed=false;
			movePanel(event.key==="ArrowRight" ? 1 : -1);
			event.preventDefault();
		}
	});
	apply();
	initTraceClouds();
	var firstOpen=bar.querySelector("details.dfd-panel[data-dfd-panel][open]") || bar.querySelector("details.dfd-panel[data-dfd-panel]");
	if(firstOpen && !state.activePanel){setActivePanel(firstOpen.getAttribute("data-dfd-panel") || "", false);}
})();
JS;
		return '<script'.$nonce.'>'.$script.'</script>';
	}

	/**
	 * Builds the script used by static snapshot history pages.
	 *
	 * Snapshot navigation only opens and scrolls captured panels already present
	 * in the document. It performs no network calls and becomes a no-op when the
	 * expected history container is not rendered.
	 *
	 * @return string Inline script tag for snapshot panel navigation.
	 */
	private static function snapshot_script(): string {
		$nonce=defined('NONCE') ? ' nonce="'.self::e((string)NONCE).'"' : '';
		$script=<<<'JS'
(function(){
	var root=document.currentScript ? document.currentScript.previousElementSibling : null;
	if(!root || !root.classList || !root.classList.contains("dfd-history")){
		root=document.querySelector(".dfd-history");
	}
	if(!root){return;}
	root.addEventListener("click", function(event){
		var link=event.target && event.target.closest ? event.target.closest("[data-dfd-panel-target]") : null;
		if(!link || !root.contains(link)){return;}
		var panel=link.getAttribute("data-dfd-panel-target") || "";
		var target=root.querySelector('[data-dfd-panel="'+panel+'"]') || document.getElementById("dfd-panel-"+panel);
		if(!target){return;}
		target.open=true;
		try{target.scrollIntoView({block:"start", behavior:"smooth"});}catch(error){target.scrollIntoView(true);}
	});
})();
JS;
		return '<script'.$nonce.'>'.$script.'</script>';
	}

	/**
	 * Builds the client telemetry probe for a live debugbar snapshot.
	 *
	 * The probe records bounded browser events such as JavaScript errors, failed
	 * or slow network requests, resource timing, page performance, and
	 * accessibility policy status. Events are posted to the debugbar endpoint
	 * using the supplied snapshot token; replay capture is enabled only for
	 * eligible non-Dataphyre GET and HEAD requests.
	 *
	 * @param string $snapshot_id Snapshot identifier that owns the browser events.
	 * @param string $token Event token accepted by the debugbar endpoint.
	 * @return string Inline script tag, or an empty string when probe credentials are missing.
	 */
	private static function client_probe_script(string $snapshot_id, string $token): string {
		if($snapshot_id==='' || $token===''){
			return '';
		}
		$method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		$uri=(string)($_SERVER['REQUEST_URI'] ?? '/');
		$path=parse_url($uri, PHP_URL_PATH);
		$path=is_string($path) ? $path : '';
		$replay_enabled=in_array($method, ['GET', 'HEAD'], true)
			&& !(defined('DATAPHYRE_FLIGHTDECK_REPLAY') && DATAPHYRE_FLIGHTDECK_REPLAY===true)
			&& !str_starts_with($path, '/dataphyre');
		$replay_token=$replay_enabled ? self::replay_token($method, $uri) : '';
		$config=json_encode([
			'snapshotId'=>$snapshot_id,
			'token'=>$token,
			'endpoint'=>'/dataphyre/debugbar?action=client_event',
			'maxEvents'=>self::CLIENT_EVENT_LIMIT,
			'resourceTimingLimit'=>self::CLIENT_RESOURCE_TIMING_LIMIT,
			'productionReplayEnabled'=>$replay_enabled && $replay_token!=='',
			'productionReplayToken'=>$replay_token,
			'productionReplayMethod'=>$method,
		], JSON_UNESCAPED_SLASHES);
		if(!is_string($config)){
			return '';
		}
		$nonce=defined('NONCE') ? ' nonce="'.self::e((string)NONCE).'"' : '';
		$script=<<<'JS'
(function(){
	var cfg=__CFG__;
	if(!cfg.snapshotId || !cfg.token){return;}
	var queue=[];
	var sent=0;
	var maxEvents=cfg.maxEvents || 120;
	var resourceTimingLimit=cfg.resourceTimingLimit || 24;
	var rawFetch=window.fetch ? window.fetch.bind(window) : null;
	var replayStarted=false;
	var accessibilityFlushTimer=0;
	var liveCounts={events:0, resourceErrors:0, jsErrors:0, networkErrors:0, networkSlow:0, resourceSamples:0, resourceTransfer:0};
	/**
	 * Finds or derives debugbar DOM state for the toolbar root helper.
	 */
	function toolbarRoot(){
		var host=document.getElementById("dataphyre-flightdeck-debugbar-host");
		if(host && host.shadowRoot){return host.shadowRoot;}
		return document;
	}
	/**
	 * Finds or derives debugbar DOM state for the toolbar bar helper.
	 */
	function toolbarBar(){
		var root=toolbarRoot();
		return root && root.querySelector ? root.querySelector("#dataphyre-flightdeck-debugbar") : null;
	}
	/**
	 * Normalizes browser telemetry values through the clip helper.
	 */
	function clip(value, max){
		value=(value===undefined || value===null) ? "" : String(value);
		return value.length>max ? value.slice(0, Math.max(1, max-3))+"..." : value;
	}
	/**
	 * Supports Flightdeck debugbar browser behavior through the asset url helper.
	 */
	function assetUrl(node){
		if(!node){return "";}
		return node.currentSrc || node.src || node.href || node.data || "";
	}
	/**
	 * Normalizes browser telemetry values through the perf now helper.
	 */
	function perfNow(){
		return window.performance && performance.now ? performance.now() : Date.now();
	}
	/**
	 * Normalizes browser telemetry values through the event start helper.
	 */
	function eventStart(value){
		return window.performance && performance.now ? Math.max(0, value || 0) : 0;
	}
	/**
	 * Collects or sends bounded client telemetry through the request url helper.
	 */
	function requestUrl(input){
		if(typeof input==="string"){return input;}
		if(input && input.url){return input.url;}
		return "";
	}
	/**
	 * Collects or sends bounded client telemetry through the request method helper.
	 */
	function requestMethod(input, init){
		if(init && init.method){return String(init.method).toUpperCase();}
		if(input && input.method){return String(input.method).toUpperCase();}
		return "GET";
	}
	/**
	 * Supports Flightdeck debugbar browser behavior through the ignored url helper.
	 */
	function ignoredUrl(url){
		url=String(url || "");
		return !url || url.indexOf(cfg.endpoint)!==-1;
	}
	/**
	 * Collects or sends bounded client telemetry through the request event type helper.
	 */
	function requestEventType(status, duration){
		return status>=400 ? "client_http_error" : (duration>=1000 ? "client_http_slow" : "");
	}
	/**
	 * Collects or sends bounded client telemetry through the request message helper.
	 */
	function requestMessage(status){
		return status>=400 ? "Browser request returned an error status" : "Browser request was slow";
	}
	/**
	 * Normalizes browser telemetry values through the header number helper.
	 */
	function headerNumber(headers, name){
		var value="";
		try{value=headers && headers.get ? headers.get(name) || "" : "";}catch(headerError){value="";}
		var number=Number(value);
		return isFinite(number) ? number : 0;
	}
	/**
	 * Normalizes browser telemetry values through the escape html helper.
	 */
	function escapeHtml(value){
		return String(value===undefined || value===null ? "" : value).replace(/[&<>"']/g, function(ch){
			return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"}[ch] || ch;
		});
	}
	/**
	 * Normalizes browser telemetry values through the format ms helper.
	 */
	function formatMs(value){
		value=Number(value || 0);
		if(!isFinite(value) || value<=0){return "0ms";}
		return value>=1000 ? (value/1000).toFixed(value>=10000 ? 1 : 2)+"s" : value.toFixed(value>=100 ? 1 : 2)+"ms";
	}
	/**
	 * Normalizes browser telemetry values through the format bytes helper.
	 */
	function formatBytes(value){
		value=Number(value || 0);
		if(!isFinite(value) || value<=0){return "0b";}
		var units=["b","kb","mb","gb"];
		var index=0;
		while(value>=1024 && index<units.length-1){value=value/1024;index++;}
		return (index===0 ? Math.round(value) : value.toFixed(value>=10 ? 1 : 2))+units[index];
	}
	/**
	 * Keeps the live debugbar interface synchronized through the set metric helper.
	 */
	function setMetric(key, value, hint){
		var bar=toolbarBar();
		if(!bar){return;}
		var metrics=bar.querySelectorAll('[data-dfd-metric="'+key+'"]');
		for(var i=0;i<metrics.length;i++){
			var metric=metrics[i];
			var valueNode=metric.querySelector("b");
			var hints=metric.querySelectorAll("span");
			if(valueNode && value!==null && value!==undefined){valueNode.textContent=value;}
			if(hints.length>1){hints[hints.length-1].textContent=hint;}
		}
	}
	/**
	 * Maintains accessibility diagnostics in the debugbar through the current browser event count helper.
	 */
	function currentBrowserEventCount(panel){
		if(liveCounts.events>0){return liveCounts.events;}
		var countNode=panel ? panel.querySelector("[data-dfd-browser-count]") : null;
		if(!countNode){return 0;}
		var current=parseInt(countNode.textContent, 10);
		liveCounts.events=isFinite(current) ? current : 0;
		return liveCounts.events;
	}
	/**
	 * Maintains accessibility diagnostics in the debugbar through the update browser event count helper.
	 */
	function updateBrowserEventCount(panel){
		liveCounts.events=currentBrowserEventCount(panel)+1;
		var countNode=panel ? panel.querySelector("[data-dfd-browser-count]") : null;
		if(countNode){countNode.textContent=liveCounts.events+" events";}
		var bar=toolbarBar();
		var nav=bar ? bar.querySelector('[data-dfd-panel-target="browser"] .dfd-muted') : null;
		if(nav){nav.textContent=String(liveCounts.events);}
		setMetric("signals", null, "Server findings plus "+liveCounts.events+" browser events");
	}
	/**
	 * Keeps the live debugbar interface synchronized through the apply live metrics helper.
	 */
	function applyLiveMetrics(event){
		var type=event.type || "";
		if(event.type==="accessibility_policy"){
			var issueCount=Number(event.a11y_issue_count || 0);
			var adjustmentCount=Number(event.a11y_adjustment_count || 0);
			setMetric("accessibility", issueCount ? String(issueCount) : (adjustmentCount ? "adjusted" : "pass"), accessibilitySummaryMessage({issue_count:issueCount, adjustment_count:adjustmentCount}));
			return;
		}
		if(type==="page_performance"){
			var load=Number(event.load_ms || 0);
			var dom=Number(event.dom_content_loaded_ms || 0);
			var firstByte=Number(event.first_byte_ms || 0);
			var pageLabel=load>0 ? formatMs(load) : (dom>0 ? formatMs(dom) : "captured");
			var pageHint=dom>0 ? "DOMContentLoaded "+formatMs(dom) : (firstByte>0 ? "First byte "+formatMs(firstByte) : "Navigation timing captured");
			setMetric("page-load", pageLabel, pageHint);
			setMetric("browser", pageLabel, dom>0 ? "DOM "+formatMs(dom) : "Client probe reported");
			if(event.resource_count && liveCounts.resourceSamples<=0){
				setMetric("resources", String(event.resource_count)+" observed", event.transfer_size ? formatBytes(event.transfer_size)+" navigation transfer" : "Navigation timing resources");
			}
			return;
		}
		if(type==="resource_timing"){
			liveCounts.resourceSamples++;
			liveCounts.resourceTransfer+=Number(event.transfer_size || 0);
			setMetric("resources", liveCounts.resourceSamples+" sampled", formatBytes(liveCounts.resourceTransfer)+" transferred");
			return;
		}
		if(type==="resource_error" || type==="stylesheet_missing"){
			liveCounts.resourceErrors++;
			setMetric("resource-errors", String(liveCounts.resourceErrors), "Failed or missing browser assets");
			return;
		}
		if(type==="js_error" || type==="unhandled_rejection"){
			liveCounts.jsErrors++;
			setMetric("javascript", String(liveCounts.jsErrors), "Errors and unhandled rejections");
			return;
		}
		if(type==="client_http_error" || type==="client_fetch_error"){
			liveCounts.networkErrors++;
			setMetric("network", String(liveCounts.networkErrors), liveCounts.networkSlow+" slow fetch/XHR");
			return;
		}
		if(type==="client_http_slow"){
			liveCounts.networkSlow++;
			setMetric("network", String(liveCounts.networkErrors), liveCounts.networkSlow+" slow fetch/XHR");
			return;
		}
		if(type==="production_replay"){
			setMetric("production-replay", event.response_status ? String(event.response_status) : "captured", event.server_duration_ms ? "Server "+formatMs(event.server_duration_ms) : "HTTP response received");
			var replayMemoryDetail=(event.replay_write_blocks || 0)+" blocked write"+((event.replay_write_blocks || 0)===1 ? "" : "s");
			if(event.replay_debug_overhead_mb){replayMemoryDetail+=" / debug overhead excluded "+event.replay_debug_overhead_mb+"mb";}
			setMetric("replay-memory", event.replay_peak_mb ? String(event.replay_peak_mb)+"mb" : "captured", replayMemoryDetail);
		}
	}
	/**
	 * Maintains accessibility diagnostics in the debugbar through the live detail helper.
	 */
	function liveDetail(event){
		var parts=[];
		if(event.method){parts.push(event.method);}
		if(event.duration_ms){parts.push(formatMs(event.duration_ms));}
		if(event.response_status){parts.push("status "+event.response_status);}
		if(event.replay_responded){parts.push("HTTP response received");}
		if(event.replay_verified){parts.push("replay verified");}
		if(event.replay_production){parts.push("production mode");}
		if(event.replay_readonly){parts.push("read-only");}
		if(event.server_duration_ms){parts.push("server "+formatMs(event.server_duration_ms));}
		if(event.replay_peak_mb){parts.push("peak "+event.replay_peak_mb+"mb");}
		if(event.replay_debug_overhead_mb){parts.push("debug overhead excluded "+event.replay_debug_overhead_mb+"mb");}
		if(event.replay_body_bytes){parts.push("body "+formatBytes(event.replay_body_bytes));}
		if(event.replay_write_blocks){parts.push(event.replay_write_blocks+" write blocks");}
		return parts.join(" / ");
	}
	function accessibilitySummaryMessage(summary){
		var issueCount=Number(summary && summary.issue_count || 0);
		var adjustmentCount=Number(summary && summary.adjustment_count || 0);
		if(issueCount>0){return issueCount+" accessibility issue"+(issueCount===1 ? "" : "s");}
		if(adjustmentCount>0){return adjustmentCount+" automatic adjustment"+(adjustmentCount===1 ? "" : "s");}
		return "Accessibility policy passed";
	}
	function accessibilityStatusPill(status){
		status=String(status || "pass");
		return '<span class="dfd-pill">'+escapeHtml(status)+'</span>';
	}
	function accessibilityPolicyEvent(input){
		var detail=input && input.detail ? input.detail : (input || {});
		var issues=Array.isArray(detail.issues) ? detail.issues : [];
		var adjustments=Array.isArray(detail.adjustments) ? detail.adjustments : [];
		var fallbackFields=Array.isArray(detail.fields) ? detail.fields : [];
		var combinedFields=fallbackFields.filter(function(field){return field && (field.issue_tokens || field.action_tokens || field.status);});
		var fieldSource="split_fields";
		if(combinedFields.length){fieldSource="combined_fields";}
		var issueCount=Number(detail.issue_count || issues.length || 0);
		var adjustmentCount=Number(detail.adjustment_count || adjustments.length || 0);
		var status=detail.status || (issueCount>0 ? "needs_attention" : (adjustmentCount>0 ? "adjusted" : "pass"));
		var event={
			type:"accessibility_policy",
			level:issueCount>0 ? "warning" : "info",
			message:detail.message || accessibilitySummaryMessage({issue_count:issueCount, adjustment_count:adjustmentCount}),
			a11y_status:status,
			a11y_issue_count:issueCount,
			a11y_adjustment_count:adjustmentCount,
			a11y_checked:Number(detail.checked || detail.a11y_checked || 0),
			a11y_issues:issues,
			a11y_adjustments:adjustments,
			a11y_fields:fallbackFields,
			a11y_field_source:fieldSource,
			a11y_summary_html:accessibilityStatusPill(status)+(fieldSource==="combined_fields" ? " (combined fields)" : "")
		};
		push(event);
		if(accessibilityFlushTimer){clearTimeout(accessibilityFlushTimer);}
		accessibilityFlushTimer=setTimeout(function(){
			accessibilityFlushTimer=0;
			flush();
		}, 120);
	}
	document.addEventListener("DataphyrePanelAccessibilityPolicy", accessibilityPolicyEvent);
	window.addEventListener("DataphyrePanelAccessibilityPolicy", accessibilityPolicyEvent);
	/**
	 * Keeps the live debugbar interface synchronized through the render live browser event helper.
	 */
	function renderLiveBrowserEvent(event){
		if(!event){return;}
		applyLiveMetrics(event);
		if(event.type==="resource_timing"){return;}
		var bar=toolbarBar();
		var panel=bar ? bar.querySelector('[data-dfd-panel="browser"]') : null;
		if(!panel){return;}
		panel.open=true;
		updateBrowserEventCount(panel);
		var empty=panel.querySelector("[data-dfd-browser-empty]");
		if(empty){empty.remove();}
		var live=panel.querySelector("[data-dfd-browser-live]");
		if(!live){return;}
		var table=live.querySelector("table");
		if(!table){
			table=document.createElement("table");
			table.className="dfd-table";
			table.innerHTML="<thead><tr><th>Type</th><th>Message</th><th>Source</th><th>Detail</th></tr></thead><tbody></tbody>";
			live.appendChild(table);
		}
		var body=table.querySelector("tbody");
		if(body){
			var row=document.createElement("tr");
			row.innerHTML="<td>"+escapeHtml(event.type || "client_event")+"</td><td>"+escapeHtml(clip(event.message || "", 220))+"</td><td><code>"+escapeHtml(clip(event.url || event.source || "", 260))+"</code></td><td>"+escapeHtml(liveDetail(event))+"</td>";
			body.insertBefore(row, body.firstChild);
		}
	}
	/**
	 * Collects or sends bounded client telemetry through the push helper.
	 */
	function push(event){
		if(!event || sent>=maxEvents){return;}
		event.timestamp=Date.now();
		queue.push(event);
		renderLiveBrowserEvent(event);
		if(queue.length>=8){flush();}
	}
	/**
	 * Collects or sends bounded client telemetry through the flush helper.
	 */
	function flush(){
		if(!queue.length){return;}
		var batch=queue.splice(0, 40);
		sent+=batch.length;
		var body=JSON.stringify({snapshot_id:cfg.snapshotId, token:cfg.token, events:batch});
		/**
		 * Supports Flightdeck debugbar browser behavior through the beacon helper.
		 */
		function beacon(){
			if(!navigator.sendBeacon){return false;}
			try{
				return navigator.sendBeacon(cfg.endpoint, new Blob([body], {type:"application/json"}));
			}catch(beaconError){}
			return false;
		}
		if(rawFetch && document.visibilityState!=="hidden"){
			try{
				rawFetch(cfg.endpoint, {method:"POST", credentials:"same-origin", keepalive:true, headers:{"Content-Type":"application/json"}, body:body}).catch(function(){beacon();});
				return;
			}catch(fetchError){}
		}
		if(beacon()){
			return;
		}
		try{
			(rawFetch || fetch)(cfg.endpoint, {method:"POST", credentials:"same-origin", keepalive:true, headers:{"Content-Type":"application/json"}, body:body}).catch(function(){});
		}catch(fetchError){}
	}
	/**
	 * Supports Flightdeck debugbar browser behavior through the production replay helper.
	 */
	function productionReplay(){
		if(replayStarted || !cfg.productionReplayEnabled || !cfg.productionReplayToken || !rawFetch){return;}
		if(window.location && window.location.pathname && window.location.pathname.indexOf("/dataphyre")===0){return;}
		replayStarted=true;
		var started=perfNow();
		var replayMethod=cfg.productionReplayMethod==="HEAD" ? "HEAD" : "GET";
		rawFetch(window.location.href, {
			method:replayMethod,
			credentials:"same-origin",
			cache:"no-store",
			headers:{
				"X-Dataphyre-Flightdeck-Replay":"1",
				"X-Dataphyre-Flightdeck-Replay-Token":cfg.productionReplayToken,
				"X-Dataphyre-Flightdeck-Replay-Client":cfg.snapshotId
			}
		}).then(function(response){
			var duration=perfNow()-started;
			var status=response && typeof response.status==="number" ? response.status : 0;
			var verified="";
			try{verified=response.headers.get("X-Dataphyre-Replay") || "";}catch(headerError){verified="";}
			var readBody=response && response.text ? response.text() : Promise.resolve("");
			return readBody.catch(function(){return "";}).then(function(body){
				var serverDuration=headerNumber(response.headers, "X-Dataphyre-Replay-Duration-Ms");
				var memoryMb=headerNumber(response.headers, "X-Dataphyre-Replay-Memory-Mb");
				var peakMb=headerNumber(response.headers, "X-Dataphyre-Replay-Peak-Mb");
				var debugOverheadMb=headerNumber(response.headers, "X-Dataphyre-Replay-Debug-Overhead-Mb");
				var memoryMode="";
				try{memoryMode=response.headers.get("X-Dataphyre-Replay-Memory-Mode") || "";}catch(memoryModeError){memoryMode="";}
				var writeBlocks=headerNumber(response.headers, "X-Dataphyre-Replay-Write-Blocks");
				var production=headerNumber(response.headers, "X-Dataphyre-Replay-Production");
				var readonly=headerNumber(response.headers, "X-Dataphyre-Replay-Readonly");
				var bodyBytes=0;
				try{
					var lengthHeader=response.headers.get("Content-Length");
					bodyBytes=Number(lengthHeader || 0);
				}catch(lengthError){bodyBytes=0;}
				if(!bodyBytes && body){
					try{bodyBytes=window.Blob ? (new Blob([body])).size : body.length;}catch(blobError){bodyBytes=body.length;}
				}
				push({
					type:"production_replay",
					level:(status>=400 || !verified) ? "warning" : "info",
					method:replayMethod,
					url:clip(window.location.href, 520),
					response_status:status,
					duration_ms:duration,
					start_time_ms:eventStart(started),
					server_duration_ms:serverDuration,
					replay_responded:1,
					replay_verified:verified==="1" ? 1 : 0,
					replay_production:production ? 1 : 0,
					replay_readonly:readonly ? 1 : 0,
					replay_memory_mb:memoryMb,
					replay_peak_mb:peakMb,
					replay_debug_overhead_mb:debugOverheadMb,
					replay_memory_mode:memoryMode,
					replay_body_bytes:bodyBytes,
					replay_write_blocks:writeBlocks,
					message:verified==="1" ? "Production replay completed" : "Production replay returned HTTP without Dataphyre metrics"
				});
				flush();
			});
		}, function(error){
			push({type:"production_replay", level:"error", method:replayMethod, url:clip(window.location.href, 520), replay_responded:0, duration_ms:perfNow()-started, start_time_ms:eventStart(started), message:clip(error && error.message ? error.message : "Production replay failed before an HTTP response", 360), stack:clip(error && error.stack ? error.stack : "", 1600)});
			flush();
		});
	}
	if(window.fetch){
		var originalFetch=window.fetch;
		window.fetch=function(input, init){
			var url=requestUrl(input);
			var method=requestMethod(input, init);
			var started=perfNow();
			return originalFetch.apply(this, arguments).then(function(response){
				var duration=perfNow()-started;
				var status=response && typeof response.status==="number" ? response.status : 0;
				var type=requestEventType(status, duration);
				if(type && !ignoredUrl(url)){
					push({type:type, level:status>=400 ? "error" : "warning", method:method, url:clip(url, 520), response_status:status, duration_ms:duration, start_time_ms:eventStart(started), message:requestMessage(status)});
				}
				return response;
			}, function(error){
				if(!ignoredUrl(url)){
					push({type:"client_fetch_error", level:"error", method:method, url:clip(url, 520), duration_ms:perfNow()-started, start_time_ms:eventStart(started), message:clip(error && error.message ? error.message : "Browser fetch failed", 360), stack:clip(error && error.stack ? error.stack : "", 1600)});
				}
				throw error;
			});
		};
	}
	if(window.XMLHttpRequest && XMLHttpRequest.prototype){
		var originalOpen=XMLHttpRequest.prototype.open;
		var originalSend=XMLHttpRequest.prototype.send;
		XMLHttpRequest.prototype.open=function(method, url){
			this.__dfdRequest={method:String(method || "GET").toUpperCase(), url:String(url || "")};
			return originalOpen.apply(this, arguments);
		};
		XMLHttpRequest.prototype.send=function(){
			var request=this.__dfdRequest || {method:"GET", url:""};
			var started=perfNow();
			try{
				this.addEventListener("loadend", function(){
					if(ignoredUrl(request.url)){return;}
					var duration=perfNow()-started;
					var status=Number(this.status || 0);
					var type=requestEventType(status, duration);
					if(type){
						push({type:type, level:status>=400 ? "error" : "warning", method:request.method, url:clip(request.url, 520), response_status:status, duration_ms:duration, start_time_ms:eventStart(started), message:requestMessage(status)});
					}
				});
				this.addEventListener("error", function(){
					if(!ignoredUrl(request.url)){
						push({type:"client_fetch_error", level:"error", method:request.method, url:clip(request.url, 520), duration_ms:perfNow()-started, start_time_ms:eventStart(started), message:"Browser XHR failed"});
					}
				});
			}catch(xhrHookError){}
			return originalSend.apply(this, arguments);
		};
	}
	window.addEventListener("error", function(event){
		var target=event.target || event.srcElement;
		if(target && target!==window && target.tagName){
			push({type:"resource_error", level:"error", tag:clip(target.tagName.toLowerCase(), 40), rel:clip(target.rel || "", 80), url:clip(assetUrl(target), 520), message:"Resource failed to load"});
			return;
		}
		push({type:"js_error", level:"error", message:clip(event.message || "JavaScript error", 360), source:clip(event.filename || "", 520), line:event.lineno || 0, column:event.colno || 0, stack:clip(event.error && event.error.stack ? event.error.stack : "", 1600)});
	}, true);
	window.addEventListener("unhandledrejection", function(event){
		var reason=event.reason;
		push({type:"unhandled_rejection", level:"error", message:clip(reason && reason.message ? reason.message : reason, 360), stack:clip(reason && reason.stack ? reason.stack : "", 1600)});
	});
	/**
	 * Collects or sends bounded client telemetry through the audit stylesheets helper.
	 */
	function auditStylesheets(){
		var links=document.querySelectorAll ? document.querySelectorAll('link[rel~="stylesheet"]') : [];
		for(var i=0;i<links.length;i++){
			var link=links[i];
			if(link.sheet===null){
				push({type:"stylesheet_missing", level:"error", tag:"link", rel:clip(link.rel || "", 80), url:clip(assetUrl(link), 520), message:"Stylesheet was not attached to the CSSOM after load"});
			}
		}
	}
	/**
	 * Collects or sends bounded client telemetry through the audit performance helper.
	 */
	function auditPerformance(){
		if(!window.performance || !performance.getEntriesByType){return;}
		var entries=performance.getEntriesByType("resource") || [];
		var slowCount=0;
		var candidates=[];
		for(var i=0;i<entries.length && i<240;i++){
			var entry=entries[i];
			if(!entry || ignoredUrl(entry.name || "")){continue;}
			var status=entry.responseStatus || 0;
			var duration=entry.duration || 0;
			var transfer=entry.transferSize || 0;
			var decoded=entry.decodedBodySize || 0;
			var encoded=entry.encodedBodySize || 0;
			candidates.push({entry:entry, score:Math.max(duration, transfer / 1024, decoded / 2048)});
			if(duration>=1000 || status>=400){
				slowCount++;
				push({type:"slow_resource", level:status>=400 ? "error" : "warning", url:clip(entry.name || "", 520), initiator_type:clip(entry.initiatorType || "", 60), message:status>=400 ? "Browser resource returned an error status" : "Browser resource loaded slowly", duration_ms:duration, start_time_ms:Math.max(0, entry.startTime || 0), transfer_size:entry.transferSize || 0, decoded_size:entry.decodedBodySize || 0, response_status:status});
			}
		}
		candidates.sort(function(a, b){return b.score-a.score;});
		for(var j=0;j<candidates.length && j<resourceTimingLimit;j++){
			var resource=candidates[j].entry;
			push({
				type:"resource_timing",
				level:"info",
				url:clip(resource.name || "", 520),
				initiator_type:clip(resource.initiatorType || "", 60),
				message:"Browser resource timing",
				duration_ms:resource.duration || 0,
				start_time_ms:Math.max(0, resource.startTime || 0),
				transfer_size:resource.transferSize || 0,
				encoded_size:resource.encodedBodySize || 0,
				decoded_size:resource.decodedBodySize || 0,
				response_status:resource.responseStatus || 0,
				next_hop_protocol:clip(resource.nextHopProtocol || "", 40),
				render_blocking_status:clip(resource.renderBlockingStatus || "", 40)
			});
		}
		return slowCount;
	}
	/**
	 * Collects or sends bounded client telemetry through the audit navigation helper.
	 */
	function auditNavigation(slowCount){
		if(!window.performance){return;}
		var nav=null;
		if(performance.getEntriesByType){
			var navs=performance.getEntriesByType("navigation") || [];
			nav=navs[0] || null;
		}
		var event={type:"page_performance", level:"info", message:"Browser navigation timing", slow_resource_count:slowCount || 0};
		if(nav){
			event.first_byte_ms=Math.max(0, nav.responseStart || 0);
			event.dom_content_loaded_ms=Math.max(0, nav.domContentLoadedEventEnd || nav.domContentLoadedEventStart || 0);
			event.load_ms=Math.max(0, nav.loadEventEnd || nav.duration || 0);
			event.transfer_size=nav.transferSize || 0;
			event.decoded_size=nav.decodedBodySize || 0;
			event.resource_count=performance.getEntriesByType ? (performance.getEntriesByType("resource") || []).length : 0;
		}else if(performance.timing){
			var timing=performance.timing;
			var start=timing.navigationStart || 0;
			if(start>0){
				event.first_byte_ms=Math.max(0, (timing.responseStart || 0)-start);
				event.dom_content_loaded_ms=Math.max(0, (timing.domContentLoadedEventEnd || timing.domContentLoadedEventStart || 0)-start);
				event.load_ms=Math.max(0, (timing.loadEventEnd || 0)-start);
				event.resource_count=performance.getEntriesByType ? (performance.getEntriesByType("resource") || []).length : 0;
			}
		}
		if(event.load_ms || event.dom_content_loaded_ms || event.resource_count){
			push(event);
		}
	}
	/**
	 * Collects or sends bounded client telemetry through the audit helper.
	 */
	function audit(){
		auditStylesheets();
		var slowCount=auditPerformance() || 0;
		auditNavigation(slowCount);
		productionReplay();
		flush();
	}
	if(document.readyState==="complete"){setTimeout(audit, 50);}
	else{window.addEventListener("load", function(){setTimeout(audit, 120);}, {once:true});}
	window.addEventListener("pagehide", flush);
})();
JS;
		return '<script'.$nonce.'>'.str_replace('__CFG__', $config, $script).'</script>';
	}

}
