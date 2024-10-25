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


require_once($rootpath['common_dataphyre']."core.php");

function convert_storage($size){
	if(is_numeric($size)){
		$unit=array('b','kb','mb','gb','tb','pb');
		return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
	}
}
function lines_of_code(){
    global $rootpath;
    $command="find ".$rootpath['common_root']." -type f -name '*.php' ! -path '*/logs/*' ! -path '*/cache/*' -exec wc -l {} + | awk '{total += $1} END{print total}'";
    return shell_exec($command);
}

function code_size(){
	global $rootpath;
	$code_size=shell_exec("du -d 0 -h ".$rootpath['common_root']);
	return str_replace($rootpath['common_root'],'',$code_size);
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
<span style="font-size: 15px;">Memory: <?=convert_storage($_SESSION['memory_used']); ?> out of <?=convert_storage($_SESSION['memory_used_peak']); ?></span><br>
<span style="font-size: 15px;">CPU: <?=sys_getloadavg()[0]; ?>%</span><br>
<span style="font-size: 15px;">Execution: <?=$_SESSION['exec_time']; ?>ms</span><br>
<span style="font-size: 15px;">PHP: <?=phpversion(); ?></span><br>
<span style="font-size: 15px;">SLOC: <?=number_format(lines_of_code(), 0, '.', ","); ?></span><br>
<span style="font-size: 15px;">Project size: <?=code_size(); ?></span><br>
<span style="font-size: 15px;">Loaded user functions: <?=$_SESSION['defined_user_function_count']; ?></span><br>
<span style="font-size: 15px;">Included files: <?=$_SESSION['included_files']; ?></span><br>
<span style="font-size: 15px;">Dataphyre mod_SQL session cache: <?=convert_storage(strlen(json_encode($_SESSION['db_cache']))); ?></span><br>
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