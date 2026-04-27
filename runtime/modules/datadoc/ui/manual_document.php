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

$document_path=\dataphyre\datadoc::normalize_manual_path(\dataphyre\routing::$bindings['documentid'] ?? '');
$document_data=$document_path!=='' ? \dataphyre\datadoc::get_manudoc($project['name'], $document_path) : null;
$document_title=is_array($document_data)
	? ($document_data['title'] ?? $document_data['titles'] ?? (basename($document_path) ?: 'Manual document'))
	: (basename($document_path) ?: 'Manual document');
$document_contents=is_array($document_data)
	? ($document_data['contents'] ?? $document_data['content'] ?? $document_data['body'] ?? $document_data['html'] ?? '')
	: '';
if(is_array($document_contents)){
	$document_contents=$document_contents['html'] ?? $document_contents['content'] ?? nl2br(htmlspecialchars(json_encode($document_contents, JSON_PRETTY_PRINT)));
}
$sections=[];
if(is_string($document_contents) && $document_contents!==''){
	$exploded=[]; 
	preg_match_all('/<section id=(.*?)<\/section>/s', $document_contents, $exploded);
	foreach($exploded as $exploded_group){
		foreach($exploded_group as $section){
			if(str_contains($section, '>')){
				$exploded2=explode('>', $section);
				$section_id=str_replace(array("'",'"', '<section id=','</section>'), '', $exploded2[0]);
				$section_name=$exploded2[1];
				$sections[$section_id]=$section_name;
			}
		}
	}
}
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
			<style>section{visibility:hidden;}</style>
			<h1><?=htmlspecialchars($document_title);?></h1>
			<?php
			if($document_data===null){
				echo '<div class="alert alert-warning">Manual document not found.</div>';
			}
			else
			{
				echo is_string($document_contents) ? $document_contents : '';
			}
			?>
		</div>
		<div class="col-md right-col">
			<div class="position-sticky">
				<?php if(!empty($sections)){ ?>
				<h5 class="py-3 <?=adapt(["dark"=>"text-white"]);?>">On this page</h5>
				<div class="ml-2 pb-3">
					<?php
						foreach($sections as $id=>$name){
							echo'<a href="javascript:void(0);" onclick="$(\'html, body\').animate({scrollTop: $(\'#'.$id.'\').offset().top}, 500);" class="'.adapt(["light"=>"text-body","dark"=>"text-white"]).'">'.$name.'</a><br>';
						}
					?>
				</div>
				<?php } ?>
			</div>
		</div>
	</div>
</div>
<?php
require_once(__DIR__."/footer.php");
