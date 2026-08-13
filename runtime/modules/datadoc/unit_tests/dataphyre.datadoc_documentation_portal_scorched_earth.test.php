<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Datadoc\DocumentationPortal;
use Dataphyre\Datadoc\DocumentationPortalBuild;
use Dataphyre\Datadoc\DocumentationPortalPublication;
use Dataphyre\Datadoc\DocumentationPortalWriteResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__,2).'/testing/tooling/bootstrap.php';
require_once dirname(__DIR__).'/Framework/DocumentationPortal.php';
require_once dirname(__DIR__).'/Framework/DocumentationPortalBuild.php';
require_once dirname(__DIR__).'/Framework/DocumentationPortalWriteResult.php';
require_once dirname(__DIR__).'/Framework/DocumentationPortalPublication.php';

suite('Datadoc universal static documentation portal')
	->contract('datadoc.documentation-static-portal',1)
	->layer('integration')
	->risk('high')
	->watches('module:datadoc','framework:documentation')
	->through('normalized-corpus','safe-markdown','static-assets','responsive-shell','integrity-checked-build')
	->tag('datadoc','documentation','portal','security','responsive','deep-coverage')
	->group('framework-coverage');

/** @return array<string,string> */
function dp_datadoc_portal_corpus():array {
	return [
		'index.md'=>"# Dataphyre Documentation\n\nUniversal module and product documentation.\n",
		'api/types/example.md'=>"# Example API\n\n## Methods\n\nUse `Example::make()`.\n",
		'guides/index.md'=>"# Guides\n\n## Start here\n\nRead the [API](../api/types/example.md).\n",
		'modules/cache.md'=>"# Cache module\n\nA module without a directory index.\n",
		'release-notes.md'=>"# Release notes\n\nRoot-level supporting material.\n",
	];
}

function dp_datadoc_portal_png():string {
	$contents=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',true);
	if(!is_string($contents)){ throw new RuntimeException('Unable to decode the Datadoc PNG fixture.'); }
	return $contents;
}

/** @return array<string,string> */
function dp_datadoc_portal_raster_assets():array {
	return [
		'media/architecture.png'=>dp_datadoc_portal_png(),
		'media/photo.jpg'=>"\xff\xd8\xff\xd9",
		'media/animation.gif'=>"GIF89a\x01\x00\x01\x00",
		'media/preview.webp'=>"RIFF\x08\x00\x00\x00WEBPVP8 ",
		'media/preview.avif'=>"\x00\x00\x00\x18ftypavif\x00\x00\x00\x00avif",
		'media/icon.ico'=>"\x00\x00\x01\x00\x01\x00",
	];
}

/** @return array<string,string> */
function dp_datadoc_portal_french_copy():array {
	return [
		'skip_to_content'=>'Aller au contenu',
		'repository'=>'Dépôt',
		'search'=>'Rechercher',
		'version'=>'Version',
		'change_color_theme'=>'Changer le thème de couleur',
		'theme'=>'Thème',
		'menu'=>'Menu',
		'close_navigation'=>'Fermer la navigation',
		'documentation'=>'Documentation',
		'generated_release'=>'Généré à partir de la version vérifiée {version} de la documentation.',
		'search_documentation'=>'Rechercher dans la documentation',
		'close_search'=>'Fermer la recherche',
		'close'=>'Fermer',
		'search_terms'=>'Termes de recherche',
		'search_placeholder'=>'Saisissez une classe, une méthode ou un guide',
		'type_two_characters'=>'Saisissez au moins deux caractères.',
		'javascript_required'=>'La recherche, le changement de thème et la navigation mobile nécessitent JavaScript. Chaque page demeure lisible sans JavaScript.',
		'page_not_found'=>'Page introuvable',
		'missing_page'=>'La page demandée ne fait pas partie de cette version immuable.',
		'open_documentation'=>'Ouvrir la documentation',
		'on_this_page'=>'Dans cette page',
		'overview'=>'Vue d’ensemble',
		'api_reference'=>'Référence de l’API',
		'color_theme_status'=>'Thème de couleur : {theme}. Activez pour le changer.',
		'color_theme_changed'=>'Le thème de couleur est maintenant {theme}.',
		'search_result_one'=>'{count} résultat.',
		'search_result_many'=>'{count} résultats.',
		'no_matching_documentation'=>'Aucun document correspondant.',
		'searching'=>'Recherche en cours.',
		'search_index_unavailable'=>'L’index de recherche n’a pas pu être chargé.',
		'copy'=>'Copier',
		'copied'=>'Copié',
		'code_copied'=>'Le code a été copié dans le presse-papiers.',
		'code_copy_failed'=>'Le code n’a pas pu être copié.',
	];
}

/** @param array<mixed> $value */
function dp_datadoc_portal_json(array $value):string {
	$canonical=static function(array $input) use (&$canonical):array {
		foreach($input as $key=>$item){ if(is_array($item)){ $input[$key]=$canonical($item); } }
		if(!array_is_list($input)){ ksort($input,SORT_STRING); }
		return $input;
	};
	return json_encode($canonical($value),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n";
}

test('Datadoc builds deterministic product-neutral portals with dynamic navigation',static function(Context $t):void {
	$engine=DocumentationPortal::make();
	$t->same($engine->manifest(),$engine->jsonSerialize());
	$t->same('datadoc_documentation_portal_engine',$engine->manifest()['type']);
	$t->same('datadoc',$engine->manifest()['owner_module']);
	$t->same('normalized_markdown_corpus',$engine->manifest()['input']);
	$t->isFalse($engine->manifest()['network_access']);
	$t->isFalse($engine->manifest()['source_execution']);
	$t->isFalse($engine->manifest()['raw_html_trusted']);
	$t->isFalse($engine->manifest()['external_dependencies']);

	$options=[
		'language'=>'en-CA',
		'default_theme'=>'system',
		'version_links'=>['2.1.0'=>'','2.0.0'=>'../2.0.0/index.html','1.9.0'=>'/dataphyre/1.9.0/index.html'],
		'canonical_base_url'=>'https://docs.example.test/dataphyre/2.1.0',
		'repository_url'=>'https://code.example.test/dataphyre',
		'maximum_search_text_bytes'=>2048,
	];
	$first=$engine->build('2.1.0','Dataphyre Documentation',dp_datadoc_portal_corpus(),['source-map.json'],$options);
	$second=$engine->build('2.1.0','Dataphyre Documentation',dp_datadoc_portal_corpus(),['source-map.json'],$options);
	$t->instanceOf(DocumentationPortalBuild::class,$first);
	$t->same($first->jsonSerialize(),$second->jsonSerialize());
	$t->same($first->files(),$second->files());
	$t->same('2.1.0',$first->version());
	$t->same(count($first->files()),count($first));
	$t->same($first->files(),iterator_to_array($first));
	$t->same($first->fileManifest(),$first->jsonSerialize()['files']);
	$t->same(count($first),$first->jsonSerialize()['file_count']);
	$t->same('dataphyre_datadoc_portal_build',$first->jsonSerialize()['type']);
	$t->same(null,$first->file('missing.html'));
	$t->same($first->file('api/types/example.html'),$first->file('api\\types\\example.html'));

	$manifest=$first->manifest();
	$t->same('dataphyre_datadoc_static_portal',$manifest['type']);
	$t->same('2.1.0',$manifest['documentation_version']);
	$t->same(5,$manifest['page_count']);
	$t->same(5,$manifest['search_entry_count']);
	$t->same(['overview','api','guides','modules'],array_column($manifest['navigation'],'id'));
	$t->same(['index.html','api/types/example.html','guides/index.html','modules/cache.html'],array_column($manifest['navigation'],'path'));
	$t->same('https://docs.example.test/dataphyre/2.1.0/',$manifest['canonical_base_url']);
	$t->same($manifest,json_decode((string)$first->file('portal.json'),true,128,JSON_THROW_ON_ERROR));

	$search=json_decode((string)$first->file('search-index.json'),true,128,JSON_THROW_ON_ERROR);
	$t->same('dataphyre_datadoc_search_index',$search['type']);
	$t->same(5,$search['entry_count']);
	$t->same(['api','guides','overview','modules'],array_values(array_unique(array_column($search['entries'],'section'))));
	$versions=json_decode((string)$first->file('versions.json'),true,128,JSON_THROW_ON_ERROR);
	$t->same('dataphyre_datadoc_versions',$versions['type']);
	$t->same('2.1.0',$versions['current']);

	$index=(string)$first->file('index.html');
	$deep=(string)$first->file('api/types/example.html');
	$t->contains('Dataphyre Documentation',$index);
	$t->contains('href="api/types/example.html"',$index);
	$t->contains('href="../../assets/portal.css"',$deep);
	$t->contains('href="../../guides/index.html"',$deep);
	$t->contains('Content-Security-Policy',$deep);
	$t->notContains('<script>',$deep);
	$t->notContains('Panel',(string)$first->file('assets/portal.js'));
	$t->notContains('Panel',(string)$first->file('assets/favicon.svg'));
	$t->contains('dataphyre-datadoc-theme',(string)$first->file('assets/portal.js'));
	$t->contains('dataphyre_datadoc_search_index',(string)$first->file('assets/portal.js'));
	$t->contains("<url><loc>https://docs.example.test/dataphyre/2.1.0/api/types/example.html</loc></url>",(string)$first->file('sitemap.xml'));
});

test('Datadoc publishes bounded signature-validated local raster assets with safe Markdown images',static function(Context $t):void {
	$engine=DocumentationPortal::make();
	$assets=dp_datadoc_portal_raster_assets();
	$t->same([
		'image/png','image/jpeg','image/gif','image/webp','image/avif','image/vnd.microsoft.icon',
	],array_values(array_map(static fn(string $contents,string $path):string=>DocumentationPortal::contentAssetMime($path,$contents),$assets,array_keys($assets))));
	$documents=[
		'index.md'=>"# Media documentation\n\n![Architecture <script>safe text</script>](media/architecture.png \"Architecture diagram\")\n\n![Remote image](https://example.test/tracker.png)\n",
		'guides/deep/start.md'=>"# Deep media\n\n## Screenshot\n\n![Nested architecture](../../media/architecture.png)\n",
	];
	$first=$engine->build('2.2.0','Dataphyre media documentation',$documents,[],[],contentAssets:$assets);
	$second=$engine->build('2.2.0','Dataphyre media documentation',array_reverse($documents,true),[],[],array_reverse($assets,true));
	$t->same($first->jsonSerialize(),$second->jsonSerialize());
	$t->same(6,$first->manifest()['content_asset_count']);
	$t->same(array_sum(array_map('strlen',$assets)),$first->manifest()['content_asset_bytes']);
	$t->same(['media/animation.gif','media/architecture.png','media/icon.ico','media/photo.jpg','media/preview.avif','media/preview.webp'],array_column($first->manifest()['content_assets'],'path'));
	$t->same($assets['media/architecture.png'],$first->file('media/architecture.png'));
	$t->same('image/png',$first->manifest()['content_assets'][1]['media_type']);
	$t->same(1,preg_match('/^[a-f0-9]{64}$/D',$first->manifest()['corpus_fingerprint']));
	$t->isTrue($first->manifest()['capabilities']['local_content_assets']);
	$index=(string)$first->file('index.html');
	$deep=(string)$first->file('guides/deep/start.html');
	$t->contains('<img class="dp-doc-image" src="media/architecture.png" alt="Architecture safe text" title="Architecture diagram" loading="lazy" decoding="async">',$index);
	$t->contains('<img class="dp-doc-image" src="../../media/architecture.png" alt="Nested architecture" loading="lazy" decoding="async">',$deep);
	$t->notContains('src="https://example.test/tracker.png"',$index);
	$t->contains('Remote image',$index);
	$t->contains('.dp-doc-image{display:block',(string)$first->file('assets/portal.css'));
	$search=json_decode((string)$first->file('search-index.json'),true,128,JSON_THROW_ON_ERROR);
	$searchByPath=array_column($search['entries'],null,'path');
	$t->contains('Architecture safe text',$searchByPath['index.html']['text']);
	$t->contains('Nested architecture',$searchByPath['guides/deep/start.html']['text']);
	$angleBuild=$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n\n![Architecture copy](<media/é copy.png> 'Copy (diagram)')\n"],[],[],contentAssets:['media/é copy.png'=>dp_datadoc_portal_png()]);
	$t->contains('<img class="dp-doc-image" src="media/%C3%A9%20copy.png" alt="Architecture copy" title="Copy (diagram)" loading="lazy" decoding="async">',(string)$angleBuild->file('index.html'));
	$invalidReference=$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n\n![Invalid local reference](".str_repeat('a',256).".png)\n"]);
	$t->contains('Invalid local reference',(string)$invalidReference->file('index.html'));
	$t->notContains('<img class="dp-doc-image"',(string)$invalidReference->file('index.html'));

	$t->throws(static fn()=>$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n\n![Missing](media/missing.png)\n"],[],[],contentAssets:['media/known.png'=>dp_datadoc_portal_png()]),RuntimeException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n\n![Alt](media/known.png)\n"],[],[],contentAssets:['media/known.png'=>'not a PNG']),InvalidArgumentException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n"],[],[],contentAssets:['media/unsafe.svg'=>'<svg/>']),InvalidArgumentException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n"],[],[],contentAssets:[0=>dp_datadoc_portal_png()]),InvalidArgumentException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n"],[],[],contentAssets:['media/empty.png'=>'']),LengthException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n"],['media/known.png'],[],contentAssets:['media/known.png'=>dp_datadoc_portal_png()]),RuntimeException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n"],[],[],contentAssets:['media/KNOWN.png'=>dp_datadoc_portal_png(),'media/known.png'=>dp_datadoc_portal_png()]),RuntimeException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n\n![".str_repeat('a',513)."](media/known.png)\n"],[],[],contentAssets:['media/known.png'=>dp_datadoc_portal_png()]),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationPortal::contentAssetMime('../escape.png',dp_datadoc_portal_png()),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationPortal::contentAssetMime('media/large.png',str_pad(dp_datadoc_portal_png(),16777217,"\0")),LengthException::class);
	$tooMany=[];
	for($index=0;$index<10001;$index++){ $tooMany['media/image-'.$index.'.png']=dp_datadoc_portal_png(); }
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',['index.md'=>"# Docs\n"],[],[],contentAssets:$tooMany),LengthException::class);

	$files=$first->files();
	$manifest=$first->manifest();
	$tampered=$files;
	$tampered['media/architecture.png'].='forged';
	$t->throws(static fn()=>DocumentationPortalBuild::make('2.2.0',$tampered,$manifest),InvalidArgumentException::class);
	$wrongMedia=$manifest;
	$wrongMedia['content_assets'][1]['media_type']='image/jpeg';
	$wrongMediaFiles=$files;
	$wrongMediaFiles['portal.json']=dp_datadoc_portal_json($wrongMedia);
	$t->throws(static fn()=>DocumentationPortalBuild::make('2.2.0',$wrongMediaFiles,$wrongMedia),InvalidArgumentException::class);
	$reordered=$manifest;
	$reordered['content_assets']=array_reverse($reordered['content_assets']);
	$reorderedFiles=$files;
	$reorderedFiles['portal.json']=dp_datadoc_portal_json($reordered);
	$t->throws(static fn()=>DocumentationPortalBuild::make('2.2.0',$reorderedFiles,$reordered),InvalidArgumentException::class);
	$malformedTop=$manifest;
	$malformedTop['content_asset_count']=7;
	$malformedTopFiles=$files;
	$malformedTopFiles['portal.json']=dp_datadoc_portal_json($malformedTop);
	$t->throws(static fn()=>DocumentationPortalBuild::make('2.2.0',$malformedTopFiles,$malformedTop),InvalidArgumentException::class);
	$malformedEntry=$manifest;
	$malformedEntry['content_assets'][0]=[];
	$malformedEntryFiles=$files;
	$malformedEntryFiles['portal.json']=dp_datadoc_portal_json($malformedEntry);
	$t->throws(static fn()=>DocumentationPortalBuild::make('2.2.0',$malformedEntryFiles,$malformedEntry),InvalidArgumentException::class);
	$invalidSignature=$manifest;
	$invalidSignatureFiles=$files;
	$invalidSignatureFiles['media/architecture.png']='not an image';
	$invalidSignature['content_asset_bytes']=$invalidSignature['content_asset_bytes']-strlen($assets['media/architecture.png'])+strlen($invalidSignatureFiles['media/architecture.png']);
	$invalidSignature['content_assets'][1]['bytes']=strlen($invalidSignatureFiles['media/architecture.png']);
	$invalidSignature['content_assets'][1]['sha256']=hash('sha256',$invalidSignatureFiles['media/architecture.png']);
	$invalidSignatureFiles['portal.json']=dp_datadoc_portal_json($invalidSignature);
	$t->throws(static fn()=>DocumentationPortalBuild::make('2.2.0',$invalidSignatureFiles,$invalidSignature),InvalidArgumentException::class);
	$undeclared=$manifest;
	$undeclared['content_assets']=[];
	$undeclared['content_asset_count']=0;
	$undeclared['content_asset_bytes']=0;
	$undeclaredFiles=$files;
	$undeclaredFiles['portal.json']=dp_datadoc_portal_json($undeclared);
	$t->throws(static fn()=>DocumentationPortalBuild::make('2.2.0',$undeclaredFiles,$undeclared),InvalidArgumentException::class);
});

test('Datadoc corpus rendering escapes hostile content and retains useful Markdown',static function(Context $t):void {
	$markdown=<<<'MARKDOWN'
# Unsafe <script>alert(1)</script>

[Blocked](javascript:alert(1)) [Encoded](%6aavascript:alert(1)) [Safe](guide.md#start) [Web](https://example.test/docs).

## Table

| Name | Value |
| --- | --- |
| one | <img src=x onerror=alert(1)> |

> A quote.

- First
- Second

```php
echo "<script>";
```
MARKDOWN;
	$build=DocumentationPortal::make()->build('1.0.0','Security documentation',[
		'index.md'=>$markdown,
		'guide.md'=>"# Guide\n\n## Start\n",
	]);
	$html=(string)$build->file('index.html');
	$t->contains('&lt;script&gt;alert(1)&lt;/script&gt;',$html);
	$t->notContains('<script>alert(1)</script>',$html);
	$t->notContains('<img',$html);
	$t->notContains('javascript:alert',strtolower($html));
	$t->notContains('%6aavascript',strtolower($html));
	$t->contains('<a href="guide.html#start">Safe</a>',$html);
	$t->contains('<a href="https://example.test/docs" target="_blank" rel="noopener noreferrer">Web</a>',$html);
	$t->contains('<div class="dp-doc-table" tabindex="0"><table>',$html);
	$t->contains('<blockquote><p>A quote.</p></blockquote>',$html);
	$t->contains('<ul><li>First</li><li>Second</li></ul>',$html);
	$t->contains('<code class="language-php">echo &quot;&lt;script&gt;&quot;;',$html);
	$search=(string)$build->file('search-index.json');
	$t->notContains('<script>',$search);
	$t->notContains('<img',$search);
});

test('Datadoc localizes the complete portal shell and supports explicit text direction',static function(Context $t):void {
	$engine=DocumentationPortal::make();
	$copy=dp_datadoc_portal_french_copy();
	$build=$engine->build('2.1.0','Documentation Dataphyre',[
		'index.md'=>"# Documentation Dataphyre\n\nUne documentation complète.\n\n## Commencer\n\nOuvrez le guide.\n",
		'guides/index.md'=>"# Guides d’exploitation\n\nDes procédures pour les équipes.\n",
	],[],[
		'language'=>'fr-CA',
		'direction'=>'rtl',
		'ui_copy'=>$copy,
	]);
	$index=(string)$build->file('index.html');
	$missing=(string)$build->file('404.html');
	$javascript=(string)$build->file('assets/portal.js');
	$manifest=$build->manifest();

	$t->contains('<html lang="fr-CA" dir="rtl"',$index);
	$t->contains('Aller au contenu',$index);
	$t->contains('Rechercher dans la documentation',$index);
	$t->contains('Dans cette page',$index);
	$t->contains('Généré à partir de la version vérifiée 2.1.0',$index);
	$t->notContains('Search documentation',$index);
	$t->contains('Page introuvable',$missing);
	$t->contains('Ouvrir la documentation',$missing);
	$t->contains('Aucun document correspondant.',$javascript);
	$t->contains('Le code a été copié dans le presse-papiers.',$javascript);
	$t->notContains('No matching documentation.',$javascript);
	$t->same('fr-CA',$manifest['language']);
	$t->same('rtl',$manifest['direction']);
	$t->same(count($copy),$manifest['ui_copy']['key_count']);
	$t->same(hash('sha256',dp_datadoc_portal_json($copy)),$manifest['ui_copy']['sha256']);
	$t->same('Guides d’exploitation',$manifest['navigation'][1]['label']);
	$t->isTrue($manifest['capabilities']['localized_shell']);
	$t->isTrue($manifest['capabilities']['text_direction']);

	$missingKey=$copy;
	unset($missingKey['search']);
	$t->throws(static fn()=>$engine->build('2.1.0','Docs',['index.md'=>"# Docs\n"],[],['ui_copy'=>$missingKey]),InvalidArgumentException::class);
	$unknownKey=$copy;
	$unknownKey['unknown']='value';
	$t->throws(static fn()=>$engine->build('2.1.0','Docs',['index.md'=>"# Docs\n"],[],['ui_copy'=>$unknownKey]),InvalidArgumentException::class);
	$badPlaceholder=$copy;
	$badPlaceholder['generated_release']='Version générée.';
	$t->throws(static fn()=>$engine->build('2.1.0','Docs',['index.md'=>"# Docs\n"],[],['ui_copy'=>$badPlaceholder]),InvalidArgumentException::class);
	$t->throws(static fn()=>$engine->build('2.1.0','Docs',['index.md'=>"# Docs\n"],[],['direction'=>'sideways']),InvalidArgumentException::class);
});

test('Datadoc portal corpus version option and path boundaries fail closed',static function(Context $t):void {
	$engine=DocumentationPortal::make();
	$internals=$t->nonPublic(DocumentationPortal::class);
	$valid=['index.md'=>"# Docs\n"];
	$t->same('1.2.3',DocumentationPortal::canonicalVersion('v1.2.3'));
	$t->same('1.2.3-beta.1+build.2',DocumentationPortal::canonicalVersion('1.2.3-beta.1+build.2'));
	foreach(['','1','1.2','01.2.3','1.02.3','1.2.03','1.2.3-BETA',str_repeat('1',65)] as $version){
		$t->throws(static fn()=>DocumentationPortal::canonicalVersion($version),InvalidArgumentException::class);
	}
	foreach([
		['1.0.0','', $valid,[],[]],
		['1.0.0',str_repeat('x',161),$valid,[],[]],
		['1.0.0',"bad\xC3",$valid,[],[]],
		['1.0.0','Docs',[],[],[]],
		['1.0.0','Docs',['index.txt'=>'text'],[],[]],
		['1.0.0','Docs',[0=>'# Docs'],[],[]],
		['1.0.0','Docs',['index.md'=>1],[],[]],
		['1.0.0','Docs',['INDEX.md'=>"# One\n",'index.md'=>"# Two\n"],[],[]],
		['1.0.0','Docs',['index.md'=>''],[],[]],
		['1.0.0','Docs',['index.md'=>str_repeat('x',2097153)],[],[]],
		['1.0.0','Docs',$valid,['index.md'],[]],
		['1.0.0','Docs',$valid,['index.html'],[]],
		['1.0.0','Docs',$valid,['assets/portal.css'],[]],
		['1.0.0','Docs',$valid,['A.json','a.json'],[]],
		['1.0.0','Docs',$valid,['not-a-list'=>'value'],[]],
		['1.0.0','Docs',$valid,[1],[]],
	] as [$version,$title,$documents,$reserved,$options]){
		$t->throws(static fn()=>$engine->build($version,$title,$documents,$reserved,$options),Throwable::class);
	}
	foreach([' ../index.md','../index.md','/index.md','a\\index.md','a//index.md','a/./index.md','a/../index.md','a/%2e%2e/index.md','a?b.md','a#b.md','a:b.md',"a\0b.md",str_repeat('a',256).'/index.md'] as $path){
		$t->throws(static fn()=>$engine->build('1.0.0','Docs',[$path=>"# Docs\n"]),InvalidArgumentException::class);
	}
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',$valid,[],['unknown'=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',$valid,[],['maximum_search_text_bytes'=>511]),InvalidArgumentException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',$valid,[],['version_links'=>['0.9.0'=>'']]),InvalidArgumentException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',$valid,[],['canonical_base_url'=>'http://docs.example.test']),InvalidArgumentException::class);
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',$valid,[],['repository_url'=>'javascript:alert(1)']),InvalidArgumentException::class);
	$t->same(4,$internals->invoke('unescapedPosition','a\\]b] ',']',0));
	$t->same(null,$internals->invoke('unescapedPosition','missing',']',0));
	$t->same(null,$internals->invoke('linkTargetEnd','unterminated(target',0));
	$t->same('Reference Notes',$internals->invoke('fallbackTitle','reference_notes.md'));
	$t->same('é',$internals->invoke('byteCut','éé',3));
	$t->throws(static fn()=>$internals->invoke('json',['invalid'=>"\xC3"]),RuntimeException::class);
	$tooMany=[];
	for($index=0;$index<10001;$index++){ $tooMany['page-'.$index.'.md']="# Page\n"; }
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',$tooMany),LengthException::class);
	$tooManyReserved=array_fill(0,50001,'asset.json');
	$t->throws(static fn()=>$engine->build('1.0.0','Docs',$valid,$tooManyReserved),InvalidArgumentException::class);
});

test('Datadoc portal build rejects malformed metadata paths and tampering',static function(Context $t):void {
	$build=DocumentationPortal::make()->build('3.0.0','Dataphyre Docs',dp_datadoc_portal_corpus());
	$files=$build->files();
	$manifest=$build->manifest();
	$t->same($build->jsonSerialize(),DocumentationPortalBuild::make('3.0.0',$files,$manifest)->jsonSerialize());
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',[],$manifest),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',['value'],$manifest),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',['index.html'=>1],$manifest),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',$files,array_replace($manifest,['type'=>'wrong'])),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.1',$files,$manifest),InvalidArgumentException::class);

	$duplicate=$files;
	$duplicate['Index.html']=$duplicate['index.html'];
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',$duplicate,$manifest),InvalidArgumentException::class);
	$unsafe=$files;
	$unsafe['../escape.html']='escape';
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',$unsafe,$manifest),InvalidArgumentException::class);
	$absolute=$files;
	$absolute['/escape.html']='escape';
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',$absolute,$manifest),InvalidArgumentException::class);
	$missing=$files;
	unset($missing['404.html']);
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',$missing,$manifest),InvalidArgumentException::class);

	$invalidJson=$files;
	$invalidJson['portal.json']='{';
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',$invalidJson,$manifest),InvalidArgumentException::class);
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',$files,array_replace($manifest,['title'=>'Forged'])),InvalidArgumentException::class);
	$badSearch=$files;
	$search=json_decode($badSearch['search-index.json'],true,128,JSON_THROW_ON_ERROR);
	$search['type']='wrong';
	$badSearch['search-index.json']=dp_datadoc_portal_json($search);
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',$badSearch,$manifest),InvalidArgumentException::class);
	$badVersions=$files;
	$versions=json_decode($badVersions['versions.json'],true,128,JSON_THROW_ON_ERROR);
	$versions['current']='3.0.1';
	$badVersions['versions.json']=dp_datadoc_portal_json($versions);
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',$badVersions,$manifest),InvalidArgumentException::class);
	$tamperedAsset=$files;
	$tamperedAsset['assets/portal.css'].='forged';
	$t->throws(static fn()=>DocumentationPortalBuild::make('3.0.0',$tamperedAsset,$manifest),InvalidArgumentException::class);
});

test('Datadoc publishes immutable portal releases atomically and idempotently',static function(Context $t):void {
	$build=DocumentationPortal::make()->build('4.2.0','Dataphyre Documentation',dp_datadoc_portal_corpus());
	$publication=DocumentationPortalPublication::fromBuild($build,'docs/dataphyre',['channel'=>'stable']);
	$t->same('4.2.0',$publication->version());
	$t->same('docs/dataphyre/versions/4.2.0',$publication->releasePrefix());
	$t->same(count($build)+1,count($publication));
	$t->same($publication->files(),iterator_to_array($publication));
	$t->same($publication->manifest(),$publication->jsonSerialize());
	$t->same('dataphyre_datadoc_static_publication',$publication->manifest()['type']);
	$t->same('stable',$publication->manifest()['meta']['channel']);
	$t->same(count($publication),$publication->manifest()['artifact_count']);
	$t->same($publication->manifest(),json_decode((string)$publication->file('publication.json'),true,256,JSON_THROW_ON_ERROR));
	$t->same(null,$publication->file('missing'));
	$t->same($publication->file('assets/portal.css'),$publication->file('assets\\portal.css'));

	$root=$t->tempDirectory('datadoc-publication');
	$target=$root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'dataphyre'.DIRECTORY_SEPARATOR.'versions'.DIRECTORY_SEPARATOR.'4.2.0';
	$preview=$publication->apply($root,true);
	$t->instanceOf(DocumentationPortalWriteResult::class,$preview);
	$t->same($root,$preview->root());
	$t->same($target,$preview->target());
	$t->isTrue($preview->dryRun());
	$t->isTrue($preview->changed());
	$t->same(count($publication),$preview->fileCount());
	$t->same('preview',$preview->jsonSerialize()['status']);
	$t->isFalse(is_dir($target));

	$previousUmask=umask(0077);
	try { $written=$publication->apply($root,false); }
	finally { umask($previousUmask); }
	$t->isFalse($written->dryRun());
	$t->isTrue($written->changed());
	$t->same('applied',$written->jsonSerialize()['status']);
	$t->isTrue(is_file($target.DIRECTORY_SEPARATOR.'index.html'));
	$t->isTrue(is_file($target.DIRECTORY_SEPARATOR.'publication.json'));
	$t->same($publication->manifest(),json_decode((string)file_get_contents($target.DIRECTORY_SEPARATOR.'publication.json'),true,256,JSON_THROW_ON_ERROR));
	if(DIRECTORY_SEPARATOR!=='\\'){
		clearstatcache();
		$t->same('0755',substr(sprintf('%o',(int)fileperms($target)),-4));
		$t->same('0755',substr(sprintf('%o',(int)fileperms($target.DIRECTORY_SEPARATOR.'assets')),-4));
		$t->same('0644',substr(sprintf('%o',(int)fileperms($target.DIRECTORY_SEPARATOR.'index.html')),-4));
		$t->same('0755',substr(sprintf('%o',(int)fileperms(dirname($target))),-4));
	}
	$idempotent=$publication->apply($root,false);
	$t->isFalse($idempotent->changed());
	$t->same('unchanged',$idempotent->jsonSerialize()['status']);
	$t->isFalse($publication->apply($root,true)->changed());
	$t->same([],glob($root.DIRECTORY_SEPARATOR.'.dataphyre-datadoc-stage-*')?:[]);
	if(DIRECTORY_SEPARATOR!=='\\'){
		chmod($target,0700);
		clearstatcache(true,$target);
		$repaired=$publication->apply($root,false);
		$t->isTrue($repaired->changed());
		$t->same('applied',$repaired->jsonSerialize()['status']);
		clearstatcache(true,$target);
		$t->same('0755',substr(sprintf('%o',(int)fileperms($target)),-4));
		$t->isFalse($publication->apply($root,false)->changed());
	}

	file_put_contents($target.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'portal.css','forged',FILE_APPEND);
	$t->throws(static fn()=>$publication->apply($root,true),RuntimeException::class);
	$t->throws(static fn()=>$publication->apply($root,false),RuntimeException::class);

	$busyRoot=$t->tempDirectory('datadoc-publication-busy');
	$lock=fopen($busyRoot.DIRECTORY_SEPARATOR.'.dataphyre-datadoc.lock','c+b');
	if(!is_resource($lock)||!flock($lock,LOCK_EX|LOCK_NB)){ throw new RuntimeException('Unable to prepare publication lock fixture.'); }
	try { $t->throws(static fn()=>$publication->apply($busyRoot,false),RuntimeException::class); }
	finally { flock($lock,LOCK_UN); fclose($lock); }

	$symlinkRoot=$t->tempDirectory('datadoc-publication-symlink');
	$outside=$symlinkRoot.DIRECTORY_SEPARATOR.'outside.lock';
	file_put_contents($outside,'lock');
	symlink($outside,$symlinkRoot.DIRECTORY_SEPARATOR.'.dataphyre-datadoc.lock');
	$t->throws(static fn()=>$publication->apply($symlinkRoot,false),RuntimeException::class);
	$blockedRoot=$t->tempDirectory('datadoc-publication-blocked-lock');
	mkdir($blockedRoot.DIRECTORY_SEPARATOR.'.dataphyre-datadoc.lock');
	$t->throws(static fn()=>$publication->apply($blockedRoot,false),RuntimeException::class);

	foreach(['/absolute','//server/share','C:/absolute','../escape','docs/../escape','docs/%2e%2e','docs?preview','docs#part','docs:bad',str_repeat('a',256)] as $basePath){
		$t->throws(static fn()=>DocumentationPortalPublication::fromBuild($build,$basePath),InvalidArgumentException::class);
	}
	$t->same('versions/4.2.0',DocumentationPortalPublication::fromBuild($build)->releasePrefix());
	$resource=fopen('php://memory','r');
	try { $t->throws(static fn()=>DocumentationPortalPublication::fromBuild($build,'',['resource'=>$resource]),InvalidArgumentException::class); }
	finally { fclose($resource); }
	$t->throws(static fn()=>DocumentationPortalPublication::fromBuild($build,'',['large'=>str_repeat('x',65537)]),LengthException::class);
	foreach(['', $root.DIRECTORY_SEPARATOR.'missing'] as $invalidRoot){
		$t->throws(static fn()=>$publication->apply($invalidRoot,true),InvalidArgumentException::class);
	}

	$metaObject=(object)['channel'=>'preview'];
	$normalized=DocumentationPortalPublication::fromBuild($build,'',['object'=>$metaObject]);
	$metaObject->channel='mutated';
	$t->same('preview',$normalized->manifest()['meta']['object']['channel']);

	$internals=$t->nonPublic(DocumentationPortalPublication::class);
	$t->same((string)realpath(DIRECTORY_SEPARATOR),$internals->invoke('root',DIRECTORY_SEPARATOR));
	$t->throws(static fn()=>$internals->invoke('json',['invalid'=>"\xC3"]),RuntimeException::class);
	$temporaryLock=fopen('php://temp','w+b');
	if(!is_resource($temporaryLock)){ throw new RuntimeException('Unable to prepare temporary lock fixture.'); }
	$t->instanceOf(RuntimeException::class,$internals->invoke('lockFailure',$temporaryLock));
	$t->isFalse(is_resource($temporaryLock));

	$cleanupFile=$root.DIRECTORY_SEPARATOR.'cleanup-file';
	file_put_contents($cleanupFile,'remove');
	$internals->invoke('removeTree',$cleanupFile);
	$t->isFalse(file_exists($cleanupFile));
	$cleanupDirectory=$root.DIRECTORY_SEPARATOR.'cleanup-tree';
	mkdir($cleanupDirectory.DIRECTORY_SEPARATOR.'nested',0775,true);
	file_put_contents($cleanupDirectory.DIRECTORY_SEPARATOR.'artifact','remove');
	file_put_contents($cleanupDirectory.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'artifact','remove');
	$internals->invoke('removeTree',$cleanupDirectory);
	$t->isFalse(file_exists($cleanupDirectory));
});
