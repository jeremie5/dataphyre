<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Datadoc\DocumentationCorpus;
use Dataphyre\Datadoc\DocumentationPortal;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__,2).'/testing/tooling/bootstrap.php';
require_once dirname(__DIR__).'/Framework/DocumentationPortal.php';
require_once dirname(__DIR__).'/Framework/DocumentationPortalBuild.php';
require_once dirname(__DIR__).'/Framework/DocumentationCorpus.php';

suite('Datadoc universal workspace corpus')
	->contract('datadoc.documentation-corpus',1)
	->layer('integration')
	->risk('high')
	->watches('module:datadoc','framework:documentation','workspace:discovery')
	->through('root-confinement','module-discovery','content-assets','deterministic-manifest')
	->tag('datadoc','documentation','corpus','workspace','security','deep-coverage')
	->group('framework-coverage');

function dp_datadoc_corpus_png():string {
	$contents=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',true);
	if(!is_string($contents)){ throw new RuntimeException('Unable to decode the Datadoc corpus PNG fixture.'); }
	return $contents;
}

function dp_datadoc_corpus_directory(string $path):void {
	if(!is_dir($path)&&!mkdir($path,0775,true)&&!is_dir($path)){ throw new RuntimeException('Unable to create a Datadoc corpus fixture directory.'); }
}

test('Datadoc discovers one repository-relative all-module corpus without executing modules',static function(Context $t):void {
	$root=$t->tempDirectory('datadoc-workspace-corpus');
	dp_datadoc_corpus_directory($root.'/docs');
	dp_datadoc_corpus_directory($root.'/docs/generated/old');
	dp_datadoc_corpus_directory($root.'/runtime/modules/panel/documentation/assets');
	dp_datadoc_corpus_directory($root.'/runtime/modules/core/documentation');
	dp_datadoc_corpus_directory($root.'/config');
	dp_datadoc_corpus_directory($root.'/examples/minimal');
	dp_datadoc_corpus_directory($root.'/producer-panel/assets');
	dp_datadoc_corpus_directory($root.'/producer-api');
	file_put_contents($root.'/docs/README.md',"# Project docs\n\n![Logo](../runtime/logo.png)\n\n[Panel](../runtime/modules/panel/documentation/index.md)\n");
	file_put_contents($root.'/docs/generated/old/index.md',"# Stale generated release\n");
	file_put_contents($root.'/docs/generated/old/stale.png',dp_datadoc_corpus_png());
	file_put_contents($root.'/runtime/README.md',"# Runtime\n\n[Project](../docs/README.md)\n");
	file_put_contents($root.'/runtime/logo.png',dp_datadoc_corpus_png());
	file_put_contents($root.'/runtime/bootstrap.php','<?php throw new RuntimeException("must not execute");');
	file_put_contents($root.'/runtime/modules/panel/documentation/index.md',"# Panel\n\n![Panel](assets/panel.png)\n");
	file_put_contents($root.'/runtime/modules/panel/documentation/assets/panel.png',dp_datadoc_corpus_png());
	file_put_contents($root.'/runtime/modules/core/documentation/README.md',"# Core\n");
	file_put_contents($root.'/config/README.md',"# Configuration\n");
	file_put_contents($root.'/config/private.php','<?php return ["secret"=>"not loaded"];');
	file_put_contents($root.'/examples/minimal/README.md',"# Minimal example\n");
	file_put_contents($root.'/examples/minimal/index.php','<?php exit("not loaded");');
	file_put_contents($root.'/producer-panel/index.md',"# Generated Panel API\n\n![Generated](assets/generated.png)\n");
	file_put_contents($root.'/producer-panel/assets/generated.png',dp_datadoc_corpus_png());
	file_put_contents($root.'/producer-api/reference.md',"# Generated API\n");

	$mounts=['generated/panel'=>'producer-panel','generated/api'=>'producer-api'];
	$corpus=DocumentationCorpus::discover($root,null,$mounts,true,'Dataphyre [Universal] Documentation',['docs/generated']);
	$reversed=DocumentationCorpus::discover($root,null,array_reverse($mounts,true),true,'Dataphyre [Universal] Documentation',['docs/generated']);
	$t->same($corpus->manifest(),$corpus->jsonSerialize());
	$t->same($corpus->manifest(),$reversed->manifest());
	$t->same($corpus->documents(),$reversed->documents());
	$t->same($corpus->contentAssets(),$reversed->contentAssets());
	$t->same('dataphyre_datadoc_markdown_corpus',$corpus->manifest()['type']);
	$t->same(2,$corpus->manifest()['schema_version']);
	$t->same('workspace',$corpus->manifest()['discovery_mode']);
	$t->same(8,$corpus->manifest()['source_count']);
	$t->same(9,$corpus->manifest()['page_count']);
	$t->same(1,$corpus->manifest()['generated_page_count']);
	$t->same(3,$corpus->manifest()['content_asset_count']);
	$t->same(strlen(dp_datadoc_corpus_png())*3,$corpus->manifest()['content_asset_bytes']);
	$t->same(3,$corpus->manifest()['ignored_file_count']);
	$t->same(['docs/generated'],$corpus->manifest()['excluded_paths']);
	$t->same(2,$corpus->manifest()['workspace']['module_count']);
	$t->same(['core','panel'],$corpus->manifest()['workspace']['modules']);
	$t->isTrue($corpus->manifest()['workspace']['project_documentation']);
	$t->isTrue($corpus->manifest()['workspace']['generated_index']);
	$t->same(1,preg_match('/^[a-f0-9]{64}$/D',$corpus->manifest()['corpus_fingerprint']));
	$t->isTrue($corpus->manifest()['security']['source_not_executed']);
	$t->isTrue($corpus->manifest()['security']['project_confined']);
	$t->isTrue($corpus->manifest()['security']['raster_assets_signature_validated']);
	$t->isTrue($corpus->manifest()['security']['publication_self_ingestion_prevented']);
	$t->same([
		'config','docs','examples/minimal','generated/api','generated/panel','runtime',
		'runtime/modules/core/documentation','runtime/modules/panel/documentation',
	],array_column($corpus->manifest()['sources'],'mount'));
	$sourceByMount=array_column($corpus->manifest()['sources'],null,'mount');
	$t->same('workspace_project',$sourceByMount['docs']['kind']);
	$t->same('workspace_module',$sourceByMount['runtime/modules/panel/documentation']['kind']);
	$t->same('workspace_support',$sourceByMount['runtime']['kind']);
	$t->same('mount',$sourceByMount['generated/panel']['kind']);
	$t->isFalse($sourceByMount['runtime']['recursive']);
	$t->isTrue($sourceByMount['runtime/modules/panel/documentation']['recursive']);
	$t->same('docs/README.md',$sourceByMount['docs']['entry_page']);
	$t->same(['generated'],$sourceByMount['docs']['excluded_paths']);
	$t->same('runtime/modules/panel/documentation/index.md',$sourceByMount['runtime/modules/panel/documentation']['entry_page']);
	$t->same($corpus->documents(),iterator_to_array($corpus));
	$t->same(count($corpus->documents()),count($corpus));
	$t->contains('# Dataphyre [Universal] Documentation',(string)$corpus->document('index.md'));
	$t->contains('[Project documentation](docs/README.md)',(string)$corpus->document('index.md'));
	$t->contains('[Panel](runtime/modules/panel/documentation/index.md)',(string)$corpus->document('index.md'));
	$t->contains('[Generated Panel](generated/panel/index.md)',(string)$corpus->document('index.md'));
	$t->same($corpus->document('docs/README.md'),$corpus->document('docs\\README.md'));
	$t->same(null,$corpus->document('missing.md'));
	$t->same(null,$corpus->document('docs/generated/old/index.md'));
	$t->same(dp_datadoc_corpus_png(),$corpus->contentAsset('runtime\\logo.png'));
	$t->same(null,$corpus->contentAsset('missing.png'));
	$t->same(null,$corpus->contentAsset('docs/generated/old/stale.png'));

	$build=DocumentationPortal::make()->build('3.0.0','Dataphyre Universal Documentation',$corpus->documents(),[],[],contentAssets:$corpus->contentAssets());
	$t->same(9,$build->manifest()['page_count']);
	$t->same(3,$build->manifest()['content_asset_count']);
	$t->contains('href="docs/README.html"',(string)$build->file('index.html'));
	$t->contains('src="../runtime/logo.png"',(string)$build->file('docs/README.html'));
	$t->contains('src="assets/panel.png"',(string)$build->file('runtime/modules/panel/documentation/index.html'));
	$t->same(dp_datadoc_corpus_png(),$build->file('runtime/logo.png'));
});

test('Datadoc manual corpus composition remains compatible and deterministic',static function(Context $t):void {
	$root=$t->tempDirectory('datadoc-manual-corpus');
	dp_datadoc_corpus_directory($root.'/manual/guides');
	dp_datadoc_corpus_directory($root.'/panel');
	dp_datadoc_corpus_directory($root.'/api');
	file_put_contents($root.'/manual/index.md',"# Manual\n");
	file_put_contents($root.'/manual/guides/start.md',"# Start\n");
	file_put_contents($root.'/manual/ignored.txt','ignored');
	file_put_contents($root.'/panel/index.md',"# Panel\n");
	file_put_contents($root.'/api/reference.md',"# API\n");
	$mounts=['modules/panel'=>'panel','modules/api'=>'api'];
	$first=DocumentationCorpus::discover($root,'manual',$mounts);
	$second=DocumentationCorpus::discover($root.'/','manual',array_reverse($mounts,true));
	$t->same($first->manifest(),$second->manifest());
	$t->same($first->documents(),$second->documents());
	$t->same('manual',$first->manifest()['discovery_mode']);
	$t->same(3,$first->manifest()['source_count']);
	$t->same(4,$first->manifest()['page_count']);
	$t->same(0,$first->manifest()['generated_page_count']);
	$t->same(1,$first->manifest()['ignored_file_count']);
	$t->same(['.','modules/api','modules/panel'],array_column($first->manifest()['sources'],'mount'));
	$t->same('root',$first->manifest()['sources'][0]['kind']);
	$t->same('manual',$first->manifest()['sources'][0]['path']);
	$t->same('index.md',$first->manifest()['sources'][0]['entry_page']);
	$t->same("# Manual\n",$first->document(' index.md '));
	$t->same(4,count($first));
	$t->same(null,$first->contentAsset('none.png'));
});

test('Datadoc corpus discovery fails closed at every filesystem and corpus boundary',static function(Context $t):void {
	$root=$t->tempDirectory('datadoc-corpus-security');
	dp_datadoc_corpus_directory($root.'/manual');
	file_put_contents($root.'/manual/index.md',"# Manual\n");
	file_put_contents($root.'/root-file','not a directory');
	$t->throws(static fn()=>DocumentationCorpus::discover('',null,[],true),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root.'/missing',null,[],true),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root.'/root-file',null,[],true),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[],true),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,null,[],false),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'missing'),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,''),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[] ,false,''),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[] ,false,str_repeat('x',161)),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[] ,false,"\xff"),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[0=>'manual']),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[''=>'manual']),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',['../escape'=>'manual']),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',['bad%mount'=>'manual']),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',['Guide'=>'manual','guide'=>'manual']),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[],false,'Docs',['bad%path']),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[],false,'Docs',[0=>'manual']),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[],false,'Docs',['docs','DOCS']),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[],false,'Docs',['manual']),InvalidArgumentException::class);
	$tooManyExclusions=[];
	for($index=0;$index<257;$index++){ $tooManyExclusions[]='excluded-'.$index; }
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',[],false,'Docs',$tooManyExclusions),LengthException::class);

	$outside=$t->tempDirectory('datadoc-corpus-outside');
	file_put_contents($outside.'/index.md',"# Outside\n");
	$t->throws(static fn()=>DocumentationCorpus::discover($root,$outside),InvalidArgumentException::class);
	$sourceLink=$root.'/source-link';
	symlink($root.'/manual',$sourceLink);
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'source-link'),InvalidArgumentException::class);
	$rootLink=$root.'-link';
	symlink($root,$rootLink);
	$t->throws(static fn()=>DocumentationCorpus::discover($rootLink,null,[],true),InvalidArgumentException::class);

	$emptyWorkspace=$t->tempDirectory('datadoc-empty-workspace');
	$t->throws(static fn()=>DocumentationCorpus::discover($emptyWorkspace,null,[],true),InvalidArgumentException::class);
	$projectLinkWorkspace=$t->tempDirectory('datadoc-project-link-workspace');
	dp_datadoc_corpus_directory($projectLinkWorkspace.'/real-docs');
	symlink($projectLinkWorkspace.'/real-docs',$projectLinkWorkspace.'/docs');
	$t->throws(static fn()=>DocumentationCorpus::discover($projectLinkWorkspace,null,[],true),InvalidArgumentException::class);
	$moduleRootLinkWorkspace=$t->tempDirectory('datadoc-module-root-link-workspace');
	dp_datadoc_corpus_directory($moduleRootLinkWorkspace.'/runtime/real-modules');
	symlink($moduleRootLinkWorkspace.'/runtime/real-modules',$moduleRootLinkWorkspace.'/runtime/modules');
	$t->throws(static fn()=>DocumentationCorpus::discover($moduleRootLinkWorkspace,null,[],true),InvalidArgumentException::class);
	$moduleLinkWorkspace=$t->tempDirectory('datadoc-module-link-workspace');
	dp_datadoc_corpus_directory($moduleLinkWorkspace.'/runtime/modules');
	dp_datadoc_corpus_directory($moduleLinkWorkspace.'/real-module/documentation');
	symlink($moduleLinkWorkspace.'/real-module',$moduleLinkWorkspace.'/runtime/modules/linked');
	$t->throws(static fn()=>DocumentationCorpus::discover($moduleLinkWorkspace,null,[],true),InvalidArgumentException::class);
	$documentationLinkWorkspace=$t->tempDirectory('datadoc-documentation-link-workspace');
	dp_datadoc_corpus_directory($documentationLinkWorkspace.'/runtime/modules/linked');
	dp_datadoc_corpus_directory($documentationLinkWorkspace.'/real-documentation');
	symlink($documentationLinkWorkspace.'/real-documentation',$documentationLinkWorkspace.'/runtime/modules/linked/documentation');
	$t->throws(static fn()=>DocumentationCorpus::discover($documentationLinkWorkspace,null,[],true),InvalidArgumentException::class);
	$invalidModuleWorkspace=$t->tempDirectory('datadoc-invalid-module-workspace');
	dp_datadoc_corpus_directory($invalidModuleWorkspace.'/runtime/modules/bad%module/documentation');
	file_put_contents($invalidModuleWorkspace.'/runtime/modules/bad%module/documentation/index.md',"# Bad\n");
	$t->throws(static fn()=>DocumentationCorpus::discover($invalidModuleWorkspace,null,[],true),InvalidArgumentException::class);
	$supportLinkWorkspace=$t->tempDirectory('datadoc-support-link-workspace');
	dp_datadoc_corpus_directory($supportLinkWorkspace.'/docs');
	dp_datadoc_corpus_directory($supportLinkWorkspace.'/real-config');
	file_put_contents($supportLinkWorkspace.'/docs/README.md',"# Docs\n");
	symlink($supportLinkWorkspace.'/real-config',$supportLinkWorkspace.'/config');
	$t->throws(static fn()=>DocumentationCorpus::discover($supportLinkWorkspace,null,[],true),InvalidArgumentException::class);

	$missingIndex=$t->tempDirectory('datadoc-corpus-missing-index');
	file_put_contents($missingIndex.'/guide.md',"# Guide\n");
	$t->throws(static fn()=>DocumentationCorpus::discover($missingIndex,'.'),InvalidArgumentException::class);
	$emptyPage=$t->tempDirectory('datadoc-corpus-empty-page');
	file_put_contents($emptyPage.'/index.md','');
	$t->throws(static fn()=>DocumentationCorpus::discover($emptyPage,'.'),LengthException::class);
	$largePage=$t->tempDirectory('datadoc-corpus-large-page');
	file_put_contents($largePage.'/index.md',str_repeat('x',2097153));
	$t->throws(static fn()=>DocumentationCorpus::discover($largePage,'.'),LengthException::class);
	$badAsset=$t->tempDirectory('datadoc-corpus-bad-asset');
	file_put_contents($badAsset.'/index.md',"# Bad asset\n");
	file_put_contents($badAsset.'/broken.png','not a png');
	$t->throws(static fn()=>DocumentationCorpus::discover($badAsset,'.'),InvalidArgumentException::class);
	$emptyAsset=$t->tempDirectory('datadoc-corpus-empty-asset');
	file_put_contents($emptyAsset.'/index.md',"# Empty asset\n");
	file_put_contents($emptyAsset.'/empty.png','');
	$t->throws(static fn()=>DocumentationCorpus::discover($emptyAsset,'.'),LengthException::class);
	$largeAsset=$t->tempDirectory('datadoc-corpus-large-asset');
	file_put_contents($largeAsset.'/index.md',"# Large asset\n");
	file_put_contents($largeAsset.'/large.png',str_pad(dp_datadoc_corpus_png(),16777217,"\0"));
	$t->throws(static fn()=>DocumentationCorpus::discover($largeAsset,'.'),LengthException::class);

	$unsafePath=$t->tempDirectory('datadoc-corpus-unsafe-path');
	file_put_contents($unsafePath.'/index.md',"# Safe\n");
	file_put_contents($unsafePath.'/bad%.md',"# Unsafe\n");
	$t->throws(static fn()=>DocumentationCorpus::discover($unsafePath,'.'),InvalidArgumentException::class);
	$caseCollision=$t->tempDirectory('datadoc-corpus-case-collision');
	file_put_contents($caseCollision.'/index.md',"# Index\n");
	file_put_contents($caseCollision.'/A.md',"# A\n");
	file_put_contents($caseCollision.'/a.md',"# a\n");
	$t->throws(static fn()=>DocumentationCorpus::discover($caseCollision,'.'),InvalidArgumentException::class);
	$mountedCollision=$t->tempDirectory('datadoc-corpus-mounted-collision');
	dp_datadoc_corpus_directory($mountedCollision.'/root/guides');
	dp_datadoc_corpus_directory($mountedCollision.'/mounted');
	file_put_contents($mountedCollision.'/root/index.md',"# Root\n");
	file_put_contents($mountedCollision.'/root/guides/start.md',"# Root start\n");
	file_put_contents($mountedCollision.'/mounted/start.md',"# Mounted start\n");
	$t->throws(static fn()=>DocumentationCorpus::discover($mountedCollision,'root',['guides'=>'mounted']),InvalidArgumentException::class);
	$linkedContent=$t->tempDirectory('datadoc-corpus-linked-content');
	file_put_contents($linkedContent.'/index.md',"# Index\n");
	symlink($outside.'/index.md',$linkedContent.'/linked.md');
	$t->throws(static fn()=>DocumentationCorpus::discover($linkedContent,'.'),InvalidArgumentException::class);
	$excludedLink=$t->tempDirectory('datadoc-corpus-excluded-link');
	dp_datadoc_corpus_directory($excludedLink.'/manual');
	file_put_contents($excludedLink.'/manual/index.md',"# Index\n");
	symlink($outside,$excludedLink.'/manual/generated');
	$t->throws(static fn()=>DocumentationCorpus::discover($excludedLink,'manual',[],false,'Docs',['manual/generated']),InvalidArgumentException::class);

	$tooMany=[];
	for($index=0;$index<256;$index++){ $tooMany['mount-'.$index]='manual'; }
	$t->throws(static fn()=>DocumentationCorpus::discover($root,'manual',$tooMany),LengthException::class);
})->maxMillis(15000);
