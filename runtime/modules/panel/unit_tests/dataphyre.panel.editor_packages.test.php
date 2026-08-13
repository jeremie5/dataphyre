<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelEditorAsset;
use Dataphyre\Panel\PanelEditorAssetEndpoint;
use Dataphyre\Panel\PanelEditorAssetPage;
use Dataphyre\Panel\PanelEditorAssetProvider;
use Dataphyre\Panel\PanelEditorAssetResult;
use Dataphyre\Panel\PanelEditorCallbackNormalizer;
use Dataphyre\Panel\PanelEditorCallbackAssetProvider;
use Dataphyre\Panel\PanelEditorBrowserAdapter;
use Dataphyre\Panel\PanelEditorContentResult;
use Dataphyre\Panel\PanelEditorContext;
use Dataphyre\Panel\PanelEditorHtmlSanitizer;
use Dataphyre\Panel\PanelEditorManifest;
use Dataphyre\Panel\PanelEditorMedia;
use Dataphyre\Panel\PanelEditorMediaManagerProvider;
use Dataphyre\Panel\PanelEditorPlugin;
use Dataphyre\Panel\PanelEditorProfile;
use Dataphyre\Panel\PanelEditorSanitizationPolicy;
use Dataphyre\Panel\PanelEditorSyntaxHighlighter;
use Dataphyre\Panel\PanelEditorToolbar;
use Dataphyre\Panel\PanelEditorUpload;
use Dataphyre\Panel\PanelMediaManager;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
if(!function_exists('tracelog')){ function tracelog(mixed ...$arguments): void {} }
require_once dirname(__DIR__).'/Framework/Bootstrap.php';

test('editor URL policy rejects encoded and control-character scheme attacks', static function(Context $t): void {
	$policy=PanelEditorSanitizationPolicy::strict();
	$deeplyEncoded='javascript:alert(1)';
	for($pass=0;$pass<8;$pass++){ $deeplyEncoded=rawurlencode($deeplyEncoded); }
	foreach([
		'javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'java&#x73;cript:alert(1)',
		'java%73cript:alert(1)', '%256a%2561%2576%2561script%253aalert(1)', "java\nscript:alert(1)", '//evil.example/x',
		'data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)', 'mailto:team@example.test%0d%0aBcc:evil@example.test', $deeplyEncoded,
	] as $unsafe){ $t->same(null, $policy->normalizeUrl($unsafe)); }
	foreach(['https://example.test/a', 'http://example.test', 'mailto:team@example.test', 'tel:+15145550101', '/panel/files/1', '#section'] as $safe){ $t->same($safe, $policy->normalizeUrl($safe)); }
	$t->same('//cdn.example.test/a.png', $policy->protocolRelativeUrls()->normalizeUrl('//cdn.example.test/a.png'));
	$t->same(null, $policy->urlSchemes(['https','javascript'])->normalizeUrl('javascript:alert(1)'));
	$t->same('https://example.test/a%20b', $policy->normalizeUrl('https://example.test/a%20b'));
})->tag('panel','editor','security','urls')->maxMillis(1000);

test('editor policy context and result value objects stay immutable and bounded', static function(Context $t): void {
	$base=PanelEditorSanitizationPolicy::strict();
	$custom=PanelEditorSanitizationPolicy::fromArray([
		'elements'=>['p'=>[], 'a'=>['href']], 'schemes'=>['https','javascript'],
		'relative_urls'=>false, 'protocol_relative_urls'=>false, 'reject_unsafe'=>false,
		'strip_comments'=>false, 'max_nodes'=>2, 'max_depth'=>2, 'max_url_length'=>64, 'max_bytes'=>1024,
	]);
	$t->same(true, array_key_exists('strong', $base->allowedElements()));
	$t->same(false, array_key_exists('strong', $custom->allowedElements()));
	$t->same(null, $custom->normalizeUrl('/relative'));
	$t->same('https://example.test', $custom->normalizeUrl('https://example.test'));
	$t->same(null, $custom->normalizeUrl('javascript:alert(1)'));
	$t->same(false, $custom->rejectsUnsafe());
	$t->same(false, $custom->stripsComments());
	$t->same(2, $custom->nodeLimit());
	$t->same(2, $custom->depthLimit());
	$t->same(1024, $custom->byteLimit());

	$context=PanelEditorContext::make('Article Body', 'wysiwyg', 'PHP 8!', 'UPLOAD', ['private'=>'record'], null, ['secret'=>'value']);
	$t->same('rich_text', $context->mode());
	$t->same('php_8', $context->language());
	$t->same(false, array_key_exists('record', $context->toArray()));
	$t->same(false, array_key_exists('values', $context->toArray()));
	$rejected=PanelEditorContentResult::reject('safe fallback', ['Invalid.', 'Invalid.']);
	$t->same(['Invalid.'], $rejected->errors());
	$thrown=false; try{ $rejected->requireValid(); }catch(DomainException){ $thrown=true; }
	$t->same(true, $thrown);
})->tag('panel','editor','policy','value_objects')->maxMillis(1000);

test('editor value objects and declarative manifests expose their complete safe contract', static function(Context $t): void {
	$result=PanelEditorContentResult::accept('original', false, [' Notice. ', 'Notice.'], ['theme'=>'dark', 'api_token'=>'hidden']);
	$t->same(['theme'=>'dark'], $result->meta());
	$changed=$result->withContent('normalized')->withErrors([' Invalid. ', 'Invalid.']);
	$t->same('normalized', $changed->content());
	$t->same(true, $changed->changed());
	$t->same(['Invalid.'], $changed->errors());
	$t->same(['Notice.'], $changed->warnings());
	$t->same(false, $changed->valid());
	$t->same($changed->toArray(), $changed->jsonSerialize());
	$t->same('normalized', $changed->toArray()['content'] ?? null);

	$record=(object)['id'=>42];
	$context=PanelEditorContext::make('Article Body', 'htm', 'PHP 8!', 'validate', $record, null, ['status'=>'draft']);
	$t->same('article_body', $context->field());
	$t->same('html', $context->mode());
	$t->same('validate', $context->stage());
	$t->same($record, $context->record());
	$t->same(null, $context->request());
	$t->same(['status'=>'draft'], $context->values());
	$t->same('persist', $context->withStage('persist')->stage());
	$t->same($context->toArray(), $context->jsonSerialize());
	$t->same('markdown', PanelEditorContext::normalizeMode('md'));
	$t->same('code', PanelEditorContext::normalizeMode('source'));

	$long=str_repeat('a', 4095).'é'.str_repeat('b', 32);
	$truncated=PanelEditorManifest::sanitize(['value'=>$long])['value'] ?? '';
	$t->same(4095, strlen((string)$truncated));
	$t->same(1, preg_match('//u', (string)$truncated));
	$closed=fopen('php://memory', 'rb');
	if(is_resource($closed)){ fclose($closed); }
	$t->same([], PanelEditorManifest::sanitize(['closed'=>$closed]));
	$t->same(128, count(PanelEditorManifest::sanitize(range(1, 140))));
	$t->same([], PanelEditorManifest::sanitize([[PanelEditorManifest::class, 'sanitize']]));
	$bounded=[];
	for($index=0;$index<128;$index++){ $bounded['token_'.$index]='hidden'; }
	$bounded['safe_after_limit']='must not be inspected';
	$t->same([], PanelEditorManifest::sanitize($bounded));

	$policy=PanelEditorSanitizationPolicy::make()
		->elements(['P', 'bad tag'=>[], 'a'=>'href, onclick, style, title'])
		->disallowElement('p');
	$t->same(['href','title'], $policy->allowedElements()['a'] ?? null);
	$t->same(false, array_key_exists('p', $policy->allowedElements()));
	$t->same($policy, $policy->allowElement('bad tag'));
	$t->same(['http','https','mailto','tel'], PanelEditorSanitizationPolicy::strict()->allowedSchemes());
	$t->same(PanelEditorSanitizationPolicy::strict()->toArray(), PanelEditorSanitizationPolicy::strict()->jsonSerialize());

	$toolbar=PanelEditorToolbar::make('formatting')->command('strike_through');
	$t->same('formatting', $toolbar->name());
	$t->same('Strike Through', $toolbar->commands()[0]['label'] ?? null);
	$t->same($toolbar->toArray(), $toolbar->jsonSerialize());
	$plugin=PanelEditorPlugin::make('mentions')->commands('mention');
	$t->same($plugin->toArray(), $plugin->jsonSerialize());
})->tag('panel','editor','coverage','manifests','value_objects')->maxMillis(1000);

test('browser editor adapters expose safe versioned global and registry contracts', static function(Context $t): void {
	$tiny=PanelEditorBrowserAdapter::tinyMce(['toolbar'=>'bold italic','api_token'=>'hidden']);
	$ck=PanelEditorBrowserAdapter::ckEditor5(['placeholder'=>'Article']);
	$monaco=PanelEditorBrowserAdapter::monaco(['minimap'=>['enabled'=>false]]);
	$tiptap=PanelEditorBrowserAdapter::tiptap(['extensions'=>['starter_kit']]);
	$codeMirror=PanelEditorBrowserAdapter::codeMirror6(['lineNumbers'=>true]);
	$prism=PanelEditorBrowserAdapter::prism(['php','PHP','bad language!'], ['theme'=>'night']);
	$highlight=PanelEditorBrowserAdapter::highlightJs('*', ['classPrefix'=>'hljs-']);

	$t->same(1, $tiny->manifest()['schema_version'] ?? null);
	$t->same('surface', $tiny->kind());
	$t->same('tinymce', $tiny->name());
	$t->same('tinymce', $tiny->driver());
	$t->same('native', $tiny->fallbackMode());
	$t->same('global', $tiny->loadStrategy());
	$t->same(true, $tiny->isEnabled());
	$t->same(true, $tiny->isConfigured());
	$t->same(true, $tiny->isSurface());
	$t->same(false, $tiny->isSyntax());
	$t->same(true, $tiny->supportsMode('wysiwyg'));
	$t->same(false, $tiny->supportsMode('code'));
	$t->same(false, $tiny->supportsLanguage('php'));
	$t->same(false, str_contains((string)json_encode($tiny), 'hidden'));
	$t->same($tiny->manifest(), $tiny->toArray());
	$t->same($tiny->manifest(), $tiny->jsonSerialize());
	$t->same('ckeditor5', $ck->driver());
	$t->same('source', $monaco->fallbackMode());
	$t->same('registry', $tiptap->loadStrategy());
	$t->same('codemirror6', $codeMirror->driver());
	$t->same(true, $prism->isSyntax());
	$t->same(true, $prism->supportsLanguage('php'));
	$t->same(false, $prism->supportsLanguage('rust'));
	$t->same(true, $highlight->supportsLanguage('rust'));

	$custom=PanelEditorBrowserAdapter::surface('Host bridge', '', ['plain','PLAIN',''])
		->languages(['php','php'])
		->requiredGlobals(['Host.Editor','bad path()','Host.Editor'])
		->capabilities(['Commands','commands',''])
		->fallback('invalid fallback')
		->strategy('invalid strategy')
		->options(['safe'=>'yes','callback'=>'hidden']);
	$t->same(['Host.Editor'], $custom->manifest()['required_globals'] ?? null);
	$t->same(['source_sync','lifecycle','commands'], $custom->manifest()['capabilities'] ?? null);
	$t->same('native', $custom->fallbackMode());
	$t->same('registry', $custom->loadStrategy());
	$t->same('yes', $custom->manifest()['options']['safe'] ?? null);
	$t->same(false, array_key_exists('callback', $custom->manifest()['options'] ?? []));

	$roundTrip=PanelEditorBrowserAdapter::fromArray($monaco->manifest());
	$t->same($monaco->manifest(), $roundTrip->manifest());
	$syntaxRoundTrip=PanelEditorBrowserAdapter::fromArray($prism->manifest());
	$t->same($prism->manifest(), $syntaxRoundTrip->manifest());
	$invalid=PanelEditorBrowserAdapter::fromArray(['kind'=>'unknown','name'=>'broken','driver'=>'broken','enabled'=>true,'modes'=>42,'languages'=>42,'capabilities'=>42,'required_globals'=>42,'options'=>'bad','fallback'=>'error','strategy'=>'global']);
	$t->same(false, $invalid->isConfigured());
	$t->same(false, $invalid->supportsMode('plain'));
	$t->same(false, $invalid->supportsLanguage('plain'));
	$t->same('error', $invalid->fallbackMode());
	$t->same('global', $invalid->loadStrategy());
	$t->same(false, PanelEditorBrowserAdapter::surface('', '', '*')->isConfigured());
})->tag('panel','editor','browser-adapter','manifest','security','coverage')->maxMillis(1000);

test('editor asset value objects reject unsafe references and expose bounded public envelopes', static function(Context $t): void {
	$asset=new PanelEditorAsset('asset_42','hero.png','/panel/editor/assets/asset_42','image/png',128,'invalid',640,480,str_repeat('A',600),'ready',['theme'=>'dark','api_token'=>'hidden']);
	$t->same('asset_42',$asset->id());
	$t->same('hero.png',$asset->name());
	$t->same('/panel/editor/assets/asset_42',$asset->url());
	$t->same('image/png',$asset->mime());
	$t->same(128,$asset->size());
	$t->same('image',$asset->kind());
	$t->same(640,$asset->width());
	$t->same(480,$asset->height());
	$t->same(512,strlen($asset->alt()));
	$t->same('ready',$asset->status());
	$t->same(true,$asset->ready());
	$t->same('dark',$asset->metadata()['theme']??null);
	$t->same(false,array_key_exists('api_token',$asset->metadata()));
	$t->same($asset->toArray(),$asset->jsonSerialize());
	$roundTrip=PanelEditorAsset::fromArray($asset->toArray());
	$t->same($asset->toArray(),$roundTrip->toArray());
	$document=PanelEditorAsset::fromArray(['id'=>'doc-1','filename'=>'guide.pdf','url'=>'https://cdn.example.test/guide.pdf','mime'=>'application/pdf','size'=>42,'width'=>0,'height'=>100001,'status'=>'unknown']);
	$t->same('document',$document->kind());
	$t->same(null,$document->width());
	$t->same(null,$document->height());
	$t->same('ready',$document->status());
	foreach(['','../asset','asset/one',str_repeat('a',193)] as $invalid){$thrown=false;try{PanelEditorAsset::normalizeId($invalid);}catch(InvalidArgumentException){$thrown=true;}$t->same(true,$thrown);}
	foreach(['javascript:alert(1)','//evil.example/x','http://example.test/x','/assets/../secret','/assets/%252e%252e/secret','https://user:pass@example.test/x','/assets/x#fragment','/assets/x?api_key=secret'] as $invalid){$thrown=false;try{PanelEditorAsset::normalizeUrl($invalid);}catch(InvalidArgumentException){$thrown=true;}$t->same(true,$thrown);}
	$deepKey='to%6ben';for($pass=0;$pass<6;$pass++){$deepKey=rawurlencode($deepKey);}
	$thrown=false;try{PanelEditorAsset::normalizeUrl('/assets/x?'.$deepKey.'=hidden');}catch(InvalidArgumentException){$thrown=true;}$t->same(true,$thrown);
	$t->same('/assets/x?variant=thumb',PanelEditorAsset::normalizeUrl('/assets/x?variant=thumb'));
	$thrown=false;try{PanelEditorAsset::normalizeEndpoint('/assets?variant=thumb');}catch(InvalidArgumentException){$thrown=true;}$t->same(true,$thrown);

	$page=new PanelEditorAssetPage([$asset,$asset->toArray(),['invalid'=>true],'ignored'],'cursor_1',true,2,['safe'=>'yes','secret'=>'hidden']);
	$t->same(2,count($page->assets()));
	$t->same('cursor_1',$page->nextCursor());
	$t->same(true,$page->hasMore());
	$t->same(2,$page->total());
	$t->same('yes',$page->meta()['safe']??null);
	$t->same($page->toArray(),$page->jsonSerialize());
	$t->same(null,(new PanelEditorAssetPage([],"bad\n",true,-1))->nextCursor());
	$t->same(false,(new PanelEditorAssetPage([],null,true))->hasMore());
	$t->same($page->toArray(),PanelEditorAssetPage::fromArray($page->toArray())->toArray());

	$result=PanelEditorAssetResult::success('uploaded',' Uploaded. ',$asset,$page,['safe'=>'yes','access_token'=>'hidden']);
	$t->same(true,$result->ok());
	$t->same('uploaded',$result->code());
	$t->same('Uploaded.',$result->message());
	$t->same(200,$result->status());
	$t->same($asset,$result->asset());
	$t->same($page,$result->page());
	$t->same([],$result->warnings());
	$t->same('yes',$result->meta()['safe']??null);
	$t->same($result->toArray(),$result->jsonSerialize());
	$t->same($result->toArray(),PanelEditorAssetResult::fromArray($result->toArray())->toArray());
	$failed=PanelEditorAssetResult::failure('unsafe code',str_repeat('é',400),999);
	$t->same(false,$failed->ok());
	$t->same(599,$failed->status());
	$t->same(0,preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$failed->message()));
})->tag('panel','editor','assets','value-objects','security','coverage')->maxMillis(1000);

test('callback editor asset providers normalize external libraries behind a default-deny runtime', static function(Context $t): void {
	$context=PanelEditorContext::make('body','rich_text','plain','assets');
	$asset=new PanelEditorAsset('asset_1','hero.png','/assets/asset_1','image/png',64);
	$provider=PanelEditorCallbackAssetProvider::make('Library','/assets')
		->providerType('flysystem')->browserDriver('host_assets')->accept('image/png')->maxBytes(100)
		->detectUsing(static fn(array $upload):string=>(string)($upload['type']??''))->uploads()->deletes()->csrf(true,'csrf_token','X-Panel-CSRF')
		->authorizeUsing(static fn(string $operation):bool=>$operation!=='blocked')
		->browseUsing(static fn(array $query):array=>['items'=>[$query['asset']??[]],'total'=>1])
		->findUsing(static fn(string $id):array=>['id'=>$id,'name'=>'hero.png','url'=>'/assets/'.$id,'mime'=>'image/png','bytes'=>64])
		->storeUsing(static fn():PanelEditorAsset=>$asset)
		->deleteUsing(static fn():bool=>true)
		->deliverUsing(static fn():PanelEditorAssetResult=>PanelEditorAssetResult::success('delivery_ready','Ready.',$asset,null,['url'=>'/delivery']))
		->normalizeUsing(static fn(string $url):?string=>str_starts_with($url,'/assets/')?$url:null);
	$t->same(true,$provider->ready());
	$t->same('library',$provider->name());
	$t->same(true,$provider->validateUpload(['name'=>'hero.png','type'=>'image/png','size'=>64,'error'=>0],$context)->valid());
	$t->same(false,$provider->validateUpload(['name'=>'hero.png','type'=>'image/png','size'=>101,'error'=>0],$context)->valid());
	$page=$provider->browse(['asset'=>$asset->toArray()],$context);
	$t->same('asset_1',$page->assets()[0]->id()??null);
	$t->same('asset_1',$provider->findAsset('asset_1',$context)?->id());
	$t->same(null,$provider->findAsset('../bad',$context));
	$t->same('uploaded',$provider->storeAsset(['name'=>'hero.png','type'=>'image/png','size'=>64,'error'=>0],$context)->code());
	$t->same('upload_invalid',$provider->storeAsset(['name'=>'hero.png','type'=>'image/png','size'=>101,'error'=>0],$context)->code());
	$t->same('deleted',$provider->deleteAsset('asset_1',$context)->code());
	$t->same('delivery_ready',$provider->delivery('asset_1',$context)->code());
	$t->same('/assets/asset_1',$provider->normalizeReference('/assets/asset_1',$context));
	$t->same(null,$provider->normalizeReference('javascript:alert(1)',$context));
	$manifest=$provider->manifest();
	$t->same('flysystem',$manifest['provider']??null);
	$t->same('host_assets',$manifest['browser']['driver']??null);
	$t->same('csrf_token',$manifest['browser']['verification_field']??null);
	$t->same($manifest,$provider->jsonSerialize());
	$t->same(false,PanelEditorCallbackAssetProvider::fromArray($manifest)->ready());
	$profile=PanelEditorProfile::make('asset_article','rich_text')->assetProvider($provider);
	$t->same($provider,$profile->assets());
	$t->same($provider,$profile->upload());
	$t->same($provider,$profile->media());
	$t->same(true,array_key_exists('img',$profile->policy()->allowedElements()));
	$t->same(true,in_array('asset_provider',$profile->manifest()['capabilities']??[],true));
	$t->same(false,PanelEditorProfile::fromArray($profile->manifest())->assets()?->ready());
	$t->same(null,$profile->assetProvider(null)->assets());
	$t->same(null,$profile->assetProvider(null)->upload());
	$t->same(false,array_key_exists('img',PanelEditorProfile::make('no_images','rich_text')->assetProvider($provider,false)->policy()->allowedElements()));
	$t->same(true,$profile->process('<p>Before</p><img src="/assets/asset_1" alt="Hero"><p>After</p>',PanelEditorContext::make('body','rich_text'))->valid());
	$field=Field::make('body')->richText()->editorAssetProvider($provider);
	$t->same(true,in_array('editor_asset_provider',$field->toArray()['component']['capabilities']??[],true));
	$t->same(1,count($field->browseEditorAssets(['asset'=>$asset->toArray()])->assets()));
	$t->same('asset_1',$field->findEditorAsset('asset_1')?->id());
	$t->same('uploaded',$field->storeEditorAsset(['name'=>'hero.png','type'=>'image/png','size'=>64,'error'=>0])->code());
	$t->same('deleted',$field->deleteEditorAsset('asset_1')->code());
	$t->same('delivery_ready',$field->editorAssetDelivery('asset_1')->code());
	$t->same('/assets/asset_1',$field->normalizeEditorMediaReference('/assets/asset_1'));
	$renderer=$t->nonPublic(PanelRenderer::class);
	$rendered=$renderer->invoke('fieldControl','body',$field->toArray(),'<p>Before</p><img src="/assets/asset_1" alt="Hero">',false);
	$t->contains('data-dp-panel-editor-assets-trigger="1"',$rendered);
	$t->contains('aria-haspopup="dialog"',$rendered);
	$t->contains('data-dp-panel-editor-assets="host_assets"',$rendered);
	$t->contains('data-dp-panel-editor-assets-host="1"',$rendered);
	$t->contains('&quot;asset_provider&quot;',$rendered);
	$t->notContains('private browse failure',$rendered);
	$inert=Field::fromArray($field->toArray());
	$inertRendered=$renderer->invoke('fieldControl','body',$inert->toArray(),'<p>Safe fallback</p>',false);
	$t->notContains('data-dp-panel-editor-assets-trigger="1"',$inertRendered);
	$t->notContains('data-dp-panel-editor-assets-host="1"',$inertRendered);
	$without=$field->editorAssetProvider(null);
	$t->same([],$without->browseEditorAssets()->assets());
	$t->same(null,$without->findEditorAsset('asset_1'));
	$t->same(503,$without->storeEditorAsset([])->status());
	$t->same(503,$without->deleteEditorAsset('asset_1')->status());
	$t->same(503,$without->editorAssetDelivery('asset_1')->status());
	$t->same(false,PanelEditorCallbackAssetProvider::make('disabled','/assets')->uploads(false)->validateUpload([],$context)->valid());
	$t->same(false,PanelEditorCallbackAssetProvider::make('missing','/assets')->accept('image/png')->ready());
	$t->same(false,$provider->enabled(false)->ready());

	$throwing=$provider->browseUsing(static function():never{throw new RuntimeException('private browse failure');})
		->findUsing(static function():never{throw new RuntimeException('private find failure');})
		->storeUsing(static function():never{throw new RuntimeException('private store failure');})
		->deleteUsing(static function():never{throw new RuntimeException('private delete failure');})
		->deliverUsing(static function():never{throw new RuntimeException('private delivery failure');})
		->normalizeUsing(static function():never{throw new RuntimeException('private normalize failure');});
	$t->same([],$throwing->browse([],$context)->assets());
	$t->same(null,$throwing->findAsset('asset_1',$context));
	$t->same('upload_failed',$throwing->storeAsset(['name'=>'hero.png','type'=>'image/png','size'=>64,'error'=>0],$context)->code());
	$t->same('asset_not_found',$throwing->deleteAsset('asset_1',$context)->code());
	$t->same('asset_not_found',$throwing->delivery('asset_1',$context)->code());
	$t->same(null,$throwing->normalizeReference('/assets/asset_1',$context));
	$readOnly=$provider->uploads(false)->deletes(false)->deliverUsing(static fn():array=>['safe'=>'value']);
	$t->same('operation_denied',$readOnly->deleteAsset('asset_1',$context)->code());
	$t->same('delivery_ready',$readOnly->delivery('asset_1',$context)->code());
})->tag('panel','editor','assets','callback-provider','security','coverage')->maxMillis(1500);

test('Panel media editor provider scopes uploads browsing cursors delivery and deletion', static function(Context $t): void {
	$root=$t->workspace('panel-editor-media-provider')->root();
	$manager=PanelMediaManager::local($root,str_repeat('D',32),['delivery_url'=>'/panel/media/private']);
	$tokens=['uploadtoken01','uploadtoken02','uploadtoken03'];
	$provider=PanelEditorMediaManagerProvider::make($manager,'/panel/editor/assets',str_repeat('S',32),[
		'scope'=>static fn(PanelEditorContext $context):string=>(string)($context->values()['tenant']??''),
		'authorize'=>static fn(string $operation,string $scope):bool=>$scope==='tenant-a'&&$operation!=='blocked',
		'accepted'=>['text/plain'],'max_bytes'=>4096,'chunk_size'=>1024,'deletes'=>true,
		'id_generator'=>static function()use(&$tokens):string{return array_shift($tokens)??'uploadtoken99';},
	]);
	$context=PanelEditorContext::make('Body','rich_text','plain','assets',null,null,['tenant'=>'tenant-a']);
	$other=PanelEditorContext::make('Body','rich_text','plain','assets',null,null,['tenant'=>'tenant-b']);
	$t->same(true,$provider->ready());
	$t->same('panel_media_manager',$provider->manifest()['provider']??null);
	$t->notContains(str_repeat('S',32),(string)json_encode($provider->manifest()));
	$ids=[];
	foreach(['alpha.txt'=>'alpha','beta.txt'=>'beta','gamma.txt'=>'gamma'] as $name=>$contents){
		$file=$t->workspace('panel-editor-media-'.$name)->file($name,$contents);
		$result=$provider->storeAsset(['name'=>$name,'tmp_name'=>$file,'type'=>'text/plain','size'=>strlen($contents),'error'=>0],$context);
		$t->same(true,$result->ok());
		$ids[]=$result->asset()?->id();
	}
	$t->same(3,count(array_filter($ids)));
	$first=$provider->browse(['limit'=>1],$context);
	$t->same(1,count($first->assets()));
	$t->same(true,$first->hasMore());
	$t->same(3,$first->total());
	$second=$provider->browse(['limit'=>1,'cursor'=>$first->nextCursor()],$context);
	$t->same(1,count($second->assets()));
	$t->same([],$provider->browse(['limit'=>1,'cursor'=>$first->nextCursor().'tampered'],$context)->assets());
	$t->same(1,count($provider->browse(['search'=>'alpha','mime'=>'text/*','kind'=>'document'],$context)->assets()));
	$t->same([],$provider->browse(['kind'=>'image'],$context)->assets());
	$id=(string)$ids[0];$asset=$provider->findAsset($id,$context);
	$t->same($id,$asset?->id());
	$t->same(null,$provider->findAsset($id,$other));
	$t->same($asset?->url(),$provider->normalizeReference((string)$asset?->url(),$context));
	$t->same(null,$provider->normalizeReference((string)$asset?->url(),$other));
	$t->same(null,$provider->normalizeReference('/panel/editor/assets/'.$id.'?variant=bad',$context));
	$delivery=$provider->delivery($id,$context);
	$t->same(true,$delivery->ok());
	$t->contains('/panel/media/private?token=',(string)($delivery->meta()['url']??''));
	$t->same('asset_not_found',$provider->delivery($id,$other)->code());
	$t->same('deleted',$provider->deleteAsset($id,$context)->code());
	$t->same(null,$provider->findAsset($id,$context));
	$t->same('asset_not_found',$provider->deleteAsset($id,$context)->code());
	$duplicate=PanelEditorMediaManagerProvider::make($manager,'/panel/editor/assets',str_repeat('S',32),[
		'scope'=>static fn():string=>'tenant-a','authorize'=>static fn():bool=>true,'accepted'=>'text/plain','id_generator'=>static fn():string=>'duplicate01',
	]);
	$duplicateFile=$t->workspace('panel-editor-media-duplicate')->file('duplicate.txt','duplicate');
	$duplicateChanged=$t->workspace('panel-editor-media-duplicate-changed')->file('duplicate.txt','different');
	$t->same(true,$duplicate->storeAsset(['name'=>'duplicate.txt','tmp_name'=>$duplicateFile,'type'=>'text/plain','size'=>9,'error'=>0],$context)->ok());
	$t->same('upload_failed',$duplicate->storeAsset(['name'=>'duplicate.txt','tmp_name'=>$duplicateChanged,'type'=>'text/plain','size'=>9,'error'=>0],$context)->code());
	$t->same(null,$t->nonPublic(PanelEditorMediaManagerProvider::class)->invoke('asset','/panel/editor/assets',[]));
	$badSecret=false;try{PanelEditorMediaManagerProvider::make($manager,'/assets','short');}catch(InvalidArgumentException){$badSecret=true;}$t->same(true,$badSecret);
	$badPrefix=false;try{PanelEditorMediaManagerProvider::make($manager,'/assets',str_repeat('S',32),['prefix'=>'../private']);}catch(InvalidArgumentException){$badPrefix=true;}$t->same(true,$badPrefix);
	$t->same(false,PanelEditorMediaManagerProvider::make($manager,'/assets',str_repeat('S',32))->ready());
})->tag('panel','editor','assets','panel-media','scope','upload','security')->maxMillis(5000);

test('editor asset endpoint keeps transport verification and public errors fail closed', static function(Context $t): void {
	$context=PanelEditorContext::make('body','rich_text','plain','assets');
	$asset=new PanelEditorAsset('asset_1','hero.png','/assets/asset_1','image/png',64);
	$provider=PanelEditorCallbackAssetProvider::make('endpoint','/assets')->accept('image/png')->detectUsing(static fn():string=>'image/png')->deletes()
		->authorizeUsing(static fn():bool=>true)->browseUsing(static fn():array=>[$asset])->findUsing(static fn():PanelEditorAsset=>$asset)
		->storeUsing(static fn():PanelEditorAsset=>$asset)->deleteUsing(static fn():bool=>true)->normalizeUsing(static fn(string $url):string=>$url);
	$denied=new PanelEditorAssetEndpoint($provider);
	$t->same(403,$denied->handle(['operation'=>'browse'],[],$context)['status']);
	$endpoint=new PanelEditorAssetEndpoint($provider,static fn(string $operation,array $trusted_request):bool=>($trusted_request['csrf']??'')==='valid'&&$operation!=='blocked');
	$trusted=['csrf'=>'valid'];
	$t->same(200,$endpoint->handle(json_encode(['operation'=>'browse'],JSON_THROW_ON_ERROR),[],$context,$trusted)['status']);
	$t->same(1,count($endpoint->handle(['operation'=>'browse','query'=>[]],[],$context,$trusted)['body']['page']['assets']??[]));
	$t->same(200,$endpoint->handle(['operation'=>'asset','id'=>'asset_1'],[],$context,$trusted)['status']);
	$t->same(200,$endpoint->handle(['operation'=>'upload'],['field'=>['name'=>'hero.png','type'=>'image/png','size'=>64,'error'=>0]],$context,$trusted)['status']);
	$t->same(200,$endpoint->handle(['operation'=>'delete','id'=>'asset_1'],[],$context,$trusted)['status']);
	$t->same(200,$endpoint->handle(['operation'=>'delivery','id'=>'asset_1'],[],$context,$trusted)['status']);
	$t->same(400,$endpoint->handle(['operation'=>'unknown'],[],$context,$trusted)['status']);
	$t->same(400,$endpoint->handle('{bad json',[],$context,$trusted)['status']);
	$t->same(400,$endpoint->handle([],[],$context,$trusted)['status']);
	$t->same(400,$endpoint->handle(['operation'=>'upload'],[],$context,$trusted)['status']);
	$t->same('stable_non_diagnostic',$endpoint->manifest()['public_errors']??null);
	$t->same($endpoint->manifest(),$endpoint->jsonSerialize());
	$throwing=new PanelEditorAssetEndpoint($provider,static function():never{throw new RuntimeException('private verifier failure');});
	$t->same(403,$throwing->handle(['operation'=>'browse'],[],$context)['status']);
	$unavailable=new PanelEditorAssetEndpoint($provider->enabled(false),static fn():bool=>true);
	$t->same(503,$unavailable->handle(['operation'=>'browse'],[],$context)['status']);
	$explodingProvider=new class implements PanelEditorAssetProvider {
		public function name():string{return 'exploding';} public function ready():bool{return true;}
		public function validateUpload(array $upload,PanelEditorContext $context):PanelEditorContentResult{return PanelEditorContentResult::accept('file');}
		public function normalizeReference(string $url,PanelEditorContext $context):?string{return null;}
		public function browse(array $query,PanelEditorContext $context):PanelEditorAssetPage{throw new RuntimeException('private provider failure');}
		public function findAsset(string $id,PanelEditorContext $context):?PanelEditorAsset{return null;}
		public function storeAsset(array $upload,PanelEditorContext $context):PanelEditorAssetResult{return PanelEditorAssetResult::failure('failed');}
		public function deleteAsset(string $id,PanelEditorContext $context):PanelEditorAssetResult{return PanelEditorAssetResult::failure('failed');}
		public function delivery(string $id,PanelEditorContext $context):PanelEditorAssetResult{return PanelEditorAssetResult::failure('failed');}
		public function manifest():array{return ['type'=>'exploding'];} public function jsonSerialize():array{return $this->manifest();}
	};
	$t->same(500,(new PanelEditorAssetEndpoint($explodingProvider,static fn():bool=>true))->handle(['operation'=>'browse'],[],$context)['status']);
})->tag('panel','editor','assets','endpoint','security','errors')->maxMillis(1500);

test('profiles fields and renderer carry browser editor adapters without weakening server policy', static function(Context $t): void {
	$browser=PanelEditorBrowserAdapter::tinyMce(['menubar'=>false]);
	$syntax=PanelEditorBrowserAdapter::prism(['html','php']);
	$profile=PanelEditorProfile::make('browser_article', 'rich_text')->browserAdapter($browser)->browserSyntaxAdapter($syntax);
	$t->same($browser, $profile->browser());
	$t->same($syntax, $profile->browserSyntax());
	$t->same(true, in_array('browser_editor_adapter', $profile->manifest()['capabilities'] ?? [], true));
	$t->same(true, in_array('browser_syntax_adapter', $profile->manifest()['capabilities'] ?? [], true));
	$t->same($profile->manifest(), PanelEditorProfile::fromArray($profile->manifest())->manifest());
	$t->same(null, $profile->browserAdapter(null)->browser());
	$t->same(null, $profile->browserSyntaxAdapter(null)->browserSyntax());

	$surfaceRejected=false;
	try{ $profile->browserAdapter($syntax); }catch(InvalidArgumentException){ $surfaceRejected=true; }
	$syntaxRejected=false;
	try{ $profile->browserSyntaxAdapter($browser); }catch(InvalidArgumentException){ $syntaxRejected=true; }
	$t->same(true, $surfaceRejected);
	$t->same(true, $syntaxRejected);
	$t->same(null, PanelEditorProfile::fromArray(['browser_adapter'=>$syntax->manifest(),'browser_syntax'=>$browser->manifest()])->browser());

	$field=Field::make('body')->richText()->editorBrowserAdapter($browser)->editorBrowserSyntaxAdapter($syntax);
	$manifest=$field->toArray();
	$t->same(true, in_array('editor_browser_adapter', $manifest['component']['capabilities'] ?? [], true));
	$t->same(true, in_array('editor_browser_syntax_adapter', $manifest['component']['capabilities'] ?? [], true));
	$t->same('tinymce', $manifest['meta']['editor_profile']['browser_adapter']['driver'] ?? null);
	$t->same('prism', Field::fromArray($manifest)->editorProfileManifest()['browser_syntax']['driver'] ?? null);
	$arrayField=Field::make('source')->codeEditor('php')
		->editorBrowserAdapter(PanelEditorBrowserAdapter::monaco()->manifest())
		->editorBrowserSyntaxAdapter(PanelEditorBrowserAdapter::highlightJs('php')->manifest());
	$t->same('monaco', $arrayField->editorProfileManifest()['browser_adapter']['driver'] ?? null);
	$t->same('syntax', Field::make('source')->codeEditor('php')->editorBrowserSyntaxAdapter([])->editorProfileManifest()['browser_syntax']['kind'] ?? null);
	$t->same(null, $arrayField->editorBrowserAdapter(null)->effectiveEditorProfile()->browser());
	$t->same(null, $arrayField->editorBrowserSyntaxAdapter(null)->effectiveEditorProfile()->browserSyntax());

	$html=$t->nonPublic(PanelRenderer::class)->invoke('fieldControl','body',$manifest,'<p>Safe article.</p>',false);
	$t->contains('data-dp-panel-editor-browser-adapter="tinymce"', $html);
	$t->contains('data-dp-panel-editor-browser-fallback="native"', $html);
	$t->contains('data-dp-panel-editor-browser-syntax="prism"', $html);
	$t->contains('data-dp-panel-editor-external-host="1"', $html);
	$t->notContains('api_token', $html);
})->tag('panel','editor','browser-adapter','field','renderer','compatibility')->maxMillis(1500);

test('DOM editor sanitizer enforces tags attributes links and reject versus strip policy', static function(Context $t): void {
	$sanitizer=new PanelEditorHtmlSanitizer();
	$context=PanelEditorContext::make('body', 'rich_text');
	$safe=$sanitizer->sanitize('<p>Hello <a href="https://example.test" target="_blank">team</a></p>', PanelEditorSanitizationPolicy::strict(), $context);
	$t->same(true, $safe->valid());
	$t->contains('noopener noreferrer', $safe->content());
	$t->contains('<p>Hello ', $safe->content());

	$unsafe=$sanitizer->sanitize('<div onclick="x()"><script>alert(1)</script><a href="javascript:alert(1)" style="color:red">bad</a><p>safe</p></div>', PanelEditorSanitizationPolicy::strict(), $context);
	$t->same(false, $unsafe->valid());
	$t->notContains('script', strtolower($unsafe->content()));
	$t->notContains('onclick', strtolower($unsafe->content()));
	$t->notContains('javascript', strtolower($unsafe->content()));
	$t->contains('<p>safe</p>', $unsafe->content());
	$activeDocument=$sanitizer->sanitize('<meta http-equiv="refresh" content="0;url=https://evil.example"><p>safe</p>', PanelEditorSanitizationPolicy::strict()->allowElement('meta', ['http-equiv','content']), $context);
	$t->same(false, $activeDocument->valid());
	$t->notContains('<meta', $activeDocument->content());

	$stripped=$sanitizer->sanitize('<p onclick="x()">safe</p>', PanelEditorSanitizationPolicy::strict()->stripUnsafe(), $context);
	$t->same(true, $stripped->valid());
	$t->same(true, $stripped->warnings()!==[]);
	$t->same('<p>safe</p>', $stripped->content());
	$oversized=$sanitizer->sanitize(str_repeat('x', 1025), PanelEditorSanitizationPolicy::strict()->maxBytes(1024), $context);
	$t->same(false, $oversized->valid());
	$t->same('', $oversized->content());
	$escaped=$sanitizer->sanitize('</div><script>alert(1)</script><p>outside</p>', PanelEditorSanitizationPolicy::strict(), $context);
	$t->same(false, $escaped->valid());
	$t->notContains('outside', $escaped->content());
	$t->same(false, $sanitizer->sanitize('<p><strong>deep</strong></p>', PanelEditorSanitizationPolicy::strict()->maxDepth(1), $context)->valid());
	$t->same(false, $sanitizer->sanitize('<p>one</p><p>two</p>', PanelEditorSanitizationPolicy::strict()->maxNodes(1), $context)->valid());
	$t->same(false, $sanitizer->sanitize("\xC3\x28", PanelEditorSanitizationPolicy::strict(), $context)->valid());
	$comment=$sanitizer->sanitize('<p>safe<!-- private --></p>', PanelEditorSanitizationPolicy::strict(), $context);
	$t->same(true, $comment->valid());
	$t->notContains('private', $comment->content());
})->tag('panel','editor','sanitizer','security')->maxMillis(1000);

test('DOM editor sanitizer closes unavailable head-escape and URL-set branches', static function(Context $t): void {
	$context=PanelEditorContext::make('body', 'rich_text');
	$disabled=new PanelEditorHtmlSanitizer(false);
	$t->same(false, $disabled->ready());
	$t->same(false, $disabled->sanitize('<p>Unsafe without a sanitizer.</p>', PanelEditorSanitizationPolicy::strict(), $context)->valid());

	$sanitizer=new PanelEditorHtmlSanitizer();
	$headEscape=$sanitizer->sanitize('</div></body><head><title>escaped</title></head><body><div>', PanelEditorSanitizationPolicy::strict(), $context);
	$t->same(false, $headEscape->valid());
	$t->notContains('escaped', $headEscape->content());
	$media=PanelEditorMedia::make('library')->allowPrefixes('/uploads/');
	$srcset=$sanitizer->sanitize(
		'<img src="/uploads/hero.png" srcset="/uploads/hero.png 1x">',
		PanelEditorSanitizationPolicy::strict()->allowElement('img', ['src','srcset']),
		$context,
		$media,
	);
	$t->same(false, $srcset->valid());
	$t->notContains('srcset=', $srcset->content());
	$t->same($sanitizer->manifest(), $sanitizer->jsonSerialize());
})->tag('panel','editor','sanitizer','security','coverage')->maxMillis(1000);

test('embedded rich media requires both explicit policy and a ready media adapter', static function(Context $t): void {
	$sanitizer=new PanelEditorHtmlSanitizer();
	$context=PanelEditorContext::make('body', 'rich_text');
	$policy=PanelEditorSanitizationPolicy::strict()->allowElement('img', ['src','alt']);
	$missing=$sanitizer->sanitize('<img src="/uploads/hero.png" alt="Hero">', $policy, $context);
	$t->same(false, $missing->valid());
	$t->notContains('<img', $missing->content());

	$media=PanelEditorMedia::make('library')->allowPrefixes('/uploads/');
	$accepted=$sanitizer->sanitize('<img src="/uploads/hero.png" alt="Hero">', $policy, $context, $media);
	$t->same(true, $accepted->valid());
	$t->contains('/uploads/hero.png', $accepted->content());
	$rejected=$sanitizer->sanitize('<img src="https://evil.example/hero.png">', $policy, $context, $media);
	$t->same(false, $rejected->valid());
	$t->same(null, $media->normalizeReference('/uploads/%2e%2e/private.png', $context));
	$t->same(false, PanelEditorMedia::make('unsafe')->allowPrefixes('javascript:')->allowSchemes('javascript')->ready());
	$t->same(null, $media->normalizeReference('//evil.example/hero.png', $context));
	$t->same(null, $media->normalizeReference('/outside/hero.png', $context));
	$encodedTraversal='%2e%2e';
	for($pass=0;$pass<8;$pass++){ $encodedTraversal=rawurlencode($encodedTraversal); }
	$t->same(null, $media->normalizeReference('/uploads/'.$encodedTraversal.'/private.png', $context));
	$t->same('/uploads/hero.png', Field::make('body')->richText()->editorMediaAdapter($media)->normalizeEditorMediaReference('/uploads/hero.png'));
})->tag('panel','editor','media','security')->maxMillis(1000);

test('plain code and safe markdown remain byte exact while unsafe markdown destinations fail', static function(Context $t): void {
	$content="line 1\r\n\t<raw>& data\n";
	foreach(['plain','code'] as $mode){
		$result=PanelEditorProfile::defaultFor($mode)->process($content, PanelEditorContext::make('body', $mode));
		$t->same(true, $result->valid());
		$t->same($content, $result->content());
	}
	$markdown="[Safe](/orders/1)\r\n\r\n`unchanged`\n";
	$result=PanelEditorProfile::defaultFor('markdown')->process($markdown, PanelEditorContext::make('body', 'markdown'));
	$t->same(true, $result->valid());
	$t->same($markdown, $result->content());
	foreach(['[x](javascript:alert(1))', '[x][bad]'."\n\n".'[bad]: data:text/html,x', '<javascript:alert(1)>', '![x](vbscript:msgbox(1))', '<a href="javascript:alert(1)">x</a>'] as $unsafe){
		$t->same(false, PanelEditorProfile::defaultFor('markdown')->process($unsafe, PanelEditorContext::make('body', 'markdown'))->valid());
	}
})->tag('panel','editor','markdown','compatibility')->maxMillis(1000);

test('Field enforces editor policy during validation and after custom dehydration', static function(Context $t): void {
	$field=Field::make('body')->richText();
	$t->same([], $field->validateValue('<p>Safe</p>'));
	$t->same(true, $field->validateValue('<script>alert(1)</script><p>Safe</p>')!==[]);
	$t->same('<p>Safe</p>', $field->dehydrateValue('<p>Safe</p>'));
	$thrown=false;
	try{ $field->dehydrateValue('<a href="javascript:alert(1)">unsafe</a>'); }catch(DomainException){ $thrown=true; }
	$t->same(true, $thrown);

	$postCallback=$field->dehydrateUsing(static fn(string $value): string => '<script>reintroduced()</script>'.$value);
	$thrown=false;
	try{ $postCallback->dehydrateValue('<p>Safe</p>'); }catch(DomainException){ $thrown=true; }
	$t->same(true, $thrown);
	$t->same(true, in_array('server_sanitization', $field->toArray()['component']['capabilities'] ?? [], true));
	$mismatched=Field::make('body')->richText()->editorProfile(PanelEditorProfile::make('misconfigured', 'plain'));
	$t->same('rich_text', $mismatched->effectiveEditorProfile()->mode());
	$t->same(true, $mismatched->validateValue('<script>x()</script>')!==[]);
	$custom=Field::make('source')->codeEditor('php')
		->editorNormalizer(static fn(string $content): string => trim($content), 'trim')
		->editorValidator(static fn(string $content): array => str_contains($content, 'forbidden') ? ['Forbidden source.'] : [], 'source_policy');
	$t->same('echo 1;', $custom->dehydrateValue("  echo 1; \n"));
	$t->same(['Forbidden source.'], $custom->validateValue('forbidden'));
	$invalidNormalizer=new PanelEditorCallbackNormalizer('invalid', static fn(): array => ['not text']);
	$t->same(false, $invalidNormalizer->normalize('original', PanelEditorContext::make('source', 'code'))->valid());
	$t->same('original', $invalidNormalizer->normalize('original', PanelEditorContext::make('source', 'code'))->content());
})->tag('panel','editor','field','security','dehydrate')->maxMillis(1000);

test('editor adapter manifests redact executable state and sensitive metadata', static function(Context $t): void {
	$normalizer=new PanelEditorCallbackNormalizer('trim', static fn(string $content): string => trim($content), ['api_token'=>'nope','theme'=>'dark']);
	$upload=PanelEditorUpload::make('media', '/panel/editor/upload')->accept(['image/*','.png'])->detectUsing(static fn(): string => 'image/png');
	$profile=PanelEditorProfile::make('article', 'rich_text')->normalizer($normalizer)->uploadAdapter($upload)->plugin(PanelEditorPlugin::make('mentions')->options(['secret'=>'hidden','trigger'=>'@']));
	$json=(string)json_encode($profile->manifest(), JSON_UNESCAPED_SLASHES);
	$t->notContains('nope', $json);
	$t->notContains('hidden', $json);
	$t->notContains('Closure', $json);
	$t->notContains('api_token', $json);
	$t->contains('"theme":"dark"', $json);
	$t->contains('"trigger":"@"', $json);
	$redacted=PanelEditorManifest::sanitize(['safe'=>'yes','password'=>'bad','clientSecret'=>'bad','callback'=>'system','nested'=>['authorization'=>'bad']]);
	$t->same('yes', $redacted['safe'] ?? null);
	$t->same(false, array_key_exists('password', $redacted));
	$t->same(false, array_key_exists('authorization', $redacted['nested'] ?? []));
	$t->same(false, array_key_exists('clientSecret', $redacted));
	$t->same(false, array_key_exists('callback', $redacted));
	$t->same(['first','last'], PanelEditorManifest::sanitize(['first', static fn(): string => 'hidden', 'last']));
	$rehydrated=PanelEditorProfile::fromArray($profile->manifest());
	$t->same(false, $rehydrated->process('<p>Safe</p>', PanelEditorContext::make('body', 'rich_text'))->valid());
	$t->same(false, $rehydrated->upload()?->ready());
	$t->same(false, PanelEditorProfile::fromArray($rehydrated->manifest())->process('<p>Safe</p>', PanelEditorContext::make('body', 'rich_text'))->valid());
})->tag('panel','editor','manifest','security')->maxMillis(1000);

test('editor upload adapter validates readiness names size MIME and extension', static function(Context $t): void {
	$context=PanelEditorContext::make('body', 'rich_text', 'plain', 'upload');
	$adapter=PanelEditorUpload::make('media', '/panel/editor/upload')->accept(['image/*','.md'])->maxBytes(100)
		->detectUsing(static fn(array $upload): string => (string)($upload['type'] ?? ''));
	$t->same(true, $adapter->ready());
	$t->same(true, $adapter->validateUpload(['name'=>'hero.png','type'=>'image/png','size'=>90,'error'=>0], $context)->valid());
	$t->same(true, $adapter->validateUpload(['name'=>'readme.md','type'=>'text/plain','size'=>10,'error'=>0], $context)->valid());
	$t->same(false, $adapter->validateUpload(['name'=>'payload.exe','type'=>'application/octet-stream','size'=>10,'error'=>0], $context)->valid());
	$t->same(false, $adapter->validateUpload(['name'=>'hero.png','type'=>'image/png','size'=>101,'error'=>0], $context)->valid());
	$t->same(false, $adapter->validateUpload(['name'=>'../hero.png','type'=>'image/png','size'=>10,'error'=>0], $context)->valid());
	$t->same(false, PanelEditorUpload::make('bad', 'javascript:alert(1)')->ready());
	$t->same(false, PanelEditorUpload::make('bad', 'https://user:secret@example.test/upload')->ready());
	$t->same(false, PanelEditorUpload::make('bad', '/panel/upload?api_key=secret')->ready());
	$t->same(false, PanelEditorUpload::make('bad', '/panel/upload?to%6ben=secret')->ready());
	$t->same(false, PanelEditorUpload::make('bad', '/panel/%2e%2e/upload')->accept('image/png')->ready());
	$t->same(false, PanelEditorUpload::make('bad', '/panel/upload?safe[to%256ben]=secret')->accept('image/png')->ready());
	$t->same(true, PanelEditorUpload::make('safe_query', '/panel/upload?collection=images')->accept('image/png')->ready());
	$deepQueryKey='to%6ben';
	for($pass=0;$pass<6;$pass++){ $deepQueryKey=rawurlencode($deepQueryKey); }
	$t->same(false, PanelEditorUpload::make('deep_query', '/panel/upload?'.$deepQueryKey.'=secret')->accept('image/png')->ready());
	$t->same(false, PanelEditorUpload::make('missing_policy', '/panel/upload')->ready());
	$t->same(false, PanelEditorUpload::make('dangerous', '/panel/upload')->accept('.php')->detectUsing(static fn(): string => 'text/plain')->validateUpload(['name'=>'shell.php','size'=>1,'error'=>0], $context)->valid());
	$t->same(false, PanelEditorUpload::make('dangerous_mime', '/panel/upload')->accept('image/*')->detectUsing(static fn(): string => 'image/svg+xml')->validateUpload(['name'=>'image.bin','size'=>1,'error'=>0], $context)->valid());
	$t->same(false, PanelEditorUpload::make('bad_detector', '/panel/upload')->accept('image/*')->detectUsing(static fn(): string => '')->validateUpload(['name'=>'image.png','size'=>1,'error'=>0], $context)->valid());
	$field=Field::make('body')->richText()->editorUploadAdapter($adapter);
	$t->same(true, $field->validateEditorUpload(['name'=>'hero.png','type'=>'image/png','size'=>90,'error'=>0])->valid());
	$t->same(false, Field::make('body')->richText()->validateEditorUpload([])->valid());
})->tag('panel','editor','upload','security')->maxMillis(1000);

test('editor media upload syntax and profile adapters cover runtime and rehydration boundaries', static function(Context $t): void {
	$context=PanelEditorContext::make('body', 'rich_text', 'plain', 'upload');
	$declared=PanelEditorMedia::fromArray([
		'name'=>'cdn', 'prefixes'=>['/uploads/'], 'hosts'=>['cdn.example.test'],
		'schemes'=>['https'], 'enabled'=>true, 'runtime'=>'resolver',
	]);
	$t->same(false, $declared->ready());
	$media=PanelEditorMedia::make('cdn')
		->allowHosts(['CDN.EXAMPLE.TEST.', 'bad host'])
		->allowSchemes(['https','javascript'])
		->resolveUsing(static fn(string $url): ?string => str_contains($url, 'reject') ? null : 'https://cdn.example.test'.$url);
	$t->same('https://cdn.example.test/hero.png', $media->normalizeReference('/hero.png', $context));
	$t->same(null, $media->normalizeReference('/reject.png', $context));
	$unsafeResolver=PanelEditorMedia::make('unsafe_resolver')->allowPrefixes('/uploads/')->resolveUsing(static fn(): string => '/uploads/../private.png');
	$t->same(null, $unsafeResolver->normalizeReference('/candidate.png', $context));
	$t->same(false, $media->enabled(false)->ready());
	$t->same($media->manifest(), $media->jsonSerialize());

	$detected=PanelEditorUpload::make('detected', '/panel/editor/upload')
		->accept('image/png')
		->detectUsing(static fn(): string => 'image/png');
	$t->same(true, $detected->validateUpload(['name'=>'hero.png','type'=>'application/octet-stream','size'=>10,'error'=>0], $context)->valid());
	$exact=PanelEditorUpload::make('exact', '/panel/editor/upload')->accept('text/plain')->detectUsing(static fn(): string => 'text/plain');
	$t->same(true, $exact->validateUpload(['name'=>'notes.txt','type'=>'text/plain','size'=>10,'error'=>0], $context)->valid());
	$workspace=$t->workspace('panel-editor-upload');
	$file=$workspace->file('sample.txt', 'plain editor upload');
	$automatic=PanelEditorUpload::make('automatic', '/panel/editor/upload')->accept(['text/plain','application/octet-stream']);
	$t->same(class_exists(\finfo::class), $automatic->validateUpload(['name'=>'sample.txt','tmp_name'=>$file,'type'=>'application/octet-stream','size'=>1,'error'=>0], $context)->valid());
	$t->same($exact->manifest(), $exact->jsonSerialize());

	$syntax=PanelEditorSyntaxHighlighter::make('tokens', static fn(string $code): array => [['type'=>'keyword','text'=>$code]], 'html');
	$t->contains('&lt;tag&gt;', $syntax->highlightHtml('<tag>', 'html', $context));
	$unavailable=PanelEditorSyntaxHighlighter::unavailable(['name'=>'remote','languages'=>['php'],'options'=>['theme'=>'dark']]);
	$t->same(false, $unavailable->ready());
	$t->same($unavailable->manifest(), $unavailable->jsonSerialize());
	$wildcard=PanelEditorSyntaxHighlighter::make('wildcard', static fn(string $code): array => [['type'=>'plain','text'=>$code]], '*');
	$t->same(true, $wildcard->supports('rust'));
	$sameLengthMutation=PanelEditorSyntaxHighlighter::make('mutated', static fn(): array => [['type'=>'plain','text'=>'xxxxx']], 'plain');
	$t->same([['type'=>'plain','text'=>'abcde']], $sameLengthMutation->tokens('abcde', 'plain', $context));

	$blockedSanitizer=new PanelEditorHtmlSanitizer(false);
	$profile=PanelEditorProfile::make('complete', 'html')
		->sanitizer($blockedSanitizer)
		->plugins([PanelEditorPlugin::make('mentions'), 'ignored']);
	$t->same('complete', $profile->name());
	$t->same(true, $profile->policy() instanceof PanelEditorSanitizationPolicy);
	$t->same($blockedSanitizer, $profile->sanitizerAdapter());
	$t->same(false, $profile->process('<p>blocked</p>', $context)->valid());
	$t->same($profile->manifest(), $profile->toArray());
	$t->same($profile->manifest(), $profile->jsonSerialize());
	$missingValidator=PanelEditorProfile::fromArray(['name'=>'remote','mode'=>'plain','validators'=>[['name'=>'server_policy']]]);
	$t->same(false, $missingValidator->process('plain', PanelEditorContext::make('body', 'plain'))->valid());
})->tag('panel','editor','adapters','security','coverage')->maxMillis(1500);

test('syntax adapters expose inert token streams and escape every highlighted byte', static function(Context $t): void {
	$code='<script>alert("x")</script>';
	$syntax=PanelEditorSyntaxHighlighter::make('demo', static fn(string $value): array => [['type'=>'keyword"><img src=x onerror=x>','text'=>$value]], 'html', ['api_key'=>'hidden']);
	$profile=PanelEditorProfile::make('code', 'code')->syntaxAdapter($syntax);
	$html=$profile->highlightHtml($code, 'html', PanelEditorContext::make('source', 'code', 'html'));
	$t->contains('&lt;script&gt;', $html);
	$t->notContains('<script>', $html);
	$t->notContains('<img', $html);
	$t->notContains('hidden', (string)json_encode($profile->manifest()));
	$t->same($html, Field::make('source')->codeEditor('html')->editorSyntaxAdapter($syntax)->highlightEditorCode($code, 'html'));
	$broken=PanelEditorSyntaxHighlighter::make('broken', static fn(): array => [['type'=>'x','text'=>'different']], 'html');
	$t->same([['type'=>'plain','text'=>$code]], $broken->tokens($code, 'html', PanelEditorContext::make('source', 'code', 'html')));
})->tag('panel','editor','syntax','security')->maxMillis(1000);

test('explicit editor profiles round trip and render custom toolbars without changing legacy markup', static function(Context $t): void {
	$renderer=$t->nonPublic(PanelRenderer::class);
	$legacy=Field::make('body')->richText();
	$legacyHtml=$renderer->invoke('fieldControl', 'body', $legacy->toArray(), '<p>Legacy</p>', false);
	$roundTripHtml=$renderer->invoke('fieldControl', 'body', Field::fromArray($legacy->toArray())->toArray(), '<p>Legacy</p>', false);
	$t->same($legacyHtml, $roundTripHtml);
	$t->notContains('data-dp-panel-editor-profile=', $legacyHtml);

	$toolbar=PanelEditorToolbar::make('article')->command('bold', 'Strong', 'inline', 'Make strong')->command('mention', 'Mention', 'insert', 'Mention someone', 'mentions');
	$profile=PanelEditorProfile::make('article', 'rich_text')->toolbar($toolbar)->plugin(PanelEditorPlugin::make('mentions')->commands('mention'));
	$field=$legacy->editorProfile($profile);
	$manifest=$field->toArray();
	$html=$renderer->invoke('fieldControl', 'body', $manifest, '<script>x()</script><p>Article</p>', false);
	$t->contains('data-dp-panel-editor-profile=', $html);
	$t->contains('data-dp-panel-editor-command="mention"', $html);
	$t->contains('data-dp-panel-editor-plugin="mentions"', $html);
	$t->notContains('data-dp-panel-editor-command="undo"', $html);
	$t->notContains('<script>', $html);
	$mismatchedManifest=$manifest;
	$mismatchedManifest['meta']['editor_profile']['mode']='plain';
	$mismatchedHtml=$renderer->invoke('fieldControl', 'body', $mismatchedManifest, '<script>mismatch()</script><p>Article</p>', false);
	$t->notContains('<script>', $mismatchedHtml);
	$rehydrated=Field::fromArray($manifest)->toArray();
	$t->same('article', $rehydrated['meta']['editor_profile']['name'] ?? null);
})->tag('panel','editor','renderer','compatibility')->maxMillis(1500);

test('editor and asset runtimes publish isolated extension hooks without executable markup', static function(Context $t): void {
	$asset=PanelRenderer::assetContent('panel-editor.js');
	$js=(string)($asset['content'] ?? '');
	foreach(['dp-panel-editor:command','dp-panel-editor:highlight','dp-panel-editor:insert','dp-panel-editor:ready','dpPanelEditorProfile','DataphyrePanelEditors','registerSurface','registerSyntax','createTiptapBridge','createCodeMirror6Bridge','adapter-unmounted','stale_mount','page_cache'] as $needle){ $t->contains($needle, $js); }
	$t->contains('span.textContent=token.text', $js);
	$t->notContains('document.addEventListener(', $js);
	$t->contains('joined!==value', $js);
	$t->contains('var states=new WeakMap()', $js);
	$t->contains('var ownedEditors=new Set()', $js);
	$t->contains('source.dispatchEvent(new Event("input",{bubbles:true}))', $js);
	$t->contains('event.formData.set(source.name,source.value)', $js);
	$t->contains('surfaceBridges.set("tinymce",tinyMceBridge())', $js);
	$t->contains('surfaceBridges.set("ckeditor5",ckEditor5Bridge())', $js);
	$t->contains('surfaceBridges.set("monaco",monacoBridge())', $js);
	$t->contains('syntaxBridges.set("prism",prismBridge())', $js);
	$t->contains('syntaxBridges.set("highlightjs",highlightJsBridge())', $js);
	$t->notContains('span.innerHTML=token.text', $js);
	$t->notContains('eval(', $js);
	$t->notContains('new Function(', $js);
	$t->notContains('registerAssets', $js);
	$assetBrowser=(string)(PanelRenderer::assetContent('panel-editor-assets.js')['content'] ?? '');
	foreach(['registerAssets','unregisterAssets','openAssets','closeAssets','assetState','createHttpAssetBridge','response_too_large','verification_missing','safeAssetUrl'] as $needle){ $t->contains($needle, $assetBrowser); }
	$t->contains('title.textContent=asset.name', $assetBrowser);
	$t->contains('grid.appendChild(assetCard', $assetBrowser);
	$t->contains('url.protocol!=="https:"', $assetBrowser);
	$t->contains('image.setAttribute("src",asset.url)', $assetBrowser);
	$t->contains('var cleanups=[]', $assetBrowser);
	$t->notContains('document.addEventListener(', $assetBrowser);
	$t->notContains('eval(', $assetBrowser);
	$t->notContains('new Function(', $assetBrowser);
	$core=(string)(PanelRenderer::assetContent('panel.js')['content'] ?? '');
	$t->contains('dpPanelEditorInitProfile', $core);
	$t->contains('window.DataphyrePanelEditors.sync(editor)', $core);
	$t->contains('dpPanelEditorAllowMediaReference', $core);
	$t->contains('dpPanelEditorSanitizeRichHtml', $core);
	$t->contains('window.DataphyrePanel.editorRuntime={', $core);
	$t->contains('allowMediaReference:dpPanelEditorAllowMediaReference', $core);
	$t->contains('sanitizeRichHtml:dpPanelEditorSanitizeRichHtml', $core);
	$t->contains('allowMediaUrl', $core);
	$t->contains('allowed.IMG=1', $core);
	$t->contains('node.querySelector("br,hr,img")', $core);
	$css=(string)(PanelRenderer::assetContent('panel.css')['content'] ?? '');
	$t->contains('.dp-panel-editor-assets-dialog', $css);
	$t->contains('.dp-panel-editor-assets-grid', $css);
	$t->contains('@media(max-width:220px)', $css);
	$t->contains('@media(forced-colors:active)', $css);
	$t->contains('@media(prefers-reduced-motion:reduce)', $css);
	$editorCss=(string)(PanelRenderer::assetContent('panel.css', ['editor'])['content'] ?? '');
	$assetCss=(string)(PanelRenderer::assetContent('panel.css', ['editor-assets'])['content'] ?? '');
	$t->notContains('.dp-panel-editor-assets-dialog', $editorCss);
	$t->contains('.dp-panel-editor-assets-dialog', $assetCss);
})->tag('panel','editor','runtime','security')->maxMillis(1500);
