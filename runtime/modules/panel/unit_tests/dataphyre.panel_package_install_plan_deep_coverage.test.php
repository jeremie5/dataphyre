<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel {
	require_once __DIR__.'/panel_test_probes.php';

	if(!function_exists(__NAMESPACE__.'\\realpath')){
		function realpath(string $path): string|false {
			$override=\Dataphyre\Panel\TestFixtures\PackageFilesystemScenario::realpathOverride($path);
			if($override!==null){
				return $override;
			}
			return \realpath($path);
		}
	}

	if(!function_exists(__NAMESPACE__.'\\file_exists')){
		function file_exists(string $path): bool {
			if(\Dataphyre\Panel\TestFixtures\PackageFilesystemScenario::entriesAreHidden()){
				return false;
			}
			return \file_exists($path);
		}
	}

	if(!function_exists(__NAMESPACE__.'\\is_link')){
		function is_link(string $path): bool {
			if(\Dataphyre\Panel\TestFixtures\PackageFilesystemScenario::entriesAreHidden()){
				return false;
			}
			return \is_link($path);
		}
	}

	if(!function_exists(__NAMESPACE__.'\\file_put_contents')){
		function file_put_contents(string $filename,mixed $data,int $flags=0,mixed $context=null): int|false {
			if(\Dataphyre\Panel\TestFixtures\PackageFilesystemScenario::writeShouldBeShort()){
				return max(0,strlen((string)$data)-1);
			}
			return $context===null
				? \file_put_contents($filename,$data,$flags)
				: \file_put_contents($filename,$data,$flags,$context);
		}
	}
}

namespace {
	use Dataphyre\Panel\PanelCompatibilityMatrix;
	use Dataphyre\Panel\PanelFilesystemPath;
	use Dataphyre\Panel\PanelPackageApplyResult;
	use Dataphyre\Panel\PanelPackageInstallPlan;
	use Dataphyre\Panel\PanelPackageManifest;
	use Dataphyre\Panel\PanelPackageTemplate;
	use Dataphyre\Panel\PanelPackageTrustPolicy;
	use Dataphyre\Panel\TestFixtures\PackageFilesystemScenario;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!defined('DATAPHYRE_MODULE_POLICY')){
		define('DATAPHYRE_MODULE_POLICY',[
			'enabled'=>['core'=>true,'panel'=>true],
			'disabled'=>[],
			'core_implicit'=>true,
		]);
	}
	$modulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
	require_once $modulesRoot.'/core/kernel/autoloader.php';
	\dataphyre\autoloader::register($modulesRoot);
	\dataphyre\autoloader::register_framework_modules(['panel']);
	if(!class_exists(\dataphyre\core::class,false)){
		require_once $modulesRoot.'/core/kernel/core_functions.php';
	}
	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}

	/** @param array<string,string> $files */
	function dp_panel_package_install_plan_template(string|PanelPackageManifest $package='coverage-package',array $files=[]): PanelPackageTemplate {
		$template=PanelPackageTemplate::make($package)
			->plugin(false)
			->provider(false)
			->theme(false)
			->docs(false)
			->tests(false)
			->with('marketplace',false);
		foreach($files as $path=>$contents){
			$template->file($path,$contents);
		}
		return $template;
	}

	function dp_panel_package_install_plan_tmp(Context $t,string $name): string {
		return $t->workspace('dp-panel-install-plan-'.$name)->root();
	}

	/** @param array<int,array<string,mixed>> $entries */
	function dp_panel_package_install_plan_has_reason(array $entries,string $needle): bool {
		foreach($entries as $entry){
			if(str_contains((string)($entry['reason'] ?? ''),$needle)){
				return true;
			}
		}
		return false;
	}

	test('panel package install plan configures serializes and normalizes helpers',static function(Context $t): void {
		$template=dp_panel_package_install_plan_template('configured-package',[
			'./nested/../safe.php'=>'<?php return true;',
			'../invalid.php'=>'not installable',
		]);
		$policy=PanelPackageTrustPolicy::make(['allow_unknown_publishers'=>false]);
		$runtime=PanelCompatibilityMatrix::defaultRuntime();
		$plan=new PanelPackageInstallPlan($template,' preview\\root/ ',[
			'runtime'=>$runtime,
			'trust_policy'=>$policy,
			'overwrite_policy'=>'SKIP',
			'meta'=>['source'=>'constructor'],
		]);

		$blocked=$plan->manifest(['call'=>'manifest']);
		$t->same('preview/root',$blocked['target']);
		$t->same('skip',$blocked['overwrite_policy']);
		$t->same(false,$blocked['ready']);
		$t->same('constructor',$blocked['meta']['source']);
		$t->same('manifest',$blocked['meta']['call']);
		$t->same($plan,$plan->runtime($runtime));
		$t->same($plan,$plan->trustPolicy(null));
		$t->same($plan,$plan->overwritePolicy('REPLACE'));
		$t->same($plan,$plan->overwritePolicy('unsupported'));
		$t->same($plan,$plan->meta(['array'=>'value']));
		$t->same($plan,$plan->meta(' single ','entry'));
		$t->same($plan,$plan->meta('   ','ignored'));
		$t->same($plan,$plan->target(' next\\preview/// '));

		$array=$plan->toArray();
		$t->same('next/preview',$array['target']);
		$t->same('fail',$array['overwrite_policy']);
		$t->same('value',$array['meta']['array']);
		$t->same('entry',$array['meta']['single']);
		$t->same($array,$plan->jsonSerialize());
		$t->same($array,json_decode((string)json_encode($plan),true));

		foreach([
			['',''],
			['./safe.php','safe.php'],
			['folder//./file.php','folder/file.php'],
			['folder/../file.php','file.php'],
			['../escape.php',''],
			['name.',''],
			['name ',''],
			['AUX.log',''],
			['name:stream',''],
			["bad\x7fname",''],
		] as [$input,$expected]){
			$t->same($expected,$t->nonPublic($plan)->invoke('normalizeArtifactPath',$input));
		}
		$t->same('replace',$t->nonPublic($plan)->invoke('effectiveOverwritePolicy',['overwrite'=>1]));
		$t->same('fail',$t->nonPublic($plan)->invoke('effectiveOverwritePolicy',['overwrite'=>0]));
		$t->same('fail',$t->nonPublic($plan)->invoke('effectiveOverwritePolicy',[]));
		$t->same('',$t->nonPublic($plan)->invoke('joinPath','','file.php'));
		$t->same('root'.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'file.php',$t->nonPublic($plan)->invoke('joinPath','root','nested/file.php'));

		$drive='C:'.DIRECTORY_SEPARATOR.'alpha'.DIRECTORY_SEPARATOR.'beta'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'gamma';
		$t->same('C:'.DIRECTORY_SEPARATOR.'alpha'.DIRECTORY_SEPARATOR.'gamma',$t->nonPublic($plan)->invoke('normalizeFilesystemPath',$drive));
		$t->same(DIRECTORY_SEPARATOR.'alpha'.DIRECTORY_SEPARATOR.'beta',$t->nonPublic($plan)->invoke('normalizeFilesystemPath',DIRECTORY_SEPARATOR.'alpha'.DIRECTORY_SEPARATOR.'.'.DIRECTORY_SEPARATOR.'beta'));
		$t->same('alpha',$t->nonPublic($plan)->invoke('normalizeFilesystemPath','..'.DIRECTORY_SEPARATOR.'alpha'));
		$t->same(true,$t->nonPublic($plan)->invoke('pathPrefixMatches','C:\\ROOT\\child','c:\\root'));
		$t->same(false,$t->nonPublic($plan)->invoke('pathPrefixMatches','C:\\root-sibling','C:\\root'));
		$t->same('C:'.DIRECTORY_SEPARATOR.'alpha'.DIRECTORY_SEPARATOR.'gamma',PanelFilesystemPath::normalize('c:\\alpha\\beta\\..\\gamma'));
		$t->isTrue(PanelFilesystemPath::prefixMatches('\\\\SERVER\\Share\\root\\child','//server/share/root'));
		$t->isTrue(PanelFilesystemPath::usesWindowsSemantics('D:/packages/panel'));
		$t->isFalse(PanelFilesystemPath::usesWindowsSemantics('/srv/packages/panel'));
	});

	test('panel package install activation gates fail closed and recheck under the package lock',static function(Context $t): void {
		$root=dp_panel_package_install_plan_tmp($t,'activation-gate');
		$calls=[];
		$plan=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('activation-package',[
			'artifact.txt'=>'must not publish',
		]),'',[
			'activation_gate'=>static function(array $context)use(&$calls):array{
				$calls[]=$context;
				return count($calls)===1
					? ['allowed'=>true,'complete'=>true,'stale'=>false,'revoked'=>false,'blocked'=>false]
					: ['allowed'=>false,'complete'=>true,'stale'=>false,'revoked'=>true,'blocked'=>false,'reason_codes'=>['live-revocation']];
			},
		]);
		$result=$plan->apply($root);
		$t->same(2,count($calls));
		$t->same('preflight',$calls[0]['phase']);
		$t->same('activation',$calls[1]['phase']);
		$t->same(['id'=>'activation-package','version'=>''],$calls[0]['package']);
		$t->isFalse($result->ok());
		$t->same([],$result->written());
		$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'artifact.txt'));
		$t->isFalse($result->toArray()['meta']['activation_gate_passed']);
		$t->same('activation',$result->toArray()['meta']['activation_gate_phase']);
		$t->isTrue(dp_panel_package_install_plan_has_reason($result->blocked(),'changed after planning'));

		$boolean=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('boolean-gate'))->activationGate(static fn():bool=>true);
		$t->isTrue($boolean->manifest()['ready']);
		$t->isTrue($boolean->manifest()['activation_gate']['allowed']);
		$t->same($boolean,$boolean->activationGate(null));
		$t->isFalse($boolean->manifest()['activation_gate']['configured']);

		$denied=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('denied-gate'))->activationGate(static fn():bool=>false)->manifest();
		$t->isFalse($denied['ready']);
		$t->same(['activation_gate_denied'],$denied['activation_gate']['reason_codes']);
		$throwing=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('throwing-gate'))->activationGate(static function():never{throw new RuntimeException('secret failure');})->manifest();
		$t->isFalse($throwing['ready']);
		$t->same(['activation_gate_unavailable'],$throwing['activation_gate']['reason_codes']);
		$invalid=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('invalid-gate'))->activationGate(static fn():string=>'invalid')->manifest();
		$t->isFalse($invalid['ready']);
		$t->same(['activation_gate_invalid'],$invalid['activation_gate']['reason_codes']);
		$stale=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('stale-gate'))->activationGate(static fn():array=>['allowed'=>true,'complete'=>false,'stale'=>true,'revoked'=>false,'blocked'=>false])->manifest();
		$t->isFalse($stale['ready']);
		$t->same(['activation_gate_incomplete','activation_gate_stale'],$stale['activation_gate']['reason_codes']);
	});

	test('panel package install plan classifies create replace skip and conflict previews',static function(Context $t): void {
		$root=dp_panel_package_install_plan_tmp($t,'previews');
			file_put_contents($root.DIRECTORY_SEPARATOR.'dataphyre-panel-package.json','old manifest');
			file_put_contents($root.DIRECTORY_SEPARATOR.'existing.txt','old existing');
			$template=dp_panel_package_install_plan_template('preview-package',[
				'existing.txt'=>'new existing',
				'fresh.txt'=>'fresh',
			]);
			$plan=PanelPackageInstallPlan::make($template,$root);

			$replace=$plan->overwritePolicy('replace')->manifest();
			$t->same(true,$replace['ready']);
			$t->same(2,$replace['summary']['replaces']);
			$t->same(1,$replace['summary']['creates']);
			$t->same('replace',$replace['steps'][0]['action']);

			$skip=$plan->overwritePolicy('skip')->manifest();
			$t->same(true,$skip['ready']);
			$t->same(2,$skip['summary']['skips']);
			$t->same(1,$skip['summary']['creates']);

			$conflict=$plan->overwritePolicy('fail')->manifest();
			$t->same(false,$conflict['ready']);
			$t->same(true,$conflict['blocked']);
			$t->same(2,$conflict['summary']['conflicts']);
			$t->same(true,$conflict['steps'][0]['blocked']);
	});

	test('panel package install plan applies skip replace backup legacy and dry run policies',static function(Context $t): void {
		$base=dp_panel_package_install_plan_tmp($t,'policies');
			$skipRoot=$base.DIRECTORY_SEPARATOR.'skip';
			mkdir($skipRoot,0775,true);
			file_put_contents($skipRoot.DIRECTORY_SEPARATOR.'old.txt','old');
			$skip=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('skip-package',[
				'new.txt'=>'new',
				'old.txt'=>'replacement',
			]))->overwritePolicy('skip')->apply($skipRoot);
			$t->same(true,$skip->ok());
			$t->same(1,count($skip->skipped()));
			$t->same(2,count($skip->written()));
			$t->same('old',file_get_contents($skipRoot.DIRECTORY_SEPARATOR.'old.txt'));

			$conflictRoot=$base.DIRECTORY_SEPARATOR.'conflict';
			mkdir($conflictRoot,0775,true);
			file_put_contents($conflictRoot.DIRECTORY_SEPARATOR.'old.txt','old');
			$conflict=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('conflict-package',[
				'fresh.txt'=>'fresh',
				'old.txt'=>'replacement',
			]))->apply($conflictRoot);
			$t->same(false,$conflict->ok());
			$t->same(0,count($conflict->written()));
			$t->same(true,dp_panel_package_install_plan_has_reason($conflict->blocked(),'conflicts'));
			$t->same(true,dp_panel_package_install_plan_has_reason($conflict->blocked(),'not ready'));

			$replaceRoot=$base.DIRECTORY_SEPARATOR.'replace';
			$backupRoot=$base.DIRECTORY_SEPARATOR.'backups';
			mkdir($replaceRoot,0775,true);
			mkdir($backupRoot,0775,true);
			file_put_contents($replaceRoot.DIRECTORY_SEPARATOR.'old.txt','original');
			$replace=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('replace-package',[
				'old.txt'=>'replacement',
			]))->overwritePolicy('replace')->apply($replaceRoot,['backup_root'=>$backupRoot]);
			$t->same(true,$replace->ok());
			$t->same('replacement',file_get_contents($replaceRoot.DIRECTORY_SEPARATOR.'old.txt'));
			$t->same(1,count($replace->backups()));
			$t->same('original',file_get_contents((string)$replace->backups()[0]['backup']));

			$legacyRoot=$base.DIRECTORY_SEPARATOR.'legacy';
			mkdir($legacyRoot,0775,true);
			file_put_contents($legacyRoot.DIRECTORY_SEPARATOR.'old.txt','legacy');
			$legacy=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('legacy-package',[
				'old.txt'=>'modern',
			]))->apply($legacyRoot,['overwrite'=>true]);
			$t->same(true,$legacy->ok());
			$t->same('modern',file_get_contents($legacyRoot.DIRECTORY_SEPARATOR.'old.txt'));
			$t->same(0,count($legacy->backups()));

			$dryRoot=$base.DIRECTORY_SEPARATOR.'dry';
			$dryBackups=$base.DIRECTORY_SEPARATOR.'dry-backups';
			mkdir($dryRoot,0775,true);
			mkdir($dryBackups,0775,true);
			file_put_contents($dryRoot.DIRECTORY_SEPARATOR.'old.txt','unchanged');
			$dry=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('dry-package',[
				'old.txt'=>'not written',
			]))->overwritePolicy('replace')->apply($dryRoot,[
				'dry_run'=>true,
				'backup_root'=>$dryBackups,
			]);
			$t->same(true,$dry->ok());
			$t->same('unchanged',file_get_contents($dryRoot.DIRECTORY_SEPARATOR.'old.txt'));
			$t->same(1,count($dry->backups()));
			$t->same(true,$dry->backups()[0]['dry_run']);
			$t->same(false,is_file((string)$dry->backups()[0]['backup']));
	});

	test('panel package install plan reports unresolved policy directory write and containment blocks',static function(Context $t): void {
		$base=dp_panel_package_install_plan_tmp($t,'blocked');
		$filesystem=PackageFilesystemScenario::reset($t);
		try{
			$unresolved=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('unresolved'))->apply('',[
				'dry_run'=>true,
			]);
			$t->same(false,$unresolved->ok());
			$t->same(true,dp_panel_package_install_plan_has_reason($unresolved->blocked(),'could not be resolved'));
			$t->same(true,dp_panel_package_install_plan_has_reason($unresolved->blocked(),'outside the target root'));

			$incompatiblePackage=PanelPackageManifest::make('incompatible-package')->requires('php','>=999.0.0');
			$incompatible=PanelPackageInstallPlan::make(
				dp_panel_package_install_plan_template($incompatiblePackage),
				'',
				['runtime'=>['php'=>'1.0.0','modules'=>[],'themes'=>[]]]
			)->apply($base,['dry_run'=>true]);
			$t->same(false,$incompatible->ok());
			$t->same(true,dp_panel_package_install_plan_has_reason($incompatible->blocked(),'compatibility or trust'));

			$trustPolicy=PanelPackageTrustPolicy::make(['revoked_packages'=>['revoked-package']]);
			$untrusted=PanelPackageInstallPlan::make(
				dp_panel_package_install_plan_template('revoked-package'),
				'',
				['trust_policy'=>$trustPolicy]
			)->apply($base,['dry_run'=>true]);
			$t->same(false,$untrusted->ok());

			$backupFailureRoot=$base.DIRECTORY_SEPARATOR.'backup-failure';
			mkdir($backupFailureRoot,0775,true);
			file_put_contents($backupFailureRoot.DIRECTORY_SEPARATOR.'old.txt','old');
			$backupBlocker=$base.DIRECTORY_SEPARATOR.'backup-blocker';
			file_put_contents($backupBlocker,'not a directory');
			$backupFailure=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('backup-failure-package',[
				'old.txt'=>'new',
			]))->overwritePolicy('replace')->apply($backupFailureRoot,['backup_root'=>$backupBlocker]);
			$t->same(false,$backupFailure->ok());
			$t->same(true,dp_panel_package_install_plan_has_reason($backupFailure->blocked(),'could not be backed up'));

			$directoryRoot=$base.DIRECTORY_SEPARATOR.'directory-failure';
			mkdir($directoryRoot,0775,true);
			file_put_contents($directoryRoot.DIRECTORY_SEPARATOR.'nested','file blocks directory');
			$directoryFailure=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('directory-failure-package',[
				'nested/artifact.txt'=>'content',
			]))->apply($directoryRoot);
			$t->same(false,$directoryFailure->ok());
			$t->same(true,dp_panel_package_install_plan_has_reason($directoryFailure->blocked(),'directory could not be created'));

			$writeRoot=$base.DIRECTORY_SEPARATOR.'write-failure';
			mkdir($writeRoot.DIRECTORY_SEPARATOR.'occupied',0775,true);
			$writeFailure=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('write-failure-package',[
				'occupied'=>'content',
			]))->apply($writeRoot);
			$t->same(false,$writeFailure->ok());
			$t->same(true,dp_panel_package_install_plan_has_reason($writeFailure->blocked(),'could not be written'));

			$shortRoot=$base.DIRECTORY_SEPARATOR.'short-write';
			mkdir($shortRoot,0775,true);
			$filesystem->shortWrites();
			try{
				$shortWrite=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('short-write-package',[
					'artifact.txt'=>'complete contents',
				]))->apply($shortRoot);
			}
			finally {
				$filesystem->shortWrites(false);
			}
			$t->same(false,$shortWrite->ok());
			$t->same(true,dp_panel_package_install_plan_has_reason($shortWrite->blocked(),'could not be written'));

			$raceRoot=$base.DIRECTORY_SEPARATOR.'race-root';
			$outside=$base.DIRECTORY_SEPARATOR.'outside';
			mkdir($raceRoot,0775,true);
			mkdir($outside,0775,true);
			$raceDirectory=$raceRoot.DIRECTORY_SEPARATOR.'raced';
			$filesystem->realpathUsing(static function(string $path)use($raceDirectory,$outside): string|false|null {
				return str_replace('\\','/',$path)===str_replace('\\','/',$raceDirectory) && is_dir($raceDirectory) ? $outside : null;
			});
			try{
				$race=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('race-package',[
					'raced/artifact.txt'=>'content',
				]))->apply($raceRoot);
			}
			finally {
				$filesystem->useNativeRealpath();
			}
			$t->same(false,$race->ok());
			$t->same(true,dp_panel_package_install_plan_has_reason($race->blocked(),'changed to a location outside'));
		}
		finally {
			$filesystem->shortWrites(false)->useNativeRealpath();
		}
	});

	test('panel package install plan honors before and after dialback result overrides',static function(Context $t): void {
		$root=dp_panel_package_install_plan_tmp($t,'dialbacks');
		$beforeObjectTemplate=dp_panel_package_install_plan_template('before-object');
		$beforeArrayTemplate=dp_panel_package_install_plan_template('before-array');
		$afterObjectTemplate=dp_panel_package_install_plan_template('after-object');
		$afterArrayTemplate=dp_panel_package_install_plan_template('after-array');
		$mutationTemplate=PanelPackageTemplate::make('mutation-package')
			->provider(false)
			->docs(false)
			->tests(false)
			->with('marketplace',false);
		$beforeObjectId=$beforeObjectTemplate->package()->id();
		$beforeArrayId=$beforeArrayTemplate->package()->id();
		$afterObjectId=$afterObjectTemplate->package()->id();
		$afterArrayId=$afterArrayTemplate->package()->id();
		$mutationId=$mutationTemplate->package()->id();
		$beforeObject=PanelPackageApplyResult::make(['ok'=>true,'meta'=>['source'=>'before-object']]);
		$afterObject=PanelPackageApplyResult::make(['ok'=>true,'meta'=>['source'=>'after-object']]);
		\dataphyre\core::register_dialback('CALL_PANEL_FRAMEWORK_PACKAGE_BEFORE_APPLY',static function(array $payload)use($beforeObjectId,$beforeArrayId,$mutationId,$mutationTemplate,$beforeObject): PanelPackageApplyResult|array|null {
			if(($payload['package_id'] ?? '')===$mutationId){
				$mutationTemplate->plugin(false);
				return null;
			}
			return match($payload['package_id'] ?? ''){
				$beforeObjectId=>$beforeObject,
				$beforeArrayId=>['ok'=>false,'meta'=>['source'=>'before-array']],
				default=>null,
			};
		});
		\dataphyre\core::register_dialback('CALL_PANEL_FRAMEWORK_PACKAGE_AFTER_APPLY',static function(array $payload)use($afterObjectId,$afterArrayId,$afterObject): PanelPackageApplyResult|array|null {
			return match($payload['package_id'] ?? ''){
				$afterObjectId=>$afterObject,
				$afterArrayId=>['ok'=>false,'meta'=>['source'=>'after-array']],
				default=>null,
			};
		});

		$fromBeforeObject=PanelPackageInstallPlan::make($beforeObjectTemplate)->apply($root,['dry_run'=>true]);
			$fromBeforeArray=PanelPackageInstallPlan::make($beforeArrayTemplate)->apply($root,['dry_run'=>true]);
			$fromAfterObject=PanelPackageInstallPlan::make($afterObjectTemplate)->apply($root,['dry_run'=>true]);
			$fromAfterArray=PanelPackageInstallPlan::make($afterArrayTemplate)->apply($root,['dry_run'=>true]);
			$fromMutation=PanelPackageInstallPlan::make($mutationTemplate)->apply($root,['dry_run'=>true]);
			$t->same($beforeObject,$fromBeforeObject);
			$t->same('before-array',$fromBeforeArray->toArray()['meta']['source']);
			$t->same($afterObject,$fromAfterObject);
			$t->same('after-array',$fromAfterArray->toArray()['meta']['source']);
			$t->same(false,$fromMutation->ok());
		$t->same(true,dp_panel_package_install_plan_has_reason($fromMutation->blocked(),'contents are unavailable'));
	});

	test('panel package install plan resolves filesystem and backup helper edges',static function(Context $t): void {
		$base=dp_panel_package_install_plan_tmp($t,'helpers');
		$filesystem=PackageFilesystemScenario::reset($t);
		$plan=PanelPackageInstallPlan::make(dp_panel_package_install_plan_template('helper-package'));
		try{
			$t->same('',$t->nonPublic($plan)->invoke('resolveRoot','',true));
			$t->same(realpath($base),$t->nonPublic($plan)->invoke('resolveRoot',$base,true));
			$created=$base.DIRECTORY_SEPARATOR.'created';
			$t->same(
				str_replace('\\','/',realpath($created) ?: $created),
				str_replace('\\','/',(string)$t->nonPublic($plan)->invoke('resolveRoot',$created,true))
			);
			$t->same(true,is_dir($created));
			$preview=$base.DIRECTORY_SEPARATOR.'preview';
			$t->same(
				str_replace('\\','/',$preview),
				str_replace('\\','/',(string)$t->nonPublic($plan)->invoke('resolveRoot',$preview,false))
			);
			$blocker=$base.DIRECTORY_SEPARATOR.'blocker';
			file_put_contents($blocker,'file');
			$t->same('',$t->nonPublic($plan)->invoke('resolveRoot',$blocker,true));
			$t->same('',$t->nonPublic($plan)->invoke('resolveRoot',$base.DIRECTORY_SEPARATOR.'missing-parent'.DIRECTORY_SEPARATOR.'child',false));

			$t->same(false,$t->nonPublic($plan)->invoke('pathWithinRoot','',$base));
			$t->same(false,$t->nonPublic($plan)->invoke('pathWithinRoot',$base,''));
			$t->same(false,$t->nonPublic($plan)->invoke('pathWithinRoot',$base.'-sibling'.DIRECTORY_SEPARATOR.'file',$base));
			$t->same(false,$t->nonPublic($plan)->invoke('pathWithinRoot',$base.DIRECTORY_SEPARATOR.'ghost'.DIRECTORY_SEPARATOR.'file',$base.DIRECTORY_SEPARATOR.'ghost'));
			$t->same(true,$t->nonPublic($plan)->invoke('pathWithinRoot',$base.DIRECTORY_SEPARATOR.'inside'.DIRECTORY_SEPARATOR.'file',$base));

			$fakeRoot='Z:'.DIRECTORY_SEPARATOR.'ghost-root';
			$filesystem
				->realpathUsing(static fn(string $path): string|false|null=>$path===$fakeRoot ? $fakeRoot : null)
				->hideFilesystemEntries();
			try{
				$t->same(false,$t->nonPublic($plan)->invoke('pathWithinRoot',$fakeRoot.DIRECTORY_SEPARATOR.'child',$fakeRoot));
			}
			finally {
				$filesystem->useNativeRealpath()->hideFilesystemEntries(false);
			}

			$target=$base.DIRECTORY_SEPARATOR.'target.txt';
			file_put_contents($target,'target contents');
			$t->same(null,$t->nonPublic($plan)->invoke('backupTarget',$target,'target.txt','helper-package','',false));
			$t->same(null,$t->nonPublic($plan)->invoke('backupTarget',$target,'target.txt','helper-package',$blocker,false));

			$containmentBackups=$base.DIRECTORY_SEPARATOR.'containment-backups';
			mkdir($containmentBackups,0775,true);
			$t->same(null,$t->nonPublic($plan)->invoke('backupTarget',$target,'../../../../escape.txt','helper-package',$containmentBackups,true));
			$dryBackup=$t->nonPublic($plan)->invoke('backupTarget',$target,'target.txt','helper-package',$containmentBackups,true);
			$t->same(true,is_array($dryBackup));
			$t->same(true,$dryBackup['dry_run']);
			$t->same(strlen('target contents'),$dryBackup['bytes']);

			$realBackups=$base.DIRECTORY_SEPARATOR.'real-backups';
			$realBackup=$t->nonPublic($plan)->invoke('backupTarget',$target,'target.txt','helper-package',$realBackups,false);
			$t->same(true,is_array($realBackup));
			$t->same('target contents',file_get_contents($realBackup['backup']));

			$directoryBackups=$base.DIRECTORY_SEPARATOR.'directory-backups';
			mkdir($directoryBackups,0775,true);
			file_put_contents($directoryBackups.DIRECTORY_SEPARATOR.'blocked','file blocks directory');
			$t->same(null,$t->nonPublic($plan)->invoke('backupTarget',$target,'target.txt','blocked',$directoryBackups,false));

			$copyBackups=$base.DIRECTORY_SEPARATOR.'copy-backups';
			mkdir($copyBackups,0775,true);
			$t->same(null,$t->nonPublic($plan)->invoke('backupTarget',$base.DIRECTORY_SEPARATOR.'missing-target','target.txt','copy-failure',$copyBackups,false));
		}
		finally {
			$filesystem->useNativeRealpath()->hideFilesystemEntries(false);
		}
	});
}
