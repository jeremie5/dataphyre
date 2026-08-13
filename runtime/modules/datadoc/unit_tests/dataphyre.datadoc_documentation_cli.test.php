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

require_once dirname(__DIR__,2).'/testing/tooling/bootstrap.php';

function dp_datadoc_docs_cli(Context $t,array $arguments,string $cwd):ProcessResult {
	return $t->phpProcess([
		dirname(__DIR__,4).DIRECTORY_SEPARATOR.'dev'.DIRECTORY_SEPARATOR.'tools'.DIRECTORY_SEPARATOR.'datadoc_docs.php',
		...$arguments,
	],working_directory:$cwd);
}

function dp_datadoc_docs_png():string {
	$contents=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',true);
	if(!is_string($contents)){ throw new RuntimeException('Unable to decode the Datadoc CLI PNG fixture.'); }
	return $contents;
}

test('Datadoc documentation CLI publishes a producer-neutral portal safely',static function(Context $t):void {
	$root=$t->tempDirectory('datadoc-doc-cli');
	$source=$root.DIRECTORY_SEPARATOR.'manual';
	mkdir($source.DIRECTORY_SEPARATOR.'guides',0775,true);
	mkdir($source.DIRECTORY_SEPARATOR.'media');
	file_put_contents($source.DIRECTORY_SEPARATOR.'index.md',"# Dataphyre Documentation\n\nUniversal documentation home.\n\n![Architecture](media/architecture.png)\n");
	file_put_contents($source.DIRECTORY_SEPARATOR.'guides'.DIRECTORY_SEPARATOR.'start.md',"# Start\n\nRead the [home](../index.md).\n");
	file_put_contents($source.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'architecture.png',dp_datadoc_docs_png());
	file_put_contents($source.DIRECTORY_SEPARATOR.'ignored.txt','not part of the Markdown corpus');
	$panelSource=$root.DIRECTORY_SEPARATOR.'panel-manual';
	mkdir($panelSource.DIRECTORY_SEPARATOR.'assets',0775,true);
	file_put_contents($panelSource.DIRECTORY_SEPARATOR.'index.md',"# Panel manual\n\n## Screenshot\n\n![Panel screenshot](assets/panel.png)\n");
	file_put_contents($panelSource.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'panel.png',dp_datadoc_docs_png());
	file_put_contents($root.DIRECTORY_SEPARATOR.'portal.json',json_encode([
		'language'=>'en-CA',
		'default_theme'=>'dark',
		'version_links'=>['1.2.3'=>''],
		'canonical_base_url'=>'https://docs.example.test/dataphyre/1.2.3/',
		'repository_url'=>'https://code.example.test/dataphyre',
		'maximum_search_text_bytes'=>2048,
	],JSON_THROW_ON_ERROR));
	file_put_contents($root.DIRECTORY_SEPARATOR.'meta.json',json_encode(['channel'=>'stable','producer'=>'dataphyre'],JSON_THROW_ON_ERROR));
	$arguments=[
		'--root',$root,
		'--source','manual',
		'--mount','modules/panel=panel-manual',
		'--output','public/docs',
		'--version','1.2.3',
		'--title','Dataphyre Documentation',
		'--portal-config','portal.json',
		'--meta','meta.json',
	];

	$preview=dp_datadoc_docs_cli($t,$arguments,$root);
	$t->processSucceeded($preview);
	$t->same('',$preview->stderr());
	$previewPayload=$preview->json();
	$t->isTrue($previewPayload['ok']);
	$t->same('preview',$previewPayload['mode']);
	$t->same('dataphyre_datadoc_markdown_corpus',$previewPayload['corpus']['type']);
	$t->same(2,$previewPayload['corpus']['schema_version']);
	$t->same(2,$previewPayload['corpus']['source_count']);
	$t->same(['.','modules/panel'],array_column($previewPayload['corpus']['sources'],'mount'));
	$t->same(3,$previewPayload['corpus']['page_count']);
	$t->same(2,$previewPayload['corpus']['content_asset_count']);
	$t->same(strlen(dp_datadoc_docs_png())*2,$previewPayload['corpus']['content_asset_bytes']);
	$t->same(1,$previewPayload['corpus']['ignored_file_count']);
	$t->same('dataphyre_datadoc_portal_build',$previewPayload['build']['type']);
	$t->same('dataphyre_datadoc_static_publication',$previewPayload['publication']['type']);
	$t->same('stable',$previewPayload['publication']['meta']['channel']);
	$t->same('dataphyre',$previewPayload['publication']['meta']['producer']);
	$t->same('dark',$previewPayload['publication']['portal']['default_theme']);
	$t->same('preview',$previewPayload['result']['status']);
	$target=$root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'versions'.DIRECTORY_SEPARATOR.'1.2.3';
	$t->isFalse(is_dir($target));

	$write=dp_datadoc_docs_cli($t,[...$arguments,'--write'],$root);
	$t->processSucceeded($write);
	$t->same('',$write->stderr());
	$writePayload=$write->json();
	$t->same('write',$writePayload['mode']);
	$t->same('applied',$writePayload['result']['status']);
	foreach(['index.html','guides'.DIRECTORY_SEPARATOR.'start.html','modules'.DIRECTORY_SEPARATOR.'panel'.DIRECTORY_SEPARATOR.'index.html','media'.DIRECTORY_SEPARATOR.'architecture.png','modules'.DIRECTORY_SEPARATOR.'panel'.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'panel.png','portal.json','publication.json','search-index.json','versions.json','assets'.DIRECTORY_SEPARATOR.'portal.css','assets'.DIRECTORY_SEPARATOR.'portal.js','assets'.DIRECTORY_SEPARATOR.'favicon.svg','sitemap.xml'] as $relative){
		$t->isTrue(is_file($target.DIRECTORY_SEPARATOR.$relative));
	}
	$t->contains('Dataphyre Documentation',(string)file_get_contents($target.DIRECTORY_SEPARATOR.'index.html'));
	$t->contains('src="media/architecture.png"',(string)file_get_contents($target.DIRECTORY_SEPARATOR.'index.html'));
	$t->contains('src="assets/panel.png"',(string)file_get_contents($target.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'panel'.DIRECTORY_SEPARATOR.'index.html'));
	$t->same(dp_datadoc_docs_png(),file_get_contents($target.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'panel'.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'panel.png'));
	$t->notContains('Panel',(string)file_get_contents($target.DIRECTORY_SEPARATOR.'portal.json'));
	$publication=json_decode((string)file_get_contents($target.DIRECTORY_SEPARATOR.'publication.json'),true,512,JSON_THROW_ON_ERROR);
	$t->same('dataphyre_datadoc_static_publication',$publication['type']);
	$t->same('public/docs/versions/1.2.3',$publication['release_prefix']);

	$idempotent=dp_datadoc_docs_cli($t,[...$arguments,'--write'],$root);
	$t->processSucceeded($idempotent);
	$t->same('unchanged',$idempotent->json()['result']['status']);
	file_put_contents($source.DIRECTORY_SEPARATOR.'index.md',"\nChanged.\n",FILE_APPEND);
	$conflict=dp_datadoc_docs_cli($t,[...$arguments,'--write'],$root);
	$t->processFailed($conflict,1);
	$t->same('',$conflict->stdout());
	$conflictPayload=$conflict->stderrJson();
	$t->same('operation_failed',$conflictPayload['error_code']);
	$t->notContains(str_replace('\\','/',$root),str_replace('\\','/',$conflictPayload['message']));

	$help=dp_datadoc_docs_cli($t,['--help'],$root);
	$t->processSucceeded($help);
	$helpPayload=$help->json();
	$t->same('datadoc',$helpPayload['ownership']['engine']);
	$t->same('datadoc',$helpPayload['ownership']['publication']);
	$t->isTrue($helpPayload['safety']['source_not_executed']);
	$t->isTrue($helpPayload['safety']['preview_by_default']);
	$t->isTrue($helpPayload['safety']['symlinks_rejected']);
	$t->isTrue($helpPayload['safety']['inert_raster_assets_only']);
	$t->isTrue($helpPayload['safety']['deterministic_source_mounts']);
	$t->isFalse($helpPayload['safety']['external_dependencies']);
	$t->same(256,$helpPayload['limits']['maximum_mounts']);
	$t->contains('--source DIR',$helpPayload['usage']);
	$t->contains('--mount PREFIX=DIR',$helpPayload['usage']);

	foreach([
		['--unknown'],
		['--source','manual','--source','manual','--version','2.0.0'],
		['--source','manual','--mount','--version','2.0.0'],
		['--source','manual','--mount','bad','--version','2.0.0'],
		['--source','manual','--mount','=panel-manual','--version','2.0.0'],
		['--source','manual','--mount','../panel=panel-manual','--version','2.0.0'],
		['--source','manual','--mount','modules/panel=panel-manual','--mount','MODULES/PANEL=panel-manual','--version','2.0.0'],
		['--write=true'],
		['positional'],
		['--source'],
		['--source','manual'],
		['--version','2.0.0'],
		['--source','manual','--version','2.0.0','--output','../escape'],
		['--source','manual','--version','2.0.0','--output','/absolute'],
	] as $invalid){
		$result=dp_datadoc_docs_cli($t,$invalid,$root);
		$t->processFailed($result,1);
		$t->same('',$result->stdout());
		$t->isFalse($result->stderrJson()['ok']);
	}
	$tooManyMounts=['--source','manual'];
	for($index=0;$index<257;$index++){ array_push($tooManyMounts,'--mount','module-'.$index.'=panel-manual'); }
	array_push($tooManyMounts,'--version','2.0.0');
	$boundedMounts=dp_datadoc_docs_cli($t,$tooManyMounts,$root);
	$t->processFailed($boundedMounts,1);
	$t->contains('mount count',$boundedMounts->stderrJson()['message']);

	$outside=$t->tempDirectory('datadoc-doc-cli-outside');
	file_put_contents($outside.DIRECTORY_SEPARATOR.'index.md',"# Outside\n");
	$escaped=dp_datadoc_docs_cli($t,['--root',$root,'--source',$outside,'--version','2.0.0'],$root);
	$t->processFailed($escaped,1);
	$t->contains('escaped',$escaped->stderrJson()['message']);
	$escapedMount=dp_datadoc_docs_cli($t,['--root',$root,'--source','manual','--mount','modules/outside='.$outside,'--version','2.0.0'],$root);
	$t->processFailed($escapedMount,1);
	$t->contains('escaped',$escapedMount->stderrJson()['message']);

	$collisionSource=$root.DIRECTORY_SEPARATOR.'collision-manual';
	mkdir($collisionSource);
	file_put_contents($collisionSource.DIRECTORY_SEPARATOR.'start.md',"# Collision\n");
	$collision=dp_datadoc_docs_cli($t,['--root',$root,'--source','manual','--mount','guides=collision-manual','--version','2.0.0'],$root);
	$t->processFailed($collision,1);
	$t->contains('colliding',strtolower($collision->stderrJson()['message']));

	$badAssetSource=$root.DIRECTORY_SEPARATOR.'bad-asset-manual';
	mkdir($badAssetSource);
	file_put_contents($badAssetSource.DIRECTORY_SEPARATOR.'index.md',"# Bad asset\n");
	file_put_contents($badAssetSource.DIRECTORY_SEPARATOR.'broken.png','not an image');
	$badAsset=dp_datadoc_docs_cli($t,['--root',$root,'--source','bad-asset-manual','--version','2.0.0'],$root);
	$t->processFailed($badAsset,1);
	$t->contains('raster image',strtolower($badAsset->stderrJson()['message']));

	file_put_contents($root.DIRECTORY_SEPARATOR.'broken.json','{');
	$broken=dp_datadoc_docs_cli($t,['--root',$root,'--source','manual','--version','2.0.0','--portal-config','broken.json'],$root);
	$t->processFailed($broken,1);
	$t->contains('invalid',strtolower($broken->stderrJson()['message']));
	file_put_contents($root.DIRECTORY_SEPARATOR.'oversized.json',str_repeat('x',1048577));
	$oversized=dp_datadoc_docs_cli($t,['--root',$root,'--source','manual','--version','2.0.0','--meta','oversized.json'],$root);
	$t->processFailed($oversized,1);
	$t->contains('byte bound',$oversized->stderrJson()['message']);
	file_put_contents($root.DIRECTORY_SEPARATOR.'unknown.json',json_encode(['panel_only'=>true],JSON_THROW_ON_ERROR));
	$unknownConfig=dp_datadoc_docs_cli($t,['--root',$root,'--source','manual','--version','2.0.0','--portal-config','unknown.json'],$root);
	$t->processFailed($unknownConfig,1);
	$t->same('invalid_argument',$unknownConfig->stderrJson()['error_code']);

	$missingIndex=$root.DIRECTORY_SEPARATOR.'missing-index';
	mkdir($missingIndex);
	file_put_contents($missingIndex.DIRECTORY_SEPARATOR.'guide.md',"# Guide\n");
	$missing=dp_datadoc_docs_cli($t,['--root',$root,'--source','missing-index','--version','2.0.0'],$root);
	$t->processFailed($missing,1);
	$t->contains('index.md',$missing->stderrJson()['message']);

	$link=$source.DIRECTORY_SEPARATOR.'linked.md';
	symlink($outside.DIRECTORY_SEPARATOR.'index.md',$link);
	$linked=dp_datadoc_docs_cli($t,['--root',$root,'--source','manual','--version','2.0.0'],$root);
	$t->processFailed($linked,1);
	$t->contains('symlink',strtolower($linked->stderrJson()['message']));
	unlink($link);

	$lock=$root.DIRECTORY_SEPARATOR.'.dataphyre-datadoc.lock';
	if(is_file($lock)){ unlink($lock); }
	mkdir($lock);
	$blocked=dp_datadoc_docs_cli($t,['--root',$root,'--source','manual','--version','2.0.0','--write'],$root);
	$t->processFailed($blocked,1);
	$t->same('',$blocked->stdout());
	$blockedPayload=$blocked->stderrJson();
	$t->same('operation_failed',$blockedPayload['error_code']);
	$t->notContains(str_replace('\\','/',$root),str_replace('\\','/',$blockedPayload['message']));
})->tag('datadoc','documentation','cli','process','security')->maxMillis(20000);

test('Datadoc documentation CLI discovers and publishes the universal workspace corpus',static function(Context $t):void {
	$root=$t->tempDirectory('datadoc-doc-cli-workspace');
	mkdir($root.DIRECTORY_SEPARATOR.'docs',0775,true);
	mkdir($root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'panel'.DIRECTORY_SEPARATOR.'documentation',0775,true);
	mkdir($root.DIRECTORY_SEPARATOR.'generated-panel');
	mkdir($root.DIRECTORY_SEPARATOR.'generated-api');
	file_put_contents($root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'README.md',"# Dataphyre workspace\n\n![Logo](../runtime/logo.png)\n");
	file_put_contents($root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'README.md',"# Runtime\n\n[Workspace](../docs/README.md)\n");
	file_put_contents($root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'logo.png',dp_datadoc_docs_png());
	file_put_contents($root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'bootstrap.php','<?php throw new RuntimeException("must not execute");');
	file_put_contents($root.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'panel'.DIRECTORY_SEPARATOR.'documentation'.DIRECTORY_SEPARATOR.'Dataphyre_Panel.md',"# Panel\n");
	file_put_contents($root.DIRECTORY_SEPARATOR.'generated-panel'.DIRECTORY_SEPARATOR.'index.md',"# Generated Panel API\n");
	file_put_contents($root.DIRECTORY_SEPARATOR.'generated-api'.DIRECTORY_SEPARATOR.'index.md',"# Generated API\n");
	$arguments=[
		'--root',$root,
		'--workspace',
		'--mount','generated/panel=generated-panel',
		'--mount','generated/api=generated-api',
		'--output','docs/generated/datadoc',
		'--version','4.0.0',
		'--title','Dataphyre Universal Documentation',
	];
	$preview=dp_datadoc_docs_cli($t,$arguments,$root);
	$t->processSucceeded($preview);
	$t->same('',$preview->stderr());
	$payload=$preview->json();
	$t->isTrue($payload['ok']);
	$t->same('preview',$payload['mode']);
	$t->same('workspace',$payload['corpus']['discovery_mode']);
	$t->same(5,$payload['corpus']['source_count']);
	$t->same(6,$payload['corpus']['page_count']);
	$t->same(1,$payload['corpus']['generated_page_count']);
	$t->same(1,$payload['corpus']['content_asset_count']);
	$t->same(1,$payload['corpus']['ignored_file_count']);
	$t->same(1,$payload['corpus']['workspace']['module_count']);
	$t->same(['panel'],$payload['corpus']['workspace']['modules']);
	$t->same(['docs','generated/api','generated/panel','runtime','runtime/modules/panel/documentation'],array_column($payload['corpus']['sources'],'mount'));
	$t->same(1,preg_match('/^[a-f0-9]{64}$/D',$payload['corpus']['corpus_fingerprint']));
	$t->isTrue($payload['corpus']['security']['source_not_executed']);
	$t->same('preview',$payload['result']['status']);

	$reversed=dp_datadoc_docs_cli($t,[
		'--root',$root,'--workspace',
		'--mount','generated/api=generated-api',
		'--mount','generated/panel=generated-panel',
		'--output','docs/generated/datadoc','--version','4.0.0','--title','Dataphyre Universal Documentation',
	],$root);
	$t->processSucceeded($reversed);
	$t->same($payload['corpus'],$reversed->json()['corpus']);
	$t->same($payload['build'],$reversed->json()['build']);

	$write=dp_datadoc_docs_cli($t,[...$arguments,'--write'],$root);
	$t->processSucceeded($write);
	$t->same('applied',$write->json()['result']['status']);
	$target=$root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'generated'.DIRECTORY_SEPARATOR.'datadoc'.DIRECTORY_SEPARATOR.'versions'.DIRECTORY_SEPARATOR.'4.0.0';
	foreach([
		'index.html','docs'.DIRECTORY_SEPARATOR.'README.html','runtime'.DIRECTORY_SEPARATOR.'README.html','runtime'.DIRECTORY_SEPARATOR.'logo.png',
		'runtime'.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.'panel'.DIRECTORY_SEPARATOR.'documentation'.DIRECTORY_SEPARATOR.'Dataphyre_Panel.html',
		'generated'.DIRECTORY_SEPARATOR.'panel'.DIRECTORY_SEPARATOR.'index.html','generated'.DIRECTORY_SEPARATOR.'api'.DIRECTORY_SEPARATOR.'index.html',
	] as $relative){ $t->isTrue(is_file($target.DIRECTORY_SEPARATOR.$relative)); }
	$t->contains('href="docs/README.html"',(string)file_get_contents($target.DIRECTORY_SEPARATOR.'index.html'));
	$t->contains('src="../runtime/logo.png"',(string)file_get_contents($target.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'README.html'));
	$t->same(dp_datadoc_docs_png(),file_get_contents($target.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'logo.png'));
	$idempotent=dp_datadoc_docs_cli($t,[...$arguments,'--write'],$root);
	$t->processSucceeded($idempotent);
	$t->same('unchanged',$idempotent->json()['result']['status']);
	$t->same(6,$idempotent->json()['corpus']['page_count']);
	$t->same(['docs/generated/datadoc'],$idempotent->json()['corpus']['excluded_paths']);
	$t->isTrue($idempotent->json()['corpus']['security']['publication_self_ingestion_prevented']);

	$help=dp_datadoc_docs_cli($t,['--help'],$root)->json();
	$t->contains('--workspace',$help['usage']);
	$t->same(256,$help['limits']['maximum_sources']);
	$t->isTrue($help['safety']['deterministic_workspace_discovery']);
	$t->isTrue($help['safety']['workspace_module_code_not_loaded']);
	$t->isTrue($help['safety']['publication_output_excluded_from_corpus']);
	foreach([
		['--root',$root,'--workspace','--source','docs','--version','4.0.0'],
		['--root',$root,'--workspace=true','--version','4.0.0'],
		['--root',$root,'--workspace','--mount','DOCS=generated-panel','--version','4.0.0'],
	] as $invalid){
		$result=dp_datadoc_docs_cli($t,$invalid,$root);
		$t->processFailed($result,1);
		$t->same('',$result->stdout());
		$t->same('invalid_argument',$result->stderrJson()['error_code']);
	}
})->tag('datadoc','documentation','cli','workspace','process','security')->maxMillis(20000);
