<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable, source-bound evidence mapping profile for one external framework. */
final class PanelComplianceFrameworkPack implements \JsonSerializable {
	private readonly string $id;
	private readonly string $frameworkVersion;
	private readonly string $title;
	private readonly string $sourceUrl;
	private readonly string $sourceCheckedAt;
	private readonly string $coverageScope;
	/** @var array<string,array<string,mixed>> */ private readonly array $controls;
	/** @var array<string,mixed> */ private readonly array $metadata;
	private readonly string $fingerprint;

	/** @param array<string,array<string,mixed>> $controls @param array<string,mixed> $options */
	private function __construct(string $id,string $frameworkVersion,string $title,string $sourceUrl,array $controls,array $options){
		$this->id=PanelOperationsGuard::name($id,'compliance framework id');
		$this->frameworkVersion=self::version($frameworkVersion);
		$this->title=PanelOperationsGuard::label($title,'compliance framework title',256);
		$this->sourceUrl=self::url($sourceUrl);
		$this->sourceCheckedAt=PanelOperationsGuard::instant($options['source_checked_at']??gmdate('c'),'compliance source checked at');
		$scope=PanelOperationsGuard::name((string)($options['coverage_scope']??'reference_profile'),'compliance coverage scope');
		if(!in_array($scope,['reference_profile','complete_host_mapping','licensed_profile'],true)){throw new \InvalidArgumentException('Compliance framework coverage scope is invalid.');}$this->coverageScope=$scope;
		if($controls===[]||count($controls)>2000){throw new \LengthException('Compliance framework packs require between one and 2000 controls.');}
		$normalized=[];foreach($controls as$key=>$definition){if(!is_array($definition)){throw new \InvalidArgumentException('Compliance framework controls must be maps.');}$control=self::normalizeControl((string)($definition['id']??$key),$definition);if(isset($normalized[$control['id']])){throw new \LogicException('Compliance framework control ids must be unique.');}$normalized[$control['id']]=$control;}ksort($normalized,SORT_STRING);$this->controls=$normalized;
		$this->metadata=PanelOperationsGuard::safeMetadata(is_array($options['metadata']??null)?$options['metadata']:[],128);
		$this->fingerprint=PanelOperationsGuard::digest($this->payload());
	}

	/** @param array<string,array<string,mixed>> $controls @param array<string,mixed> $options */
	public static function make(string $id,string $frameworkVersion,string $title,string $sourceUrl,array $controls,array $options=[]):self{return new self($id,$frameworkVersion,$title,$sourceUrl,$controls,$options);}
	public function id():string{return$this->id;}
	public function frameworkVersion():string{return$this->frameworkVersion;}
	public function title():string{return$this->title;}
	public function sourceUrl():string{return$this->sourceUrl;}
	public function sourceCheckedAt():string{return$this->sourceCheckedAt;}
	public function coverageScope():string{return$this->coverageScope;}
	public function fingerprint():string{return$this->fingerprint;}
	/** @return array<string,array<string,mixed>> */ public function controls():array{return$this->controls;}
	/** @return array<string,mixed>|null */ public function control(string $id):?array{return$this->controls[PanelOperationsGuard::name($id,'framework control id')]??null;}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return PanelManifestContract::stamp($this->payload()+['fingerprint'=>$this->fingerprint]);}
	/** @return array<string,mixed> */ private function payload():array{return[
		'type'=>'panel_compliance_framework_pack','version'=>1,'id'=>$this->id,'framework_version'=>$this->frameworkVersion,
		'title'=>$this->title,'source_url'=>$this->sourceUrl,'source_checked_at'=>$this->sourceCheckedAt,
		'coverage_scope'=>$this->coverageScope,'controls'=>$this->controls,'metadata'=>$this->metadata,
		'claims'=>['certification'=>false,'legal_advice'=>false,'control_equivalence'=>false],
	];}

	/** @param array<string,mixed> $definition @return array<string,mixed> */
	private static function normalizeControl(string $id,array $definition):array {
		$id=PanelOperationsGuard::name($id,'framework control id');
		$title=PanelOperationsGuard::label((string)($definition['title']??str_replace('_',' ',$id)),'framework control title',256);
		$references=self::labels(is_array($definition['references']??null)?$definition['references']:[],'framework control reference',64,256);
		if($references===[]){throw new \InvalidArgumentException('Compliance framework controls require at least one external reference.');}
		$domains=PanelOperationsGuard::names(is_array($definition['domains']??null)?$definition['domains']:[],'compliance evidence domain',96,64);
		$collectors=PanelOperationsGuard::names(is_array($definition['collectors']??null)?$definition['collectors']:[],'compliance collector id',96,32);
		$requirements=self::labels(is_array($definition['evidence_requirements']??null)?$definition['evidence_requirements']:[],'evidence requirement',64,256);
		$crosswalks=[];$items=is_array($definition['crosswalks']??null)?$definition['crosswalks']:[];if(count($items)>128){throw new \LengthException('Compliance control crosswalks exceed their limit.');}
		foreach($items as$item){if(!is_array($item)){throw new \InvalidArgumentException('Compliance crosswalk entries must be maps.');}$relation=PanelOperationsGuard::name((string)($item['relation']??'related'),'compliance crosswalk relation');if(!in_array($relation,['related','supports','overlaps','equivalent'],true)){throw new \InvalidArgumentException('Compliance crosswalk relation is invalid.');}$crosswalks[]=['framework'=>PanelOperationsGuard::name((string)($item['framework']??''),'crosswalk framework id'),'control'=>PanelOperationsGuard::name((string)($item['control']??''),'crosswalk control id'),'relation'=>$relation];}
		usort($crosswalks,static fn(array $a,array $b):int=>[$a['framework'],$a['control'],$a['relation']]<=>[$b['framework'],$b['control'],$b['relation']]);
		$payload=['id'=>$id,'title'=>$title,'references'=>$references,'domains'=>$domains,'collectors'=>$collectors,'evidence_requirements'=>$requirements,'crosswalks'=>$crosswalks,'metadata'=>PanelOperationsGuard::safeMetadata(is_array($definition['metadata']??null)?$definition['metadata']:[],64)];
		$payload['fingerprint']=PanelOperationsGuard::digest($payload);return$payload;
	}

	/** @param array<int,mixed> $values @return list<string> */ private static function labels(array $values,string $label,int $limit,int $maximum):array {if(count($values)>$limit){throw new \LengthException(ucfirst($label).' list exceeds its limit.');}$out=[];foreach($values as$value){$item=PanelOperationsGuard::label((string)$value,$label,$maximum);$out[$item]=true;}$result=array_keys($out);sort($result,SORT_STRING);return$result;}
	private static function version(string $value):string {$value=trim($value);if($value===''||strlen($value)>64||preg_match('/^[A-Za-z0-9][A-Za-z0-9._+ \/-]*$/D',$value)!==1){throw new \InvalidArgumentException('Compliance framework version is invalid.');}return$value;}
	private static function url(string $value):string {$value=trim($value);if(strlen($value)>2048||filter_var($value,FILTER_VALIDATE_URL)===false){throw new \InvalidArgumentException('Compliance framework source URL is invalid.');}$parts=parse_url($value);if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||isset($parts['user'],$parts['pass'])||trim((string)($parts['host']??''))===''){throw new \InvalidArgumentException('Compliance framework source URLs must use credential-free HTTPS.');}return$value;}
}
