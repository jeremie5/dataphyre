<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

/** @return array{code:int,stdout:string,stderr:string} */
function dp_panel_scaffold_cli(Context $t,array $arguments,string $cwd): array {
	$result=$t->phpProcess([
		dirname(__DIR__,4).DIRECTORY_SEPARATOR.'dev'.DIRECTORY_SEPARATOR.'tools'.DIRECTORY_SEPARATOR.'panel_scaffold.php',
		...$arguments,
	], working_directory:$cwd);
	return ['code'=>$result->exitCode(),'stdout'=>$result->stdout(),'stderr'=>$result->stderr()];
}

test('panel scaffold cli is preview first explicit transactional and machine readable',static function(Context $t): void {
	$root=$t->tempDirectory('panel-scaffold-cli');
	$preview=dp_panel_scaffold_cli($t,['--kind','resource','--class','CliResource','--root',$root],$root);
	$t->same(0,$preview['code']);
	$t->same('',$preview['stderr']);
	$payload=json_decode($preview['stdout'],true,512,JSON_THROW_ON_ERROR);
	$t->isTrue($payload['ok']);
	$t->same('preview',$payload['mode']);
	$t->isTrue($payload['result']['dry_run']);
	$target=$root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Panel'.DIRECTORY_SEPARATOR.'Resources'.DIRECTORY_SEPARATOR.'CliResource.php';
	$t->isFalse(is_file($target));

	$write=dp_panel_scaffold_cli($t,['--kind=resource','--class=CliResource','--root='.$root,'--write'],$root);
	$t->same(0,$write['code']);
	$t->isTrue(is_file($target));
	$t->contains('final class CliResource',file_get_contents($target));
	$t->same('write',json_decode($write['stdout'],true,512,JSON_THROW_ON_ERROR)['mode']);

	$unknown=dp_panel_scaffold_cli($t,['--unknown'],$root);
	$t->same(1,$unknown['code']);
	$t->same('',trim($unknown['stdout']));
	$error=json_decode($unknown['stderr'],true,512,JSON_THROW_ON_ERROR);
	$t->isFalse($error['ok']);
	$t->same(InvalidArgumentException::class,$error['exception']);
})->tag('panel','scaffolding','cli','process')->maxMillis(10000);

test('panel scaffold cli consumes suite configs and composer dev namespaces without implicit writes',static function(Context $t): void {
	$root=$t->tempDirectory('panel-scaffold-cli-suite');
	file_put_contents($root.DIRECTORY_SEPARATOR.'composer.json',json_encode([
		'autoload'=>['psr-4'=>['Product\\'=>'src/Panel/']],
		'autoload-dev'=>['psr-4'=>['ProductTests\\'=>'tests/Panel/']],
	],JSON_THROW_ON_ERROR));
	$config=$root.DIRECTORY_SEPARATOR.'panel-scaffold.json';
	file_put_contents($config,json_encode([
		'root'=>'.',
		'base_path'=>'src/Panel',
		'test_base_path'=>'tests/Panel',
		'artifacts'=>[
			['kind'=>'page','class'=>'ReportPage'],
			['kind'=>'test','class'=>'ReportPageTest','options'=>['resource'=>'reports']],
		],
	],JSON_THROW_ON_ERROR));
	$result=dp_panel_scaffold_cli($t,['--config',$config],$root);
	$t->same(0,$result['code']);
	$payload=json_decode($result['stdout'],true,512,JSON_THROW_ON_ERROR);
	$t->same(2,$payload['result']['counts']['created']);
	$t->same('Product\\Pages\\ReportPage',$payload['artifacts'][0]['class']);
	$t->same('ProductTests\\ReportPageTest',$payload['artifacts'][1]['class']);
	$t->isFalse(is_file($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Panel'.DIRECTORY_SEPARATOR.'Pages'.DIRECTORY_SEPARATOR.'ReportPage.php'));

	$ambiguous=dp_panel_scaffold_cli($t,['--config',$config,'--kind','page','--class','OtherPage'],$root);
	$t->same(1,$ambiguous['code']);
	$t->contains('cannot be combined',json_decode($ambiguous['stderr'],true,512,JSON_THROW_ON_ERROR)['message']);
})->tag('panel','scaffolding','cli','suite','namespace')->maxMillis(10000);
