<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
require_once(ROOTPATH['common_dataphyre_runtime']."modules/core/kernel/core.main.php");
$tracelog_assets_support=__DIR__.'/assets_support.php';
if(is_file($tracelog_assets_support)){
	require_once($tracelog_assets_support);
}

/**
 * Formats a byte count for the Tracelog viewer.
 *
 * @param mixed $size Numeric byte count or already-formatted value.
 * @return mixed Human-readable storage size for numeric input, otherwise the original value.
 */
function convert_storage($size){
    if(is_numeric($size)){
        if($size==0){
            return '0 b';
        }
        $unit=array('b','kb','mb','gb','tb','pb');
        $i=floor(log($size, 1024));
        return round($size/pow(1024, $i), 2).' '.$unit[$i];
    }
    return $size;
}

/**
 * Counts project PHP source lines for viewer diagnostics.
 *
 * The count excludes cache/log directories through `project_files()` and is cached in the
 * session to avoid repeated filesystem walks during the same trace session.
 *
 * @return int Total PHP source lines.
 */
function lines_of_code(){
	if(isset($_SESSION['tracelog_sloc'])) return $_SESSION['tracelog_sloc'];
	$lines=0;
	foreach(project_files(ROOTPATH['common_root'], 'php') as $file){
		$handle=@fopen($file, 'rb');
		if($handle===false){
			continue;
		}
		while(!feof($handle)){
			fgets($handle);
			$lines++;
		}
		fclose($handle);
	}
	return $_SESSION['tracelog_sloc']=$lines;
}

/**
 * Calculates total project file size for viewer diagnostics.
 *
 * The formatted result is cached in the session and excludes cache/log directories through
 * `project_files()`.
 *
 * @return mixed cached formatted project size produced from the current project file set.
 */
function code_size(){
	if(isset($_SESSION['tracelog_code_size'])) return $_SESSION['tracelog_code_size'];
	$bytes=0;
	foreach(project_files(ROOTPATH['common_root']) as $file){
		$size=@filesize($file);
		if($size!==false){
			$bytes+=$size;
		}
	}
	return $_SESSION['tracelog_code_size']=convert_storage($bytes);
}

/**
 * Iterates project files while excluding volatile cache and log directories.
 *
 *
 * @param string $root Root directory to scan.
 * @param ?string $extension Optional extension filter without a leading dot.
 * @return iterable<string> File paths that match the filter.
 */
function project_files(string $root, ?string $extension=null): iterable {
	$root=rtrim($root, '/\\');
	if(!is_dir($root)){
		return;
	}
	$iterator=new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach($iterator as $item){
		$path=$item->getPathname();
		if(str_contains($path, DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR) || str_contains($path, DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR)){
			continue;
		}
		if(!$item->isFile()){
			continue;
		}
		if($extension!==null && strtolower($item->getExtension())!==strtolower($extension)){
			continue;
		}
		yield $path;
	}
}

$jit_info='';
if(function_exists('opcache_get_status')){
    $opcache_status=opcache_get_status();
    if(isset($opcache_status['jit'])){
        $jit_info=$opcache_status['jit'];
    }
}

$memory_overhead=strlen($_SESSION['tracelog'])+$_SESSION['runtime_memory_used'];

?>
<link rel="stylesheet" href="<?=htmlspecialchars(dataphyre_tracelog_asset_url('viewer.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');?>">
<h1>Dataphyre: Tracelog Viewer</h1>
<span style="font-size: 15px;">CPU Usage: <?=round(sys_getloadavg()[0], 3); ?>%</span><br>
<span style="font-size: 15px;">PHP: <?=phpversion(); ?></span><br>
<span style="font-size: 15px;">Project execution: <?=number_format($_SESSION['exec_time'], 3); ?>s</span><br>
<span style="font-size: 15px;">Project PHP SLOC: <?=number_format(lines_of_code(), 0, '.', ","); ?></span><br>
<span style="font-size: 15px;">Project memory: <?=convert_storage($_SESSION['memory_used']-$memory_overhead); ?> out of dynamic allocation of <?=convert_storage($_SESSION['memory_used_peak']-$memory_overhead); ?></span><br>
<span style="font-size: 15px;">PHP VM+Tracelog overhead: <?=convert_storage($memory_overhead); ?></span><br>
<span style="font-size: 15px;">Project size: <?=code_size(); ?></span><br>
<span style="font-size: 15px;">Loaded user functions: <?=$_SESSION['defined_user_function_count']; ?></span><br>
<span style="font-size: 15px;">Included files: <?=$_SESSION['included_files']; ?></span><br>
<span style="font-size: 15px;">Dataphyre mod_SQL session cache: <?=convert_storage(strlen(serialize($_SESSION['db_cache']))); ?></span><br>
<span style="font-size: 15px;">JIT Buffer Size: <?=isset($jit_info['buffer_size']) ? convert_storage($jit_info['buffer_size']) : 'N/A'; ?></span><br>
<span style="font-size: 15px;">JIT Enabled: <?=isset($jit_info['enabled']) && $jit_info['enabled'] ? 'Yes' : 'No'; ?></span><br>
<span style="font-size: 15px;">JIT Optimization Level: <?=isset($jit_info['opt_level']) ? $jit_info['opt_level'] : 'N/A'; ?></span><br>
<?php
if(!empty($_SESSION['tracelog_plotting'])){
	echo'<a href="'.dataphyre\core::url_self().'dataphyre/tracelog/plotter">View plotter</a>';
}
?>
<hr>
<?php
if(!empty($_SESSION['tracelog'])){
	echo $_SESSION['tracelog'];
}
else
{
	echo'<br>';
	echo'<br>';
	echo'<br>';
	echo "Load a page and refresh to show tracelog data.";
}
unset($_SESSION['tracelog']);	
unset($_SESSION['tracelog_plotting']);	
