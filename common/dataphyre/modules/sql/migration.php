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


$rootpath['common_dataphyre']='/../../'
$is_task=true;

$table_versions=json_decode(file_get_contents($rootpath['common_dataphyre']."sql_migration/table_versions.json"), true);
foreach($table_versions as $table_name=>$version){
    $files=glob($rootpath['common_dataphyre']."sql_migration/tables/{$table_name}/*.php");
    sort($files);
    foreach($files as $file){
        if(preg_match("/(\d+)\.php$/", basename($file), $matches)){
            $file_version=(int)$matches[1];
            if($file_version>$version){
                $table_versions[$table_name]=$file_version;
                include($file);
            }
        }
    }
}
file_put_contents($rootpath['common_dataphyre']."sql_migration/table_versions.json", json_encode($table_versions));
unlink($rootpath['common_dataphyre']."sql_migration/migrating");