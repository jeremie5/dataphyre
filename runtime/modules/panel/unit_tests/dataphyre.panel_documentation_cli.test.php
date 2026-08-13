<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\ProcessResult;
use function Dataphyre\Test\test;

function dp_panel_docs_cli(Context $t,array $arguments,string $cwd): ProcessResult {
	return $t->phpProcess([
		dirname(__DIR__,4).DIRECTORY_SEPARATOR.'dev'.DIRECTORY_SEPARATOR.'tools'.DIRECTORY_SEPARATOR.'panel_docs.php',
		...$arguments,
	], working_directory:$cwd);
}

test('panel documentation cli is confined preview first explicit and machine readable',static function(Context $t): void {
	$root=$t->tempDirectory('panel-doc-cli');
	mkdir($root.DIRECTORY_SEPARATOR.'src');
	file_put_contents($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Demo.php',<<<'PHP'
<?php
namespace Example;
/** CLI demonstration. */
final class Demo { public function run(): bool { return true; } }
PHP);
	file_put_contents($root.DIRECTORY_SEPARATOR.'paths.json',json_encode(['Demo.php'],JSON_THROW_ON_ERROR));
	file_put_contents($root.DIRECTORY_SEPARATOR.'catalog.json',json_encode(['entries'=>[
		['id'=>'start','title'=>'Start','status'=>'published','examples'=>[['code'=>'new Demo();','language'=>'php']]],
	]],JSON_THROW_ON_ERROR));
	file_put_contents($root.DIRECTORY_SEPARATOR.'packages.json',json_encode(['packages'=>[
		['id'=>'example-package','version'=>'1.0.0'],
	], 'runtime'=>['php'=>PHP_VERSION,'panel'=>'2.0','reactor'=>'2.0','modules'=>[],'themes'=>[]]],JSON_THROW_ON_ERROR));
	file_put_contents($root.DIRECTORY_SEPARATOR.'meta.json',json_encode(['channel'=>'stable'],JSON_THROW_ON_ERROR));
	file_put_contents($root.DIRECTORY_SEPARATOR.'portal.json',json_encode([
		'language'=>'en-CA',
		'default_theme'=>'dark',
		'version_links'=>['1.3.0'=>'','1.2.3'=>'../1.2.3/index.html'],
		'canonical_base_url'=>'https://docs.example.test/panel/1.3.0/',
		'repository_url'=>'https://code.example.test/dataphyre/panel',
		'maximum_search_text_bytes'=>2048,
	],JSON_THROW_ON_ERROR));
	$arguments=['--root',$root,'--source','src','--output','docs/panel','--version','1.2.3','--paths','paths.json','--catalog','catalog.json','--packages','packages.json','--meta','meta.json'];
	$preview=dp_panel_docs_cli($t,$arguments,$root);
	$t->processSucceeded($preview);
	$t->same('',$preview->stderr());
	$payload=$preview->json();
	$t->isTrue($payload['ok']);
	$t->same('preview',$payload['mode']);
	$t->same(1,$payload['publication']['manifest']['api_symbol_count']);
	$t->same('stable',$payload['publication']['manifest']['meta']['channel']);
	$index=$root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'panel'.DIRECTORY_SEPARATOR.'versions'.DIRECTORY_SEPARATOR.'1.2.3'.DIRECTORY_SEPARATOR.'index.md';
	$t->isFalse(is_file($index));
	$portalArguments=$arguments;
	$versionIndex=array_search('1.2.3',$portalArguments,true);
	$t->isTrue(is_int($versionIndex));
	$portalArguments[$versionIndex]='1.3.0';
	$portalArguments=[...$portalArguments,'--portal','--portal-config','portal.json'];
	$portalPreview=dp_panel_docs_cli($t,$portalArguments,$root);
	$t->processSucceeded($portalPreview);
	$t->same('',$portalPreview->stderr());
	$portalPayload=$portalPreview->json();
	$t->same('preview',$portalPayload['mode']);
	$t->same('dataphyre_datadoc_static_portal',$portalPayload['publication']['manifest']['portal']['type']);
	$t->same('en-CA',$portalPayload['publication']['manifest']['portal']['language']);
	$t->same('dark',$portalPayload['publication']['manifest']['portal']['default_theme']);
	$t->isTrue($portalPayload['publication']['manifest']['portal']['capabilities']['local_search']);
	$portalRoot=$root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'panel'.DIRECTORY_SEPARATOR.'versions'.DIRECTORY_SEPARATOR.'1.3.0';
	$t->isFalse(is_dir($portalRoot));
	$portalWrite=dp_panel_docs_cli($t,[...$portalArguments,'--write'],$root);
	$t->processSucceeded($portalWrite);
	$t->same('write',$portalWrite->json()['mode']);
	foreach(['index.md','index.html','assets'.DIRECTORY_SEPARATOR.'favicon.svg','assets'.DIRECTORY_SEPARATOR.'portal.css','assets'.DIRECTORY_SEPARATOR.'portal.js','search-index.json','portal.json','sitemap.xml'] as $relative){
		$t->isTrue(is_file($portalRoot.DIRECTORY_SEPARATOR.$relative));
	}
	$t->contains('Content-Security-Policy',(string)file_get_contents($portalRoot.DIRECTORY_SEPARATOR.'index.html'));
	$portalIdempotent=dp_panel_docs_cli($t,[...$portalArguments,'--write'],$root);
	$t->processSucceeded($portalIdempotent);
	$t->isFalse($portalIdempotent->json()['result']['changed']);

	$write=dp_panel_docs_cli($t,[...$arguments,'--write'],$root);
	$t->processSucceeded($write);
	$t->same('write',$write->json()['mode']);
	$t->isTrue(is_file($index));
	$t->contains('1 declared public PHP types',file_get_contents($index));
	file_put_contents($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Demo.php',"\n",FILE_APPEND);
	$conflict=dp_panel_docs_cli($t,[...$arguments,'--write'],$root);
	$t->processFailed($conflict,1);
	$conflictPayload=$conflict->stderrJson();
	$t->same('operation_failed',$conflictPayload['error_code']);
	$t->notContains(str_replace('\\','/',$root),str_replace('\\','/',$conflictPayload['message']));

	$help=dp_panel_docs_cli($t,['--help'],$root);
	$t->processSucceeded($help);
	$helpPayload=$help->json();
	$t->isTrue($helpPayload['safety']['source_not_executed']);
	$t->isTrue($helpPayload['safety']['portal_opt_in']);
	$t->isFalse($helpPayload['safety']['portal_external_dependencies']);
	$t->contains('--portal',$helpPayload['usage']);
	foreach([
		['--unknown'],
		['--version','1.0.0','--version','1.0.1'],
		['--write=true'],
		['--portal=true'],
		['--version','1.0.0','--portal-config','portal.json'],
		['--version','1.0.0','--portal','--portal-config','missing.json'],
		['--version','1.0.0','--policy','skip'],
		['--version','1.0.0','--policy','replace'],
		['positional'],
		['--version'],
	] as $invalid){
		$result=dp_panel_docs_cli($t,$invalid,$root);
		$t->processFailed($result,1);
		$t->same('',$result->stdout());
		$t->isFalse($result->stderrJson()['ok']);
	}

	$outside=$t->tempDirectory('panel-doc-cli-outside');
	file_put_contents($outside.DIRECTORY_SEPARATOR.'paths.json','[]');
	$escaped=dp_panel_docs_cli($t,['--root',$root,'--source','src','--version','1.0.0','--paths',$outside.DIRECTORY_SEPARATOR.'paths.json'],$root);
	$t->processFailed($escaped,1);
	$t->contains('Panel documentation input',$escaped->stderrJson()['message']);
	file_put_contents($root.DIRECTORY_SEPARATOR.'broken.json','{');
	$broken=dp_panel_docs_cli($t,['--root',$root,'--source','src','--version','1.0.0','--paths','broken.json'],$root);
	$t->processFailed($broken,1);
	$t->contains('invalid',strtolower($broken->stderrJson()['message']));
	$lock=$root.DIRECTORY_SEPARATOR.'.dataphyre-panel-docs.lock';
	if(is_file($lock)){ unlink($lock); }
	mkdir($lock);
	$hostileLock=dp_panel_docs_cli($t,['--root',$root,'--source','src','--version','2.0.0','--write'],$root);
	$t->processFailed($hostileLock,1);
	$t->same('',$hostileLock->stdout());
	$hostilePayload=$hostileLock->stderrJson();
	$t->isFalse($hostilePayload['ok']);
	$t->same('operation_failed',$hostilePayload['error_code']);
	$t->notContains(str_replace('\\','/',$root),str_replace('\\','/',$hostilePayload['message']));
})->tag('panel','documentation','cli','process','security')->maxMillis(20000);
