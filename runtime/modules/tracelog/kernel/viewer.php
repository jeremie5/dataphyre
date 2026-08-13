<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/assets_support.php';

/** Formats a byte count for the Tracelog viewer. */
function convert_storage(mixed $size): mixed {
	if(!is_numeric($size)){
		return $size;
	}
	$bytes=(float)$size;
	if($bytes<=0){
		return '0 b';
	}
	$units=['b','kb','mb','gb','tb','pb'];
	$index=min(count($units)-1, (int)floor(log($bytes, 1024)));
	return round($bytes/pow(1024, $index), 2).' '.$units[$index];
}

/** Iterates project files while excluding volatile cache and log directories. */
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
		$portable=str_replace('\\', '/', $path);
		if(str_contains($portable, '/logs/') || str_contains($portable, '/cache/')){
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

/** Counts project PHP source lines with an explicit cache and file inventory seam. */
function lines_of_code(?array &$state=null, ?iterable $files=null, ?string $root=null): int {
	if($state===null){
		$state=&$_SESSION;
	}
	if(isset($state['tracelog_sloc'])){
		return (int)$state['tracelog_sloc'];
	}
	$root ??=defined('ROOTPATH') && is_array(ROOTPATH) ? (string)(ROOTPATH['common_root'] ?? '') : '';
	$files ??=project_files($root, 'php');
	$lines=0;
	foreach($files as $file){
		$content=@file((string)$file);
		if(is_array($content)){
			$lines+=count($content);
		}
	}
	$state['tracelog_sloc']=$lines;
	return $lines;
}

/** Calculates total project file size with an explicit cache and inventory seam. */
function code_size(?array &$state=null, ?iterable $files=null, ?string $root=null): mixed {
	if($state===null){
		$state=&$_SESSION;
	}
	if(isset($state['tracelog_code_size'])){
		return $state['tracelog_code_size'];
	}
	$root ??=defined('ROOTPATH') && is_array(ROOTPATH) ? (string)(ROOTPATH['common_root'] ?? '') : '';
	$files ??=project_files($root);
	$bytes=0;
	foreach($files as $file){
		$size=@filesize((string)$file);
		if($size!==false){
			$bytes+=$size;
		}
	}
	$state['tracelog_code_size']=convert_storage($bytes);
	return $state['tracelog_code_size'];
}

/** Renders and consumes a normalized Tracelog viewer snapshot. */
final class dataphyre_tracelog_viewer_page {
	/** @param array<string,mixed> $runtime */
	public static function bootstrap(?bool $dispatch=null, array $runtime=[]): string {
		$dispatch ??=!defined('DATAPHYRE_TRACELOG_VIEWER_NO_DISPATCH');
		if(!$dispatch){
			return '';
		}
		$usesGlobalSession=!is_array($runtime['session'] ?? null);
		$session=$usesGlobalSession ? ($_SESSION ?? []) : $runtime['session'];
		$html=self::render($session, $runtime);
		if($usesGlobalSession){
			unset($_SESSION['tracelog'], $_SESSION['tracelog_plotting']);
		}
		return $html;
	}

	/** @param array<string,mixed> $session @param array<string,mixed> $runtime */
	public static function render(array $session, array $runtime=[]): string {
		$jit=is_array($runtime['jit'] ?? null) ? $runtime['jit'] : [];
		$load=is_array($runtime['load_average'] ?? null) ? $runtime['load_average'] : (function_exists('sys_getloadavg') ? (sys_getloadavg() ?: []) : []);
		$memoryOverhead=strlen((string)($session['tracelog'] ?? ''))+(int)($session['runtime_memory_used'] ?? 0);
		$state=$session;
		$root=(string)($runtime['project_root'] ?? (defined('ROOTPATH') && is_array(ROOTPATH) ? (ROOTPATH['common_root'] ?? '') : ''));
		$sourceFiles=$runtime['source_files'] ?? null;
		$allFiles=$runtime['all_files'] ?? null;
		$sloc=lines_of_code($state, is_iterable($sourceFiles) ? $sourceFiles : null, $root);
		$size=code_size($state, is_iterable($allFiles) ? $allFiles : null, $root);
		$trace=(string)($session['tracelog'] ?? '');
		$plotLink=!empty($session['tracelog_plotting']) ? '<a href="/dataphyre/tracelog/plotter">View plotter</a>' : '';
		$metric=static fn(mixed $value): string=>htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
		$html='<link rel="stylesheet" href="'.$metric(dataphyre_tracelog_asset_url('viewer.css')).'">'
			.'<h1>Dataphyre: Tracelog Viewer</h1>'
			.'<span>CPU Usage: '.$metric(round((float)($load[0] ?? 0), 3)).'%</span><br>'
			.'<span>PHP: '.$metric($runtime['php_version'] ?? phpversion()).'</span><br>'
			.'<span>Project execution: '.$metric(number_format((float)($session['exec_time'] ?? 0), 3)).'s</span><br>'
			.'<span>Project PHP SLOC: '.$metric(number_format($sloc)).'</span><br>'
			.'<span>Project memory: '.$metric(convert_storage((int)($session['memory_used'] ?? 0)-$memoryOverhead)).' out of dynamic allocation of '.$metric(convert_storage((int)($session['memory_used_peak'] ?? 0)-$memoryOverhead)).'</span><br>'
			.'<span>PHP VM+Tracelog overhead: '.$metric(convert_storage($memoryOverhead)).'</span><br>'
			.'<span>Project size: '.$metric($size).'</span><br>'
			.'<span>Loaded user functions: '.$metric($session['defined_user_function_count'] ?? 0).'</span><br>'
			.'<span>Included files: '.$metric($session['included_files'] ?? 0).'</span><br>'
			.'<span>Dataphyre SQL session cache: '.$metric(convert_storage(strlen(serialize($session['db_cache'] ?? [])))).'</span><br>'
			.'<span>JIT Buffer Size: '.$metric(isset($jit['buffer_size']) ? convert_storage($jit['buffer_size']) : 'N/A').'</span><br>'
			.'<span>JIT Enabled: '.(!empty($jit['enabled']) ? 'Yes' : 'No').'</span><br>'
			.'<span>JIT Optimization Level: '.$metric($jit['opt_level'] ?? 'N/A').'</span><br>'.$plotLink.'<hr>';
		return $html.($trace!=='' ? $trace : '<br><br><br>Load a page and refresh to show tracelog data.');
	}
}

echo dataphyre_tracelog_viewer_page::bootstrap();
