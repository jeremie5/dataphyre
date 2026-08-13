<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Signed, expiring, source-bound evidence for one Panel release run.
 *
 * Automated evidence may use php, browser, or adapter execution. Manual
 * declarations deliberately remain outside this envelope so a browser proxy
 * cannot be relabelled as native assistive-technology proof.
 */
final class PanelReleaseEvidenceBundle implements \JsonSerializable {
	private const DOMAIN="dataphyre.panel.release-evidence.v1\0";
	private const TREE_DOMAIN="dataphyre.panel.release-evidence.artifacts.v1\0";
	private const MAX_ARTIFACTS=512;
	private const MAX_TOTAL_BYTES=268435456;
	private const MAX_CLAIMS=256;
	private const STATUSES=['passed','failed','blocked','skipped'];
	private const EXECUTIONS=['php','browser','adapter'];

	/** @param array<string,mixed> $payload @param array<string,string> $integrity */
	private function __construct(private readonly array $payload,private readonly array $integrity) {}

	/**
	 * @param list<string> $artifactPaths
	 * @param array<string,mixed> $context
	 * @param list<array<string,mixed>> $claims
	 */
	public static function issue(string $artifactRoot,array $artifactPaths,array $context,array $claims,string $keyId,string $key,int $issuedAt,int $ttl=3600,?string $runId=null,bool $strictTree=true):self {
		if(strlen($key)<32){throw new \InvalidArgumentException('Release evidence signing keys require at least 32 bytes.');}
		$keyId=PanelOperationsGuard::name($keyId,'release evidence key id');
		if($issuedAt<1||$ttl<60||$ttl>604800||$issuedAt>PHP_INT_MAX-$ttl){throw new \InvalidArgumentException('Release evidence validity window is invalid.');}
		$runId=$runId===null?'run_'.bin2hex(random_bytes(16)):PanelOperationsGuard::identifier($runId,'release evidence run id');
		if(count($artifactPaths)<1||count($artifactPaths)>self::MAX_ARTIFACTS||!array_is_list($artifactPaths)){throw new \LengthException('Release evidence artifact list is empty or exceeds its file budget.');}
		$paths=[];
		foreach($artifactPaths as $path){if(!is_string($path)){throw new \InvalidArgumentException('Release evidence artifact paths must be strings.');}$path=PanelReleaseEvidenceArtifact::normalizePath($path);if(isset($paths[$path])){throw new \InvalidArgumentException('Release evidence artifact paths must be unique.');}$paths[$path]=true;}
		$paths=array_keys($paths);sort($paths,SORT_STRING);
		if($strictTree&&PanelReleaseEvidenceArtifact::inventory($artifactRoot,self::MAX_ARTIFACTS)!==$paths){throw new \UnexpectedValueException('Strict release evidence must inventory the exact artifact tree.');}
		$artifacts=[];$total=0;
		foreach($paths as $path){$artifact=PanelReleaseEvidenceArtifact::capture($artifactRoot,$path);$total+=$artifact->bytes();if($total>self::MAX_TOTAL_BYTES){throw new \LengthException('Release evidence artifact tree exceeds its total byte budget.');}$artifacts[]=$artifact->jsonSerialize();}
		$context=self::normalizeContext($context);
		$claims=self::normalizeClaims($claims,$artifacts,false);
		$payload=['type'=>'panel_release_evidence_bundle','version'=>1,'run_id'=>$runId,'issued_at'=>$issuedAt,'expires_at'=>$issuedAt+$ttl,'context'=>$context,'strict_tree'=>$strictTree,'artifacts'=>$artifacts,'artifact_tree_sha256'=>self::treeDigest($artifacts),'claims'=>$claims];
		$digest=PanelOperationsGuard::digest($payload);
		$integrity=['algorithm'=>'hmac-sha256','key_id'=>$keyId,'digest'=>$digest,'signature'=>hash_hmac('sha256',self::DOMAIN.$digest,$key)];
		return new self($payload,$integrity);
	}

	/** @param array<string,mixed> $envelope */
	public static function fromArray(array $envelope):self {
		self::exactKeys($envelope,['type','version','run_id','issued_at','expires_at','context','strict_tree','artifacts','artifact_tree_sha256','claims','integrity'],'bundle');
		if(($envelope['type']??null)!=='panel_release_evidence_bundle'||($envelope['version']??null)!==1){throw new \UnexpectedValueException('Release evidence bundle envelope is unsupported.');}
		$runId=PanelOperationsGuard::identifier((string)($envelope['run_id']??''),'release evidence run id');
		$issuedAt=$envelope['issued_at']??null;$expiresAt=$envelope['expires_at']??null;
		if(!is_int($issuedAt)||!is_int($expiresAt)||$issuedAt<1||$expiresAt<=$issuedAt||$expiresAt-$issuedAt>604800){throw new \InvalidArgumentException('Release evidence validity window is invalid.');}
		if(!is_bool($envelope['strict_tree']??null)){throw new \InvalidArgumentException('Release evidence strict_tree must be boolean.');}
		$artifactRows=$envelope['artifacts']??null;
		if(!is_array($artifactRows)||!array_is_list($artifactRows)||count($artifactRows)<1||count($artifactRows)>self::MAX_ARTIFACTS){throw new \LengthException('Release evidence artifact list is empty or exceeds its file budget.');}
		$artifacts=[];$paths=[];$total=0;
		foreach($artifactRows as $row){if(!is_array($row)){throw new \InvalidArgumentException('Release evidence artifacts must be objects.');}$artifact=PanelReleaseEvidenceArtifact::fromArray($row);if(isset($paths[$artifact->path()])){throw new \InvalidArgumentException('Release evidence artifact paths must be unique.');}$paths[$artifact->path()]=true;$total+=$artifact->bytes();if($total>self::MAX_TOTAL_BYTES){throw new \LengthException('Release evidence artifact tree exceeds its total byte budget.');}$artifacts[]=$artifact->jsonSerialize();}
		$sorted=$artifacts;usort($sorted,static fn(array $left,array $right):int=>strcmp((string)$left['path'],(string)$right['path']));if($sorted!==$artifacts){throw new \InvalidArgumentException('Release evidence artifacts must be sorted by path.');}
		$tree=strtolower(trim((string)($envelope['artifact_tree_sha256']??'')));
		if(preg_match('/^[a-f0-9]{64}$/D',$tree)!==1||!hash_equals(self::treeDigest($artifacts),$tree)){throw new \UnexpectedValueException('Release evidence artifact tree digest is invalid.');}
		$context=self::normalizeContext(is_array($envelope['context']??null)?$envelope['context']:throw new \InvalidArgumentException('Release evidence context must be an object.'));
		$claims=self::normalizeClaims(is_array($envelope['claims']??null)?$envelope['claims']:throw new \InvalidArgumentException('Release evidence claims must be a list.'),$artifacts,true);
		$integrity=$envelope['integrity']??null;
		if(!is_array($integrity)){throw new \InvalidArgumentException('Release evidence integrity must be an object.');}
		self::exactKeys($integrity,['algorithm','key_id','digest','signature'],'integrity');
		if(($integrity['algorithm']??null)!=='hmac-sha256'){throw new \UnexpectedValueException('Release evidence signature algorithm is unsupported.');}
		$keyId=PanelOperationsGuard::name((string)($integrity['key_id']??''),'release evidence key id');
		$digest=strtolower(trim((string)($integrity['digest']??'')));$signature=strtolower(trim((string)($integrity['signature']??'')));
		if(preg_match('/^[a-f0-9]{64}$/D',$digest)!==1||preg_match('/^[a-f0-9]{64}$/D',$signature)!==1){throw new \InvalidArgumentException('Release evidence integrity digests are invalid.');}
		$payload=['type'=>'panel_release_evidence_bundle','version'=>1,'run_id'=>$runId,'issued_at'=>$issuedAt,'expires_at'=>$expiresAt,'context'=>$context,'strict_tree'=>$envelope['strict_tree'],'artifacts'=>$artifacts,'artifact_tree_sha256'=>$tree,'claims'=>$claims];
		return new self($payload,['algorithm'=>'hmac-sha256','key_id'=>$keyId,'digest'=>$digest,'signature'=>$signature]);
	}

	/**
	 * @param array<string,string> $keys
	 * @param array<string,mixed> $expectations
	 * @param ?callable(array<string,string>):bool $replayGuard
	 */
	public function verify(string $artifactRoot,array $keys,array $expectations,?int $now=null,int $clockSkew=0,?callable $replayGuard=null):PanelReleaseEvidenceVerification {
		$expectations=self::normalizeExpectations($expectations);
		$now??=time();if($now<1||$clockSkew<0||$clockSkew>3600){throw new \InvalidArgumentException('Release evidence verification clock is invalid.');}
		$failures=[];$verified=0;$digest=PanelOperationsGuard::digest($this->payload);
		$key=$keys[$this->integrity['key_id']]??null;
		if(!hash_equals($digest,$this->integrity['digest'])){$failures[]='bundle_digest_mismatch';}
		if(!is_string($key)||strlen($key)<32||!hash_equals(hash_hmac('sha256',self::DOMAIN.$digest,$key),$this->integrity['signature'])){$failures[]='signature_untrusted';}
		if($this->payload['issued_at']>$now+$clockSkew){$failures[]='not_yet_valid';}
		if($this->payload['expires_at']<$now-$clockSkew){$failures[]='expired';}
		foreach(['source_digest','contract_digest'] as $field){if(!hash_equals($expectations[$field],$this->payload['context'][$field])){$failures[]=$field.'_mismatch';}}
		if(array_key_exists('release_digest',$expectations)&&!hash_equals((string)$expectations['release_digest'],(string)($this->payload['context']['release_digest']??''))){$failures[]='release_digest_mismatch';}
		if(array_key_exists('matrix_digests',$expectations)&&$expectations['matrix_digests']!==$this->payload['context']['matrix_digests']){$failures[]='matrix_digests_mismatch';}
		if(array_key_exists('run_id',$expectations)&&!hash_equals((string)$expectations['run_id'],(string)$this->payload['run_id'])){$failures[]='run_id_mismatch';}
		foreach($this->payload['artifacts'] as $row){
			try{PanelReleaseEvidenceArtifact::fromArray($row)->assertMatches($artifactRoot);$verified++;}
			catch(\Throwable){$failures[]='artifact_mismatch:'.hash('sha256',(string)$row['path']);}
		}
		if($this->payload['strict_tree']){
			try{$inventory=PanelReleaseEvidenceArtifact::inventory($artifactRoot,self::MAX_ARTIFACTS);$expected=array_column($this->payload['artifacts'],'path');if($inventory!==$expected){$failures[]='artifact_tree_mismatch';}}
			catch(\Throwable){$failures[]='artifact_tree_unreadable';}
		}
		$failures=array_values(array_unique($failures));
		$verification=new PanelReleaseEvidenceVerification($this->payload['run_id'],$digest,count($this->payload['artifacts']),$verified,$failures);
		if($verification->passed()&&$replayGuard!==null){
			try{$accepted=$replayGuard(['run_id'=>$this->payload['run_id'],'bundle_digest'=>$digest,'replay_key'=>$verification->replayKey()]);}
			catch(\Throwable){$accepted=false;}
			if($accepted!==true){$verification=new PanelReleaseEvidenceVerification($this->payload['run_id'],$digest,count($this->payload['artifacts']),$verified,['replay_rejected']);}
		}
		return $verification;
	}

	public function digest():string {return PanelOperationsGuard::digest($this->payload);}
	public function runId():string {return (string)$this->payload['run_id'];}
	/** @return array<string,mixed> */ public function context():array {return $this->payload['context'];}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {return $this->payload+['integrity'=>$this->integrity];}

	/** @param array<string,mixed> $context @return array<string,mixed> */
	private static function normalizeContext(array $context):array {
		self::exactKeysSubset($context,['source_digest','contract_digest','release_digest','matrix_digests','runner','environment','capabilities'],['source_digest','contract_digest','runner'],'context');
		$source=self::hex((string)$context['source_digest'],'source digest');$contract=self::hex((string)$context['contract_digest'],'contract digest');
		$release=array_key_exists('release_digest',$context)&&$context['release_digest']!==null?self::hex((string)$context['release_digest'],'release digest'):null;
		$matrices=$context['matrix_digests']??[];if(!is_array($matrices)||($matrices!==[]&&array_is_list($matrices))||count($matrices)>64){throw new \InvalidArgumentException('Release evidence matrix digests must be a bounded map.');}$normalizedMatrices=[];foreach($matrices as $id=>$digest){$normalizedMatrices[PanelOperationsGuard::name((string)$id,'release evidence matrix id')]=self::hex((string)$digest,'matrix digest');}ksort($normalizedMatrices,SORT_STRING);
		$runner=$context['runner'];if(!is_array($runner)){throw new \InvalidArgumentException('Release evidence runner must be an object.');}self::exactKeysSubset($runner,['id','version','channel','browser'],['id','version','channel'],'runner');$normalizedRunner=['id'=>PanelOperationsGuard::identifier((string)$runner['id'],'release evidence runner id'),'version'=>self::text((string)$runner['version'],'runner version',128),'channel'=>PanelOperationsGuard::name((string)$runner['channel'],'release evidence runner channel')];if(array_key_exists('browser',$runner)&&$runner['browser']!==null){$normalizedRunner['browser']=self::text((string)$runner['browser'],'runner browser',256);}else{$normalizedRunner['browser']=null;}
		$environment=$context['environment']??[];if(!is_array($environment)||($environment!==[]&&array_is_list($environment))||count($environment)>32){throw new \InvalidArgumentException('Release evidence environment must be a bounded map.');}$normalizedEnvironment=[];foreach($environment as $name=>$value){if(!is_scalar($value)&&$value!==null){throw new \InvalidArgumentException('Release evidence environment values must be scalar.');}$normalizedEnvironment[PanelOperationsGuard::name((string)$name,'release evidence environment key')]=self::text($value===null?'null':(string)$value,'environment value',256);}ksort($normalizedEnvironment,SORT_STRING);
		$capabilities=self::names($context['capabilities']??[],'release evidence capability',128);
		return ['source_digest'=>$source,'contract_digest'=>$contract,'release_digest'=>$release,'matrix_digests'=>$normalizedMatrices,'runner'=>$normalizedRunner,'environment'=>$normalizedEnvironment,'capabilities'=>$capabilities];
	}

	/** @param list<array<string,mixed>> $claims @param list<array<string,mixed>> $artifacts @return list<array<string,mixed>> */
	private static function normalizeClaims(array $claims,array $artifacts,bool $serialized):array {
		if(!array_is_list($claims)||count($claims)<1||count($claims)>self::MAX_CLAIMS){throw new \LengthException('Release evidence claims are empty or exceed their budget.');}
		$artifactMap=[];foreach($artifacts as $artifact){$artifactMap[(string)$artifact['path']]=$artifact;}
		$normalized=[];$ids=[];
		foreach($claims as $claim){
			if(!is_array($claim)){throw new \InvalidArgumentException('Release evidence claims must be objects.');}
			$allowed=['id','status','execution','assertions','report_path','capabilities','notes'];if($serialized){$allowed[]='report_sha256';$allowed[]='report_bytes';}
			self::exactKeysSubset($claim,$allowed,['id','status','execution','assertions','report_path'],'claim');
			$id=PanelOperationsGuard::name((string)$claim['id'],'release evidence claim id',128);if(isset($ids[$id])){throw new \InvalidArgumentException('Release evidence claim ids must be unique.');}$ids[$id]=true;
			$status=strtolower(trim((string)$claim['status']));$execution=strtolower(trim((string)$claim['execution']));$assertions=$claim['assertions'];
			if(!in_array($status,self::STATUSES,true)||!in_array($execution,self::EXECUTIONS,true)){throw new \InvalidArgumentException('Release evidence claim status or execution is invalid.');}
			if(!is_int($assertions)||$assertions<0||$assertions>1000000||($status==='passed'&&$assertions<1)){throw new \InvalidArgumentException('Release evidence claim assertion count is invalid.');}
			$report=$claim['report_path'];if($report!==null&&!is_string($report)){throw new \InvalidArgumentException('Release evidence claim report path must be a string or null.');}$report=$report===null?null:PanelReleaseEvidenceArtifact::normalizePath($report);
			if(in_array($status,['passed','failed'],true)&&$report===null){throw new \InvalidArgumentException('Executed release evidence claims require an artifact-backed report.');}
			$artifact=$report===null?null:($artifactMap[$report]??null);if($report!==null&&$artifact===null){throw new \InvalidArgumentException('Release evidence claim references an unattested report.');}
			$row=['id'=>$id,'status'=>$status,'execution'=>$execution,'assertions'=>$assertions,'report_path'=>$report,'report_sha256'=>$artifact['sha256']??null,'report_bytes'=>$artifact['bytes']??null,'capabilities'=>self::names($claim['capabilities']??[],'release evidence claim capability',64),'notes'=>array_key_exists('notes',$claim)&&$claim['notes']!==null?self::text((string)$claim['notes'],'claim notes',1024):null];
			if($serialized&&(($claim['report_sha256']??null)!==$row['report_sha256']||($claim['report_bytes']??null)!==$row['report_bytes'])){throw new \UnexpectedValueException('Release evidence claim report binding is invalid.');}
			$normalized[]=$row;
		}
		usort($normalized,static fn(array $left,array $right):int=>strcmp($left['id'],$right['id']));
		if($serialized&&$normalized!==$claims){throw new \InvalidArgumentException('Release evidence claims must be normalized and sorted.');}
		return $normalized;
	}

	/** @param array<string,mixed> $expectations @return array<string,mixed> */
	private static function normalizeExpectations(array $expectations):array {
		self::exactKeysSubset($expectations,['source_digest','contract_digest','release_digest','matrix_digests','run_id'],['source_digest','contract_digest'],'verification expectations');
		$result=['source_digest'=>self::hex((string)$expectations['source_digest'],'expected source digest'),'contract_digest'=>self::hex((string)$expectations['contract_digest'],'expected contract digest')];
		if(array_key_exists('release_digest',$expectations)){$result['release_digest']=self::hex((string)$expectations['release_digest'],'expected release digest');}
		if(array_key_exists('matrix_digests',$expectations)){$matrices=$expectations['matrix_digests'];if(!is_array($matrices)||($matrices!==[]&&array_is_list($matrices))||count($matrices)>64){throw new \InvalidArgumentException('Expected matrix digests must be a bounded map.');}$result['matrix_digests']=[];foreach($matrices as $id=>$digest){$result['matrix_digests'][PanelOperationsGuard::name((string)$id,'expected matrix id')]=self::hex((string)$digest,'expected matrix digest');}ksort($result['matrix_digests'],SORT_STRING);}
		if(array_key_exists('run_id',$expectations)){$result['run_id']=PanelOperationsGuard::identifier((string)$expectations['run_id'],'expected release evidence run id');}
		return $result;
	}

	/** @param list<array<string,mixed>> $artifacts */
	private static function treeDigest(array $artifacts):string {return hash('sha256',self::TREE_DOMAIN.PanelOperationsGuard::json($artifacts));}
	private static function hex(string $value,string $label):string {$value=strtolower(trim($value));if(preg_match('/^[a-f0-9]{64}$/D',$value)!==1){throw new \InvalidArgumentException(ucfirst($label).' is invalid.');}return $value;}
	private static function text(string $value,string $label,int $maximum):string {$value=trim($value);if($value===''||strlen($value)>$maximum||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/',$value)===1){throw new \InvalidArgumentException(ucfirst($label).' is invalid.');}return $value;}
	/** @param mixed $values @return list<string> */ private static function names(mixed $values,string $label,int $limit):array {if(!is_array($values)||!array_is_list($values)){throw new \InvalidArgumentException(ucfirst($label).' list is invalid.');}return PanelOperationsGuard::names($values,$label,128,$limit);}

	/** @param array<string,mixed> $payload @param list<string> $keys */
	private static function exactKeys(array $payload,array $keys,string $label):void {$actual=array_keys($payload);sort($actual,SORT_STRING);sort($keys,SORT_STRING);if($actual!==$keys){throw new \InvalidArgumentException('Release evidence '.$label.' contains unknown or missing fields.');}}
	/** @param array<string,mixed> $payload @param list<string> $allowed @param list<string> $required */
	private static function exactKeysSubset(array $payload,array $allowed,array $required,string $label):void {$unknown=array_diff(array_keys($payload),$allowed);$missing=array_diff($required,array_keys($payload));if($unknown!==[]||$missing!==[]||($payload!==[]&&array_is_list($payload))){throw new \InvalidArgumentException('Release evidence '.$label.' contains unknown or missing fields.');}}
}
