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