<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelReleaseEvidenceArtifact;
use Dataphyre\Panel\PanelReleaseEvidenceBundle;
use Dataphyre\Panel\PanelReleaseEvidenceCli;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_release_evidence_context():array {
	return [
		'source_digest'=>str_repeat('a',64),
		'contract_digest'=>str_repeat('b',64),
		'release_digest'=>str_repeat('c',64),
		'matrix_digests'=>['inclusive'=>str_repeat('d',64),'responsive'=>str_repeat('e',64)],
		'runner'=>['id'=>'panel-release-gate','version'=>'1.4.0','channel'=>'ci','browser'=>'Chromium 140'],
		'environment'=>['os'=>'ubuntu-24.04','php'=>'8.4','viewport_set'=>'desktop-tablet-mobile'],
		'capabilities'=>['browser.accessibility','browser.layout','php.coverage'],
	];
}

/** @return list<array<string,mixed>> */
function dp_panel_release_evidence_claims():array {
	return [
		['id'=>'interaction','status'=>'passed','execution'=>'browser','assertions'=>49,'report_path'=>'browser/interaction.json','capabilities'=>['browser.layout'],'notes'=>'Native browser contract passed.'],
		['id'=>'coverage','status'=>'passed','execution'=>'php','assertions'=>1200,'report_path'=>'php/coverage.json','capabilities'=>['php.coverage']],
	];
}

/** @return array{root:string,key:string,bundle:PanelReleaseEvidenceBundle} */
function dp_panel_release_evidence_fixture(Context $t,bool $strict=true):array {
	$root=$t->tempDirectory('panel-release-evidence');
	@mkdir($root.'/browser',0777,true);@mkdir($root.'/php',0777,true);
	file_put_contents($root.'/browser/interaction.json',"{\"summary\":{\"passed\":49}}\n");
	file_put_contents($root.'/php/coverage.json',"{\"coverage\":100}\n");
	$key='panel-release-evidence-test-key-material-v1';
	$bundle=PanelReleaseEvidenceBundle::issue($root,['php/coverage.json','browser/interaction.json'],dp_panel_release_evidence_context(),dp_panel_release_evidence_claims(),'quality-v1',$key,1_800_000_000,3600,'run-ci-20260716-001',$strict);
	return ['root'=>$root,'key'=>$key,'bundle'=>$bundle];
}

test('release evidence signs exact artifacts source contract matrices runner and expiry',static function(Context $t):void {
	$fixture=dp_panel_release_evidence_fixture($t);$bundle=$fixture['bundle'];$payload=$bundle->jsonSerialize();
	$t->same('panel_release_evidence_bundle',$payload['type']);$t->same(1,$payload['version']);$t->same('run-ci-20260716-001',$bundle->runId());
	$t->same(64,strlen($bundle->digest()));$t->same($bundle->digest(),$payload['integrity']['digest']);$t->same('hmac-sha256',$payload['integrity']['algorithm']);$t->same('quality-v1',$payload['integrity']['key_id']);
	$t->same(['browser/interaction.json','php/coverage.json'],array_column($payload['artifacts'],'path'));
	$t->same($payload['artifacts'][0]['sha256'],PanelReleaseEvidenceArtifact::fromArray($payload['artifacts'][0])->sha256());
	$t->same(['coverage','interaction'],array_column($payload['claims'],'id'));
	$t->same($payload['artifacts'][0]['sha256'],$payload['claims'][1]['report_sha256']);
	$t->same($payload['artifacts'][1]['sha256'],$payload['claims'][0]['report_sha256']);
	$t->same(str_repeat('a',64),$bundle->context()['source_digest']);$t->same(str_repeat('b',64),$bundle->context()['contract_digest']);
	$t->notContains($fixture['root'],json_encode($payload,JSON_THROW_ON_ERROR));$t->notContains($fixture['key'],json_encode($payload,JSON_THROW_ON_ERROR));
	$roundTrip=PanelReleaseEvidenceBundle::fromArray(json_decode(json_encode($payload,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));$t->same($payload,$roundTrip->jsonSerialize());
	$seen=null;$verification=$roundTrip->verify($fixture['root'],['quality-v1'=>$fixture['key']],[
		'source_digest'=>str_repeat('a',64),'contract_digest'=>str_repeat('b',64),'release_digest'=>str_repeat('c',64),'matrix_digests'=>['inclusive'=>str_repeat('d',64),'responsive'=>str_repeat('e',64)],'run_id'=>'run-ci-20260716-001',
	],1_800_000_010,0,static function(array $receipt)use(&$seen):bool{$seen=$receipt;return true;});
	$t->isTrue($verification->passed());$t->same([],$verification->failures());$t->same($bundle->digest(),$verification->bundleDigest());$t->same(2,$verification->jsonSerialize()['artifact_count']);$t->same(2,$verification->jsonSerialize()['verified_artifacts']);$t->same(64,strlen($verification->replayKey()));$t->same($verification->replayKey(),$seen['replay_key']);$verification->assertPassed();
})->tag('panel','release','evidence','security')->maxMillis(2000);

test('release evidence fails closed for modified missing extra and linked artifacts',static function(Context $t):void {
	$fixture=dp_panel_release_evidence_fixture($t);$expected=['source_digest'=>str_repeat('a',64),'contract_digest'=>str_repeat('b',64)];
	file_put_contents($fixture['root'].'/browser/interaction.json',"{\"summary\":{\"passed\":48}}\n");
	$modified=$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$expected,1_800_000_010);$t->isFalse($modified->passed());$t->same(1,count(array_filter($modified->failures(),static fn(string $failure):bool=>str_starts_with($failure,'artifact_mismatch:'))));$t->throws(static fn()=>$modified->assertPassed(),UnexpectedValueException::class);
	file_put_contents($fixture['root'].'/browser/interaction.json',"{\"summary\":{\"passed\":49}}\n");
	file_put_contents($fixture['root'].'/unexpected.log','extra');$extra=$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$expected,1_800_000_010);$t->isTrue(in_array('artifact_tree_mismatch',$extra->failures(),true));unlink($fixture['root'].'/unexpected.log');
	unlink($fixture['root'].'/php/coverage.json');$missing=$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$expected,1_800_000_010);$t->isTrue(count(array_filter($missing->failures(),static fn(string $failure):bool=>str_starts_with($failure,'artifact_mismatch:')))===1);$t->isTrue(in_array('artifact_tree_mismatch',$missing->failures(),true));
	file_put_contents($fixture['root'].'/php/coverage.json',"{\"coverage\":100}\n");
	if(function_exists('symlink')&&@symlink($fixture['root'].'/php/coverage.json',$fixture['root'].'/browser/linked.json')){$linked=$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$expected,1_800_000_010);$t->isTrue(in_array('artifact_tree_unreadable',$linked->failures(),true));}
})->tag('panel','release','evidence','tamper')->maxMillis(2000);

test('release evidence rejects untrusted clocks contexts and replay',static function(Context $t):void {
	$fixture=dp_panel_release_evidence_fixture($t);$base=['source_digest'=>str_repeat('a',64),'contract_digest'=>str_repeat('b',64)];
	$t->isTrue(in_array('signature_untrusted',$fixture['bundle']->verify($fixture['root'],['quality-v1'=>'wrong-key-material-that-is-still-long-enough'],$base,1_800_000_010)->failures(),true));
	$t->isTrue(in_array('signature_untrusted',$fixture['bundle']->verify($fixture['root'],[],$base,1_800_000_010)->failures(),true));
	$t->isTrue(in_array('not_yet_valid',$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$base,1_799_999_990)->failures(),true));
	$t->isTrue(in_array('expired',$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$base,1_800_003_601)->failures(),true));
	$t->isTrue($fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$base,1_799_999_990,10)->passed());
	foreach([
		['source_digest'=>str_repeat('f',64),'contract_digest'=>str_repeat('b',64)],
		['source_digest'=>str_repeat('a',64),'contract_digest'=>str_repeat('f',64)],
		$base+['release_digest'=>str_repeat('f',64)],
		$base+['matrix_digests'=>['inclusive'=>str_repeat('f',64)]],
		$base+['run_id'=>'different-run'],
	] as $expectation){$t->isFalse($fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$expectation,1_800_000_010)->passed());}
	$t->same(['replay_rejected'],$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$base,1_800_000_010,0,static fn():bool=>false)->failures());
	$t->same(['replay_rejected'],$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$base,1_800_000_010,0,static function():bool{throw new RuntimeException('store offline');})->failures());
	$t->throws(static fn()=>$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],[],1_800_000_010),InvalidArgumentException::class);
	$t->throws(static fn()=>$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$base,0),InvalidArgumentException::class);
	$t->throws(static fn()=>$fixture['bundle']->verify($fixture['root'],['quality-v1'=>$fixture['key']],$base,1_800_000_010,3601),InvalidArgumentException::class);
})->tag('panel','release','evidence','verification')->maxMillis(2000);

test('release evidence hydration rejects structural ambiguity and false automation claims',static function(Context $t):void {
	$fixture=dp_panel_release_evidence_fixture($t);$payload=$fixture['bundle']->jsonSerialize();
	$unknown=$payload;$unknown['surprise']=true;$t->throws(static fn()=>PanelReleaseEvidenceBundle::fromArray($unknown),InvalidArgumentException::class);
	$tree=$payload;$tree['artifact_tree_sha256']=str_repeat('0',64);$t->throws(static fn()=>PanelReleaseEvidenceBundle::fromArray($tree),UnexpectedValueException::class);
	$integrity=$payload;$integrity['integrity']['signature']='bad';$t->throws(static fn()=>PanelReleaseEvidenceBundle::fromArray($integrity),InvalidArgumentException::class);
	$algorithm=$payload;$algorithm['integrity']['algorithm']='none';$t->throws(static fn()=>PanelReleaseEvidenceBundle::fromArray($algorithm),UnexpectedValueException::class);
	$unsorted=$payload;$unsorted['artifacts']=array_reverse($unsorted['artifacts']);$t->throws(static fn()=>PanelReleaseEvidenceBundle::fromArray($unsorted),InvalidArgumentException::class);
	$claim=$payload;$claim['claims'][0]['report_sha256']=str_repeat('0',64);$t->throws(static fn()=>PanelReleaseEvidenceBundle::fromArray($claim),UnexpectedValueException::class);
	$manual=$payload;$manual['claims'][0]['execution']='manual';$t->throws(static fn()=>PanelReleaseEvidenceBundle::fromArray($manual),InvalidArgumentException::class);
	$path=$payload;$path['artifacts'][0]['path']='../escape.json';$t->throws(static fn()=>PanelReleaseEvidenceBundle::fromArray($path),InvalidArgumentException::class);
	$duplicate=$payload;$duplicate['artifacts'][]=$duplicate['artifacts'][0];$t->throws(static fn()=>PanelReleaseEvidenceBundle::fromArray($duplicate),InvalidArgumentException::class);
	$strict=$payload;$strict['strict_tree']='yes';$t->throws(static fn()=>PanelReleaseEvidenceBundle::fromArray($strict),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseEvidenceArtifact::fromArray(['type'=>'panel_release_evidence_artifact','version'=>1,'path'=>'a','bytes'=>0,'sha256'=>str_repeat('a',64),'media_type'=>'text/plain','x'=>1]),InvalidArgumentException::class);
})->tag('panel','release','evidence','schema')->maxMillis(2000);

test('release evidence issuance enforces tree limits paths reports and key strength',static function(Context $t):void {
	$root=$t->tempDirectory('panel-release-evidence-guards');file_put_contents($root.'/report.json','{}');file_put_contents($root.'/extra.json','{}');
	$context=dp_panel_release_evidence_context();$claim=[['id'=>'one','status'=>'passed','execution'=>'browser','assertions'=>1,'report_path'=>'report.json']];$key=str_repeat('k',32);
	$t->throws(static fn()=>PanelReleaseEvidenceBundle::issue($root,['report.json'],$context,$claim,'quality-v1',$key,1_800_000_000,3600,'run-one',true),UnexpectedValueException::class);
	$bundle=PanelReleaseEvidenceBundle::issue($root,['report.json'],$context,$claim,'quality-v1',$key,1_800_000_000,3600,'run-one',false);$t->isTrue($bundle->verify($root,['quality-v1'=>$key],['source_digest'=>str_repeat('a',64),'contract_digest'=>str_repeat('b',64)],1_800_000_010)->passed());
	$t->throws(static fn()=>PanelReleaseEvidenceBundle::issue($root,['../report.json'],$context,$claim,'quality-v1',$key,1_800_000_000,3600,'run-one',false),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseEvidenceBundle::issue($root,['report.json','report.json'],$context,$claim,'quality-v1',$key,1_800_000_000,3600,'run-one',false),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseEvidenceBundle::issue($root,['report.json'],$context,$claim,'quality-v1','weak',1_800_000_000,3600,'run-one',false),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseEvidenceBundle::issue($root,['report.json'],$context,$claim,'quality-v1',$key,1_800_000_000,59,'run-one',false),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseEvidenceBundle::issue($root,['report.json'],$context,[['id'=>'manual','status'=>'passed','execution'=>'manual','assertions'=>1,'report_path'=>'report.json']],'quality-v1',$key,1_800_000_000,3600,'run-one',false),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseEvidenceBundle::issue($root,['report.json'],$context,[['id'=>'missing','status'=>'passed','execution'=>'browser','assertions'=>1,'report_path'=>'missing.json']],'quality-v1',$key,1_800_000_000,3600,'run-one',false),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReleaseEvidenceBundle::issue($root,['report.json'],$context,[['id'=>'thin','status'=>'passed','execution'=>'browser','assertions'=>0,'report_path'=>'report.json']],'quality-v1',$key,1_800_000_000,3600,'run-one',false),InvalidArgumentException::class);
})->tag('panel','release','evidence','guards')->maxMillis(2000);

test('release evidence CLI issues outside the artifact root and verifies independently',static function(Context $t):void {
	$base=$t->tempDirectory('panel-release-evidence-cli');$root=$base.'/artifacts';mkdir($root);file_put_contents($root.'/interaction.json',"{\"summary\":{\"passed\":1}}\n");
	$key=str_repeat('q',40);file_put_contents($base.'/quality.key',$key);
	$context=['source_digest'=>str_repeat('1',64),'contract_digest'=>str_repeat('2',64),'runner'=>['id'=>'native-browser','version'=>'1','channel'=>'release']];
	$issue=['artifacts'=>['interaction.json'],'context'=>$context,'claims'=>[['id'=>'interaction','status'=>'passed','execution'=>'browser','assertions'=>1,'report_path'=>'interaction.json']],'key_id'=>'quality-v2','issued_at'=>1_800_000_000,'ttl'=>600,'run_id'=>'run-cli-001','strict_tree'=>true];
	file_put_contents($base.'/issue.json',json_encode($issue,JSON_THROW_ON_ERROR));
	$result=PanelReleaseEvidenceCli::execute(['tool','issue','--root',$root,'--spec',$base.'/issue.json','--key-file',$base.'/quality.key','--output',$base.'/evidence.json'],$base);
	$t->same(0,$result['exit_code']);$t->isTrue($result['payload']['ok']);$t->same('issue',$result['payload']['mode']);$t->same('run-cli-001',$result['payload']['run_id']);$t->same(1,$result['payload']['artifacts']);$t->isTrue(is_file($base.'/evidence.json'));$t->notContains($key,json_encode($result['payload'],JSON_THROW_ON_ERROR));
	$inline=PanelReleaseEvidenceCli::execute(['tool','issue','--root',$root,'--spec',$base.'/issue.json','--key-file',$base.'/quality.key'],$base);
	$t->same('panel_release_evidence_bundle',$inline['payload']['bundle']['type']);
	file_put_contents($base.'/expected.json',json_encode(['source_digest'=>str_repeat('1',64),'contract_digest'=>str_repeat('2',64),'run_id'=>'run-cli-001'],JSON_THROW_ON_ERROR));
	$verified=PanelReleaseEvidenceCli::execute(['tool','verify','--root='.$root,'--spec='.$base.'/expected.json','--key-file='.$base.'/quality.key','--bundle='.$base.'/evidence.json','--now=1800000010'],$base);
	$t->same(0,$verified['exit_code']);$t->isTrue($verified['payload']['ok']);$t->isTrue($verified['payload']['verification']['passed']);
	file_put_contents($root.'/interaction.json','tampered');$failed=PanelReleaseEvidenceCli::execute(['tool','verify','--root',$root,'--spec',$base.'/expected.json','--key-file',$base.'/quality.key','--bundle',$base.'/evidence.json','--now','1800000010'],$base);$t->same(1,$failed['exit_code']);$t->isFalse($failed['payload']['ok']);
	file_put_contents($root.'/interaction.json',"{\"summary\":{\"passed\":1}}\n");
	$inside=PanelReleaseEvidenceCli::execute(['tool','issue','--root',$root,'--spec',$base.'/issue.json','--key-file',$base.'/quality.key','--output',$root.'/evidence.json'],$base);$t->same(2,$inside['exit_code']);$t->contains('outside',$inside['payload']['message']);
	$t->same(0,PanelReleaseEvidenceCli::execute(['tool','--help'],$base)['exit_code']);
	$t->same('help',$t->nonPublic(PanelReleaseEvidenceCli::class)->invoke('help')['mode']);
	$t->same(2,PanelReleaseEvidenceCli::execute(['tool','issue','--root',$root,'--spec',$base.'/issue.json','--key-file',$base.'/quality.key','--wat','x'],$base)['exit_code']);
	file_put_contents($base.'/weak.key','tiny');$t->same(2,PanelReleaseEvidenceCli::execute(['tool','issue','--root',$root,'--spec',$base.'/issue.json','--key-file',$base.'/weak.key'],$base)['exit_code']);
})->tag('panel','release','evidence','cli')->maxMillis(2000);
