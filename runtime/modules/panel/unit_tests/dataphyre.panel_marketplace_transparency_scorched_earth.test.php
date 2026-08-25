<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelPackageTransparencyLog;
use Dataphyre\Panel\PanelPackageTransparencyMerkle;
use Dataphyre\Panel\PanelPackageTransparencyReceipt;
use Dataphyre\Panel\PanelPackageTransparencyVerifier;
use Dataphyre\Panel\PanelPackageMarketplaceTrustNetwork;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

suite('Panel marketplace transparency contracts')
	->coverageMemoryLimit('2G');

/** @return array{signer:Closure,verifier:Closure,primary:string,witness:string} */
function dp_panel_transparency_authority():array{
	$primaryPair=sodium_crypto_sign_keypair();
	$witnessPair=sodium_crypto_sign_keypair();
	$secrets=[
		'primary'=>sodium_crypto_sign_secretkey($primaryPair),
		'witness_a'=>sodium_crypto_sign_secretkey($witnessPair),
	];
	$public=[
		'primary'=>sodium_crypto_sign_publickey($primaryPair),
		'witness_a'=>sodium_crypto_sign_publickey($witnessPair),
	];
	$signer=static function(string $payload,string $keyId,string $role)use($secrets):string{
		if(!isset($secrets[$keyId])){throw new LogicException('Unknown signing key.');}
		return base64_encode(sodium_crypto_sign_detached($payload,$secrets[$keyId]));
	};
	$verifier=static function(string $payload,string $keyId,string $signature,string $role)use($public):bool{
		$decoded=base64_decode($signature,true);
		return isset($public[$keyId])&&is_string($decoded)&&sodium_crypto_sign_verify_detached($decoded,$payload,$public[$keyId]);
	};
	return compact('signer','verifier')+['primary'=>'primary','witness'=>'witness_a'];
}

test('Merkle inclusion and append-only consistency proofs verify exhaustively and reject tampering',static function(Context $t):void{
	for($size=1;$size<=64;$size++){
		$leaves=[];
		for($index=0;$index<$size;$index++){$leaves[]=PanelPackageTransparencyMerkle::leaf(['size'=>$size,'index'=>$index]);}
		$root=PanelPackageTransparencyMerkle::root($leaves);
		for($index=0;$index<$size;$index++){
			$proof=PanelPackageTransparencyMerkle::inclusionProof($leaves,$index);
			$t->isTrue(PanelPackageTransparencyMerkle::verifyInclusion($leaves[$index],$index,$size,$proof,$root));
			$t->isFalse(PanelPackageTransparencyMerkle::verifyInclusion(PanelPackageTransparencyMerkle::leaf(['tampered'=>true]),$index,$size,$proof,$root));
		}
		for($oldSize=1;$oldSize<=$size;$oldSize++){
			$oldRoot=PanelPackageTransparencyMerkle::root(array_slice($leaves,0,$oldSize));
			$proof=PanelPackageTransparencyMerkle::consistencyProof($leaves,$oldSize,$size);
			$t->isTrue(PanelPackageTransparencyMerkle::verifyConsistency($oldSize,$size,$oldRoot,$root,$proof));
			$t->isFalse(PanelPackageTransparencyMerkle::verifyConsistency($oldSize,$size,str_repeat('f',64),$root,$proof));
		}
	}
	$t->same(hash('sha256',''),PanelPackageTransparencyMerkle::root([]));
	$t->throws(static fn()=>PanelPackageTransparencyMerkle::inclusionProof([],0),OutOfBoundsException::class);
	$t->isFalse(PanelPackageTransparencyMerkle::verifyConsistency(1,2,str_repeat('a',64),str_repeat('b',64),['not-a-hash']));
});

test('signed logs survive restart and receipts bind sequence inclusion consistency and witness quorum',static function(Context $t):void{
	$authority=dp_panel_transparency_authority();$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};
	$root=$t->tempDirectory('panel-package-transparency-log');
	$log=new PanelPackageTransparencyLog($root,'example_public_log',$authority['primary'],$authority['signer'],$clock);
	$first=$log->append('registry_index',['registry'=>'example_registry','sequence'=>1,'digest'=>str_repeat('a',64)]);
	$t->same(1,$log->size());$t->same(0,$first->leafIndex());$t->same(1,$first->event()['sequence']);
	$now='2026-07-16T12:01:00.000000Z';
	$second=$log->append('package_release',['package'=>'orders','version'=>'1.0.0','artifact_sha256'=>str_repeat('b',64)],['consistency_from_size'=>1])->witness($authority['witness'],$authority['signer']);
	$t->isTrue($second->verify('package_release',['package'=>'orders','version'=>'1.0.0','artifact_sha256'=>str_repeat('b',64)],$authority['verifier'],1,[$authority['witness']]));
	$t->isFalse($second->verify('package_release',['package'=>'orders','version'=>'1.0.1','artifact_sha256'=>str_repeat('b',64)],$authority['verifier'],1,[$authority['witness']]));
	$t->isTrue($second->consistency()?->verify()??false);
	$restarted=new PanelPackageTransparencyLog($root,'example_public_log',$authority['primary'],$authority['signer'],$clock);
	$t->same(2,$restarted->size());$t->same($second->checkpoint()->rootHash(),$restarted->checkpoint()->rootHash());
	$t->same(2,$restarted->checkpoint()->treeSize());$t->isTrue($restarted->consistency(1)->verify());
	$t->same(2,$restarted->jsonSerialize()['tree_size']);
	$t->notContains('secret',json_encode($restarted,JSON_THROW_ON_ERROR));
});

test('stateful verifier rejects rollback split views stale heads missing consistency and untrusted witnesses',static function(Context $t):void{
	$authority=dp_panel_transparency_authority();$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};
	$root=$t->tempDirectory('panel-package-transparency-verifier');$log=new PanelPackageTransparencyLog($root.'/honest','example_public_log',$authority['primary'],$authority['signer'],$clock);
	$subject1=['registry'=>'example_registry','sequence'=>1,'digest'=>str_repeat('1',64)];$receipt1=$log->append('registry_index',$subject1)->witness($authority['witness'],$authority['signer']);
	$verifier=new PanelPackageTransparencyVerifier($authority['verifier'],['example_public_log'],[],['allow_trust_on_first_use'=>true,'allowed_witnesses'=>[$authority['witness']],'required_witnesses'=>1,'clock'=>$clock]);
	$t->isTrue($verifier('registry_index',$subject1,$receipt1->jsonSerialize()));
	$trusted1=$verifier->checkpoint();
	$now='2026-07-16T12:01:00.000000Z';$subject2=['package'=>'orders','version'=>'1.0.0','artifact_sha256'=>str_repeat('2',64)];$receipt2=$log->append('package_release',$subject2,['consistency_from_size'=>1])->witness($authority['witness'],$authority['signer']);
	$t->isTrue($verifier('package_release',$subject2,$receipt2->jsonSerialize()));
	$t->isFalse($verifier('registry_index',$subject1,$receipt1->jsonSerialize()));

	$rogue=new PanelPackageTransparencyLog($root.'/rogue','example_public_log',$authority['primary'],$authority['signer'],$clock);
	$rogue->append('registry_index',['registry'=>'rogue','sequence'=>1,'digest'=>str_repeat('3',64)]);
	$rogueReceipt=$rogue->append('package_release',$subject2,['consistency_from_size'=>1])->witness($authority['witness'],$authority['signer']);
	$t->isFalse($verifier('package_release',$subject2,$rogueReceipt->jsonSerialize()));

	$restored=new PanelPackageTransparencyVerifier($authority['verifier'],['example_public_log'],$trusted1['checkpoints'],['allow_trust_on_first_use'=>false,'allowed_witnesses'=>[$authority['witness']],'required_witnesses'=>1,'clock'=>$clock]);
	$t->isTrue($restored('package_release',$subject2,$receipt2->jsonSerialize()));
	$unwitnessed=$log->receipt(1,1);$t->isFalse($restored('package_release',$subject2,$unwitnessed->jsonSerialize()));
	$consistencyRequired=new PanelPackageTransparencyVerifier($authority['verifier'],['example_public_log'],$trusted1['checkpoints'],['clock'=>$clock]);$t->isFalse($consistencyRequired('package_release',$subject2,$log->receipt(1)->jsonSerialize()));
	$t->isFalse($restored('package_release',$subject2,[]));
	$t->throws(static fn()=>$restored->restore(['checkpoints'=>['example_public_log'=>['tree_size'=>0,'root_hash'=>'invalid','issued_at'=>$now]]]),InvalidArgumentException::class);
	$invalidClock=new PanelPackageTransparencyVerifier($authority['verifier'],['example_public_log'],[],['allow_trust_on_first_use'=>true,'clock'=>static fn():array=>[]]);$t->throws(static fn()=>$t->nonPublic($invalidClock)->invoke('now'),UnexpectedValueException::class);
	$now='2026-07-20T12:00:00.000000Z';$stale=new PanelPackageTransparencyVerifier($authority['verifier'],['example_public_log'],[],['allow_trust_on_first_use'=>true,'clock'=>$clock,'max_checkpoint_age_seconds'=>60]);
	$t->isFalse($stale('package_release',$subject2,$receipt2->jsonSerialize()));
});

test('transparency events reject secrets malformed schemas and event sequence substitution',static function(Context $t):void{
	$authority=dp_panel_transparency_authority();$root=$t->tempDirectory('panel-package-transparency-rejections');$log=new PanelPackageTransparencyLog($root,'example_public_log',$authority['primary'],$authority['signer'],static fn():string=>'2026-07-16T12:00:00.000000Z');
	$t->throws(static fn()=>$log->append('package_release',['access_token'=>'must-not-enter-an-append-only-log']),InvalidArgumentException::class);
	$t->throws(static fn()=>$log->append('unknown',['value'=>'safe']),InvalidArgumentException::class);
	$receipt=$log->append('package_release',['package'=>'safe','version'=>'1.0.0','artifact_sha256'=>str_repeat('a',64)]);
	$manifest=$receipt->jsonSerialize();$manifest['event']['sequence']=2;
	$t->throws(static fn()=>PanelPackageTransparencyReceipt::fromArray($manifest),InvalidArgumentException::class);
	$manifest=$receipt->jsonSerialize();$manifest['proof'][]=str_repeat('z',64);
	$t->throws(static fn()=>PanelPackageTransparencyReceipt::fromArray($manifest),InvalidArgumentException::class);
});

test('durable trust network propagates contiguous revocations and evidence-based publisher profiles across restart',static function(Context $t):void{
	$authority=dp_panel_transparency_authority();$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$root=$t->tempDirectory('panel-package-trust-network');
	$log=new PanelPackageTransparencyLog($root.'/log','example_public_log',$authority['primary'],$authority['signer'],$clock);
	$verifier=new PanelPackageTransparencyVerifier($authority['verifier'],['example_public_log'],[],['allow_trust_on_first_use'=>true,'clock'=>$clock]);
	$network=new PanelPackageMarketplaceTrustNetwork($root.'/network',$verifier,$clock,3600);
	$attestation=['attestation_id'=>'attestation_identity_1','publisher'=>'example_publisher','issuer'=>'independent_lab','category'=>'identity','signal'=>'verified','evidence_hash'=>str_repeat('a',64),'issued_at'=>$now,'valid_until'=>'2026-08-16T12:00:00.000000Z'];
	$network->ingest($log->append('publisher_attestation',$attestation));
	$t->same($verifier,$network->verifier());$publishers=$network->publishers();$t->same($network,$publishers->network());$profile=$publishers->profile('example_publisher');$t->same('observed',$profile->status());$t->isTrue($profile->eligible());$t->isTrue($profile->complete());$t->same(null,$profile->jsonSerialize()['score']);$t->same('example_publisher',$publishers->assertEligible('example_publisher')->publisher());$t->same('panel_package_publisher_trust_registry_manifest',$publishers->jsonSerialize()['type']);

	$now='2026-07-16T12:01:00.000000Z';$revocation=['revocation_id'=>'revocation_orders_1','scope'=>'version','publisher'=>'example_publisher','package'=>'orders','version'=>'1.0.0','reason'=>'supply_chain_incident','effective_at'=>$now];
	$network->ingest($log->append('revocation',$revocation,['consistency_from_size'=>1]));
	$revocations=$network->revocations();$t->same($network,$revocations->network());$decision=$revocations->decision('package',['publisher'=>'example_publisher','package'=>'orders','version'=>'1.0.0']);$t->isTrue($decision->revoked());$t->isFalse($decision->allowed());$t->throws(static fn()=>$decision->assertAllowed(),LogicException::class);$t->isTrue($revocations('package',['publisher'=>'example_publisher','package'=>'orders','version'=>'1.0.0'])['revoked']);$t->same('panel_package_revocation_registry_manifest',$revocations->jsonSerialize()['type']);
	$t->isTrue($network->revocations()->decision('package',['publisher'=>'example_publisher','package'=>'orders','version'=>'2.0.0'])->allowed());
	$replayed=$network->ingestMany([$log->receipt(1,1)]);$t->isTrue($replayed[0]['replayed']);$t->throws(static fn()=>$network->ingestMany([new stdClass()]),InvalidArgumentException::class);$t->throws(static fn()=>$network->ingestMany(array_fill(0,10001,[])),LengthException::class);
	$t->throws(static fn()=>$t->nonPublic($network)->invoke('assertState',['schema'=>'invalid']),UnexpectedValueException::class);

	$freshVerifier=new PanelPackageTransparencyVerifier($authority['verifier'],['example_public_log'],[],['allow_trust_on_first_use'=>false,'clock'=>$clock]);
	$restarted=new PanelPackageMarketplaceTrustNetwork($root.'/network',$freshVerifier,$clock,3600);
	$t->isTrue($restarted->revocations()->decision('version',['publisher'=>'example_publisher','package'=>'orders','version'=>'1.0.0'])->revoked());
	$t->same('observed',$restarted->publishers()->profile('example_publisher')->status());
	$t->same(2,$restarted->jsonSerialize()['event_count']);$t->isTrue($restarted->health()['complete']);
});

test('publisher evidence can restrict block expire withdraw and supersede without inventing a reputation score',static function(Context $t):void{
	$authority=dp_panel_transparency_authority();$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$root=$t->tempDirectory('panel-package-publisher-evidence');$log=new PanelPackageTransparencyLog($root.'/log','example_public_log',$authority['primary'],$authority['signer'],$clock);$verifier=new PanelPackageTransparencyVerifier($authority['verifier'],['example_public_log'],[],['allow_trust_on_first_use'=>true,'clock'=>$clock]);$network=new PanelPackageMarketplaceTrustNetwork($root.'/network',$verifier,$clock,3600);
	$make=static fn(string $id,string $category,string $signal,string $hash,array $extra=[]):array=>['attestation_id'=>$id,'publisher'=>'example_publisher','issuer'=>'security_lab','category'=>$category,'signal'=>$signal,'evidence_hash'=>$hash,'issued_at'=>$now,'valid_until'=>'2026-07-17T12:00:00.000000Z']+$extra;
	$network->ingest($log->append('publisher_attestation',$make('identity_ok','identity','verified',str_repeat('1',64))));
	$now='2026-07-16T12:01:00.000000Z';$network->ingest($log->append('publisher_attestation',$make('maintenance_warning','maintenance','warning',str_repeat('2',64)),['consistency_from_size'=>1]));$t->same('restricted',$network->publishers()->profile('example_publisher')->status());
	$now='2026-07-16T12:02:00.000000Z';$network->ingest($log->append('publisher_attestation',$make('security_failed','security','failed',str_repeat('3',64)),['consistency_from_size'=>2]));$t->same('blocked',$network->publishers()->profile('example_publisher')->status());
	$now='2026-07-16T12:03:00.000000Z';$network->ingest($log->append('publisher_attestation',$make('security_withdrawn','security','withdrawn',str_repeat('4',64),['supersedes'=>'security_failed']),['consistency_from_size'=>3]));$t->same('restricted',$network->publishers()->profile('example_publisher')->status());
	$now='2026-07-16T12:04:00.000000Z';$network->ingest($log->append('publisher_attestation',$make('maintenance_replaced','maintenance','verified',str_repeat('5',64),['supersedes'=>'maintenance_warning']),['consistency_from_size'=>4]));$profile=$network->publishers()->profile('example_publisher');$t->same('observed',$profile->status());$t->same(2,$profile->jsonSerialize()['signals']['verified']);
	$now='2026-07-18T12:00:00.000000Z';$expired=$network->publishers()->profile('example_publisher');$t->same('unknown',$expired->status());$t->isFalse($expired->eligible());
});

test('trust network fails closed on gaps stale checkpoints and malformed domain events',static function(Context $t):void{
	$authority=dp_panel_transparency_authority();$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$root=$t->tempDirectory('panel-package-trust-fail-closed');$log=new PanelPackageTransparencyLog($root.'/log','example_public_log',$authority['primary'],$authority['signer'],$clock);$verifier=new PanelPackageTransparencyVerifier($authority['verifier'],['example_public_log'],[],['allow_trust_on_first_use'=>true,'clock'=>$clock,'max_checkpoint_age_seconds'=>3600]);$network=new PanelPackageMarketplaceTrustNetwork($root.'/network',$verifier,$clock,3600);
	$t->isFalse($network->revocations()->decision('publisher',['publisher'=>'example_publisher'])->allowed());$t->same('unknown',$network->publishers()->profile('example_publisher')->status());
	$first=$log->append('registry_index',['registry'=>'example_registry','sequence'=>1,'digest'=>str_repeat('a',64)]);$second=$log->append('package_release',['package'=>'orders','version'=>'1.0.0','artifact_sha256'=>str_repeat('b',64)],['consistency_from_size'=>1]);
	$t->throws(static fn()=>$network->ingest($second),LogicException::class);$network->ingest($log->receipt(0));$t->isFalse($network->health()['complete']);$network->ingest($log->receipt(1));$t->isTrue($network->health()['complete']);
	$now='2026-07-16T14:00:00.000000Z';$t->isTrue($network->health()['stale']);$t->isFalse($network->revocations()->decision('package',['package'=>'orders'])->allowed());
	$t->throws(static fn()=>PanelPackageMarketplaceTrustNetwork::normalizeRevocationSubject(['revocation_id'=>'bad','scope'=>'version','package'=>'orders','reason'=>'incident','effective_at'=>$now]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPackageMarketplaceTrustNetwork::normalizeAttestationSubject(['attestation_id'=>'bad','publisher'=>'example_publisher','issuer'=>'lab','category'=>'security','signal'=>'withdrawn','evidence_hash'=>str_repeat('a',64),'issued_at'=>$now,'valid_until'=>'2026-07-17T14:00:00.000000Z']),InvalidArgumentException::class);
});

test('marketplace governance rechecks publisher evidence and revocation after independent approval',static function(Context $t):void{
	$authority=dp_panel_transparency_authority();$now='2026-07-16T12:00:00.000000Z';$clock=static function()use(&$now):string{return$now;};$root=$t->tempDirectory('panel-marketplace-governance-recheck');$log=new PanelPackageTransparencyLog($root.'/log','example_public_log',$authority['primary'],$authority['signer'],$clock);$verifier=new PanelPackageTransparencyVerifier($authority['verifier'],['example_public_log'],[],['allow_trust_on_first_use'=>true,'clock'=>$clock]);$network=new PanelPackageMarketplaceTrustNetwork($root.'/network',$verifier,$clock,3600);
	$attestation=['attestation_id'=>'example_identity','publisher'=>'example_publisher','issuer'=>'independent_lab','category'=>'identity','signal'=>'verified','evidence_hash'=>str_repeat('a',64),'issued_at'=>$now,'valid_until'=>'2026-08-16T12:00:00.000000Z'];$network->ingest($log->append('publisher_attestation',$attestation));
	$policy=new \Dataphyre\Panel\PanelPolicyControlPlane([],false);$policy->register(\Dataphyre\Panel\PanelPolicyBundle::from(['id'=>'marketplace_policy','version'=>'1.0.0','rules'=>['allow'=>['effect'=>'allow','abilities'=>['marketplace.*'],'priority'=>100,'reason'=>'Marketplace operator.']]]));$request=new \Dataphyre\Panel\PanelPolicyRequest('Operator:1','marketplace.review','Tenant:1',null,null,'high',['operator'],['marketplace.*']);
	$governance=new \Dataphyre\Panel\PanelMarketplaceGovernance($policy,2,['filesystem.*'],$clock,$network->revocations(),$network->publishers());$package=\Dataphyre\Panel\PanelPackageManifest::make('governed_pack')->version('1.0.0')->support('owner','example_publisher');$trust=new \Dataphyre\Panel\PanelPackageTrustReport([],['trusted'=>1,'blocked'=>0]);$submission=['permissions'=>['orders.read'],'sbom'=>[['component'=>'safe','vulnerability_severity'=>'none']],'provenance'=>['builder'=>'trusted_builder','source_digest'=>str_repeat('b',64)],'compatibility'=>['passed'=>true]];
	$review=$governance->review($package,$trust,$submission,$request);$t->same('candidate',$review->status());$approved=$governance->approve($review,['Reviewer:1','Reviewer:2'],$request);$t->same('approved',$approved->status());$t->same('governed_pack',$governance->activation('governed_pack')['package_id']);
	$now='2026-07-16T12:01:00.000000Z';$revocation=['revocation_id'=>'governed_pack_incident','scope'=>'version','publisher'=>'example_publisher','package'=>'governed_pack','version'=>'1.0.0','reason'=>'supply_chain_incident','effective_at'=>$now];$network->ingest($log->append('revocation',$revocation,['consistency_from_size'=>1]));$t->throws(static fn()=>$governance->activation('governed_pack'),LogicException::class);
	$manifest=$governance->jsonSerialize();$t->isTrue($manifest['capabilities']['revocation_recheck']);$t->isTrue($manifest['capabilities']['publisher_evidence_recheck']);$t->isFalse($manifest['capabilities']['scalar_publisher_score']);
});
