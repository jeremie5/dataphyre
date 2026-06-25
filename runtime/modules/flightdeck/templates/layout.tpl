<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Dataphyre Flightdeck - {{title}}</title>
	<link rel="icon" href="data:,">
	<style>{{slot "css"}}{{endslot}}</style>
	{{slot "head"}}{{endslot}}
</head>
<body>
	<aside class="fd-sidebar">
		<a class="fd-logo" href="/dataphyre">Dataphyre<br><span>Flightdeck</span></a>
		<nav>{{slot "nav"}}{{endslot}}</nav>
		<div class="fd-sidebar-bottom">{{slot "sidebar_bottom"}}{{endslot}}</div>
	</aside>
	<main class="fd-main">
		{{slot "content"}}{{endslot}}
	</main>
</body>
</html>
