<?php
/*************************************************************************
*  Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd.
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