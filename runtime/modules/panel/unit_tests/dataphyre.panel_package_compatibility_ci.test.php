<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelPackageCompatibilityCase;
use Dataphyre\Panel\PanelPackageCompatibilityCli;
use Dataphyre\Panel\PanelPackageCompatibilityPlan;
use Dataphyre\Panel\PanelPackageCompatibilityReport;
use Dataphyre\Panel\PanelPackageInstallPlan;
use Dataphyre\Panel\PanelPackageLock;
use Dataphyre\Panel\PanelPackageManifest;
use Dataphyre\Panel\PanelPackageRegistryIndex;
use Dataphyre\Panel\PanelPackageSignatureVerifier;
use Dataphyre\Panel\PanelPackageTemplate;
use Dataphyre\Panel\PanelPackageTrustPolicy;
use Dataphyre\Panel\PanelPackageVerificationResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
if(!class_exists(\dataphyre\core::class,false)){require_once dirname(__DIR__,2).'/core/kernel/core_functions.php';}
if(!function_exists('tracelog')){function tracelog(mixed ...$arguments): void {}}
if(!defined('DATAPHYRE_PANEL_PACKAGE_COMPATIBILITY_CLI_TEST')){define('DATAPHYRE_PANEL_PACKAGE_COMPATIBILITY_CLI_TEST',true);}
require_once dirname(__DIR__,4).'/dev/tools/panel_package_compatibility.php';

/** @return array{id:string,php:string,panel:string,reactor:string,modules:array<string,string>,themes:array<int,string>,features:array<int,string>} */
function dp_panel_compat_runtime(array $overrides=[]): array {
	return array_replace_recursive([
		'id'=>'reference','php'=>'8.4.0','panel'=>'2.0.0','reactor'=>'2.0.0',
		'modules'=>['panel'=>'2.0.0','reactor'=>'2.0.0'],'themes'=>['default'],'features'=>['signed_packages'],
	],$overrides);
}

function dp_panel_compat_manifest(string $id,string $version='1.0.0',array $requirements=[]): PanelPackageManifest {
	$digest=hash('sha256',$id.'@'.$version);
	return PanelPackageManifest::from([
		'id'=>$id,'label'=>ucwords(str_replace('_',' ',$id)),'version'=>$version,'type'=>'plugin','status'=>'stable',
		'requirements'=>array_replace(['php'=>'>=8.3.0','panel'=>'^2.0.0','reactor'=>'^2.0.0','modules'=>[],'themes'=>['default']],$requirements),
		'provides'=>[$id.'_feature'],'support'=>['owner'=>'shopiro'],'meta'=>['publisher'=>'shopiro'],
		'signature'=>['algorithm'=>'ed25519','key_id'=>'release','publisher'=>'shopiro','digest'=>$digest,'signature'=>base64_encode('fixture-'.$id)],
	]);
}

/** @param array<string,string> $versions */
function dp_panel_compat_lock(array $versions,bool $valid=true): PanelPackageLock {
	$packages=[];foreach($versions as $id=>$version){$packages[$id]=['id'=>$id,'version'=>$version,'dependencies'=>[]];}ksort($packages,SORT_STRING);
	$checksum=hash('sha256',json_encode($packages,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	return new PanelPackageLock(['type'=>'panel_package_lock','packages'=>$packages,'checksum'=>$valid?$checksum:str_repeat('f',64)]);
}

function dp_panel_compat_verification(PanelPackageManifest $manifest,bool $ok=true): PanelPackageVerificationResult {
	$data=$manifest->toArray();$signature=(array)$data['signature'];
	return PanelPackageVerificationResult::make([
		'ok'=>$ok,'package'=>$data['id'],'algorithm'=>$signature['algorithm'],'key_id'=>$signature['key_id'],'digest'=>$signature['digest'],
		'artifact_count'=>2,'bytes'=>256,'checks'=>[['name'=>'signature','ok'=>$ok]],'errors'=>$ok?[]:['Signature rejected.'],
	]);
}

function dp_panel_compat_trust(): PanelPackageTrustPolicy {
	return PanelPackageTrustPolicy::make(['require_signature'=>true,'allow_unknown_publishers'=>false,'trusted_publishers'=>['shopiro'],'trusted_keys'=>['release'],'allowed_statuses'=>['stable']]);
}

function dp_panel_compat_install(PanelPackageManifest $manifest,array $runtime): PanelPackageInstallPlan {
	$template=PanelPackageTemplate::make($manifest)->plugin(false)->provider(false)->theme(false)->docs(false)->tests(false)->with('marketplace',false)->file('src/Feature.php','<?php return true;');
	return PanelPackageInstallPlan::make($template,'',['runtime'=>$runtime,'trust_policy'=>dp_panel_compat_trust()]);
}

/** @param array<string,array{manifest:PanelPackageManifest,dependencies:array<string,string>,id?:string}> $packages */
function dp_panel_compat_registry(array $packages,int $now=1800000000): PanelPackageRegistryIndex {
	$keypair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($keypair);$public=sodium_crypto_sign_publickey($keypair);
	$verifier=PanelPackageSignatureVerifier::make(['release'=>['algorithm'=>'ed25519','public_key'=>base64_encode($public)]]);
	$entries=[];
	foreach($packages as $key=>$definition){$manifest=$definition['manifest']->toArray();$id=(string)($definition['id'] ?? $key);$body=json_encode($manifest,JSON_THROW_ON_ERROR);$entries[]=[
		'id'=>$id,'version'=>$manifest['version'],'status'=>'stable','publisher'=>'shopiro','key_id'=>'release','dependencies'=>$definition['dependencies'],'requirements'=>$manifest['requirements'],'yanked'=>false,
		'artifact'=>['locator'=>'registry://'.$id.'/'.$manifest['version'],'sha256'=>hash('sha256',$body),'bytes'=>strlen($body),'content_type'=>PanelPackageRegistryIndex::BUNDLE_CONTENT_TYPE],
		'transparency'=>[],
	];}
	$body=['format'=>PanelPackageRegistryIndex::FORMAT,'registry'=>'compatibility_ci','publisher'=>'shopiro','sequence'=>4,'generated_at'=>gmdate(DATE_ATOM,$now-30),'expires_at'=>gmdate(DATE_ATOM,$now+3600),'packages'=>$entries,'transparency'=>[]];
	$payload=PanelPackageRegistryIndex::signaturePayload($body,$verifier);
	$body['signature']=['algorithm'=>'ed25519','key_id'=>'release','publisher'=>'shopiro','digest'=>hash('sha256',$payload),'signature'=>base64_encode(sodium_crypto_sign_detached($payload,$secret))];
	return PanelPackageRegistryIndex::make($body,$verifier,dp_panel_compat_trust(),['now'=>$now]);
}

/** @return array<string,mixed> */
function dp_panel_compat_definition(array $overrides=[]): array {
	$dependency=dp_panel_compat_manifest('dependency_pack','1.2.0');
	$application=dp_panel_compat_manifest('application_pack','2.1.0',['modules'=>['panel'=>'^2.0.0']]);
	$dependencyOld=dp_panel_compat_manifest('dependency_pack','0.9.0');
	$applicationOld=dp_panel_compat_manifest('application_pack','2.0.0',['modules'=>['panel'=>'^2.0.0']]);
	$runtime=dp_panel_compat_runtime();$lock=dp_panel_compat_lock(['application_pack'=>'2.1.0','dependency_pack'=>'1.2.0']);
	$registry=dp_panel_compat_registry([
		'dependency_old'=>['id'=>'dependency_pack','manifest'=>$dependencyOld,'dependencies'=>[]],
		'dependency_pack'=>['manifest'=>$dependency,'dependencies'=>[]],
		'application_old'=>['id'=>'application_pack','manifest'=>$applicationOld,'dependencies'=>['dependency_pack'=>'^1.0.0']],
		'application_pack'=>['manifest'=>$application,'dependencies'=>['dependency_pack'=>'^1.0.0']],
	]);
	$definition=[
		'format'=>PanelPackageCompatibilityPlan::FORMAT,
		'runtime_axes'=>[
			'php'=>['8.4.0','8.3.0'],'panel'=>['2.0.0'],'reactor'=>['2.0.0'],
			'modules'=>['reference'=>['reactor'=>'2.0.0','panel'=>'2.0.0']],
			'themes'=>['reference'=>['default']],'features'=>['signed'=>['signed_packages']],
		],
		'packages'=>[
			['manifest'=>$application,'dependencies'=>['dependency_pack'=>'^1.0.0'],'required_features'=>['signed_packages'],'lock'=>$lock,'distribution'=>$registry,'verification'=>dp_panel_compat_verification($application),'trust'=>dp_panel_compat_trust(),'install_plan'=>dp_panel_compat_install($application,$runtime),'meta'=>['api_token'=>'remove','workspace_path'=>'C:\\Users\\'.('je'.'ref').'\\private','opaque_location'=>'C:\\private\\artifact']],
			['manifest'=>$dependency,'required_features'=>['signed_packages'],'lock'=>$lock,'distribution'=>$registry,'verification'=>dp_panel_compat_verification($dependency),'trust'=>dp_panel_compat_trust(),'install_plan'=>dp_panel_compat_install($dependency,$runtime)],
		],
		'policy'=>['require_lock'=>true,'require_distribution'=>true,'require_authenticated_distribution'=>true,'require_publisher'=>true,'require_signature'=>true,'require_trust'=>true,'require_install_ready'=>true,'fail_on_blocked'=>true,'fail_on_regression'=>true],
		'limits'=>['max_packages'=>16,'max_runtimes'=>16,'max_cases'=>64,'max_baseline_cases'=>64],
		'meta'=>['purpose'=>'compatibility-ci','authorization'=>'remove','apiToken'=>'remove-camel','sourceRoot'=>'/private/source','opaque'=>'/private/value'],
	];
	return array_replace_recursive($definition,$overrides);
}

test('compatibility plan expands stable axes and surfaces real evidence without network or install mutation',static function(Context $t): void {
	$definition=dp_panel_compat_definition();$plan=PanelPackageCompatibilityPlan::make($definition);$report=$plan->report();
	$t->same(['dependency_pack','application_pack'],$plan->packageOrder());
	$t->same(2,count($plan->runtimeIds()));$t->same(4,count($plan->cases()));$t->isTrue($report->ok());
	$t->same(4,$report->summary()['compatible_count']);$t->same([],$report->policyFailures());
	$case=$plan->cases()[1];$array=$case->toArray();
	$t->same('compatible',$array['checks']['lock']['status']);
	$t->same('runtime_authenticated',$array['checks']['distribution']['origin']);
	$t->isTrue($array['checks']['distribution']['authenticated']);
	$t->same('reported_verified',$array['checks']['signature']['status']);
	$t->same('runtime_result',$array['checks']['signature']['origin']);
	$t->same('trusted',$array['checks']['trust']['status']);
	$t->same('runtime_evaluated',$array['checks']['trust']['origin']);
	$t->same('ready',$array['checks']['install_plan']['status']);
	$t->same('runtime_preview',$array['checks']['install_plan']['origin']);
	$t->same($case->key(),$array['case_key']);$t->same($case->packageId(),$array['package']['id']);$t->same($case->version(),$array['package']['version']);$t->same($case->runtimeId(),$array['runtime']['id']);
	$t->same($case->dependencies(),['dependency_pack'=>'^1.0.0']);$t->isFalse($case->blocked());$t->same([],$case->failures());$t->same(64,strlen($case->fingerprint()));
	$json=json_encode(['plan'=>$plan,'report'=>$report],JSON_THROW_ON_ERROR);
	$t->notContains('registry://',$json);$t->notContains('fixture-application_pack',$json);$t->notContains('"api_token":"remove"',$json);$t->notContains('"authorization":"remove"',$json);$t->notContains('remove-camel',$json);$t->notContains('je'.'ref',$json);$t->notContains('/private/',$json);$t->contains('[REDACTED]',$json);
	$reversed=$definition;$reversed['packages']=array_reverse($reversed['packages']);$reversed['runtime_axes']['php']=array_reverse($reversed['runtime_axes']['php']);
	$t->same($plan->fingerprint(),PanelPackageCompatibilityPlan::make($reversed)->fingerprint());
	$t->same($report->fingerprint(),$report->toArray()['fingerprint']);$t->same($report->cases(),$report->toArray()['cases']);$t->same($report->comparison(),$report->toArray()['comparison']);
});

test('compatibility cases keep manifest feature dependency lock distribution publisher signature trust and install failures distinct',static function(Context $t): void {
	$manifest=dp_panel_compat_manifest('blocked_pack','1.0.0',['php'=>'>=9.0.0','modules'=>['missing_module'=>'^1.0.0'],'themes'=>['missing_theme']]);
	$lock=['type'=>'panel_package_lock','packages'=>['blocked_pack'=>['id'=>'blocked_pack','version'=>'2.0.0']],'checksum'=>str_repeat('f',64)];
	$distribution=['type'=>'panel_package_registry_index','ok'=>false,'publisher'=>'attacker','packages'=>[['id'=>'blocked_pack','version'=>'2.0.0','publisher'=>'attacker','key_id'=>'other','dependencies'=>[]]]];
	$case=PanelPackageCompatibilityCase::make([
		'manifest'=>$manifest,'runtime'=>dp_panel_compat_runtime(['features'=>[]]),'dependencies'=>['dependency_pack'=>'^2.0.0'],'required_features'=>['missing_feature'],'available_packages'=>['dependency_pack'=>'1.0.0'],
		'lock'=>$lock,'distribution'=>$distribution,
		'verification'=>['ok'=>false,'package'=>'other_pack','algorithm'=>'rsa-sha256','key_id'=>'other','digest'=>str_repeat('b',64)],
		'trust'=>['trusted'=>false,'package'=>'other_pack','publisher'=>'attacker'],
		'install_plan'=>['type'=>'panel_package_install_plan','ready'=>false,'blocked'=>true,'package'=>['id'=>'other_pack','version'=>'2.0.0'],'summary'=>['conflicts'=>1]],
		'policy'=>['require_lock'=>true,'require_distribution'=>true,'require_authenticated_distribution'=>true,'require_publisher'=>true,'require_signature'=>true,'require_trust'=>true,'require_install_ready'=>true],
	]);
	$t->isTrue($case->blocked());$failures=$case->failures();
	foreach(['manifest:runtime','feature:missing_feature','dependency:dependency_pack','lock:integrity','lock:package','lock:dependency:dependency_pack','distribution:reported_blocked','distribution:unauthenticated','distribution:package','distribution:dependency:dependency_pack','publisher:mismatch','signature:unverified','signature:package','signature:algorithm','signature:key','signature:digest','trust:blocked','trust:package','trust:publisher','install_plan:blocked','install_plan:package','install_plan:version'] as $failure){$t->isTrue(in_array($failure,$failures,true),$failure);}
	$plan=PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest,'required_features'=>['missing_feature']]],'runtimes'=>[dp_panel_compat_runtime(['features'=>[]])]]);
	$report=$plan->report();$t->isFalse($report->ok());$t->same(['blocked_cases'],$report->policyFailures());$t->same(1,$report->summary()['blocked_count']);
	$yankedManifest=dp_panel_compat_manifest('yanked_pack');
	$yanked=PanelPackageCompatibilityCase::make([
		'manifest'=>$yankedManifest,'runtime'=>dp_panel_compat_runtime(),'dependencies'=>['dependency_pack'=>'^1.0.0'],'available_packages'=>['dependency_pack'=>'1.2.0'],
		'distribution'=>['type'=>'panel_package_registry_index','ok'=>true,'publisher'=>'shopiro','packages'=>[
			['id'=>'yanked_pack','version'=>'1.0.0','publisher'=>'shopiro','key_id'=>'release','dependencies'=>[],'yanked'=>true],
			['id'=>'dependency_pack','version'=>'1.2.0','publisher'=>'shopiro','key_id'=>'release','dependencies'=>[],'yanked'=>true],
		]],
	]);
	$t->isTrue($yanked->blocked());$t->isTrue(in_array('distribution:yanked',$yanked->failures(),true));$t->isTrue(in_array('distribution:dependency:dependency_pack',$yanked->failures(),true));
	$unsigned=dp_panel_compat_manifest('incomplete_evidence')->toArray();$unsigned['signature']=[];
	$incomplete=PanelPackageCompatibilityCase::make([
		'manifest'=>$unsigned,'runtime'=>dp_panel_compat_runtime(),
		'verification'=>['ok'=>true,'package'=>'incomplete_evidence','algorithm'=>'ed25519','key_id'=>'release','digest'=>str_repeat('a',64)],
		'trust'=>['trusted'=>true,'package'=>'incomplete_evidence'],
		'install_plan'=>['type'=>'panel_package_install_plan','ready'=>true,'package'=>[]],
	]);
	foreach(['signature:manifest','trust:publisher','install_plan:package','install_plan:version'] as $failure){$t->isTrue(in_array($failure,$incomplete->failures(),true),$failure);}
});

test('compatibility baselines report new blocks added failures recoveries improvements and removals deterministically',static function(Context $t): void {
	$manifest=dp_panel_compat_manifest('baseline_pack');
	$healthy=PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest,'required_features'=>['signed_packages']]],'runtimes'=>[dp_panel_compat_runtime()]])->report();
	$blockedRuntime=dp_panel_compat_runtime();$blockedRuntime['features']=[];
	$blockedPlan=PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest,'required_features'=>['signed_packages']]],'runtimes'=>[$blockedRuntime],'policy'=>['fail_on_blocked'=>false,'fail_on_regression'=>true]]);
	$regressed=$blockedPlan->report($healthy->toArray());
	$t->isFalse($regressed->ok());$t->same(['compatibility_regression'],$regressed->policyFailures());
	$t->same([$blockedPlan->cases()[0]->key()],$regressed->comparison()['newly_blocked']);
	$t->same(['feature:signed_packages'],$regressed->comparison()['regressions'][0]['added_failures']);
	$recovered=PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest,'required_features'=>['signed_packages']]],'runtimes'=>[dp_panel_compat_runtime()]])->report($regressed->toArray());
	$t->isTrue($recovered->ok());$t->same([$healthy->cases()[0]['case_key']],$recovered->comparison()['recovered']);$t->same(['feature:signed_packages'],$recovered->comparison()['improvements'][0]['removed_failures']);
	$second=dp_panel_compat_manifest('removed_pack');
	$two=PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest],['manifest'=>$second]],'runtimes'=>[dp_panel_compat_runtime()]])->report();
	$removed=PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest]],'runtimes'=>[dp_panel_compat_runtime()],'policy'=>['fail_on_removed'=>true]])->report($two->toArray());
	$t->isFalse($removed->ok());$t->same(['removed_baseline_cases'],$removed->policyFailures());$t->same(1,$removed->summary()['removed_case_count']);
});

test('compatibility plans support default and keyed runtimes plus package-local availability without mutating inputs',static function(Context $t): void {
	$manifest=dp_panel_compat_manifest('default_pack','1.0.0',['php'=>'>=8.2.0']);
	$definition=['packages'=>[['manifest'=>$manifest,'dependencies'=>['external_pack'=>'^3.0.0'],'available_packages'=>['external_pack'=>'3.2.1']]]];
	$original=$definition;
	$plan=PanelPackageCompatibilityPlan::make($definition);
	$t->same($original,$definition);
	$t->same(['default'],$plan->runtimeIds());
	$t->same(PHP_VERSION,$plan->cases()[0]->toArray()['runtime']['php']);
	$t->isFalse($plan->cases()[0]->blocked());
	$t->same('compatible',$plan->cases()[0]->toArray()['checks']['dependencies']['status']);
	$t->same($plan->toArray(),$plan->jsonSerialize());
	$t->same($plan->cases()[0]->toArray(),$plan->cases()[0]->jsonSerialize());
	$keyed=PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest]],'runtimes'=>['php84'=>array_diff_key(dp_panel_compat_runtime(),['id'=>true])]]);
	$t->same(['php84'],$keyed->runtimeIds());
	$t->same('php84',$keyed->cases()[0]->runtimeId());
});

test('compatibility inputs reject ambiguous unbounded malformed and cyclic definitions fail closed',static function(Context $t): void {
	$manifest=dp_panel_compat_manifest('strict_pack');
	$runtime=dp_panel_compat_runtime();
	$caseBase=['manifest'=>$manifest,'runtime'=>$runtime];
	$malformedSignature=$manifest->toArray();$malformedSignature['signature']['algorithm']=[];
	$malformedPublisher=$manifest->toArray();$malformedPublisher['support']['owner']='Not Canonical';
	$malformedScalar=$manifest->toArray();$malformedScalar['label']=[];
	$malformedRequirements=$manifest->toArray();$malformedRequirements['requirements']['unknown']='*';
	$malformedModule=$manifest->toArray();$malformedModule['requirements']['modules']=['panel'=>[]];
	$malformedLink=$manifest->toArray();$malformedLink['links']=[['label'=>'Docs']];
	$malformedSupport=$manifest->toArray();$malformedSupport['support']=['list-value'];
	$malformedCompatibility=$manifest->toArray();$malformedCompatibility['compatibility']='reported';
	$caseInvalid=[
		$caseBase+['unknown'=>true],
		['manifest'=>[],'runtime'=>$runtime],
		['manifest'=>$malformedSignature,'runtime'=>$runtime],
		['manifest'=>$malformedPublisher,'runtime'=>$runtime],
		['manifest'=>$malformedScalar,'runtime'=>$runtime],
		['manifest'=>$malformedRequirements,'runtime'=>$runtime],
		['manifest'=>$malformedModule,'runtime'=>$runtime],
		['manifest'=>$malformedLink,'runtime'=>$runtime],
		['manifest'=>$malformedSupport,'runtime'=>$runtime],
		['manifest'=>$malformedCompatibility,'runtime'=>$runtime],
		['manifest'=>$manifest,'runtime'=>$runtime+['unknown'=>true]],
		['manifest'=>$manifest,'runtime'=>array_replace($runtime,['id'=>'Not Canonical'])],
		['manifest'=>$manifest,'runtime'=>array_replace($runtime,['id'=>[]])],
		['manifest'=>$manifest,'runtime'=>array_replace($runtime,['php'=>'8.4'])],
		['manifest'=>$manifest,'runtime'=>array_replace($runtime,['modules'=>['bad name'=>'1.0.0']])],
		['manifest'=>$manifest,'runtime'=>array_replace($runtime,['themes'=>['default','default']])],
		['manifest'=>$manifest,'runtime'=>array_replace($runtime,['axes'=>['PHP'=>'8.4.0']])],
		$caseBase+['dependencies'=>['strict_pack'=>'*']],
		$caseBase+['dependencies'=>['other'=>'not a constraint']],
		$caseBase+['required_features'=>['feature','feature']],
		$caseBase+['available_packages'=>['other'=>'1.0']],
		$caseBase+['policy'=>['require_lock'=>'yes']],
		$caseBase+['lock'=>['type'=>'wrong','packages'=>[]]],
		$caseBase+['lock'=>['packages'=>[],'checksum'=>'wrong']],
		$caseBase+['distribution'=>['type'=>'wrong','ok'=>true,'packages'=>[]]],
		$caseBase+['distribution'=>['type'=>'panel_package_registry_index','packages'=>[]]],
		$caseBase+['distribution'=>['type'=>'panel_package_registry_index','ok'=>true,'publisher'=>[],'packages'=>[]]],
		$caseBase+['distribution'=>['type'=>'panel_package_registry_index','ok'=>true,'packages'=>[['id'=>'strict_pack','version'=>'1.0.0','yanked'=>'yes']]]],
		$caseBase+['distribution'=>['type'=>'panel_package_registry_index','ok'=>true,'packages'=>[['id'=>[],'version'=>'1.0.0']]]],
		$caseBase+['distribution'=>['type'=>'panel_package_registry_index','ok'=>true,'packages'=>[['id'=>'strict_pack','version'=>'1.0.0','publisher'=>[]]]]],
		$caseBase+['verification'=>['ok'=>'yes']],
		$caseBase+['verification'=>['ok'=>true]],
		$caseBase+['verification'=>['ok'=>true,'package'=>7]],
		$caseBase+['trust'=>['trusted'=>'yes']],
		$caseBase+['trust'=>['trusted'=>true]],
		$caseBase+['trust'=>['trusted'=>true,'publisher'=>7]],
		$caseBase+['install_plan'=>['type'=>'wrong','ready'=>true]],
		$caseBase+['install_plan'=>['type'=>'panel_package_install_plan','ready'=>true,'blocked'=>'no']],
		$caseBase+['install_plan'=>['type'=>'panel_package_install_plan','ready'=>true,'package'=>['id'=>[]]]],
		$caseBase+['install_plan'=>['type'=>'panel_package_install_plan','ready'=>true,'summary'=>['list']]],
		$caseBase+['meta'=>['resource'=>fopen('php://memory','r')]],
	];
	foreach($caseInvalid as $index=>$definition){$t->throws(static fn()=>PanelPackageCompatibilityCase::make($definition),\InvalidArgumentException::class,'case invalid #'.$index);}
	$deep='leaf';for($depth=0;$depth<66;$depth++){$deep=['level'=>$deep];}
	$t->throws(static fn()=>PanelPackageCompatibilityCase::make($caseBase+['meta'=>['deep'=>$deep]]),\InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPackageCompatibilityCase::make($caseBase+['meta'=>['large'=>str_repeat('x',70000)]]),\InvalidArgumentException::class);

	$base=['packages'=>[['manifest'=>$manifest]],'runtimes'=>[$runtime]];
	$planInvalid=[
		$base+['unknown'=>true],
		array_replace($base,['format'=>'unsupported']),
		array_replace($base,['limits'=>['max_cases'=>0]]),
		array_replace($base,['policy'=>['fail_on_blocked'=>'yes']]),
		array_replace($base,['meta'=>['list-item']]),
		array_replace($base,['packages'=>[]]),
		array_replace($base,['packages'=>[['manifest'=>$manifest],['manifest'=>$manifest]]]),
		$base+['runtime_axes'=>['php'=>['8.4.0']]],
		array_replace($base,['runtimes'=>[$runtime,$runtime]]),
		array_replace($base,['runtime_axes'=>['php'=>['8.4']],'runtimes'=>[]]),
		array_replace($base,['runtime_axes'=>['modules'=>['bad name'=>[]]],'runtimes'=>[]]),
		array_replace($base,['runtime_axes'=>['modules'=>['first'=>[],'second'=>[]]],'runtimes'=>[]]),
		array_replace($base,['runtime_axes'=>['themes'=>['default'=>['bad name']]],'runtimes'=>[]]),
		array_replace($base,['runtime_axes'=>['features'=>['first'=>[],'second'=>[]]],'runtimes'=>[]]),
	];
	foreach($planInvalid as $index=>$definition){$t->throws(static fn()=>PanelPackageCompatibilityPlan::make($definition),\InvalidArgumentException::class,'plan invalid #'.$index);}
	$first=dp_panel_compat_manifest('cycle_first');$second=dp_panel_compat_manifest('cycle_second');
	$t->throws(static fn()=>PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$first,'dependencies'=>['cycle_second'=>'*']],['manifest'=>$second,'dependencies'=>['cycle_first'=>'*']]],'runtimes'=>[$runtime]]),\InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPackageCompatibilityPlan::make(['packages'=>array_fill(0,2,['manifest'=>$manifest]),'runtimes'=>[$runtime],'limits'=>['max_packages'=>1]]),\InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest]],'runtimes'=>[$runtime],'meta'=>['deep'=>$deep]]),\InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest]],'runtimes'=>[$runtime],'meta'=>['large'=>str_repeat('x',70000)]]),\LengthException::class);
	$manyPackages=[];for($index=0;$index<257;$index++){$manyPackages[]=['manifest'=>dp_panel_compat_manifest('bounded_pack_'.$index)];}
	$t->throws(static fn()=>PanelPackageCompatibilityPlan::make(['packages'=>$manyPackages,'runtimes'=>[$runtime]]),\LengthException::class);
	$manyDependencies=[];for($index=0;$index<257;$index++){$manyDependencies['dependency_'.$index]='*';}
	$t->throws(static fn()=>PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest,'dependencies'=>$manyDependencies]],'runtimes'=>[$runtime]]),\InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest],['manifest'=>$second]],'runtimes'=>[$runtime],'limits'=>['max_packages'=>1]]),\LengthException::class);
	$t->throws(static fn()=>PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest]],'runtimes'=>[$runtime,array_replace($runtime,['id'=>'second_runtime'])],'limits'=>['max_runtimes'=>1]]),\LengthException::class);
	$t->throws(static fn()=>PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest],['manifest'=>$second]],'runtimes'=>[$runtime,array_replace($runtime,['id'=>'second_runtime'])],'limits'=>['max_cases'=>3]]),\LengthException::class);
	$t->throws(static fn()=>PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>$manifest]],'runtime_axes'=>['php'=>['8.0.0','8.1.0','8.2.0','8.3.0','8.4.0','8.5.0','8.6.0','8.7.0','8.8.0','8.9.0','9.0.0','9.1.0','9.2.0','9.3.0','9.4.0','9.5.0'],'features'=>['one'=>[],'two'=>['a'],'three'=>['b'],'four'=>['c'],'five'=>['d'],'six'=>['e'],'seven'=>['f'],'eight'=>['g'],'nine'=>['h']]]]),\LengthException::class);
});

test('compatibility baselines reject forged malformed duplicate and over-budget case records',static function(Context $t): void {
	$plan=PanelPackageCompatibilityPlan::make(['packages'=>[['manifest'=>dp_panel_compat_manifest('baseline_strict')]],'runtimes'=>[dp_panel_compat_runtime()],'limits'=>['max_baseline_cases'=>1]]);
	$valid=$plan->report()->toArray();
	$invalid=[
		['type'=>'wrong','format'=>PanelPackageCompatibilityReport::FORMAT,'cases'=>[]],
		['type'=>'panel_package_compatibility_report','format'=>'wrong','cases'=>[]],
		['type'=>'panel_package_compatibility_report','format'=>PanelPackageCompatibilityReport::FORMAT,'cases'=>'bad'],
		array_replace($valid,['cases'=>[[],[]]]),
		array_replace($valid,['cases'=>[['case_key'=>'','blocked'=>false,'failures'=>[],'fingerprint'=>str_repeat('a',64)]]]),
		array_replace($valid,['cases'=>[['case_key'=>'case','blocked'=>'no','failures'=>[],'fingerprint'=>str_repeat('a',64)]]]),
		array_replace($valid,['cases'=>[['case_key'=>'case','blocked'=>false,'failures'=>[''], 'fingerprint'=>str_repeat('a',64)]]]),
		array_replace($valid,['cases'=>[['case_key'=>'case','blocked'=>false,'failures'=>['same','same'], 'fingerprint'=>str_repeat('a',64)]]]),
		array_replace($valid,['cases'=>[['case_key'=>'case','blocked'=>false,'failures'=>[], 'fingerprint'=>'forged']]]),
	];
	$contradictory=$valid;$contradictory['cases'][0]['blocked']=true;$contradictory['cases'][0]['failures']=[];$invalid[]=$contradictory;
	$badFailure=$valid;$badFailure['cases'][0]['blocked']=true;$badFailure['cases'][0]['failures']=['bad failure'];$invalid[]=$badFailure;
	foreach($invalid as $index=>$baseline){$t->throws(static fn()=>$plan->report($baseline),\InvalidArgumentException::class,'baseline invalid #'.$index);}
	$duplicate=$valid;$duplicate['cases'][]=$duplicate['cases'][0];
	$t->throws(static fn()=>$plan->report($duplicate),\InvalidArgumentException::class);
});

test('compatibility CLI is deterministic read only root confined policy aware and stream injectable',static function(Context $t): void {
	$projectRoot=dirname(__DIR__,4);$example=PanelPackageCompatibilityCli::execute(['tool','--root',$projectRoot,'--config','dev/tools/panel_package_compatibility.example.json'],$projectRoot);
	$t->same(0,$example['exit_code']);$t->same(4,$example['payload']['report']['summary']['case_count']);$t->same(0,$example['payload']['report']['summary']['blocked_count']);
	$exampleChecks=$example['payload']['report']['cases'][0]['checks'];$t->same('compatible',$exampleChecks['lock']['status']);$t->same('snapshot',$exampleChecks['distribution']['origin']);$t->same('reported_verified',$exampleChecks['signature']['status']);$t->same('trusted',$exampleChecks['trust']['status']);$t->same('ready',$exampleChecks['install_plan']['status']);
	$workspace=$t->workspace('dp-panel-compat');
	$root=$workspace->directory('project');
	try{
		$manifest=dp_panel_compat_manifest('cli_pack')->toArray();
		$config=['format'=>PanelPackageCompatibilityPlan::FORMAT,'packages'=>[['manifest'=>$manifest]],'runtimes'=>[dp_panel_compat_runtime()]];
		file_put_contents($root.DIRECTORY_SEPARATOR.'valid.json',json_encode($config,JSON_THROW_ON_ERROR));
		$blockedRuntime=dp_panel_compat_runtime();$blockedRuntime['features']=[];
		$blocked=['packages'=>[['manifest'=>$manifest,'required_features'=>['required_feature']]],'runtimes'=>[$blockedRuntime]];
		file_put_contents($root.DIRECTORY_SEPARATOR.'blocked.json',json_encode($blocked,JSON_THROW_ON_ERROR));
		$baseline=PanelPackageCompatibilityPlan::make($config)->report()->toArray();
		file_put_contents($root.DIRECTORY_SEPARATOR.'baseline.json',json_encode($baseline,JSON_THROW_ON_ERROR));
		file_put_contents($root.DIRECTORY_SEPARATOR.'invalid.json','{bad json');
		file_put_contents($root.DIRECTORY_SEPARATOR.'list.json','[]');
		file_put_contents($root.DIRECTORY_SEPARATOR.'tiny.json','1');
		file_put_contents($root.DIRECTORY_SEPARATOR.'large.json',str_repeat(' ',2097153));
		file_put_contents($root.DIRECTORY_SEPARATOR.'large-baseline.json',str_repeat(' ',8388609));
		$deepJson=['leaf'=>true];for($depth=0;$depth<130;$depth++){$deepJson=['level'=>$deepJson];}
		file_put_contents($root.DIRECTORY_SEPARATOR.'deep.json',json_encode($deepJson,JSON_THROW_ON_ERROR,256));

		$help=PanelPackageCompatibilityCli::execute(['tool','--help'],$root);
		$t->same(0,$help['exit_code']);$t->same('help',$help['payload']['mode']);$t->isTrue($help['payload']['safety']['read_only']);$t->isTrue($help['payload']['safety']['no_install']);
		$beforeHash=hash_file('sha256',$root.DIRECTORY_SEPARATOR.'valid.json');$valid=PanelPackageCompatibilityCli::execute(['tool','--root',$root,'--config','valid.json','--baseline=baseline.json'],$root);
		$t->same(0,$valid['exit_code']);$t->same('preview',$valid['payload']['mode']);$t->isTrue($valid['payload']['read_only']);
		$t->same(PanelPackageCompatibilityPlan::make($config)->fingerprint(),$valid['payload']['plan']['fingerprint']);
		$t->same($beforeHash,hash_file('sha256',$root.DIRECTORY_SEPARATOR.'valid.json'));
		$failed=PanelPackageCompatibilityCli::execute(['tool','--root='.$root,'--config','blocked.json'],$root);
		$t->same(1,$failed['exit_code']);$t->isFalse($failed['payload']['ok']);$t->same(['blocked_cases'],$failed['payload']['report']['policy_failures']);

		$invalidArguments=[
			['tool'],['tool','position'],['tool','--unknown'],['tool','--help','--help'],['tool','--help=yes'],
			['tool','--config'],['tool','--config='],['tool','--config','valid.json','--config','valid.json'],
			['tool','--config',str_repeat('x',4097)],['tool','--config',"bad\0name"],
			['tool','--root',$root.DIRECTORY_SEPARATOR.'missing','--config','valid.json'],
			['tool','--root',$root.DIRECTORY_SEPARATOR.'valid.json','--config','valid.json'],
			['tool','--root',$root,'--config','missing.json'],['tool','--root',$root,'--config',dirname($root).DIRECTORY_SEPARATOR.'outside.json'],
			['tool','--root',$root,'--config','invalid.json'],['tool','--root',$root,'--config','list.json'],['tool','--root',$root,'--config','tiny.json'],
			['tool','--root',$root,'--config','large.json'],['tool','--root',$root,'--config','deep.json'],
			['tool','--root',$root,'--config','valid.json','--baseline','large-baseline.json'],
		];
		file_put_contents(dirname($root).DIRECTORY_SEPARATOR.'outside.json',json_encode($config,JSON_THROW_ON_ERROR));
		foreach($invalidArguments as $index=>$arguments){$result=PanelPackageCompatibilityCli::execute($arguments,$root);$t->same(2,$result['exit_code'],'cli invalid #'.$index);$t->same('invalid',$result['payload']['mode']);$t->notContains($root,(string)$result['payload']['message']);}
		$t->same(2,PanelPackageCompatibilityCli::execute(['tool','--config','valid.json'],'')['exit_code']);
		$link=$root.DIRECTORY_SEPARATOR.'linked.json';if(function_exists('symlink') && @symlink($root.DIRECTORY_SEPARATOR.'valid.json',$link)){$t->same(2,PanelPackageCompatibilityCli::execute(['tool','--root',$root,'--config','linked.json'],$root)['exit_code']);@unlink($link);}

		$stdout=fopen('php://temp','w+');$stderr=fopen('php://temp','w+');
		$t->same(0,dp_panel_package_compatibility_cli_main(['tool','--root',$root,'--config','valid.json'],$root,$stdout,$stderr));
		rewind($stdout);$output=stream_get_contents($stdout);$t->contains('"mode": "preview"',$output);$t->notContains('registry://',$output);
		$t->same(2,dp_panel_package_compatibility_cli_main(['tool'],$root,$stdout,$stderr));rewind($stderr);$t->contains('"mode": "invalid"',stream_get_contents($stderr));
		$t->same(2,dp_panel_package_compatibility_cli_main(['tool','--help'],$root,'not-a-stream',$stderr));
	}finally{
		foreach((glob($root.DIRECTORY_SEPARATOR.'*') ?: []) as $path){@unlink($path);}@rmdir($root);@unlink(dirname($root).DIRECTORY_SEPARATOR.'outside.json');
	}
});
