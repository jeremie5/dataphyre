<?php
/*************************************************************************
* 2020-2022 Shopiro Ltd.
* All Rights Reserved.
* 
* NOTICE: All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

if($_REQUEST['dpvk']===dpvk()){
	if($_REQUEST['rqt']==='new_config'){
		if(!empty($_REQUEST['config'])){
			file_put_contents($rootpath['dataphyre']."modules/sql/static_config/config.json", $_REQUEST['config']);
			die("ok");
		}
		else
		{
			die("config_missing");
		}
	}
    elseif($_REQUEST['rqt']==='migration_complete'){
        if(!empty($_REQUEST['migrated_version'])){
            $sql_migrated_config_version=$_REQUEST['migrated_version'];
            $variable_definition="<?php \$sql_migrated_config_version='{$sql_migrated_config_version}';";
            file_put_contents($rootpath['dataphyre'].'modules/sql/static_config/migrated_config_version', $variable_definition);
			unlink($rootpath['dataphyre'].'modules/sql/migration_ongoing');
			die("ok");
        }
		else
		{
            die("migrated_version_missing");
        }
    }
	else
	{
		die("unknown_rqt");
	}
}
else
{
	die("bad_dpvk");
}