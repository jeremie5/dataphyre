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

$modcache=[];
$modcache_file=ROOTPATH['dataphyre']."modcache.php";
if(filemtime($modcache_file)+300>time()){
	$modcache=require($modcache_file);
}

function dp_modcache_save(): void {
	global $modcache;
	$modcache_file=ROOTPATH['dataphyre']."modcache.php";
	$cache_data='<?php return '.var_export($modcache, true).';';
	file_put_contents($modcache_file, $cache_data);
}

function dp_module_present(string $module): array|bool {
    global $modcache;
    if(!is_array($modcache))$modcache=[];
    if(isset($modcache[$module]))return $modcache[$module];
    $p=ROOTPATH['dataphyre']."modules/$module/";
    $c=ROOTPATH['common_dataphyre']."modules/$module/";
	$modcache[$module]=false;
    if(file_exists($p."$module.main.php")){
        $modcache[$module]=[$p."$module.main.php", file_exists($p."version")?trim(file_get_contents($p."version")):'1.0'];
    }
    elseif(!file_exists(ROOTPATH['dataphyre']."modules/-$module/") && file_exists($c)){
        $modcache[$module]=[$c."$module.main.php", file_exists($c."version")?trim(file_get_contents($c."version")):'1.0'];
    }
    dp_modcache_save();
    return $modcache[$module];
}

function dp_module_required(string $module, string $required_module, string $min_version = '1.0', string $max_version='1.0'): void {
    if(!$presence=dp_module_present($required_module) || (is_array($presence) && (version_compare($presence[1], $min_version, '<') || version_compare($presence[1], $max_version, '>')))){
        if(RUN_MODE !== 'diagnostic'){
            pre_init_error("Module '$module' requires '$required_module' (v$min_version - v$max_version)");
        }
        return;
    }
    if(RUN_MODE==='diagnostic'){
		if(!in_array($presence[0], get_included_files())){
			\dataphyre\dpanel::diagnose_module($module);
		}
    }
}

function dpvks(): array {
	global $configurations;

	if(false!=$keys=file_get_contents(ROOTPATH['dataphyre']."config/static/dpvk")){
		return explode(",", $keys);
	}
	if(isset($configurations['dataphyre']['private_key'])){
		return $configurations['dataphyre']['private_key'];
	}
	pre_init_error("Failed getting private keys");
}

function dpvk(): string {
	global $configurations;
	if(!isset($configurations['dataphyre']['private_key'])){
		$keys=dpvks();
		$configurations['dataphyre']['private_key']=$keys[count($keys)-1];
	}
	return $configurations['dataphyre']['private_key'];
}