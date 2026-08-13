<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelDeveloperToolkit;
use Dataphyre\Panel\PanelBrowserRegressionManifest;
use Dataphyre\Panel\PanelInclusiveQualityMatrix;
use Dataphyre\Panel\PanelQualityCapabilityReport;
use Dataphyre\Panel\PanelQualityEvidence;
use Dataphyre\Panel\PanelQualityMatrix;
use Dataphyre\Panel\PanelRegressionSuite;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_quality_profile(array $replace=[]): array {
	return array_replace(['id'=>'en_test','locale'=>'en-CA','script'=>'Latn','direction'=>'ltr','timezone'=>'America/Toronto','numbering_system'=>'latn','calendar'=>'gregory','plural_categories'=>['one','other'],'long_text_factor'=>1.25,'pseudo_locale'=>false,'representative'=>true],$replace);
}

/** @return array<string,mixed> */
function dp_panel_quality_contract(array $replace=[]): array {
	return array_replace(['id'=>'keyboard','label'=>'Keyboard','domain'=>'input','execution'=>'browser','automation'=>'fully_automated','locale_scope'=>'all','locales'=>[],'required_capabilities'=>['browser.dom'],'proves'=>['keyboard behavior'],'does_not_prove'=>[],'settings'=>[],'max_millis'=>1000],$replace);
}

test('inclusive quality defaults are deterministic versioned and honest about automated and declared cases',static function(Context $t):void {
	$matrix=PanelInclusiveQualityMatrix::make('Panel inclusive','/panel');
	$payload=$matrix->jsonSerialize();
	$t->same('panel_inclusive_quality_matrix',$payload['type']);
	$t->same(1,$payload['version']);
	$t->same(12,count($matrix->profiles()));
	$t->same(28,count($matrix->contracts()));
	$t->same(126,$payload['case_count']);
	$t->same(78,$payload['automated_case_count']);
	$t->same(48,$payload['declared_case_count']);
	$t->same(78,count($matrix->browserManifests()));
	$t->same(64,strlen($matrix->digest()));
	$t->same($matrix->digest(),$payload['digest']);
	$t->isTrue(strlen(json_encode($payload,JSON_THROW_ON_ERROR))<16*1024*1024);
	$t->same('browser',$payload['browser_manifests'][0]['meta']['quality_contract']['execution']);
	$proxy=array_values(array_filter($payload['browser_manifests'],static fn(array $manifest):bool=>($manifest['meta']['quality_contract']['id'] ?? '')==='screen_reader_semantics_proxy'))[0];
	$t->same('automated_proxy',$proxy['meta']['automation_claim']);
	$t->contains('NVDA',$proxy['meta']['quality_contract']['does_not_prove'][0] ?? '');
	$t->same($payload['digest'],PanelInclusiveQualityMatrix::fromArray($payload)->digest());
	$reordered=$payload;
	$reordered['profiles']=array_reverse($reordered['profiles']);
	$reordered['contracts']=array_reverse($reordered['contracts']);
	unset($reordered['digest'],$reordered['case_count'],$reordered['automated_case_count'],$reordered['declared_case_count'],$reordered['browser_manifests']);
	$t->same($matrix->digest(),PanelInclusiveQualityMatrix::fromArray($reordered)->digest());
	$suite=$matrix->register(PanelRegressionSuite::make('inclusive'));
	$t->same(78,$suite->manifest()['browser_count']);
	$t->same($matrix->digest(),$suite->manifest()['meta']['inclusive_quality_digest']);
	$t->same(126,count(PanelQualityMatrix::inclusive('Panel inclusive','/panel')->cases()));
	$t->same(126,count(PanelDeveloperToolkit::inclusiveQualityMatrix('Panel inclusive','/panel')->cases()));
	$t->same('panel_inclusive',$matrix->name());
	$t->same('/panel',$matrix->url());
	$t->same(512,$matrix->budgets()['max_cases']);
})->tag('panel','quality','locale','accessibility')->maxMillis(3000);

test('inclusive matrix validates locale scripts direction time number date plural long text and pseudo profiles',static function(Context $t):void {
	$profiles=[
		dp_panel_quality_profile(),
		dp_panel_quality_profile(['id'=>'ar_pseudo','locale'=>'ar-XB','script'=>'Arab','direction'=>'rtl','timezone'=>'UTC','numbering_system'=>'arab','plural_categories'=>['zero','one','two','few','many','other'],'long_text_factor'=>2.0,'pseudo_locale'=>true,'representative'=>false]),
	];
	$contracts=[
		dp_panel_quality_contract(['id'=>'all']),
		dp_panel_quality_contract(['id'=>'representative','locale_scope'=>'representative']),
		dp_panel_quality_contract(['id'=>'pseudo','automation'=>'automated_proxy','locale_scope'=>'pseudo','does_not_prove'=>['translation quality']]),
		dp_panel_quality_contract(['id'=>'listed','locale_scope'=>'list','locales'=>['ar_pseudo']]),
		dp_panel_quality_contract(['id'=>'php_locale','domain'=>'locale','execution'=>'php','automation'=>'fully_automated','required_capabilities'=>['php.intl']]),
		dp_panel_quality_contract(['id'=>'adapter_check','domain'=>'assistive_technology','execution'=>'adapter','automation'=>'adapter','required_capabilities'=>['adapter.device']]),
		dp_panel_quality_contract(['id'=>'manual_check','domain'=>'assistive_technology','execution'=>'manual','automation'=>'manual','required_capabilities'=>[]]),
	];
	$matrix=PanelInclusiveQualityMatrix::make('custom','https://example.test/panel',['profiles'=>$profiles,'contracts'=>$contracts,'budgets'=>['max_cases'=>'20','max_evidence'=>20,'minimum_assertions'=>2]]);
	$t->same(11,count($matrix->cases()));
	$t->same(5,count($matrix->browserManifests()));
	$t->same(20,$matrix->budgets()['max_cases']);
	$t->same(2,$matrix->budgets()['minimum_assertions']);
	$t->same('quality_environment',$matrix->browserManifests()[0]->toArray()['interactions'][0]['type']);
	$t->same('quality_contract',$matrix->browserManifests()[0]->toArray()['interactions'][1]['type']);
	$rtlManifest=array_values(array_filter($matrix->browserManifests(),static fn($manifest):bool=>($manifest->toArray()['meta']['quality_profile']['id'] ?? '')==='ar_pseudo'))[0];
	$t->same('rtl',$rtlManifest->toArray()['meta']['quality_profile']['direction']);
	$collisionSafe=PanelInclusiveQualityMatrix::make('tuple','/panel',['profiles'=>[dp_panel_quality_profile(['id'=>'a.b']),dp_panel_quality_profile(['id'=>'a'])],'contracts'=>[dp_panel_quality_contract(['id'=>'c']),dp_panel_quality_contract(['id'=>'b.c'])]]);
	$t->same(4,count(array_unique(array_column($collisionSafe->cases(),'id'))));
	$t->isTrue(in_array('tuple.p3.a.b.c1.c',array_column($collisionSafe->cases(),'id'),true));
	$t->isTrue(in_array('tuple.p1.a.c3.b.c',array_column($collisionSafe->cases(),'id'),true));
})->tag('panel','quality','matrix')->maxMillis(1000);

test('inclusive matrix fails closed on invalid empty oversized or misleading declarations',static function(Context $t):void {
	$profile=dp_panel_quality_profile(); $contract=dp_panel_quality_contract();
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make(str_repeat('x',65),'/panel'),LengthException::class);
	foreach(['','javascript:alert(1)',"/bad\npath",'/'.str_repeat('x',2049),"/\xB1"] as $url){ $t->throws(static fn()=>PanelInclusiveQualityMatrix::make('bad',$url),InvalidArgumentException::class); }
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('empty','/panel',['profiles'=>[]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('empty','/panel',['contracts'=>[]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('shape','/panel',['profiles'=>['bad'],'contracts'=>[$contract]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('shape','/panel',['profiles'=>[$profile],'contracts'=>['bad']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('wide','/panel',['profiles'=>array_fill(0,33,$profile),'contracts'=>[$contract]]),LengthException::class);
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('wide','/panel',['profiles'=>[$profile],'contracts'=>array_fill(0,65,$contract)]),LengthException::class);
	foreach([
		['id'=>''],['id'=>'en_test'],['locale'=>'not_a_locale'],['script'=>'latin'],['direction'=>'down'],['timezone'=>'Mars/Olympus'],['numbering_system'=>'!'],['calendar'=>'!'],['plural_categories'=>[]],['plural_categories'=>['invalid']],['long_text_factor'=>INF],['long_text_factor'=>0.9],['long_text_factor'=>4.1],['unknown_profile_key'=>true]
	] as $replace){ $candidate=$profile; if(($replace['id'] ?? null)==='en_test'){ $profiles=[$profile,$profile]; }else{ $profiles=[array_replace($candidate,$replace)]; } $t->throws(static fn()=>PanelInclusiveQualityMatrix::make('bad','/panel',['profiles'=>$profiles,'contracts'=>[$contract]]),InvalidArgumentException::class); }
	foreach([
		['id'=>''],['id'=>'keyboard'],['domain'=>'unknown'],['execution'=>'robot'],['automation'=>'robot'],['execution'=>'manual','automation'=>'fully_automated'],['id'=>'screen_reader_nvda','execution'=>'browser','automation'=>'fully_automated'],['id'=>'custom_at_claim','domain'=>'assistive_technology','execution'=>'browser','automation'=>'fully_automated'],['automation'=>'automated_proxy','does_not_prove'=>[]],['proves'=>[]],['required_capabilities'=>array_map(static fn(int $i):string=>'cap.'.$i,range(1,17))],['locale_scope'=>'unknown'],['locale_scope'=>'list','locales'=>[]],['locale_scope'=>'list','locales'=>['missing']],['locales'=>['en_test']],['max_millis'=>0],['max_millis'=>300001],['settings'=>['bad'=>new stdClass()]],['label'=>"bad\xB1"],['unknown_contract_key'=>true]
	] as $replace){ $contracts=($replace['id'] ?? null)==='keyboard'?[$contract,$contract]:[array_replace($contract,$replace)]; $t->throws(static fn()=>PanelInclusiveQualityMatrix::make('bad','/panel',['profiles'=>[$profile],'contracts'=>$contracts]),InvalidArgumentException::class); }
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('budget','/panel',['profiles'=>[$profile],'contracts'=>[$contract],'budgets'=>['unknown'=>1]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('options','/panel',['unknown'=>true]),InvalidArgumentException::class);
	foreach([['max_cases'=>0],['max_cases'=>2049],['max_cases'=>'one'],['minimum_assertions'=>0],['max_automated_missing'=>-1]] as $budget){ $t->throws(static fn()=>PanelInclusiveQualityMatrix::make('budget','/panel',['profiles'=>[$profile],'contracts'=>[$contract],'budgets'=>$budget]),InvalidArgumentException::class); }
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('overflow','/panel',['profiles'=>[$profile,dp_panel_quality_profile(['id'=>'two'])],'contracts'=>[$contract],'budgets'=>['max_cases'=>1]]),OverflowException::class);
	$payload=PanelInclusiveQualityMatrix::make('roundtrip','/panel',['profiles'=>[$profile],'contracts'=>[$contract]])->jsonSerialize();
	$payload['digest']=str_repeat('0',64); $t->throws(static fn()=>PanelInclusiveQualityMatrix::fromArray($payload),UnexpectedValueException::class);
	$payload['type']='other'; $t->throws(static fn()=>PanelInclusiveQualityMatrix::fromArray($payload),InvalidArgumentException::class);
	$payload['type']='panel_inclusive_quality_matrix'; $payload['version']=2; $t->throws(static fn()=>PanelInclusiveQualityMatrix::fromArray($payload),InvalidArgumentException::class);
	$payload=PanelInclusiveQualityMatrix::make('strict','/panel',['profiles'=>[$profile],'contracts'=>[$contract]])->jsonSerialize(); $payload['unknown_top_key']=true; $t->throws(static fn()=>PanelInclusiveQualityMatrix::fromArray($payload),InvalidArgumentException::class);
})->tag('panel','quality','security')->maxMillis(3000);

test('inclusive matrix bounds its repeated browser serialization at declared maxima',static function(Context $t):void {
	$profiles=[]; for($index=0;$index<32;$index++){ $profiles[]=dp_panel_quality_profile(['id'=>'p'.$index]); }
	$contracts=[]; for($index=0;$index<64;$index++){ $contracts[]=dp_panel_quality_contract(['id'=>'c'.$index]); }
	$maxCases=PanelInclusiveQualityMatrix::make('max_cases','/panel',['profiles'=>$profiles,'contracts'=>$contracts,'budgets'=>['max_cases'=>2048,'max_evidence'=>2048]]);
	$t->same(2048,count($maxCases->cases()));
	$largeSettings=array_fill(0,128,str_repeat('x',4096)); $largeContracts=[];
	for($index=0;$index<17;$index++){ $largeContracts[]=dp_panel_quality_contract(['id'=>'large'.$index,'settings'=>$largeSettings]); }
	$maxSerialized=PanelInclusiveQualityMatrix::make('max_serialized','/panel',['profiles'=>[dp_panel_quality_profile()],'contracts'=>$largeContracts,'budgets'=>['max_cases'=>32,'max_evidence'=>32]]);
	$t->throws(static fn()=>$maxSerialized->browserManifests(),OverflowException::class);
})->tag('panel','quality','bounds','security')->maxMillis(5000);

test('inclusive settings and proof data enforce recursive JSON and list budgets',static function(Context $t):void {
	$profile=dp_panel_quality_profile(); $contract=dp_panel_quality_contract();
	$tooDeep=true; for($index=0;$index<10;$index++){ $tooDeep=[$tooDeep]; }
	foreach([['settings'=>$tooDeep],['settings'=>array_fill(0,129,true)],['settings'=>[str_repeat('k',129)=>true]],['settings'=>['value'=>INF]],['settings'=>['value'=>str_repeat('x',4097)]],['settings'=>["\xB1"]]] as $replace){ $t->throws(static fn()=>PanelInclusiveQualityMatrix::make('json','/panel',['profiles'=>[$profile],'contracts'=>[array_replace($contract,$replace)]]),Throwable::class); }
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('ids','/panel',['profiles'=>[$profile],'contracts'=>[array_replace($contract,['locales'=>array_map(static fn(int $i):string=>'id'.$i,range(1,33))])]]),LengthException::class);
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('proofs','/panel',['profiles'=>[$profile],'contracts'=>[array_replace($contract,['proves'=>array_map(static fn(int $i):string=>'proof '.$i,range(1,33))])]]),LengthException::class);
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('proofs','/panel',['profiles'=>[$profile],'contracts'=>[array_replace($contract,['proves'=>[new stdClass()]])]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelInclusiveQualityMatrix::make('proofs','/panel',['profiles'=>[$profile],'contracts'=>[array_replace($contract,['proves'=>[str_repeat('x',1025)]])]]),InvalidArgumentException::class);
})->tag('panel','quality','bounds')->maxMillis(1000);

test('legacy quality matrix keeps associative axes and rejects nonfinite JSON numbers',static function(Context $t):void {
	$matrix=PanelQualityMatrix::make('associative','/panel',['viewport'=>['width'=>800,'height'=>600]]);
	$t->same(800,$matrix->jsonSerialize()['manifests'][0]['viewport']['width']);
	$t->same(1,$matrix->jsonSerialize()['version']);
	$t->throws(static fn()=>PanelQualityMatrix::make('inf','/panel',['custom'=>[INF]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelQualityMatrix::make('nan','/panel',['custom'=>[NAN]]),InvalidArgumentException::class);
	$t->same('/panel/orders?status=open#table',PanelBrowserRegressionManifest::normalizeUrl('/panel/orders?status=open#table'));
	$t->same('https://example.test/panel',PanelBrowserRegressionManifest::make('absolute','https://example.test/panel')->url());
	foreach(['',' ',' /panel','/panel ','//example.test/panel','\\panel','#only','orders','javascript:alert(1)','data:text/html,x','file:///tmp/panel','https://user:pass@example.test/panel','https:///missing-host',"/panel\nnext",'/'.str_repeat('x',2048),"/panel\xB1"] as $url){
		$t->throws(static fn()=>PanelBrowserRegressionManifest::make('unsafe',$url),InvalidArgumentException::class);
		$t->throws(static fn()=>PanelQualityMatrix::make('unsafe',$url),InvalidArgumentException::class);
	}
})->tag('panel','quality','compatibility')->maxMillis(1000);
