<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */

require_once(ROOTPATH['common_dataphyre']."modules/core/core.main.php");

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

function lines_of_code(){
	if(isset($_SESSION['tracelog_sloc'])) return $_SESSION['tracelog_sloc'];
   $command="find ".ROOTPATH['common_root']." -type f -name '*.php' ! -path '*/logs/*' ! -path '*/cache/*' -print0 | xargs -0 cat | wc -l";
    return $_SESSION['tracelog_sloc']=shell_exec($command);
}

function code_size(){
	if(isset($_SESSION['tracelog_code_size'])) return $_SESSION['tracelog_code_size'];
	$code_size=shell_exec("du -d 0 -h ".ROOTPATH['common_root']);
	return $_SESSION['tracelog_code_size']=str_replace(ROOTPATH['common_root'],'',$code_size);
}

$jit_info='';
if(function_exists('opcache_get_status')){
    $opcache_status=opcache_get_status();
    if(isset($opcache_status['jit'])){
        $jit_info=$opcache_status['jit'];
    }
}
?>
<style>
body {
	background-color: black;
}
b, i, body {
	color: white;
}
</style>
<h1>Dataphyre: Tracelog Viewer</h1>
<span style="font-size: 15px;">CPU Usage: <?=round(sys_getloadavg()[0], 3); ?>%</span><br>
<span style="font-size: 15px;">PHP: <?=phpversion(); ?></span><br>
<span style="font-size: 15px;">Project execution: <?=number_format($_SESSION['exec_time'], 3); ?>s</span><br>
<span style="font-size: 15px;">Project PHP SLOC: <?=number_format(lines_of_code(), 0, '.', ","); ?></span><br>
<span style="font-size: 15px;">Project memory: <?=convert_storage($_SESSION['memory_used']-$_SESSION['runtime_memory_used']); ?> out of dynamic allocation of <?=convert_storage($_SESSION['memory_used_peak']-$_SESSION['runtime_memory_used']); ?></span><br>
<span style="font-size: 15px;">Runtime overhead: <?=convert_storage($_SESSION['runtime_memory_used']); ?></span><br>
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