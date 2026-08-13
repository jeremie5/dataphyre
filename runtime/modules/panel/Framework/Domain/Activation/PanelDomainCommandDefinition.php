<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable executable command contract materialized from a signed domain. */
final class PanelDomainCommandDefinition implements \JsonSerializable {
	/** @param array<string,mixed> $input @param array<string,mixed> $effects @param array<string,mixed> $metadata */
	public function __construct(
		private readonly string $domainId,
		private readonly string $domainVersion,
		private readonly string $name,
		private readonly string $label,
		private readonly string $entity,
		private readonly string $operation,
		private readonly string $risk,
		private readonly bool $reversible,
		private readonly int $approvalCount,
		private readonly ?string $policy,
		private readonly array $input,
		private readonly array $effects,
		private readonly array $metadata=[],
	){
		PanelOperationsGuard::name($domainId,'domain command domain id');
		if($domainVersion===''||strlen($domainVersion)>64){throw new \InvalidArgumentException('Domain command version is invalid.');}
		PanelOperationsGuard::name($name,'domain command name');
		PanelOperationsGuard::label($label,'domain command label');
		PanelOperationsGuard::name($entity,'domain command entity');
		PanelOperationsGuard::name($operation,'domain command operation');
		if(!in_array($risk,['low','medium','high','critical'],true)){throw new \InvalidArgumentException('Domain command risk is invalid.');}
		if($approvalCount<0||$approvalCount>16){throw new \InvalidArgumentException('Domain command approval count is invalid.');}
		if($policy!==null){PanelOperationsGuard::name($policy,'domain command policy');}
		PanelOperationsGuard::object($input,'domain command input',1024);
		PanelOperationsGuard::object($effects,'domain command effects',1024);
		PanelOperationsGuard::safeMetadata($metadata,256);
	}

	/** @param array<string,mixed> $definition */
	public static function from(string $domainId,string $domainVersion,string $name,array $definition):self {
		return new self(
			$domainId,
			$domainVersion,
			PanelOperationsGuard::name($name,'domain command name'),
			PanelOperationsGuard::label((string)($definition['label']??ucwords(str_replace(['_','-','.'],' ',$name))),'domain command label'),
			PanelOperationsGuard::name((string)($definition['entity']??''),'domain command entity'),
			PanelOperationsGuard::name((string)($definition['operation']??$name),'domain command operation'),
			in_array(($risk=strtolower((string)($definition['risk']??'medium'))),['low','medium','high','critical'],true)?$risk:'medium',
			($definition['reversible']??false)===true,
			max(0,min(16,(int)($definition['approval']??0))),
			isset($definition['policy'])&&trim((string)$definition['policy'])!==''?PanelOperationsGuard::name((string)$definition['policy'],'domain command policy'):null,
			PanelOperationsGuard::canonical(is_array($definition['input']??null)?$definition['input']:[]),
			PanelOperationsGuard::canonical(is_array($definition['effects']??null)?$definition['effects']:[]),
			PanelOperationsGuard::safeMetadata(is_array($definition['metadata']??null)?$definition['metadata']:[],256),
		);
	}

	/** @param array<string,mixed> $payload */
	public static function hydrate(array $payload):self {
		if(
			($payload['type']??null)!=='panel_domain_command_definition'
			||($payload['version']??null)!==1
			||!is_string($payload['domain_id']??null)
			||!is_string($payload['domain_version']??null)
			||!is_string($payload['name']??null)
			||!is_array($payload['input']??null)
			||!is_array($payload['effects']??null)
			||!is_array($payload['metadata']??null)
			||!is_string($payload['fingerprint']??null)
		){throw new \UnexpectedValueException('Stored domain command definition is invalid.');}
		$definition=$payload;$definition['approval']=$payload['approval_count']??0;
		$self=self::from($payload['domain_id'],$payload['domain_version'],$payload['name'],$definition);
		if(
			!hash_equals($self->fingerprint(),$payload['fingerprint'])
			||($payload['qualified_name']??null)!==$self->qualifiedName()
			||($payload['ability']??null)!==$self->ability()
		){throw new \UnexpectedValueException('Stored domain command definition integrity check failed.');}
		return$self;
	}

	public function domainId():string{return$this->domainId;}
	public function domainVersion():string{return$this->domainVersion;}
	public function name():string{return$this->name;}
	public function qualifiedName():string{return$this->domainId.'.'.$this->name;}
	public function label():string{return$this->label;}
	public function entity():string{return$this->entity;}
	public function operation():string{return$this->operation;}
	public function ability():string{return'domain.'.$this->domainId.'.'.$this->operation;}
	public function risk():string{return$this->risk;}
	public function reversible():bool{return$this->reversible;}
	public function approvalCount():int{return$this->approvalCount;}
	public function policy():?string{return$this->policy;}
	/** @return array<string,mixed> */public function input():array{return$this->input;}
	/** @return array<string,mixed> */public function effects():array{return$this->effects;}
	/** @return array<string,mixed> */public function metadata():array{return$this->metadata;}

	/** @return array<string,mixed> */
	public function inputSchema():array {
		if(($this->input['type']??null)==='object'){return$this->input;}
		return['type'=>'object','properties'=>$this->input,'additionalProperties'=>false];
	}

	public function fingerprint():string{return PanelOperationsGuard::digest($this->values());}
	public function jsonSerialize():array{return PanelManifestContract::stamp($this->values()+['fingerprint'=>$this->fingerprint()]);}
	/** @return array<string,mixed> */private function values():array{return[
		'type'=>'panel_domain_command_definition','version'=>1,'domain_id'=>$this->domainId,'domain_version'=>$this->domainVersion,
		'name'=>$this->name,'qualified_name'=>$this->qualifiedName(),'label'=>$this->label,'entity'=>$this->entity,
		'operation'=>$this->operation,'ability'=>$this->ability(),'risk'=>$this->risk,'reversible'=>$this->reversible,
		'approval_count'=>$this->approvalCount,'policy'=>$this->policy,'input'=>$this->input,'effects'=>$this->effects,
		'metadata'=>$this->metadata,
	];}
}
