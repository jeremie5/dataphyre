<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/application_runtime_process_broker.php';

suite('Internal application bootstrap-only boundary')
	->contract('core.internal-application-bootstrap-only',1)
	->layer('integration')->risk('critical')->watches('module:core','module:scheduling','module:mvc','module:http')
	->isolation('case')->through('fixed-entrypoint','immutable-context','read-only-bootstrap','canonical-evidence')
	->tag('core','runtime','release','security','sql')->group('framework-coverage');

function dp_bootstrap_only_exact_native_runtime(): bool
{
	return \function_exists('posix_geteuid') && \posix_geteuid()===0
		&& \getenv('DATAPHYRE_TEST_CONTAINER_ROOT')==='1'
		&& \extension_loaded('dataphyre_environment_fd')
		&& \phpversion('dataphyre_environment_fd')==='1.2.0'
		&& \function_exists('dataphyre_open_inherited_environment_fd')
		&& \function_exists('dataphyre_close_inherited_fd')
		&& \function_exists('dataphyre_close_unlisted_inherited_fds')
		&& \function_exists('dataphyre_managed_pool_request_context')
		&& \is_executable('/usr/bin/setpriv');
}

/** @return array<string,array<string,mixed>> */
function dp_bootstrap_only_snapshot(string $root): array {
	$entries=[];
	$iterator=new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST,
	);
	foreach($iterator as $entry){
		$path=$entry->getPathname();$relative=str_replace('\\','/',substr($path,strlen(rtrim($root,'/\\'))+1));
		$entries[$relative]=$entry->isLink()
			? ['type'=>'link','target'=>readlink($path)]
			: ($entry->isDir() ? ['type'=>'directory'] : [
				'type'=>'file','bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),
			]);
	}
	ksort($entries,SORT_STRING);return $entries;
}

/** @return list<string> */
function dp_bootstrap_only_lines(string $output): array {
	return array_values(array_filter(explode("\n",$output),static fn(string $line): bool=>$line!==''));
}

/** @return array<string,string> */
function dp_bootstrap_only_environment(string $runtimeRoot,string $stateRoot,array $extra=[]): array {
	return [
		'DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'=>$runtimeRoot,
		'DATAPHYRE_RUNTIME_TEST_STATE_ROOT'=>$stateRoot,
		...$extra,
	];
}

test('relative direct materializer uses immutable read-only bootstrap and blocks request scheduler and response side effects',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$runtimeRoot=$frameworkRoot.'/runtime';
	$project=__DIR__.'/fixtures/application_runtime_project';$before=dp_bootstrap_only_snapshot($project);
	$state=$t->workspace('bootstrap-only-materializer-state');
	$evidence=$state->path('evidence.json');$shutdown=$state->path('shutdown.json');
	$schedulerCallback=$state->path('scheduler-callback');$mvcHandler=$state->path('mvc-handler');
	$result=$t->phpProcess([
		'runtime/modules/sql/kernel/materialize_registered_tables.php',
		'--project-root='.$project,'--application=_Runtime$Probe','--environment=staging',
	],working_directory:$frameworkRoot,environment:dp_bootstrap_only_environment($runtimeRoot,$state->root(),[
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_MATERIALIZER'=>'1',
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_EVIDENCE'=>$evidence,
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_SHUTDOWN'=>$shutdown,
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_SCHEDULER_MUTATION'=>'1',
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_SCHEDULER_CALLBACK'=>$schedulerCallback,
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_MVC_HANDLER'=>$mvcHandler,
	]));
	$t->processSucceeded($result,$result->stderr());$t->same('',trim($result->stderr()));
	$t->same(1,count(dp_bootstrap_only_lines($result->stdout())));$payload=$result->json();
	$t->same(true,$payload['ok']);$t->same(2,$payload['registered_count']);$t->same(2,$payload['materialized_count']);
	$probe=json_decode((string)file_get_contents($evidence),true,16,JSON_THROW_ON_ERROR);
	$t->same('registered-table-materialization',$probe['purpose']);$t->same(true,$probe['scheduler_accepted']);
	$t->same(204,$probe['mvc_status']);$t->same('',$probe['mvc_body']);
	$t->same(true,$probe['emitter_status_unchanged']);$t->same(true,$probe['unavailable_rejected']);
	$t->same(true,$probe['reflection_activation_rejected']);
	$t->startsWith(rtrim($state->root(),'/\\').'/cache/scheduling/',$probe['scheduler_directory']);
	$shutdownProbe=json_decode((string)file_get_contents($shutdown),true,8,JSON_THROW_ON_ERROR);
	$t->same(['purpose'=>'registered-table-materialization','state_root_present'=>true],$shutdownProbe);
	$t->isFalse(file_exists($schedulerCallback));$t->isFalse(file_exists($mvcHandler));
	$t->isFalse(is_dir($state->path('cache/scheduling')));
	$t->same($before,dp_bootstrap_only_snapshot($project));
	$t->isFalse(str_contains($result->stdout(),'forbidden-'));
})->tag('materializer','relative-cli','read-only','mvc','response','scheduling','namespace-hijack','positive','security');

test('realtime registration preflight retains its inode and swallow boundary through shutdown',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$runtimeRoot=$frameworkRoot.'/runtime';
	$project=__DIR__.'/fixtures/application_runtime_project';$state=$t->workspace('bootstrap-only-preflight-state');
	$evidence=$state->path('evidence.json');$shutdown=$state->path('shutdown.json');
	$schedulerCallback=$state->path('scheduler-callback');$mvcHandler=$state->path('mvc-handler');
	$result=$t->phpProcess([
		'runtime/modules/core/kernel/application_release_preflight_realtime.php',
		'--project-root='.$project,'--application=_Runtime$Probe','--environment=staging',
	],working_directory:$frameworkRoot,environment:dp_bootstrap_only_environment($runtimeRoot,$state->root(),[
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_MATERIALIZER'=>'1',
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_EVIDENCE'=>$evidence,
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_SHUTDOWN'=>$shutdown,
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_GLOBAL_POISON'=>'1',
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_SCHEDULER_MUTATION'=>'1',
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_SCHEDULER_CALLBACK'=>$schedulerCallback,
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_MVC_HANDLER'=>$mvcHandler,
	]));
	$t->processSucceeded($result,$result->stderr());$t->same('',trim($result->stderr()));
	$t->same(1,count(dp_bootstrap_only_lines($result->stdout())));$payload=$result->json();
	$t->same('dataphyre.application_realtime_registration.v1',$payload['contract']);$t->same(true,$payload['ok']);
	$t->same(2,$payload['registered_table_count']);$t->same(2,$payload['scheduler_definition_count']);
	$probe=json_decode((string)file_get_contents($evidence),true,16,JSON_THROW_ON_ERROR);
	$t->same('release-preflight-registration',$probe['purpose']);$t->same(true,$probe['scheduler_accepted']);
	$t->same(204,$probe['mvc_status']);$t->same(true,$probe['emitter_status_unchanged']);
	$t->same(true,$probe['unavailable_rejected']);$t->same(true,$probe['reflection_activation_rejected']);
	$t->same(realpath($project),$probe['cwd']);$t->same(false,$probe['terminal_writer_callable']);
	$t->matches('#^/tmp/dataphyre-realtime-preflight-[a-f0-9]{32}/cache/scheduling/runtime\.bootstrap-only/$#D',$probe['scheduler_directory']);
	$t->isTrue(is_file($probe['scheduler_directory'].'properties.json'));
	foreach(['running_lock','last_run','last_success'] as $forbidden) $t->isFalse(file_exists($probe['scheduler_directory'].$forbidden));
	$shutdownProbe=json_decode((string)file_get_contents($shutdown),true,8,JSON_THROW_ON_ERROR);
	$t->same(['purpose'=>'release-preflight-registration','state_root_present'=>true],$shutdownProbe);
	$t->isFalse(file_exists($schedulerCallback));$t->isFalse(file_exists($mvcHandler));
	$t->isFalse(str_contains($result->stdout(),'forbidden-'));$t->isFalse(str_contains($result->stdout(),'tenant-forgery'));
})->tag('realtime','preflight','shutdown','inode','record-only','namespace-hijack','positive','security');

test('realtime preflight rejects context state proof request key and output-buffer replacement',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$runtimeRoot=$frameworkRoot.'/runtime';$project=__DIR__.'/fixtures/application_runtime_project';
	foreach([
		'state-delete','state-replace','private-key','proof-private-key','proof-token',
		'transport-environment','transport-argv','transport-request','output-buffer-replace',
		'deferred-transport-environment','deferred-output-buffer-replace','deferred-cwd',
	] as $mutation){
		$state=$t->workspace('bootstrap-only-mutation-'.$mutation);$shutdown=$state->path('shutdown.json');
		$result=$t->phpProcess([
			'runtime/modules/core/kernel/application_release_preflight_realtime.php',
			'--project-root='.$project,'--application=_Runtime$Probe','--environment=staging',
		],working_directory:$frameworkRoot,environment:dp_bootstrap_only_environment($runtimeRoot,$state->root(),[
			'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_MUTATION'=>$mutation,
			'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_SHUTDOWN'=>$shutdown,
		]));
		$t->processFailed($result,70,$mutation.': '.$result->stderr());$t->same('',trim($result->stderr()),$mutation);
		$t->same(1,count(dp_bootstrap_only_lines($result->stdout())),$mutation);$payload=$result->json();
		$t->same(false,$payload['ok'],$mutation);$t->same(0,$payload['route_count'],$mutation);
		$t->isFalse(str_contains($result->stdout(),'forbidden-shutdown-output'),$mutation);
	}
})->tag('realtime','preflight','mutation','reflection','output-buffer','negative','security');

test('realtime preflight terminal sentinel turns bootstrap and deferred-registry exit into one canonical failure',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$runtimeRoot=$frameworkRoot.'/runtime';$project=__DIR__.'/fixtures/application_runtime_project';
	foreach(['bootstrap-exit','deferred-exit'] as $mutation){
		$state=$t->workspace('bootstrap-only-terminal-'.$mutation);
		$result=$t->phpProcess([
			'runtime/modules/core/kernel/application_release_preflight_realtime.php',
			'--project-root='.$project,'--application=_Runtime$Probe','--environment=staging',
		],working_directory:$frameworkRoot,environment:dp_bootstrap_only_environment($runtimeRoot,$state->root(),[
			'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_MATERIALIZER'=>'1',
			'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_MUTATION'=>$mutation,
		]));
		$t->processFailed($result,70,$mutation.': '.$result->stderr());$t->same('',trim($result->stderr()),$mutation);
		$t->same(1,count(dp_bootstrap_only_lines($result->stdout())),$mutation);
		$payload=$result->json();$t->same(false,$payload['ok'],$mutation);$t->same(0,$payload['route_count'],$mutation);
	}
})->tag('realtime','preflight','terminal-sentinel','exit','deferred-registry','negative','security');

test('root broker activates the non-help one-shot materializer in the unprivileged application process',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$runtimeRoot=$frameworkRoot.'/runtime';
	$project=__DIR__.'/fixtures/application_runtime_project';
	$worker=$runtimeRoot.'/modules/core/kernel/application_runtime_one_shot_worker.php';
	$target=$runtimeRoot.'/modules/sql/kernel/materialize_registered_tables.php';
	$child=DataphyreApplicationRuntimeProcessBroker::spawn([
		'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
		'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
		PHP_BINARY,$worker,'dataphyre_materialize_tables',$target,
		'--project-root='.$project,'--application=_Runtime$Probe','--environment=staging',
	],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$frameworkRoot,[
		'PHP_INI_SCAN_DIR'=>(string)getenv('PHP_INI_SCAN_DIR'),
	],'one-shot',[
		'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project,
		'DATAPHYRE_RUNTIME_APPLICATION'=>'_Runtime$Probe',
		'DATAPHYRE_FRAMEWORK_APPLICATION'=>'_Runtime$Probe',
		'DATAPHYRE_APPLICATION_ID'=>'Runtime:Probe_2',
		'DATAPHYRE_APPLICATION_ENVIRONMENT_ID'=>'Env:Materializer_Probe',
		'DATAPHYRE_ENVIRONMENT'=>'staging','DATAPHYRE_RUNTIME_ENVIRONMENT'=>'staging',
		'DATAPHYRE_APPLICATION_RELEASE'=>'dep_'.str_repeat('a',40),
		'DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'=>$runtimeRoot,
		'DATAPHYRE_RUNTIME_TEST_STATE_ROOT'=>'/tmp',
		'DATAPHYRE_RUNTIME_TEST_BOOTSTRAP_ONLY_MATERIALIZER'=>'1',
	],5000);
	$stdout=(string)stream_get_contents($child['pipes'][1]);$stderr=(string)stream_get_contents($child['pipes'][2]);
	fclose($child['pipes'][1]);fclose($child['pipes'][2]);$exit=proc_close($child['resource']);
	$t->same(0,$exit,$stderr);$t->same('',$stderr);$t->isTrue($child['identity']['pid']>1);
	$payload=json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
	$t->same(true,$payload['ok']);$t->same(2,$payload['registered_count']);$t->same(2,$payload['materialized_count']);
	$t->same(1,count(dp_bootstrap_only_lines($stdout)));
})->tag('one-shot','materializer','broker','setpriv','non-help','exact-image','positive','security')->maxMillis(15000)
	->skipUnless(
		dp_bootstrap_only_exact_native_runtime(),
		'Requires the canonical root test image with environment_fd 1.2 and setpriv.',
	);

test('fixed entrypoints reject symlink and wrapper launch identity before application bootstrap',static function(Context $t): void {
	$frameworkRoot=dirname(__DIR__,4);$runtimeRoot=$frameworkRoot.'/runtime';$project=__DIR__.'/fixtures/application_runtime_project';
	$state=$t->workspace('bootstrap-only-entrypoint-negatives');
	$materializer=$runtimeRoot.'/modules/sql/kernel/materialize_registered_tables.php';
	$preflight=$runtimeRoot.'/modules/core/kernel/application_release_preflight_realtime.php';
	$materializerLink=$state->path('materializer.php');$preflightLink=$state->path('preflight.php');
	if(!symlink($materializer,$materializerLink) || !symlink($preflight,$preflightLink)) throw new RuntimeException('Entrypoint symlinks could not be prepared.');
	foreach([
		[$materializerLink,'--project-root='.$project,'--application=_Runtime$Probe','--environment=staging'],
		[$preflightLink,'--project-root='.$project,'--application=_Runtime$Probe','--environment=staging'],
	] as $arguments){
		$result=$t->phpProcess($arguments,environment:dp_bootstrap_only_environment($runtimeRoot,$state->root()));
		$t->processFailed($result,64);$t->same('',$result->stdout());$t->same('',$result->stderr());
	}
	$wrapper=$state->file('wrapper.php',"<?php\n\$target=\$argv[1];\n\$_SERVER['SCRIPT_FILENAME']=\$target;\n\$_SERVER['argv']=[\$target,'--help'];\n\$GLOBALS['argv']=[\$target.'.mismatch','--help'];\nrequire \$target;\n");
	$wrapped=$t->phpProcess([$wrapper,$materializer]);
	$t->processFailed($wrapped,64);$t->same('',$wrapped->stdout());$t->same('',$wrapped->stderr());
	$preflightSource=(string)file_get_contents($preflight);
	$t->isFalse(str_contains($preflightSource,'dataphyre_realtime_preflight_remove_tree'));
})->tag('entrypoint','symlink','wrapper','argv','negative','security');
