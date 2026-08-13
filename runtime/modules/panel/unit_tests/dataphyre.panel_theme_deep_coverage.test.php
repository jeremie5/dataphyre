<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelTheme;
use Dataphyre\Panel\PanelThemeLibrary;
use Dataphyre\Panel\PanelThemePreset;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel theme builds manifests presets libraries and registered previews',static function(Context $t): void {
	$t->same('#dc2626',PanelTheme::palette('red')[600]);
	$library=PanelTheme::themeLibrary();
	$t->same($library,PanelTheme::themeLibrary());

	$objectPreset=PanelThemePreset::make('object preset');
	$t->same($objectPreset,PanelTheme::presetDefinition($objectPreset));
	$t->same('array_preset',PanelTheme::presetDefinition(['name'=>'array preset'])->toArray()['name']);
	$registeredPreset=PanelTheme::registerPreset([
		'name'=>'registered preset',
		'colors'=>['primary'=>'indigo'],
		'tokens'=>['surface'=>'#ffffff'],
	]);
	$t->same($registeredPreset,PanelTheme::presetDefinition('registered preset'));
	$t->same('brutalist',PanelTheme::presetDefinition('brutalist')->toArray()['name']);
	$t->same('glass',PanelTheme::presetDefinition('glass')->toArray()['name']);
	$t->same('flat_minima',PanelTheme::presetDefinition('default')->toArray()['name']);
	$t->same('flat_minima',PanelTheme::presetDefinition('flat minima')->toArray()['name']);
	$t->same('unregistered',PanelTheme::presetDefinition('unregistered')->toArray()['name']);

	$registeredTheme=PanelTheme::make('registered object')->token('surface','#ffffff');
	$t->same($registeredTheme,PanelTheme::registerTheme($registeredTheme));
	$arrayTheme=PanelTheme::registerTheme(['name'=>'registered array','tokens'=>['surface'=>'#f8fafc']]);
	$t->same('registered_array',$arrayTheme->name());
	$t->same('registered_object',PanelTheme::namedTheme('registered object')?->name());
	$t->same(null,PanelTheme::namedTheme('missing theme'));
	$t->same($library,PanelTheme::loadPresets([]));
	$t->same($library,PanelTheme::loadThemes([]));
	$t->isTrue(is_array(PanelTheme::diagnostics()));
	$t->same('registered_object',PanelTheme::previewTheme('registered object')['name']);
	$t->contains('registered_object',PanelTheme::previewThemeHtml('registered object'));

	$base=PanelTheme::make('base theme')
		->assetRoot('icons','/base/icons')
		->color('primary','#112233')
		->tokens(['surface'=>'#ffffff','text'=>'#111111'])
		->darkTokens(['surface'=>'#111111','text'=>'#ffffff'])
		->font('Base Font','/base-font.css','local')
		->darkMode()->defaultMode('dark')->modeToggle()
		->brandName('Base')->brandLogo('/base.svg')->darkModeBrandLogo('/base-dark.svg')
		->brandLogoHeight('32px')->favicon('/base.ico')->stylesheet('/base.css','base');
	PanelTheme::registerTheme($base);
	$definition=[
		'name'=>'Manifest Theme',
		'extends'=>$base,
		'base_theme'=>PanelThemePreset::make('base preset')->token('from_preset','yes'),
		'base'=>['name'=>'array base','tokens'=>['from_array_base'=>'yes']],
		'asset_roots'=>['theme'=>'https://cdn.example.test/theme','bad'=>new stdClass()],
		'preset'=>PanelThemePreset::make('single preset')->token('single','yes'),
		'presets'=>[
			PanelThemePreset::make('list preset')->token('list','yes'),
			['name'=>'array list preset','tokens'=>['array_list'=>'yes']],
			42,
		],
		'colors'=>['primary'=>'rose','success'=>'#00aa44'],
		'tokens'=>['surface'=>'#fefefe','text'=>'#101010'],
		'dark_tokens'=>['surface'=>'#101010','text'=>'#fefefe'],
		'font'=>'Manifest Font','font_url'=>'theme::fonts/font.css','font_provider'=>'cdn',
		'dark_mode'=>true,'default_mode'=>'light','mode_toggle'=>true,
		'brand'=>['name'=>'Nested Brand','logo'=>'nested.svg','dark_logo'=>'nested-dark.svg','logo_height'=>'30px'],
		'brand_name'=>'Top Brand','brand_logo'=>'top.svg','dark_brand_logo'=>'top-dark.svg','brand_logo_height'=>'40px',
		'favicon'=>'theme::favicon.ico',
		'css'=>['theme::base.css',['href'=>'theme::structured.css','name'=>'structured']],
		'css_assets'=>[['url'=>'theme::asset.css','name'=>'asset','attributes'=>['media'=>'screen']]],
	];
	$theme=PanelTheme::fromArray($definition);
	$manifest=$theme->manifest();
	$t->same('manifest_theme',$theme->name());
	$t->same('Top Brand',$theme->brand()['name']);
	$t->same('light',$theme->mode());
	$t->isTrue($theme->darkModeEnabled());
	$t->isTrue($theme->modeToggleEnabled());
	$t->same('theme::favicon.ico',$theme->faviconUrl());
	$t->contains('https://cdn.example.test/theme/base.css',$theme->cssAssets());
	$t->isTrue(isset($manifest['css_variables']));

	$t->nonPublic(PanelTheme::class)->replacePropertyForTest('library',PanelThemeLibrary::make());
	$t->same('brutalist',PanelTheme::presetDefinition('brutalist')->toArray()['name']);
	$t->same('glass',PanelTheme::presetDefinition('glass')->toArray()['name']);
})->tag('panel','theme','coverage')->group('framework-coverage');

test('panel theme fluent variants assets presets and aliases normalize state',static function(Context $t): void {
	$theme=PanelTheme::make('')
		->colors([
			''=>'ignored',
			'Primary'=>'blue',
			'success'=>'#123456',
			'warning'=>'custom-color',
			'danger'=>[600=>'#aa0000',100=>'#ffeeee','bad'=>new stdClass()],
			'info'=>[],
		])
		->color('gray','slate')
		->tokens([' Radius '=>'12px','empty name'=>null,'bad'=>new stdClass()])
		->token('gap','10px')
		->darkTokens(['Surface'=>'#111111','bad'=>new stdClass()])
		->darkToken('text','#ffffff')
		->assetRoots(['theme'=>' /assets/theme/ ','empty'=>'', 'bad'=>new stdClass(), 3=>null])
		->assetRoot('icons','/assets/icons')
		->assetRoot('','/ignored')
		->radius('8px')->maxWidth('1200px')->panelPadding('20px')->sectionPadding('18px')
		->controlPadding('8px 12px')->inputPadding('9px 13px')->tableCellPadding('10px')->gap('14px')
		->darkSurface('#111827','#1f2937')->darkSurface('#111827')
		->darkBody('#030712')->darkText('#f9fafb','#d1d5db','#9ca3af')->darkText('#ffffff')
		->font('Inter Display','theme::font.css','google')
		->darkMode()->defaultMode('DARK')->modeToggle()
		->brandName(' Dataphyre ')->brandLogo(' /logo.svg ')->darkModeBrandLogo(' /logo-dark.svg ')
		->brandLogoHeight(' 36px ')->favicon(' /favicon.ico ')
		->css(null)
		->css('theme::main.css')
		->css(['href'=>'theme::one.css','name'=>'one','attributes'=>['media'=>'print']])
		->css([
			['url'=>'theme::two.css','name'=>'two'],
			['path'=>'icons::three.css','name'=>'three'],
			['name'=>'unsupported'],
			42,
		])
		->stylesheet('theme::four.css','four',['media'=>'screen'])
		->stylesheet('','empty');

	$t->same('default',$theme->name());
	$t->same('dark',$theme->mode());
	$t->isTrue(count($theme->cssAssets())>=4);
	$t->isTrue(count($theme->stylesheetAssets())>=4);
	$t->contains('/assets/theme/main.css',$theme->cssAssets());
	$t->same('/favicon.ico',$theme->faviconUrl());
	$t->same('Dataphyre',$theme->brand()['name']);

	$copy=$theme->copy();
	$namedCopy=$theme->copy('named copy');
	$t->same('default',$copy->name());
	$t->same('named_copy',$namedCopy->name());
	$arrayVariant=$theme->with(['tokens'=>['gap'=>'2px'],'css'=>['/replacement.css']]);
	$t->same('default',$arrayVariant->name());
	$t->same('named_array',$theme->with(['tokens'=>['gap'=>'3px']], 'named array')->name());
	$closureVariant=$theme->with(static function(PanelTheme $copy): null {
		$copy->token('closure','yes');
		return null;
	},'closure variant');
	$t->same('closure_variant',$closureVariant->name());
	$replacement=PanelTheme::make('replacement');
	$t->same($replacement,$theme->with(static fn(PanelTheme $copy): PanelTheme=>$replacement));
	$t->same('alias_variant',$theme->variant('alias variant',['tokens'=>['alias'=>'yes']])->name());

	$completePreset=PanelThemePreset::fromArray([
		'name'=>'complete preset','colors'=>['primary'=>'violet'],'tokens'=>['surface'=>'#fff'],
		'dark_tokens'=>['surface'=>'#000'],'asset_roots'=>['preset'=>'/preset'],
		'font'=>'Preset Font','font_url'=>'preset::font.css','font_provider'=>'local',
		'dark_mode'=>false,'default_mode'=>'light','mode_toggle'=>false,
		'brand'=>['name'=>'Preset Brand','logo'=>'/preset.svg','dark_logo'=>'/preset-dark.svg','logo_height'=>'22px','unknown'=>'ignored','nullable'=>null],
		'favicon'=>'/preset.ico','css'=>['preset::preset.css'],'css_assets'=>[['href'=>'preset::asset.css','name'=>'preset_asset']],
	]);
	$applied=PanelTheme::make('applied')->assetRoot('preset','/explicit')->applyPreset($completePreset);
	$t->same('Preset Brand',$applied->brand()['name']);
	$t->isFalse($applied->darkModeEnabled());
	$t->isFalse($applied->modeToggleEnabled());
	$t->same('/preset/font.css',$applied->toArray()['font_url']);
	$t->same($applied,$applied->preset($completePreset));

	PanelTheme::registerTheme($theme->copy('named base'));
	$extended=PanelTheme::make('extended')->assetRoot('theme','/explicit-theme');
	$t->same($extended,$extended->extend($completePreset));
	$t->same($extended,$extended->extend('named base'));
	$t->same($extended,$extended->extend('glass'));
	$t->same($extended,$extended->extend(['name'=>'array extension','tokens'=>['extension'=>'array']]));
	$t->same($extended,$extended->extend($theme));
	$t->same('/explicit-theme',$extended->toArray()['asset_roots']['theme']);

	$theme->font('')->brandName('')->brandLogo('')->darkModeBrandLogo('')->brandLogoHeight('')->favicon('');
	$theme->darkMode(false)->defaultMode('invalid')->modeToggle(false);
	$t->same('system',$theme->mode());
	$t->isFalse($theme->darkModeEnabled());
	$t->isFalse($theme->modeToggleEnabled());
	$t->same(null,$theme->faviconUrl());
	$t->same(null,$theme->brand()['name']);
	$t->same('Arial, sans-serif',$t->nonPublic(PanelTheme::class)->invoke('fontStack',''));
	$t->same('Inter, Arial, sans-serif',$t->nonPublic(PanelTheme::class)->invoke('fontStack','Inter'));
	$t->same('"Inter Display", Arial, sans-serif',$t->nonPublic(PanelTheme::class)->invoke('fontStack','"Inter Display"'));
	$t->isTrue(is_array($arrayVariant->toArray()));
})->tag('panel','theme','coverage')->group('framework-coverage');

test('panel theme previews contrast css json and filesystem exports',static function(Context $t): void {
	$theme=PanelTheme::make('export theme')
		->colors([
			'primary'=>[600=>'#000000'],'success'=>[600=>'#ffffff'],
			'warning'=>[600=>'invalid'],'danger'=>[600=>'#777777'],'info'=>[600=>'#123456'],
		])
		->tokens([
			'body_bg'=>'#ffffff','surface'=>'#ffffff','surface_muted'=>'invalid',
			'text'=>'#000000','text_muted'=>'#777777','border'=>'#dddddd','border_soft'=>'#eeeeee',
			'control_bg'=>'#ffffff','control_border'=>'#cccccc','neutral_bg'=>'#000000','neutral_text'=>'#ffffff',
			'control_padding'=>'8px','input_padding'=>'9px','radius'=>'6px',
		])
		->darkTokens(['surface'=>'#000000','text'=>'#ffffff'])
		->font('Inter','/font.css')->stylesheet('/theme.css','theme')
		->brandName('Export')->favicon('/favicon.ico');

	$manifest=$theme->manifest();
	$t->same('theme',$manifest['type']);
	$t->contains(':root{',$theme->styleVariables());
	$t->contains('--dp-primary-600:#000000;',$theme->styleVariables());
	$t->contains('[data-dp-theme-mode="dark"]',$theme->styleVariables());
	$t->isTrue(count($theme->contrastDiagnostics(7.0,4.0)['light'])>=5);
	$preview=$theme->preview();
	$t->same('export_theme',$preview['name']);
	$t->same('light',$preview['modes']['light']['mode']);
	$t->same('dark',$preview['modes']['dark']['mode']);
	$t->contains('dp-theme-preview',$theme->previewHtml(['title'=>'Theme preview']));
	$t->contains('export_theme',$theme->toJson(JSON_PRETTY_PRINT));
	$t->contains('Dataphyre Panel theme: export_theme',$theme->toCss());

	$withoutDark=$theme->copy('light only')->darkMode(false);
	$t->same([],$withoutDark->contrastDiagnostics()['dark']);
	$t->same(null,$withoutDark->preview()['modes']['dark']);
	$t->isFalse(str_contains($withoutDark->styleVariables(),'data-dp-theme-mode'));

	$workspace=$t->workspace('dataphyre-panel-theme');
	$directory=$workspace->path('export');
	$result=$theme->exportTo($directory,'Custom Export');
	$t->isTrue($result['manifest']);
	$t->isTrue($result['css']);
	$t->isTrue(is_file($result['manifest_path']));
	$t->isTrue(is_file($result['css_path']));
	$t->isTrue($theme->writeManifest($directory.DIRECTORY_SEPARATOR.'manual.json'));
	$t->isTrue($theme->writeCss($directory.DIRECTORY_SEPARATOR.'manual.css'));
	$t->isFalse($theme->writeCss(''));

	$blockingFile=$workspace->file('blocking.file','block');
	$t->isFalse($theme->writeCss($blockingFile.DIRECTORY_SEPARATOR.'child.css'));
	$t->isFalse($t->nonPublic(PanelTheme::class)->invoke('writeFile',$directory,'contents'));

	$invalidUtf8="\xB1\x31";
	$t->same('{}',PanelTheme::make('json failure')->token('bad',$invalidUtf8)->toJson());
})->tag('panel','theme','coverage')->group('framework-coverage');

test('panel theme private normalizers cover palette asset color and css boundaries',static function(Context $t): void {
	$theme=PanelTheme::make('private helpers')->assetRoots(['known'=>'https://cdn.example.test/root']);

	$t->same(null,$t->nonPublic(PanelTheme::class)->invoke('safeCssValue',[1,2]));
	$t->same('stringable',$t->nonPublic(PanelTheme::class)->invoke('safeCssValue',new class implements Stringable {
		public function __toString(): string { return ' stringable '; }
	}));
	foreach(['','bad;value','bad<value','expression(alert)','javascript:alert','-moz-binding:url(x)','@import url(x)',"bad\nvalue"] as $unsafe){
		$t->same(null,$t->nonPublic(PanelTheme::class)->invoke('safeCssValue',$unsafe));
	}
	$t->same('calc(100% - 1rem)',$t->nonPublic(PanelTheme::class)->invoke('safeCssValue',' calc(100% - 1rem) '));
	$css=PanelTheme::styleVariablesFor(
		['primary'=>[600=>'#112233',700=>'bad;value']],
		['gap'=>'1rem','bad'=>'javascript:alert(1)'],true,
		['surface'=>'#111111','bad'=>'expression(x)']
	);
	$t->contains('--dp-primary-600:#112233;',$css);
	$t->isFalse(str_contains($css,'javascript'));

	$variables=$t->nonPublic(PanelTheme::class)->invoke('variablesFrom',[
		'primary'=>[600=>'#112233'],
	],['gap'=>'1rem']);
	$t->same('#112233',$variables['--dp-primary-600']);
	$t->same('1rem',$variables['--dp-gap']);
	$merged=$t->nonPublic(PanelTheme::class)->invoke('mergeDefinition',['tokens'=>['a'=>1,'b'=>2],'css'=>['old'],'presets'=>['old']],
		['tokens'=>['b'=>3],'css'=>['new'],'presets'=>['new'],'preset'=>'single','css_assets'=>[]],);
	$t->same(['new'],$merged['css']);
	$t->same(['new'],$merged['presets']);
	$t->isTrue(count($t->nonPublic(PanelTheme::class)->invoke('defaultDarkTokens'))>=10);
	$t->isTrue(count($t->nonPublic(PanelTheme::class)->invoke('defaultColors'))===6);

	$t->same([17,34,51],$t->nonPublic(PanelTheme::class)->invoke('parseColor','#112233'));
	$t->same([17,34,51],$t->nonPublic(PanelTheme::class)->invoke('parseColor','112233'));
	$t->same(null,$t->nonPublic(PanelTheme::class)->invoke('parseColor','rgb(1,2,3)'));
	$t->same([37,99,235],$t->nonPublic(PanelTheme::class)->invoke('hexToRgb','invalid'));
	$t->same([255,0,128],$t->nonPublic(PanelTheme::class)->invoke('hexToRgb','#ff0080'));
	$t->same('#ff0080',$t->nonPublic(PanelTheme::class)->invoke('rgbToHex',300,-1,128));
	$t->isTrue($t->nonPublic(PanelTheme::class)->invoke('contrastRatio',[0,0,0],[255,255,255])>20.0);
	$t->same(0.0,$t->nonPublic(PanelTheme::class)->invoke('relativeLuminance',[0,0,0]));
	$t->isTrue($t->nonPublic(PanelTheme::class)->invoke('relativeLuminance',[255,255,255])>0.99);

	$t->isTrue($t->nonPublic(PanelTheme::class)->invoke('isAssetDefinition',['href'=>'/a.css']));
	$t->isTrue($t->nonPublic(PanelTheme::class)->invoke('isAssetDefinition',['url'=>'/a.css']));
	$t->isTrue($t->nonPublic(PanelTheme::class)->invoke('isAssetDefinition',['path'=>'/a.css']));
	$t->isFalse($t->nonPublic(PanelTheme::class)->invoke('isAssetDefinition',['name'=>'a']));
	$t->same('https://cdn.example.test/root/a.css',$t->nonPublic($theme)->invoke('resolveAssetEntry','known::a.css'));
	$t->same(42,$t->nonPublic($theme)->invoke('resolveAssetEntry',42));
	$t->same(['name'=>'plain'],$t->nonPublic($theme)->invoke('resolveAssetEntry',['name'=>'plain']));
	foreach(['href','url','path'] as $key){
		$resolved=$t->nonPublic($theme)->invoke('resolveAssetEntry',[$key=>'known::file.css','name'=>$key]);
		$t->same('https://cdn.example.test/root/file.css',$resolved[$key]);
	}
	$unsupported=$t->nonPublic($theme)->invoke('resolveAssetEntry',['href'=>new stdClass()]);
	$t->instanceOf(stdClass::class,$unsupported['href']);
	$t->same('/plain.css',$t->nonPublic($theme)->invoke('resolveAssetHref',' /plain.css '));
	$t->same('unknown::file.css',$t->nonPublic($theme)->invoke('resolveAssetHref','unknown::file.css'));
	$t->same('https://cdn.example.test/root/file.css',$t->nonPublic($theme)->invoke('resolveAssetHref','known::/file.css'));

	$preset=PanelThemePreset::make('private preset');
	$t->same([$preset],$t->nonPublic(PanelTheme::class)->invoke('presetDefinitions',$preset));
	$t->same(['glass'],$t->nonPublic(PanelTheme::class)->invoke('presetDefinitions','glass'));
	$t->same([],$t->nonPublic(PanelTheme::class)->invoke('presetDefinitions',42));
	$t->same(['glass',['name'=>'array']],$t->nonPublic(PanelTheme::class)->invoke('presetDefinitions',['glass',42,['name'=>'array']]));
	$t->same([['name'=>'map']],$t->nonPublic(PanelTheme::class)->invoke('presetDefinitions',[ 'name'=>'map' ]));

	$base=PanelTheme::make('private base');
	$bases=$t->nonPublic(PanelTheme::class)->invoke('baseDefinitions',[
		'extends'=>[$base,42,'glass'],
		'base_theme'=>$preset,
		'base'=>['name'=>'map base'],
	]);
	$t->same(4,count($bases));
	$t->same([],$t->nonPublic(PanelTheme::class)->invoke('baseDefinitions',[]));

	$named=$t->nonPublic(PanelTheme::class)->invoke('namedPalette','blue');
	$t->same(11,count($named));
	$t->same(null,$t->nonPublic(PanelTheme::class)->invoke('namedPalette','missing'));
	$t->same('#2563eb',$t->nonPublic(PanelTheme::class)->invoke('normalizePalette','blue')[600]);
	$t->same('#112233',$t->nonPublic(PanelTheme::class)->invoke('normalizePalette','#112233')[600]);
	$unknown=$t->nonPublic(PanelTheme::class)->invoke('normalizePalette','custom');
	$t->same('custom',$unknown[50]);
	$t->same('custom',$unknown[600]);
	$fallback=[50=>'fallback',600=>'base'];
	$t->same('custom',$t->nonPublic(PanelTheme::class)->invoke('normalizePalette','custom',$fallback)[600]);
	$t->same('#abcdef',$t->nonPublic(PanelTheme::class)->invoke('normalizePalette',[600=>'#abcdef'])[600]);
	$t->same('fallback',$t->nonPublic(PanelTheme::class)->invoke('normalizePalette',[], $fallback)[50]);
	$t->same(11,count($t->nonPublic(PanelTheme::class)->invoke('normalizePalette',new stdClass())));
	$t->same(11,count($t->nonPublic(PanelTheme::class)->invoke('paletteFromHex','#336699')));
})->tag('panel','theme','coverage')->group('framework-coverage');
