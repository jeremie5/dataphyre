<?php

ini_set('display_errors', 0);
set_error_handler(function(...$args){ return;}, E_ALL);

try{
	include(__DIR__."/rootpaths.php");
}catch(\Throwable $exception){
	pre_init_error('Fatal error: Unable to load rootpaths', $exception);
}

try{
	include($rootpath['common_dataphyre']."modules/routing/routing.main.php");
}catch(\Throwable $exception){
	pre_init_error('Fatal error: Unable to load routing module', $exception);
}