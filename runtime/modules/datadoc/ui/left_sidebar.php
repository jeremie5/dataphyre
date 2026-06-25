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
require_once(__DIR__.'/assets_support.php');

?>
<link rel="stylesheet" href="<?=htmlspecialchars(dataphyre_datadoc_ui_asset_url('datadoc-sidebar.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');?>">

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


<script src="<?=htmlspecialchars(dataphyre_datadoc_ui_asset_url('datadoc-sidebar.js'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');?>" data-datadoc-menu-endpoint="<?=htmlspecialchars(rtrim(\dataphyre\core::url_self(), '/').'/dataphyre/datadoc/dynadoc_menu_processor', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');?>" defer></script>
