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

require_once(__DIR__."/header.php");

$project=\dataphyre\datadoc::get_project(\dataphyre\routing::$bindings['project'] ?? '');
if($project===null){
	exit('Project not found.');
}

$manual_docs_root=ROOTPATH['dataphyre'].'doc/'.$project['name'].'/manudocs/';
?>
<div class="<?=adapt(["dark"=>"bg-secondary"]);?>">
	<div class="row main-row">
		<div class="col p-0 navigation-col">
			<div class="wrapper center-block h-100">
				<div class="panel-group h-100 py-3" id="accordion" role="tablist" aria-multiselectable="true">
					<?php require(__DIR__."/left_sidebar.php");?>
				</div>
			</div>
		</div>
		<div class="col col-md-6 col-lg-7 col-xl-8 pt-3 pb-5">
			<?=adapt(["dark"=>"<style>p,li,h1,h2,h3,h4,h5,h6{color:white !important;}</style>"]);?>
			<h2>Project settings</h2>
			<div class="card p-3 mt-3">
				<p><b>Project:</b> <?=htmlspecialchars((($project['title'] ?? '') ?: $project['name']));?></p>
				<p><b>Filesystem path:</b> <?=htmlspecialchars($project['path'] ?? '');?></p>
				<p><b>Manual docs root:</b> <?=htmlspecialchars($manual_docs_root);?></p>
				<p><b>Project key:</b> <?=htmlspecialchars($project['name']);?></p>
			</div>
		</div>
	</div>
</div>
<?php
require_once(__DIR__."/footer.php");
