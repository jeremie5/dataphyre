<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

/**
 * Normalizes and validates a Datadoc UI asset filename.
 *
 * Only known CSS and JavaScript assets are returned; unknown names collapse to an empty string before routing or content lookup.
 */
function dataphyre_datadoc_ui_asset_name(string $asset): string {
	$asset=strtolower(basename(str_replace('\\', '/', trim($asset))));
	return in_array($asset, ['datadoc-ui.css', 'datadoc-sidebar.css', 'datadoc-sidebar.js', 'datadoc-highlighter.css', 'datadoc-highlighter.js'], true) ? $asset : '';
}

/**
 * Builds the public Datadoc asset URL for a validated asset.
 *
 * Adds a content-hash version query string so Flightdeck can cache static Datadoc UI assets safely.
 */
function dataphyre_datadoc_ui_asset_url(string $asset): string {
	$asset=dataphyre_datadoc_ui_asset_name($asset);
	if($asset===''){
		return '';
	}
	return '/dataphyre/datadoc/assets/'.$asset.'?v='.dataphyre_datadoc_ui_asset_version($asset);
}

/**
 * Returns the short content hash used to version a Datadoc UI asset.
 */
function dataphyre_datadoc_ui_asset_version(string $asset): string {
	$content=dataphyre_datadoc_ui_asset_content($asset);
	if($content===null){
		return 'missing';
	}
	return substr(hash('sha256', $content['content']), 0, 16);
}

/**
 * Returns content and MIME type for a Datadoc UI asset.
 *
 * @return array{content_type:string,content:string}|null
 */
function dataphyre_datadoc_ui_asset_content(string $asset): ?array {
	$asset=dataphyre_datadoc_ui_asset_name($asset);
	return match($asset){
		'datadoc-ui.css'=>[
			'content_type'=>'text/css; charset=UTF-8',
			'content'=>dataphyre_datadoc_ui_base_css(),
		],
		'datadoc-sidebar.css'=>[
			'content_type'=>'text/css; charset=UTF-8',
			'content'=>dataphyre_datadoc_ui_sidebar_css(),
		],
		'datadoc-sidebar.js'=>[
			'content_type'=>'application/javascript; charset=UTF-8',
			'content'=>dataphyre_datadoc_ui_sidebar_js(),
		],
		'datadoc-highlighter.css'=>[
			'content_type'=>'text/css; charset=UTF-8',
			'content'=>dataphyre_datadoc_highlighter_css(),
		],
		'datadoc-highlighter.js'=>[
			'content_type'=>'application/javascript; charset=UTF-8',
			'content'=>dataphyre_datadoc_highlighter_js(),
		],
		default=>null,
	};
}

/**
 * Returns the base CSS used by Datadoc Flightdeck surfaces.
 */
function dataphyre_datadoc_ui_base_css(): string {
	return <<<'CSS'
.phyro-bold {
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  font-weight: 700;
  font-style: normal;
  line-height: 1.15;
  -webkit-font-smoothing: antialiased;
}
body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f6f8fb;color:#172033}
a{color:#1b65c9;text-decoration:none}a:hover{text-decoration:underline}
.bg-dark{background:#1f2633}.bg-secondary{background:#eef2f7}.text-white{color:#fff}.text-body{color:#172033}
.container{max-width:1140px;margin:0 auto;padding:0 16px}.row{display:flex;flex-wrap:wrap;margin:0 -12px}.col-md-12,.col-12{box-sizing:border-box;width:100%;padding:0 12px}
.navbar{display:flex;align-items:center;justify-content:space-between;padding:12px 0}.navbar-brand{text-decoration:none}.navbar-nav{list-style:none;margin:0;padding:0}
.d-flex{display:flex}.justify-content-between{justify-content:space-between}.align-items-center{align-items:center}.position-relative{position:relative}.position-absolute{position:absolute}
.px-3{padding-left:16px;padding-right:16px}.py-2{padding-top:8px;padding-bottom:8px}.p-0{padding:0}.pt-1{padding-top:4px}.mt-2{margin-top:8px}.mb-2{margin-bottom:8px}.mt-5{margin-top:48px}.mb-5{margin-bottom:48px}
.btn{display:inline-block;border:0;border-radius:4px;padding:8px 12px;font-weight:600;line-height:1.2;text-decoration:none}.btn-danger{background:#c73636;color:#fff}.btn-secondary{background:#6c7889;color:#fff}.disabled{opacity:.7;pointer-events:none}
.breadcrumb{display:flex;gap:8px;list-style:none;margin:0}.alert{border-radius:6px;padding:12px 14px}.alert-info{background:#e8f2ff;color:#163b68;border:1px solid #bfd9fb}
.search-container input{border:1px solid #c9d3df;border-radius:4px;padding:8px 34px 8px 10px}.search-container i{right:10px;top:0;color:#607086}.w-100{width:100%}.h-100{height:100%}
.header-menu{display:none}.dark-mode{background:#172033;color:#fff}
CSS;
}

/**
 * Returns CSS for the Datadoc sidebar and navigation tree.
 */
function dataphyre_datadoc_ui_sidebar_css(): string {
	return <<<'CSS'
.breadcrumb {
	margin: 0;
	background: transparent;
}
.search-container {
	width: 15%;
	border: 1px solid #d5d5d5;
	border-radius: 0.2rem;
	overflow: hidden
}
.search-container input {
	border: none;
	padding: 5px 5px 5px 10px;
	outline: none;
}
.search-container i {
	top: 50%;
	right: 0;
	transform: translateY(-50%);
	color: white;
	background: #343a40;
	width: 35px;
	cursor: pointer;
}
.row.main-row {
	border-top: 1px solid #eef1f2;
	margin: 0;
}
.panel-group {
	border-right: 1px solid #eef1f2;
}
.panel-heading {
	padding: 0;
	border: 0;
}
.panel-heading a,
.panel-body a {
	display: inherit;
	transition: .1s ease;
	padding: 0.5rem 1.25rem;
}
.panel-heading a {
	font-weight: bold;
}
.panel-heading a:hover,
.panel-body a:hover {
	background: rgba(0, 0, 0, 0.067);
	border-radius: 0.2rem;
}
.panel-heading a i {
	font-size: 12px;
	transition: .2s ease;
}
.panel-heading.active a i {
	transform: rotate(90deg);
	vertical-align: middle;
}
.row.main-row .position-sticky {
	top: 0;
}
.col.right-col div a {
	display: inherit;
	padding: 5px 10px;
	transition: .1s ease;
	width: max-content;
}
code {
	color: #c3c3c3!important;
}
.code-highlight {
	display: block;
	overflow-x: auto;
	padding: 1.25rem;
	color: #abb2bf;
	background: #282c34;
	border-radius: 3px;
	font-size: 12px!important;
}
.code-highlight span.string {
	color: #98c379;
}
.code-highlight span.keyword {
	color: #c678dd;
}
.col.right-col div a:hover {
	background: rgba(0, 0, 0, 0.05);
}
.header-menu {
	display: none;
	position: relative;
}
.header-menu input {
	display: block;
	width: 40px;
	height: 32px;
	position: absolute;
	top: -7px;
	left: -5px;
	cursor: pointer;
	opacity: 0;
	z-index: 2;
	-webkit-touch-callout: none;
}
.header-menu span {
	display: block;
	width: 33px;
	height: 4px;
	margin-bottom: 5px;
	position: relative;
	background: #343a40;
	border-radius: 3px;
	z-index: 1;
	transform-origin: 4px 0px;
	transition: transform 0.5s cubic-bezier(0.77, 0.2, 0.05, 1.0), background 0.5s cubic-bezier(0.77, 0.2, 0.05, 1.0), opacity 0.55s ease;
}
.header-menu:hover span {
	background: #343a40;
}
.header-menu span:first-child {
	transform-origin: 0% 0%;
}
.header-menu span:nth-last-child(2) {
	transform-origin: 0% 100%;
}
.header-menu input:checked~span {
	opacity: 1;
	transform: rotate(45deg) translate(-8px, -15px);
}
.header-menu input:checked~span:nth-last-child(2) {
	transform: rotate(-45deg) translate(-4px, 13px);
}
.header-menu input:checked~span:nth-last-child(3) {
	opacity: 0;
	transform: rotate(0deg) scale(0.2, 0.2);
}
@media(max-width:992px) {
	.wrapper {
		width: 100%;
	}
}
@media only screen and (max-width: 768px) {
	.navigation-col {
		position: fixed;
		left: 0;
		height: 100vh;
		background: white;
		width: 260px;
		padding: 40px;
		transform-origin: 0% 0%;
		transform: translate(-100%, 0);
		transition: transform 0.5s cubic-bezier(0.77, 0.2, 0.05, 1.0);
		z-index: 9;
	}
	.navigation-col.active {
		transform: none;
	}
	.navigation-col .panel-group {
		border: none;
	}
	.header-menu {
		display: initial;
	}
	.row.main-row .right-col,
	.search-container {
		display: none;
	}
}
CSS;
}

/**
 * Returns JavaScript for Datadoc sidebar filtering and branch behavior.
 */
function dataphyre_datadoc_ui_sidebar_js(): string {
	return <<<'JS'
(function(){
	if(window.jQuery){
		jQuery(".panel-collapse").on("show.bs.collapse", function(){
			jQuery(this).siblings(".panel-heading").addClass("active");
		});
		jQuery(".panel-collapse").on("hide.bs.collapse", function(event){
			if(event.target && event.target.previousElementSibling){
				event.target.previousElementSibling.classList.remove("active");
			}
		});
	}

	var headerNav=document.querySelector(".navigation-col");
	var menuBtn=document.querySelector(".header-menu");
	if(menuBtn && headerNav){
		menuBtn.addEventListener("click", function(){
			headerNav.className=this.children[0].checked ? "col navigation-col active bg-secondary" : "col navigation-col bg-secondary";
		});
		window.addEventListener("scroll", function(){
			if(headerNav.classList.contains("active")){
				headerNav.classList.remove("active");
				if(menuBtn.children[0]){
					menuBtn.children[0].checked=false;
				}
			}
		});
	}

	var script=document.currentScript;
	var datadocMenuEndpoint=script ? (script.getAttribute("data-datadoc-menu-endpoint") || "") : "";
	if(!window.jQuery || datadocMenuEndpoint===""){
		return;
	}
	jQuery(document).on("show.bs.collapse", ".datadoc-lazy-branch", function(){
		var container=this;
		if(container.dataset.datadocLoaded==="1" || container.dataset.datadocLoading==="1"){
			return;
		}
		var trigger=document.querySelector('[href="#' + container.id + '"][data-datadoc-kind]');
		if(!trigger){
			return;
		}
		container.dataset.datadocLoading="1";
		var depth=parseInt(trigger.dataset.datadocDepth || "1", 10);
		container.innerHTML='<div class="menu-item" style="padding-left: ' + (depth * 8) + 'px;"><span style="color:#777;">Loading...</span></div>';
		jQuery.get(datadocMenuEndpoint, {
			project: trigger.dataset.datadocProject || "",
			kind: trigger.dataset.datadocKind || "dynamic",
			path: trigger.dataset.datadocPath || "[]"
		}).done(function(html){
			container.innerHTML=html;
			container.dataset.datadocLoaded="1";
		}).fail(function(){
			container.innerHTML='<div class="menu-item" style="padding-left: ' + (depth * 8) + 'px;"><span style="color:#b94a48;">Unable to load menu.</span></div>';
		}).always(function(){
			delete container.dataset.datadocLoading;
		});
	});
})();
JS;
}

/**
 * Returns CSS for Datadoc PHP token highlighting.
 */
function dataphyre_datadoc_highlighter_css(): string {
	return <<<'CSS'
.dp-datadoc-highlight .php_token_string_doublequote{color:#ffff00!important}
.dp-datadoc-highlight .php_token_string_singlequote{color:#ffff80!important}
.dp-datadoc-highlight .php_token_keywords{color:#00ffff!important}
.dp-datadoc-highlight .php_token_builtin_function{color:#00ffff!important}
.dp-datadoc-highlight .php_token_user_function{color:#fff!important}
.dp-datadoc-highlight .php_token_constant{color:#00ffff!important}
.dp-datadoc-highlight .php_token_other{color:#fff!important}
.dp-datadoc-highlight .php_token_variable{color:#ff8000!important}
.dp-datadoc-highlight .php_token_integer{color:#f0f!important}
.dp-datadoc-highlight .php_token_comment{color:#00ff00!important;font-style:italic}
.dp-datadoc-highlight .php_token_tag{color:#9c9!important}
.dp-datadoc-highlight .php_token_operator{color:#c0c0c0!important}
.dp-datadoc-highlight .php_token_magic_constant{color:#00ffff!important}
.dp-datadoc-highlight .line-number{color:#aaa;margin-right:10px!important}
CSS;
}

/**
 * Returns JavaScript for Datadoc highlighted code containers.
 */
function dataphyre_datadoc_highlighter_js(): string {
	return <<<'JS'
(function(){
	/**
	 * Datadoc helper for annotate.
	 *
	 * Part of the Datadoc module contract; keep side effects visible in callers and prefer specific docs when changing this surface.
	 */
	function annotate(container){
		if(!container || container.dataset.lineNumbered==="1"){
			return;
		}
		container.dataset.lineNumbered="1";
		var lineNumber=parseInt(container.getAttribute("data-datadoc-line-start") || "1", 10);
		var highlightLine=parseInt(container.getAttribute("data-datadoc-highlight-line") || "0", 10);
		var highlightOffset=parseInt(container.getAttribute("data-datadoc-highlight-offset") || "-1", 10);
		var highlightClass=(container.getAttribute("data-datadoc-highlight-class") || "").replace(/[^A-Za-z0-9_-]/g, "");
		var content=container.innerHTML.split(/<br\s*\/?>/i);
		while(content.length>0 && content[content.length - 1].trim().length===0){
			content.pop();
		}
		container.innerHTML=content.map(function(line, offset){
			var currentLine=lineNumber;
			var newLine='<span class="line-number">' + currentLine + '</span> ' + line;
			if((highlightLine===currentLine || highlightOffset===offset) && highlightClass!==""){
				newLine='<span class="' + highlightClass + '" data-line="' + currentLine + '">' + newLine + '</span>';
			}
			lineNumber++;
			return newLine;
		}).join("<br>");
	}

	/**
	 * Datadoc helper for annotateAll.
	 *
	 * Part of the Datadoc module contract; keep side effects visible in callers and prefer specific docs when changing this surface.
	 */
	function annotateAll(root){
		var scope=root && root.querySelectorAll ? root : document;
		scope.querySelectorAll("[data-datadoc-code-container]:not([data-line-numbered=\"1\"])").forEach(annotate);
	}

	window.dataphyreDatadocHighlighterAnnotate=annotateAll;
	if(document.readyState==="loading"){
		window.addEventListener("DOMContentLoaded", function(){ annotateAll(document); }, {once:true});
	} else {
		annotateAll(document);
	}
	if(window.MutationObserver){
		new MutationObserver(function(mutations){
			mutations.forEach(function(mutation){
				mutation.addedNodes.forEach(function(node){
					if(node.nodeType!==1){
						return;
					}
					if(node.matches && node.matches("[data-datadoc-code-container]")){
						annotate(node);
					}
					annotateAll(node);
				});
			});
		}).observe(document.documentElement, {childList:true, subtree:true});
	}
})();
JS;
}
