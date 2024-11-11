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

function dp_modcache_save(): void {
	global $modcache, $modcache_file;
	$cache_data='<?php return '.var_export($modcache, true).';';
	file_put_contents($modcache_file, $cache_data);
}

function dp_module_present(string $module): array|bool {
    global $rootpath, $modcache;
    if(!is_array($modcache))$modcache=[];
    if(isset($modcache[$module]))return $modcache[$module];
    $p=$rootpath['dataphyre']."modules/$module/";
    $c=$rootpath['common_dataphyre']."modules/$module/";
	$modcache[$module]=false;
    if(file_exists($p."$module.main.php")){
        $modcache[$module]=[$p."$module.main.php", file_exists($p."version")?trim(file_get_contents($p."version")):'1.0'];
    }
    elseif(!file_exists($rootpath['dataphyre']."modules/-$module/") && file_exists($c)){
        $modcache[$module]=[$c."$module.main.php", file_exists($c."version")?trim(file_get_contents($c."version")):'1.0'];
    }
    dp_modcache_save();
    return $modcache[$module];
}

function dp_module_required(string $module, string $required_module, string $minimum_version='1.0', string $maximum_version='1.0'): void {
	global $rootpath;
	if(!$presence=dp_module_present($required_module) || 
		(is_array($presence) && 
			(!version_compare($presence[1], $minimum_version, '>=') || 
				!version_compare($presence[1], $maximum_version, '<=')))
	){
		pre_init_error("Module '$module' requires module '$required_module'");
	}
}

function dpvks(): array {
	global $configurations;
	global $rootpath;
	if(false!=$keys=file_get_contents($rootpath['dataphyre']."config/static/dpvk")){
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