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
if ($project===null) {
	exit('Project not found.');
}
$sections=[];

// Build dynamic conditions
$conditions = ['project = ?'];
$vars = [$project['name']];

foreach (['namespace', 'class', 'type', 'function', 'content'] as $field) {
	if (!empty($_GET[$field])) {
		$conditions[] = "$field LIKE ?";
		$vars[] = $_GET[$field];
	}
}

// Query the data table with fully parameterized WHERE clause
$data_rows = sql_select(
	$S='*',
	$L='dataphyre.datadoc_data',
	$P='WHERE '.implode(' AND ', $conditions),
	$V=$vars,
	$F=true
);

// Fetch first match
$dynadoc_record = is_array($data_rows) && !empty($data_rows)
	? $data_rows[0]
	: null;
if(is_array($dynadoc_record) && !empty($dynadoc_record['file'])){
	$refresh_result=\dataphyre\datadoc::sync_project_file_if_changed((string)$dynadoc_record['file'], (string)$project['name']);
	if(($refresh_result['changed'] ?? false)===true || ($refresh_result['deleted'] ?? false)===true){
		$data_rows=sql_select(
			$S='*',
			$L='dataphyre.datadoc_data',
			$P='WHERE '.implode(' AND ', $conditions),
			$V=$vars,
			$F=true
		);
		$dynadoc_record=is_array($data_rows) && !empty($data_rows)
			? $data_rows[0]
			: null;
	}
}
?>
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
		<style>section{visibility:hidden;}
			
.line-number {
	color: #aaa;
	margin-right: 10px;
}
			
			</style>
			
			<?php
			if($dynadoc_record===null){
				echo '<div class="alert alert-warning">No matching dynamic documentation record was found for this selection.</div>';
			}
			else
			{
			$content_first_line=(string)(strtok((string)$dynadoc_record['content'], "\n") ?: '');
			
			$dynadoc_record['phpdoc_tags']=json_decode((string)($dynadoc_record['phpdoc_tags'] ?? ''),true);
			if(!is_array($dynadoc_record['phpdoc_tags'])){
				$dynadoc_record['phpdoc_tags']=[];
			}
			
			$version_string = '';
			if (!empty($dynadoc_record['phpdoc_tags']['version'])) {
				$version_string = '  <span class="badge bg-secondary" style="color:white">'.$dynadoc_record['phpdoc_tags']['version']."</span>";
			}

			$is_static = false;
			if (str_contains($content_first_line, 'static') === true) {
				$is_static = true;
			}
			$access_level = 'public'; // default to public
			if (str_contains($content_first_line, 'protected') === true) {
				$access_level = 'protected';
			} elseif (str_contains($content_first_line, 'private') === true) {
				$access_level = 'private';
			}
			$static_pill = $is_static ? '<span class="badge bg-info" style="color:white">static</span> ' : '';
			$access_pill = "<span class='badge bg-success' style='color:white'>$access_level</span> ";
			switch ($dynadoc_record['type']) {
				case 'namespace':
					echo "<h2><span class='badge bg-primary' style='color:white'>namespace</span> \\{$dynadoc_record['namespace']}</h2>";
					break;
				case 'class':
					if (!empty($dynadoc_record['namespace'])){
						echo "<h2><span class='badge bg-primary' style='color:white'>Class</span> \\" . $dynadoc_record['namespace'] . "\\" . $dynadoc_record['class'] . "</h2>";
					}
					else
					{
						echo "<h2><span class='badge bg-primary' style='color:white'>Class</span> \\" . $dynadoc_record['class'] . "</h2>";
					}
					break;
				case 'function':
					$separator = ($is_static && !empty($dynadoc_record['class'])) ? '::' : '';
					$namespacePart = !empty($dynadoc_record['namespace']) ? "\\{$dynadoc_record['namespace']}" : '';
					$classPart = !empty($dynadoc_record['class']) ? "\\{$dynadoc_record['class']}" : '';
					$functionPart = $dynadoc_record['function'];
					$namespaceClassConnector = (!empty($namespacePart) && !empty($classPart)) ? '\\' : '';
					$func_display = "{$namespacePart}{$namespaceClassConnector}{$classPart}{$separator}{$functionPart}";
					echo "<h2>{$access_pill}{$static_pill} "
					   . "<span class='badge bg-primary' style='color:white'>function</span> "
					   . "{$func_display}() {$version_string}</h2>";
					break;
				case 'variable':
					echo "<h2><b><span class='badge bg-primary' style='color:white'>variable</span> \${$dynadoc_record['content']}</h2>";
					$dynadoc_record['content']='$'.$dynadoc_record['content'].';'; // Lil hack
					break;
				default:
					// Handle other types if necessary
					break;
			}
			
			if(!empty($dynadoc_record['file'])){
				if(!empty($dynadoc_record['line'])){
					echo'<h5 class="mt-1"><b>File:</b> '.$dynadoc_record['file'].'</h5>';
				}
			}
			
			if(!empty($dynadoc_record['phpdoc_tags']['author'])){
				echo'<h5 class="mt-1"><b>Author:</b> '.$dynadoc_record['phpdoc_tags']['author'].'</h5>';
			}
			
			if(!empty($dynadoc_record['phpdoc_tags']['package'])){
				if(!empty($dynadoc_record['phpdoc_tags']['subpackage'])){
					echo'<h5><b>Package</b> '.trim($dynadoc_record['phpdoc_tags']['package']).', '.$dynadoc_record['phpdoc_tags']['subpackage'].'</h5>';
				}
				else
				{
					echo'<h5><b>Package</b> '.trim($dynadoc_record['phpdoc_tags']['package']).'</h5>';
				}
			}
			
			if(!empty($dynadoc_record['line'])){
				echo'<h5 class="mt-1"><b>Line: </b>'.$dynadoc_record['line'].'</h5>';
			}
			
			if(!empty($dynadoc_record['phpdoc_description'])){
				echo'<div class="mt-3">';
				echo'<h3><b>Description</b></h3>';
				echo'<div class="card p-2">';
				echo '<h4>'.nl2br($dynadoc_record['phpdoc_description']).'</h4>';
				echo'</div>';
				echo'</div>';
			}
			
			if(!empty($dynadoc_record['phpdoc_tags']['example'])){
				echo'<div class="row mt-3">';
				echo'<div class="col-12">';
				echo'<h3><b>Example(s)</b></h3>';
				$code=dataphyre\datadoc\highlighter::highlight_code($dynadoc_record['phpdoc_tags']['example'], "php", []);
				echo dataphyre\datadoc\highlighter::linkify_php($code, $dynadoc_record['project'], $dynadoc_record['namespace'], $dynadoc_record['class'], $dynadoc_record['function']);
				echo'</div>';
				echo'</div>';
			}
			
			if(!empty($dynadoc_record['phpdoc_tags']['warning'])){
				echo'<div class="mt-3">';
				echo'<h3><b>Warning(s)</b></h3>';
				echo'<div class="card p-2">';
				echo '<h4>'.nl2br(trim($dynadoc_record['phpdoc_tags']['warning'])).'</h4>';
				echo'</div>';
				echo'</div>';
			}
			
			if(!empty($dynadoc_record['content'])){
				echo '<div class="row mt-3">';
				echo '<div class="col-12">';
				echo '<h3><b>Source</b></h3>';
				$code=dataphyre\datadoc\highlighter::highlight_code($dynadoc_record['content'], "php", ['show_lines'=>true, 'start_line'=>$dynadoc_record['line']]);
				echo dataphyre\datadoc\highlighter::linkify_php($code, $dynadoc_record['project'], $dynadoc_record['namespace'], $dynadoc_record['class'], $dynadoc_record['function']);
				echo '</div>';
				echo '</div>';
			}
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
