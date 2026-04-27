<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
if(dataphyre\datadoc::logged_in()!==true){
	require_once(__DIR__."/login.php");
	exit();
}

ini_set('memory_limit', '512M');

?>
<style>
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
</style>

<div class="panel panel-default" id="mainAccordion">
	<div class="panel-body">
		<?php
		$projects = sql_select(
			$S='*',
			$L='datadoc.projects',
			$P='ORDER BY title, name',
			$V=[],
			$F=true
		);
		if(!is_array($projects)){
			$projects=[];
		}

		foreach ($projects as $project) {
			$project_panel_id=$project['id'] ?? md5($project['name']);
			$is_active = (\dataphyre\routing::$bindings['project'] ?? '') === $project['name'];
			$panel_heading_active = $is_active ? 'show' : '';
			$aria_expanded = $is_active ? 'true' : 'false';
			$collapse_show = $is_active ? 'show' : '';
			$project_route=rawurlencode($project['name']);
			$manual_root_id='collapseManual'.substr(hash('sha256', $project['name'].'|manual|root'), 0, 16);
			$dynamic_root_id='collapseDynamic'.substr(hash('sha256', $project['name'].'|dynamic|root'), 0, 16);
		?>
			<div class="panel-heading <?= $panel_heading_active ?>" role="tab" id="heading_dynadoc<?= $project_panel_id ?>">
				<a class="collapsed" style="color:black;" role="button" data-toggle="collapse" data-parent="#mainAccordion" href="#collapse_dynadoc<?= $project_panel_id ?>" aria-expanded="<?= $aria_expanded ?>" aria-controls="collapse_dynadoc<?= $project_panel_id ?>">
					<?= htmlspecialchars((($project['title'] ?? '') ?: $project['name'])) ?>
				</a>
			</div>
			<div id="collapse_dynadoc<?= $project_panel_id ?>" class="panel-collapse collapse <?= $collapse_show ?>" role="tabpanel" aria-labelledby="heading_dynadoc<?= $project_panel_id ?>">
				<div class="panel-body pl-3">
					<div class="accordion" id="dataAccordion_dynadoc<?= $project_panel_id ?>">
						<a href="<?= rtrim(\dataphyre\core::url_self(), '/') ?>/dataphyre/datadoc/<?= $project_route ?>" style="color:black;"><i class="far fa-tachometer"></i> Project dashboard</a>
						<a href="<?= rtrim(\dataphyre\core::url_self(), '/') ?>/dataphyre/datadoc/<?= $project_route ?>/settings" style="color:black;"><i class="far fa-cogs"></i> Project settings</a>
						<div class="main-menu">
							<div class="menu-item">
								<a class="collapsed datadoc-menu-toggle" style="color:black;" role="button" data-toggle="collapse" href="#<?= $manual_root_id ?>" aria-expanded="false" data-datadoc-project="<?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?>" data-datadoc-kind="manual" data-datadoc-path="[]" data-datadoc-depth="1">
									<span style="color:black; font-weight:bold;"><i class="far fa-book"></i> Manual documentation</span>
								</a>
								<div id="<?= $manual_root_id ?>" class="panel-collapse collapse datadoc-lazy-branch" role="tabpanel" data-datadoc-loaded="0"></div>
							</div>
							<div class="menu-item">
								<a class="collapsed datadoc-menu-toggle" style="color:black;" role="button" data-toggle="collapse" href="#<?= $dynamic_root_id ?>" aria-expanded="false" data-datadoc-project="<?= htmlspecialchars($project['name'], ENT_QUOTES, 'UTF-8') ?>" data-datadoc-kind="dynamic" data-datadoc-path="[]" data-datadoc-depth="1">
									<span style="color:black; font-weight:bold;"><i class="far fa-robot"></i> Dynamic code documentation</span>
								</a>
								<div id="<?= $dynamic_root_id ?>" class="panel-collapse collapse datadoc-lazy-branch" role="tabpanel" data-datadoc-loaded="0"></div>
							</div>
						</div>
					</div>
				</div>
			</div>
		<?php } ?>
	</div>
</div>


<script>
$('.panel-collapse').on('show.bs.collapse', function() {
	$(this).siblings('.panel-heading').addClass('active');
});
$('.panel-collapse').on('hide.bs.collapse', function(e) {
	e.target.previousElementSibling.classList.remove('active');
});
const headerNav = document.querySelector(".navigation-col");
const menuBtn = document.querySelector(".header-menu");
menuBtn.addEventListener("click", function() {
	headerNav.className = this.children[0].checked ? "col navigation-col active bg-secondary" : "col navigation-col bg-secondary";
})
window.onscroll = function() {
	if (headerNav.classList.contains("active")) {
		headerNav.classList.remove("active");
		menuBtn.children[0].checked = false
	}
};

const datadocMenuEndpoint = "<?=rtrim(\dataphyre\core::url_self(), '/')?>/dataphyre/datadoc/dynadoc_menu_processor";

$(document).on('show.bs.collapse', '.datadoc-lazy-branch', function() {
	const container = this;
	if (container.dataset.datadocLoaded === '1' || container.dataset.datadocLoading === '1') {
		return;
	}
	const trigger = document.querySelector('[href="#' + container.id + '"][data-datadoc-kind]');
	if (!trigger) {
		return;
	}
	container.dataset.datadocLoading = '1';
	const depth = parseInt(trigger.dataset.datadocDepth || '1', 10);
	container.innerHTML = '<div class="menu-item" style="padding-left: ' + (depth * 8) + 'px;"><span style="color:#777;">Loading...</span></div>';
	$.get(datadocMenuEndpoint, {
		project: trigger.dataset.datadocProject || '',
		kind: trigger.dataset.datadocKind || 'dynamic',
		path: trigger.dataset.datadocPath || '[]'
	}).done(function(html) {
		container.innerHTML = html;
		container.dataset.datadocLoaded = '1';
	}).fail(function() {
		container.innerHTML = '<div class="menu-item" style="padding-left: ' + (depth * 8) + 'px;"><span style="color:#b94a48;">Unable to load menu.</span></div>';
	}).always(function() {
		delete container.dataset.datadocLoading;
	});
});
</script>
