<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Datadoc\DocumentationPortal;
use Dataphyre\Panel\PanelCompatibilityMatrix;
use Dataphyre\Panel\PanelDocumentationCatalog;
use Dataphyre\Panel\PanelDocumentationPortal;
use Dataphyre\Panel\PanelDocumentationPublication;
use Dataphyre\Panel\PanelDocumentationPublisher;
use Dataphyre\Panel\PanelScaffoldResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

suite('Panel immutable static documentation portal')
	->contract('panel.documentation-static-portal',1)
	->layer('integration')
	->risk('high')
	->watches('module:panel','framework:documentation')
	->through('safe-markdown','immutable-publication','static-assets','responsive-shell')
	->tag('panel','documentation','portal','security','responsive','deep-coverage')
	->group('framework-coverage');

function dp_panel_portal_publication(Context $t,string $version='3.2.1'): PanelDocumentationPublication {
	$source=$t->tempDirectory('panel-portal-source');
	file_put_contents($source.DIRECTORY_SEPARATOR.'Demo.php',<<<'PHP'
<?php
namespace Portal\Fixture;
/** Safe documentation fixture. */
final class Demo {
	/** Return a stable greeting. */
	public function greet(string $name='Panel'): string { return 'Hello '.$name; }
}
PHP);
	$catalog=PanelDocumentationCatalog::make([
		[
			'id'=>'quick-start',
			'title'=>'Quick start',
			'category'=>'Guides',
			'status'=>'published',
			'summary'=>'Build a versioned Panel documentation release.',
			'api'=>['Portal\\Fixture\\Demo::greet'],
			'examples'=>[['title'=>'Greet','language'=>'php','code'=>"(new Demo())->greet();"]],
			'links'=>[['label'=>'Project guide','target'=>'https://example.test/panel-guide']],
		],
	]);
	$matrix=PanelCompatibilityMatrix::make([
		['id'=>'portal-fixture','version'=>'1.0.0','requires'=>['php'=>'>=8.2'],'provides'=>['documentation']],
	],['php'=>PHP_VERSION,'panel'=>'3.0','reactor'=>'3.0','modules'=>[],'themes'=>[]]);
	return PanelDocumentationPublisher::make($source)->build($version,$catalog,$matrix,[
		'base_path'=>'docs/panel',
		'title'=>'Dataphyre Panel Reference',
		'source_paths'=>['Demo.php'],
		'meta'=>['channel'=>'stable'],
	]);
}

function dp_panel_portal_contents(PanelDocumentationPublication $publication,string $relative): string {
	$artifact=$publication->artifact($publication->releasePrefix().'/'.$relative);
	if(!$artifact instanceof PanelScaffoldResult){ throw new RuntimeException('Portal fixture artifact is missing: '.$relative); }
	return $artifact->contents();
}

function dp_panel_portal_with_extra(PanelDocumentationPublication $publication,string $relative,string $contents): PanelDocumentationPublication {
	$manifest=$publication->manifest();
	$digest=hash('sha256',$contents);
	$manifest['files'][]=['path'=>$relative,'bytes'=>strlen($contents),'sha256'=>$digest];
	usort($manifest['files'],static fn(array $left,array $right):int=>(string)$left['path']<=>(string)$right['path']);
	$artifacts=[];
	foreach($publication->artifacts() as $artifact){
		if(($artifact->metadata()['relative_path']??null)!=='publication.json'){ $artifacts[]=$artifact; }
	}
	$artifacts[]=PanelScaffoldResult::make('documentation','extra','',$publication->releasePrefix().'/'.$relative,$contents,[
		'version'=>$publication->version(),'relative_path'=>$relative,'sha256'=>$digest,
	]);
	$publicationContents=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n";
	$artifacts[]=PanelScaffoldResult::make('documentation','publication_manifest','',$publication->releasePrefix().'/publication.json',$publicationContents,[
		'version'=>$publication->version(),'relative_path'=>'publication.json','sha256'=>hash('sha256',$publicationContents),
	]);
	return PanelDocumentationPublication::make($publication->version(),$artifacts,$manifest);
}

test('portal decoration is deterministic immutable self contained and manifest verified',static function(Context $t): void {
	$builder=PanelDocumentationPortal::make();
	$t->same($builder->manifest(),$builder->jsonSerialize());
	$t->same('panel_documentation_portal_adapter',$builder->manifest()['type']);
	$t->same('datadoc',$builder->manifest()['engine_module']);
	$t->isTrue($builder->manifest()['producer_content_assets']);
	$t->same('datadoc_documentation_portal_engine',$builder->manifest()['engine']['type']);
	$t->isFalse($builder->manifest()['default_enabled']);
	$t->isFalse($builder->manifest()['engine']['network_access']);
	$t->isFalse($builder->manifest()['engine']['source_execution']);
	$t->isFalse($builder->manifest()['engine']['raw_html_trusted']);
	$t->isFalse($builder->manifest()['engine']['external_dependencies']);

	$base=dp_panel_portal_publication($t);
	$t->isFalse(isset($base->manifest()['portal']));
	$options=[
		'language'=>'fr-CA',
		'default_theme'=>'dark',
		'version_links'=>[
			'4.0.0'=>'https://docs.example.test/panel/4.0.0/',
			'3.2.1'=>'',
			'3.0.0'=>'../3.0.0/index.html',
			'2.0.0'=>'/panel/versions/2.0.0/index.html',
		],
		'canonical_base_url'=>'https://docs.example.test/panel/3.2.1',
		'repository_url'=>'https://code.example.test/dataphyre/panel',
		'maximum_search_text_bytes'=>4096,
	];
	$first=$builder->decorate($base,$options);
	$second=$builder->decorate($base,$options);
	$t->same($first->jsonSerialize(),$second->jsonSerialize());
	$t->same($base->version(),$first->version());
	$t->same($base->releasePrefix(),$first->releasePrefix());
	$t->same(dp_panel_portal_contents($base,'index.md'),dp_panel_portal_contents($first,'index.md'));
	$t->isTrue(count($first)>count($base));

	$manifest=$first->manifest();
	$portal=$manifest['portal'];
	$t->same('dataphyre_datadoc_static_portal',$portal['type']);
	$t->same(1,$portal['schema_version']);
	$t->same('3.2.1',$portal['documentation_version']);
	$t->same('fr-CA',$portal['language']);
	$t->same('dark',$portal['default_theme']);
	$t->same(4096,$portal['maximum_search_text_bytes']);
	$t->same(4,$portal['version_count']);
	$t->same(['4.0.0','3.2.1','3.0.0','2.0.0'],array_column($portal['versions'],'version'));
	$t->same(['overview','api','cookbook','packages'],array_column($portal['navigation'],'id'));
	$t->isTrue($portal['capabilities']['static_html']);
	$t->isTrue($portal['capabilities']['local_search']);
	$t->isTrue($portal['capabilities']['content_security_policy']);
	$t->isFalse($portal['capabilities']['external_runtime_dependencies']);
	$t->isFalse($portal['capabilities']['external_asset_requests']);
	$t->isFalse($portal['capabilities']['inline_scripts']);
	$t->same(count($first)-1,count($manifest['files']));

	foreach(['index.html','api/index.html','cookbook/index.html','packages/compatibility.html','assets/favicon.svg','assets/portal.css','assets/portal.js','search-index.json','versions.json','portal.json','404.html','sitemap.xml'] as $relative){
		$t->instanceOf(PanelScaffoldResult::class,$first->artifact($first->releasePrefix().'/'.$relative));
	}
	$publication=json_decode(dp_panel_portal_contents($first,'publication.json'),true,512,JSON_THROW_ON_ERROR);
	$t->same($manifest,$publication);
	$t->same($portal,json_decode(dp_panel_portal_contents($first,'portal.json'),true,64,JSON_THROW_ON_ERROR));
	$search=json_decode(dp_panel_portal_contents($first,'search-index.json'),true,64,JSON_THROW_ON_ERROR);
	$t->same('dataphyre_datadoc_search_index',$search['type']);
	$t->same($portal['page_count'],$search['entry_count']);
	$t->same($search['entry_count'],count($search['entries']));
	$t->same(count($search['entries']),count(array_unique(array_column($search['entries'],'id'))));
	foreach($search['entries'] as $entry){
		$t->isTrue(str_ends_with($entry['path'],'.html'));
		$t->isTrue(strlen($entry['text'])<=4096);
	}
	$versions=json_decode(dp_panel_portal_contents($first,'versions.json'),true,64,JSON_THROW_ON_ERROR);
	$t->same('3.2.1',$versions['current']);
	$t->same($portal['versions'],$versions['versions']);

	$files=array_column($manifest['files'],null,'path');
	$t->same(array_keys($files),array_values(array_unique(array_keys($files))));
	$sorted=array_keys($files);
	$expected=$sorted;
	sort($expected,SORT_STRING);
	$t->same($expected,$sorted);
	foreach($first->artifacts() as $artifact){
		$relative=$artifact->metadata()['relative_path'];
		$t->same(hash('sha256',$artifact->contents()),$artifact->metadata()['sha256']);
		if($relative!=='publication.json'){
			$t->same(strlen($artifact->contents()),$files[$relative]['bytes']);
			$t->same(hash('sha256',$artifact->contents()),$files[$relative]['sha256']);
		}
	}

	$index=dp_panel_portal_contents($first,'index.html');
	$t->contains('<html lang="fr-CA" dir="ltr" data-theme="dark">',$index);
	$t->contains('Content-Security-Policy',$index);
	$t->contains('<meta name="referrer" content="no-referrer">',$index);
	$t->contains("\n\t<link rel=\"canonical\" href=\"https://docs.example.test/panel/3.2.1/index.html\">",$index);
	$t->notContains('\\n\\t<link rel="canonical"',$index);
	$t->contains('href="https://code.example.test/dataphyre/panel" target="_blank" rel="noopener noreferrer"',$index);
	$t->contains('<dialog class="dp-doc-search"',$index);
	$t->notContains('<script>',$index);
	$t->notContains(' style=',$index);
	$t->notContains(' onclick=',$index);
	$api=dp_panel_portal_contents($first,'api/index.html');
	$t->contains('href="../assets/portal.css"',$api);
	$t->contains('value="../../3.0.0/index.html"',$api);
	$t->contains('value="/panel/versions/2.0.0/index.html"',$api);
	$t->contains('value="https://docs.example.test/panel/4.0.0/"',$api);
	$sitemap=dp_panel_portal_contents($first,'sitemap.xml');
	$t->contains("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset",$sitemap);
	$t->contains("\n  <url><loc>https://docs.example.test/panel/3.2.1/index.html</loc></url>",$sitemap);
	$t->notContains('\\n',$sitemap);
	$css=dp_panel_portal_contents($first,'assets/portal.css');
	$javascript=dp_panel_portal_contents($first,'assets/portal.js');
	$favicon=dp_panel_portal_contents($first,'assets/favicon.svg');
	$t->same(hash('sha256',$css),$portal['assets']['css']['sha256']);
	$t->same(hash('sha256',$javascript),$portal['assets']['javascript']['sha256']);
	$t->same(hash('sha256',$favicon),$portal['assets']['favicon']['sha256']);
	$t->contains('<svg xmlns="http://www.w3.org/2000/svg"',$favicon);
	$t->notContains('<script',$favicon);

	$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',true);
	if(!is_string($png)){ throw new RuntimeException('Unable to decode the Panel documentation PNG fixture.'); }
	$withImage=dp_panel_portal_with_extra($base,'media/panel.png',$png);
	$imagePortal=$builder->decorate($withImage,$options);
	$t->same(1,$imagePortal->manifest()['portal']['content_asset_count']);
	$t->same('media/panel.png',$imagePortal->manifest()['portal']['content_assets'][0]['path']);
	$t->same($png,dp_panel_portal_contents($imagePortal,'media/panel.png'));
	$t->same(1,count(array_filter($imagePortal->artifacts(),static fn(PanelScaffoldResult $artifact):bool=>($artifact->metadata()['relative_path']??null)==='media/panel.png')));
	$t->throws(static fn()=>$builder->decorate(dp_panel_portal_with_extra($base,'media/broken.png','not an image'),$options),InvalidArgumentException::class);
});

test('safe markdown rendering escapes raw html rejects active links and preserves useful structure',static function(Context $t): void {
	$builder=DocumentationPortal::make();
	$internals=$t->nonPublic($builder);
	$deep='javascript:alert(1)';
	for($pass=0;$pass<9;$pass++){ $deep=rawurlencode($deep); }
	$markdown=str_replace('{{DEEP}}',$deep,<<<'MARKDOWN'
# Portal <script>alert(1)</script> [blocked](javascript:alert(1))

An [internal guide](guides/start.md?mode=fast#install), an [external guide](https://example.test/docs), and [encoded](%6aavascript:alert(1)) plus [deep]({{DEEP}}).

Inline `` <b>`safe`</b> `` and escaped \* punctuation.

## Duplicate

> Quote with <img src=x onerror=alert(1)> markup.

- First item
- Second [relative](../release.md)

## Duplicate

| Name | Value |
| --- | --- |
| escaped \| pipe | <svg onload=alert(1)> |

## 日本語

### Child &amp; title

````php
echo "<script>";
```
````
MARKDOWN);
	$rendered=$internals->invoke('renderMarkdown',$markdown,'guides/portal.md');
	$t->same('Portal alert(1) blocked',$rendered['title']);
	$t->contains('&lt;script&gt;alert(1)&lt;/script&gt;',$rendered['html']);
	$t->notContains('<script>',$rendered['html']);
	$t->notContains('<img',$rendered['html']);
	$t->notContains('<svg',$rendered['html']);
	$t->notContains('javascript:',strtolower($rendered['html']));
	$t->notContains('%6aavascript',strtolower($rendered['html']));
	$t->contains('<a href="guides/start.html?mode=fast#install">internal guide</a>',$rendered['html']);
	$t->contains('<a href="https://example.test/docs" target="_blank" rel="noopener noreferrer">external guide</a>',$rendered['html']);
	$t->contains('<a href="../release.html">relative</a>',$rendered['html']);
	$t->contains('<code>&lt;b&gt;`safe`&lt;/b&gt;</code>',$rendered['html']);
	$t->contains('<blockquote><p>Quote with &lt;img',$rendered['html']);
	$t->contains('<ul><li>First item</li><li>Second',$rendered['html']);
	$t->contains('<div class="dp-doc-table" tabindex="0"><table>',$rendered['html']);
	$t->contains('<td>escaped | pipe</td>',$rendered['html']);
	$t->contains('id="duplicate"',$rendered['html']);
	$t->contains('id="duplicate-2"',$rendered['html']);
	$t->contains('id="section"',$rendered['html']);
	$t->contains('id="child-title"',$rendered['html']);
	$t->contains('<code class="language-php">echo &quot;&lt;script&gt;&quot;;',$rendered['html']);
	$t->same(4,count($rendered['toc']));
	$t->same([2,2,2,3],array_column($rendered['toc'],'level'));
	$t->notContains('<script>',$rendered['text']);
	$t->contains('First item',$rendered['text']);

	$fallback=$internals->invoke('renderMarkdown',"A plain paragraph.\r\n\r\n- Item\r\n",'release_notes.md');
	$t->same('Release Notes',$fallback['title']);
	$t->contains('<p>A plain paragraph.</p>',$fallback['html']);
	$t->contains('<li>Item</li>',$fallback['html']);
	$t->throws(static fn()=>$internals->invoke('renderMarkdown',"\xC3\x28",'invalid.md'),InvalidArgumentException::class);
	$t->throws(static fn()=>$internals->invoke('renderMarkdown',"```php\necho 1;",'invalid.md'),InvalidArgumentException::class);

	$type=$t->nonPublic(DocumentationPortal::class);
	$t->same('guide.html?x=1#part',$type->invoke('markdownHref','guide.md?x=1#part'));
	$t->same('GUIDE.html',$type->invoke('markdownHref','GUIDE.MD'));
	$t->same('https://example.test/a.md',$type->invoke('markdownHref','https://example.test/a.md'));
	foreach(['javascript:alert(1)','java&#x73;cript:alert(1)','%6aavascript:alert(1)','//example.test/path','https://user@example.test/path',"guide\n.md"] as $unsafe){
		$t->same('',$type->invoke('href',$unsafe));
	}
	$t->same('https://example.test/path',$type->invoke('href','https://example.test/path'));
	$t->same('../guide.md',$type->invoke('href','../guide.md'));
	$t->same(['escaped \\| pipe','value'],$type->invoke('tableCells','| escaped \\| pipe | value |'));
	$t->same(4,$type->invoke('unescapedPosition','a\\]b] ',']',0));
	$t->same(null,$type->invoke('unescapedPosition','missing',']',0));
	$t->same(null,$type->invoke('linkTargetEnd','unterminated(target',0));
	$t->same('é',$type->invoke('byteCut','éé',3));
	$t->throws(static fn()=>$type->invoke('json',['invalid'=>"\xC3"]),RuntimeException::class);
	$t->same('api',$type->invoke('section','api/types/demo.md'));
	$t->same('misc',$type->invoke('section','misc/demo.md'));
	$t->same('../../',$type->invoke('rootPath','api/types/demo.html'));
	$t->same('',$type->invoke('rootPath','index.html'));
	$adapterType=$t->nonPublic(PanelDocumentationPortal::class);
	$t->throws(static fn()=>$adapterType->invoke('json',['invalid'=>"\xC3"]),RuntimeException::class);
});

test('portal options and generated path boundaries fail closed',static function(Context $t): void {
	$base=dp_panel_portal_publication($t);
	$builder=PanelDocumentationPortal::make();
	foreach([
		['unknown'=>true],
		[0=>'value'],
		['language'=>1],
		['language'=>'english'],
		['language'=>str_repeat('a',36)],
		['default_theme'=>'sepia'],
		['default_theme'=>false],
		['maximum_search_text_bytes'=>511],
		['maximum_search_text_bytes'=>50001],
		['maximum_search_text_bytes'=>512.0],
		['version_links'=>['3.0.0']],
		['version_links'=>['3.0.0'=>'']],
		['version_links'=>['INVALID'=>'https://docs.example.test/']],
		['version_links'=>['3.0.0'=>1]],
		['version_links'=>['3.0.0'=>'javascript:alert(1)']],
		['version_links'=>['3.0.0'=>'%6aavascript:alert(1)']],
		['version_links'=>['3.0.0'=>'//docs.example.test/']],
		['version_links'=>['3.0.0'=>'http://docs.example.test/']],
		['version_links'=>['3.0.0'=>'https://user@docs.example.test/']],
		['version_links'=>['3.0.0'=>'./3.0.0/index.html']],
		['version_links'=>['3.0.0'=>'versions/../3.0.0/index.html']],
		['version_links'=>['3.0.0'=>str_repeat('../',17).'3.0.0/index.html']],
		['version_links'=>['3.0.0'=>'folder//index.html']],
		['version_links'=>['3.0.0'=>'..']],
		['canonical_base_url'=>1],
		['canonical_base_url'=>'http://docs.example.test/panel'],
		['canonical_base_url'=>'https://docs.example.test/panel?preview=1'],
		['canonical_base_url'=>'https://docs.example.test/panel#part'],
		['canonical_base_url'=>'https://user@docs.example.test/panel'],
		['canonical_base_url'=>'//docs.example.test/panel'],
		['repository_url'=>'http://code.example.test/panel'],
		['repository_url'=>'https://user@code.example.test/panel'],
	] as $options){
		$t->throws(static fn()=>$builder->decorate($base,$options),InvalidArgumentException::class);
	}
	$tooMany=[];
	for($version=0;$version<65;$version++){ $tooMany['1.0.'.$version]='https://docs.example.test/'.$version.'/'; }
	$t->throws(static fn()=>$builder->decorate($base,['version_links'=>$tooMany]),InvalidArgumentException::class);
	$longSegment=str_repeat('a',256).'.html';
	$t->throws(static fn()=>$builder->decorate($base,['version_links'=>['3.0.0'=>$longSegment]]),InvalidArgumentException::class);
	$deep='javascript:alert(1)';
	for($pass=0;$pass<9;$pass++){ $deep=rawurlencode($deep); }
	$t->throws(static fn()=>$builder->decorate($base,['version_links'=>['3.0.0'=>$deep]]),InvalidArgumentException::class);

	$valid=$builder->decorate($base,[
		'language'=>'en-US',
		'default_theme'=>'system',
		'version_links'=>['3.0.0'=>'../3.0.0/index.html'],
		'canonical_base_url'=>'https://docs.example.test/panel/',
		'repository_url'=>null,
		'maximum_search_text_bytes'=>512,
	]);
	$t->same('https://docs.example.test/panel/',$valid->manifest()['portal']['canonical_base_url']);
	$t->same(null,$valid->manifest()['portal']['repository_url']);
	$t->throws(static fn()=>$builder->decorate($valid),InvalidArgumentException::class);

	$assetCollision=dp_panel_portal_with_extra($base,'assets/portal.css','host asset');
	$t->throws(static fn()=>$builder->decorate($assetCollision),RuntimeException::class);
	$pageCollision=dp_panel_portal_with_extra($base,'index.html','host page');
	$t->throws(static fn()=>$builder->decorate($pageCollision),RuntimeException::class);
	$emptyMarkdown=dp_panel_portal_with_extra($base,'empty.md','');
	$t->throws(static fn()=>$builder->decorate($emptyMarkdown),LengthException::class);
	$largeMarkdown=dp_panel_portal_with_extra($base,'large.md',str_repeat('x',2097153));
	$t->throws(static fn()=>$builder->decorate($largeMarkdown),LengthException::class);
});

test('portal publication remains preview first directory atomic idempotent and tamper evident',static function(Context $t): void {
	$publication=PanelDocumentationPortal::make()->decorate(dp_panel_portal_publication($t,'5.0.0'));
	$root=$t->tempDirectory('panel-portal-publication');
	$target=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$publication->releasePrefix());
	$preview=$publication->apply($root,'error',true);
	$t->isTrue($preview->changed());
	$t->isFalse(is_dir($target));
	$t->throws(static fn()=>$publication->apply($root,'skip',true),InvalidArgumentException::class);
	$write=$publication->apply($root,'error',false);
	$t->isTrue($write->changed());
	$t->isTrue(is_dir($target));
	foreach(['index.md','index.html','assets/favicon.svg','assets/portal.css','assets/portal.js','search-index.json','versions.json','portal.json','publication.json','404.html'] as $relative){
		$t->isTrue(is_file($target.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative)));
	}
	$t->same($publication->manifest(),json_decode((string)file_get_contents($target.DIRECTORY_SEPARATOR.'publication.json'),true,512,JSON_THROW_ON_ERROR));
	$idempotent=$publication->apply($root,'error',false);
	$t->isFalse($idempotent->changed());
	$t->same([],glob($root.DIRECTORY_SEPARATOR.'.dataphyre-panel-docs-*')?:[]);
	$javascript=$target.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'portal.js';
	file_put_contents($javascript,"\nforged",FILE_APPEND);
	$t->throws(static fn()=>$publication->apply($root,'error',true),RuntimeException::class);
	$t->throws(static fn()=>$publication->apply($root,'error',false),RuntimeException::class);
});

test('portal static assets enforce responsive accessible and injection free contracts',static function(Context $t): void {
	$publication=PanelDocumentationPortal::make()->decorate(dp_panel_portal_publication($t,'6.0.0'));
	$css=dp_panel_portal_contents($publication,'assets/portal.css');
	$javascript=dp_panel_portal_contents($publication,'assets/portal.js');
	$html=dp_panel_portal_contents($publication,'index.html');
	$missing=dp_panel_portal_contents($publication,'404.html');

	foreach([
		'color-scheme:light dark','grid-template-columns:minmax(190px,248px) minmax(0,780px)',
		'@media(max-width:1080px)','@media(max-width:760px)','width:min(320px,88vw)',
		'@media(prefers-reduced-motion:reduce)','@media(forced-colors:active)','@media print',
		':focus-visible','overflow:auto','max-width:100%','min-height:42px','max-height:min(760px,calc(100dvh - 24px))',
	] as $contract){ $t->contains($contract,$css); }
	$t->notContains('overflow-x:hidden',$css);
	$t->notContains('@keyframes',$css);

	foreach(['replaceChildren','createElement','textContent','HTMLDialogElement','AbortController','credentials:"same-origin"','cache:"force-cache"','entry.title','entry.path','entry.text','data-search-open','data-nav-toggle'] as $contract){
		$t->contains($contract,$javascript);
	}
	foreach(['innerHTML','outerHTML','insertAdjacentHTML','document.write','eval(','new Function','http://','https://cdn','unpkg','jsdelivr'] as $forbidden){
		$t->notContains($forbidden,$javascript);
	}
	$t->notContains('</script>',$javascript);

	foreach(['href="#main-content"','id="main-content"','aria-label="Documentation"','aria-labelledby="portal-search-title"','role="status"','aria-live="polite"','aria-controls="portal-navigation"','aria-expanded="false"','autocomplete="off"','maxlength="120"'] as $contract){
		$t->contains($contract,$html);
	}
	$t->contains('<noscript>',$html);
	$t->contains('Every documentation page remains readable without it.',$html);
	$t->contains('Content-Security-Policy',$html);
	$t->notContains('<style',$html);
	$t->notContains('<script>',$html);
	$t->notContains('javascript:',$html);
	$t->notContains('data:text/html',$html);
	preg_match_all('/\sid="([^"]+)"/',$html,$ids);
	$t->same(count($ids[1]),count(array_unique($ids[1])));
	$t->contains('<meta name="robots" content="noindex">',$missing);
	$t->contains('Page not found',$missing);
	$t->contains('href="index.html"',$missing);
});
