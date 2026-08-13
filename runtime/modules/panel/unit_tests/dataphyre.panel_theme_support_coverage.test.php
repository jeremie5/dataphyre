<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\PanelThemeAsset;
use Dataphyre\Panel\PanelThemeLibrary;
use Dataphyre\Panel\PanelThemePreset;
use Dataphyre\Panel\PanelThemePreview;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Panel theme support contracts')
	->framework(['panel'])
	->tag('panel','theme-support','coverage')
	->group('framework-coverage');

test('panel theme support completes asset preset and preview edge behavior',static function(Context $t): void {
	$hashed=PanelThemeAsset::stylesheet('');
	$t->contains('asset_',$hashed->name());
	$t->same($hashed,PanelThemeAsset::from($hashed));
	$asset=PanelThemeAsset::from([
		'url'=>'/theme.css','name'=>'Theme CSS','attributes'=>['media'=>'screen'],
		'integrity'=>'sha384-test','unknown'=>'ignored',
	]);
	$t->same('theme_css',$asset?->name());
	$t->same('sha384-test',$asset?->toArray()['attributes']['integrity']);

	$preset=PanelThemePreset::fromArray([
		'name'=>'support','brand_name'=>'Brand','brand_logo'=>'/logo.svg','dark_brand_logo'=>'/logo-dark.svg',
		'brand_logo_height'=>'30px','meta'=>['source'=>'test'],
	]);
	$preset->darkToken('border','#111')
		->radius('4px')->maxWidth('1200px')->panelPadding('20px')->sectionPadding('12px')
		->controlPadding('8px')->inputPadding('9px')->tableCellPadding('10px')->gap('11px')
		->darkSurface('#111','#222')->darkBody('#000')->darkText('#fff','#ddd','#bbb')
		->stylesheet('/support.css','support',['media'=>'screen']);
	$manifest=$preset->toArray();
	$t->same('Brand',$manifest['brand']['name']);
	$t->same('4px',$manifest['tokens']['radius']);
	$t->same('#111',$manifest['dark_tokens']['surface']);
	$t->same('support',$preset->applyTo(PanelTheme::make('support'))->name());
	$t->same('derived',$preset->toTheme('derived')->name());
	$presetInternals=$t->nonPublic($preset);
	$unsupported=new stdClass();
	$t->same($unsupported,$presetInternals->invoke('resolveAssetEntry',$unsupported));
	$t->same('/plain.css',$presetInternals->invoke('resolveAssetHref','/plain.css'));
	$t->same('missing::theme.css',$presetInternals->invoke('resolveAssetHref','missing::theme.css'));

	$t->same('',PanelThemePreview::render([]));
	$preview=[
		'name'=>'edge','brand'=>['name'=>'Edge <Theme>'],'default_mode'=>'dark','colors'=>[],
		'modes'=>['light'=>null,'custom'=>['samples'=>[
			'surface'=>['background'=>new stdClass(),'text'=>'javascript:bad','border'=>';bad'],
			'control'=>['background'=>'','padding'=>'expression(x)'],
			'action'=>['background'=>'#123456'],
		]]],
		'contrast'=>['custom'=>[['status'=>'unsupported','background'=>'a','text'=>'b','ratio'=>1]]],
	];
	$html=PanelThemePreview::render($preview,['css'=>false]);
	$t->contains('Edge &lt;Theme&gt;',$html);
	$t->notContains('<style>',$html);
	$previewInternals=$t->nonPublic(PanelThemePreview::class);
	$t->same('fallback',$previewInternals->invoke('cssValue',new stdClass(),'fallback'));
	$t->same('fallback',$previewInternals->invoke('cssValue',';bad','fallback'));
	$t->same('fallback',$previewInternals->invoke('cssValue','@import x','fallback'));
});

test('panel theme support library registers resolves previews and exports',static function(Context $t): void {
	$library=PanelThemeLibrary::make();
	$libraryInternals=$t->nonPublic($library);
	$library->register(['name'=>'minimal','tokens'=>['radius'=>'4px']]);
	$library->registerMany([PanelThemePreset::make('object'),['name'=>'array'],'invalid']);
	$library->registerTheme(PanelTheme::make('object-theme')->token('surface','#fff'));
	$library->registerTheme(['name'=>'array-theme','preset'=>'minimal','tokens'=>['text'=>'#111']]);
	$library->registerTheme(['tokens'=>['body_bg'=>'#fff']]);
	$library->registerThemes([PanelTheme::make('second-theme'),['name'=>'third-theme'],'invalid']);
	$t->isTrue($library->has('minimal'));
	$t->isFalse($library->has('missing'));
	$t->same(null,$library->getTheme(''));
	$t->same(null,$library->getTheme('missing'));
	$t->same('array-theme',$library->getTheme('array-theme')?->name());
	$t->same('array-theme',$library->getTheme('array-theme')?->name());
	$t->isTrue($library->hasTheme('third-theme'));
	$t->notEmpty($library->all());
	$t->notEmpty($library->allThemes());
	$t->notEmpty($library->toArray());
	$t->notEmpty($library->themesToArray());
	$t->notEmpty($library->manifest());
	$t->contains('presets',$library->toJson());
	$t->same([],PanelThemeLibrary::make()->preview('missing'));
	$t->same('',PanelThemeLibrary::make()->previewHtml('missing'));
	$t->notEmpty($library->preview('array-theme'));
	$t->notEmpty($library->preview());
	$t->contains('dp-theme-preview',$library->previewHtml('array-theme'));
	$t->contains('dp-theme-preview',$library->previewHtml());

	$directory=$t->tempDirectory('dataphyre-theme-library');
	$report=$library->exportTo($directory);
	$t->isTrue($report['manifest']);
	$t->notEmpty($report['presets']);
	$t->notEmpty($report['themes']);
	$t->isTrue($library->writeManifest($directory.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'manifest.json'));
	$t->isFalse($libraryInternals->invoke('writeFile','','contents'));
	$blocking=$directory.DIRECTORY_SEPARATOR.'blocking-file';
	file_put_contents($blocking,'block');
	$t->isFalse($libraryInternals->invoke('writeFile',$blocking.DIRECTORY_SEPARATOR.'child.json','contents'));
});

test('panel theme support library loads payload shapes and source files',static function(Context $t): void {
	$directory=$t->tempDirectory('dataphyre-theme-load');
	file_put_contents($directory.DIRECTORY_SEPARATOR.'01-manifest.json',json_encode([
		'presets'=>[['name'=>'json-preset']],
		'themes'=>[['name'=>'json-theme','preset'=>'json-preset']],
	]));
	file_put_contents($directory.DIRECTORY_SEPARATOR.'02-invalid.json','{invalid');
	file_put_contents($directory.DIRECTORY_SEPARATOR.'03-preset.php',"<?php return ['name'=>'php-preset'];\n");
	file_put_contents($directory.DIRECTORY_SEPARATOR.'04-theme.php',"<?php return ['type'=>'theme','name'=>'php-theme'];\n");
	file_put_contents($directory.DIRECTORY_SEPARATOR.'05-list.php',"<?php return [['name'=>'list-one'],['name'=>'list-two']];\n");
	file_put_contents($directory.DIRECTORY_SEPARATOR.'ignored.txt','ignored');

	$library=PanelThemeLibrary::load($directory);
	$libraryInternals=$t->nonPublic($library);
	$t->isTrue($library->has('json-preset'));
	$t->isTrue($library->has('php-preset'));
	$t->isTrue($library->has('list-one'));
	$t->isTrue($library->hasTheme('json-theme'));
	$t->isTrue($library->hasTheme('php-theme'));
	$library->loadFrom([$directory.DIRECTORY_SEPARATOR.'03-preset.php','',42]);
	$libraryInternals->invoke('loadPath',$directory.DIRECTORY_SEPARATOR.'missing');
	$libraryInternals->invoke('loadFile',$directory.DIRECTORY_SEPARATOR.'ignored.txt');
	$libraryInternals->invoke('registerPayload',PanelThemePreset::make('direct-preset'),'direct');
	$libraryInternals->invoke('registerPayload',PanelTheme::make('direct-theme'),'direct');
	$libraryInternals->invoke('registerPayload','invalid','direct');
	$libraryInternals->invoke('registerPayload',[
		'presets'=>[['name'=>'only-preset']],
	],'direct');
	$libraryInternals->invoke('registerPayload',[
		'kind'=>'theme','name'=>'kind-theme','meta'=>'invalid',
	],'direct');
	$libraryInternals->invoke('registerPayload',[
		'name'=>'assoc-preset','meta'=>['existing'=>true],
	],'direct');
	$t->isTrue($library->has('direct-preset'));
	$t->isTrue($library->hasTheme('direct-theme'));
	$t->isTrue($library->has('only-preset'));
	$t->isTrue($library->hasTheme('kind-theme'));

});

test('panel theme support library diagnoses references cycles and contrast',static function(Context $t): void {
	$library=PanelThemeLibrary::make()
		->register(['name'=>'known-preset'])
		->registerThemes([
			['name'=>'known-preset-theme','preset'=>'known-preset'],
			['name'=>'cycle-a','extends'=>'cycle-b'],
			['name'=>'cycle-b','base_theme'=>'cycle-a'],
			['name'=>'missing-base','base'=>['unknown-base',PanelTheme::make('object-base')]],
			['name'=>'missing-preset','presets'=>['unknown-preset',PanelThemePreset::make('object-preset')]],
			['name'=>'contrast','colors'=>['primary'=>'#ffffff'],'tokens'=>[
				'surface'=>'#ffffff','surface_muted'=>'#ffffff','control_bg'=>'#ffffff','neutral_bg'=>'#ffffff',
				'text'=>'#ffffff','text_muted'=>'#ffffff','neutral_text'=>'#ffffff',
			]],
		]);
	$libraryInternals=$t->nonPublic($library);
	$definitions=$libraryInternals->readProperty('themeDefinitions');
	$definitions['invalid-definition']='scalar';
	$libraryInternals->writeProperty('themeDefinitions',$definitions);
	$diagnostics=$library->diagnostics();
	$t->notEmpty($diagnostics['missing_bases']);
	$t->notEmpty($diagnostics['missing_presets']);
	$t->notEmpty($diagnostics['cycles']);
	$t->notEmpty($diagnostics['contrast']);
	$t->notEmpty($diagnostics['contrast_failures']);
	$t->isFalse($library->isValid());
	$t->isTrue(PanelThemeLibrary::make()->isValid());

	$t->isTrue($libraryInternals->invoke('referenceExists','cycle-a'));
	$t->isTrue($libraryInternals->invoke('referenceExists','known-preset'));
	$t->isFalse($libraryInternals->invoke('referenceExists','missing'));
	$t->same(['a','b'],$libraryInternals->invoke('stringReferences',[
		'extends'=>[' A ','B','A',new stdClass()],
	],['extends']));
	$t->same(['one'],$libraryInternals->invoke('referenceValues','one'));
	$t->same([],$libraryInternals->invoke('referenceValues',PanelTheme::make('object')));
	$t->same([],$libraryInternals->invoke('referenceValues',['name'=>'associative']));
	$t->same([],$libraryInternals->invoke('referenceValues',42));

	$resolving=['stuck'=>true];
	$libraryInternals->writeProperty('resolvingThemes',$resolving);
	$t->same(null,$libraryInternals->invoke('resolveTheme','stuck'));
	$t->same(null,$libraryInternals->invoke('resolveTheme','missing'));
});
