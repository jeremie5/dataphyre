<?php
$configurations['dataphyre']['async']['dependencies']=array(
	$rootpath['root']."rootpaths.php",
	$rootpath['backend']."wrapper.php"
);
$configurations['dataphyre']['async']['included_vars']['app']=$app;
if(file_exists($rootpath['common_dataphyre']."modules/access/")){
	if(!empty($_SESSION["userid"])){
		$configurations['dataphyre']['async']['included_vars']['_SESSION["userid"]']=$_SESSION["userid"];
		$configurations['dataphyre']['async']['included_vars']['_SESSION["dpid"]']=$_SESSION["dpid"];
	}
}
$configurations['dataphyre']['async']['excluded_vars']=array(
'_SESSION'=>array('db_cache', 'deals_cache', 'recommendation_cache','tracelog_data'),
'language'
);