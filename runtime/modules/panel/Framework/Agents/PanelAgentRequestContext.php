<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, non-ambient identity and scope supplied by the authenticated host. */
final class PanelAgentRequestContext implements \JsonSerializable {
	public function __construct(
		private readonly string $panel,
		private readonly string $tenant,
		private readonly string $principal,
		private readonly string $session,
		private readonly string $requestId
	){
		PanelAgentGuard::identifier($panel, 'panel', 96);
		PanelAgentGuard::boundedString($tenant, 'tenant', 256);
		PanelAgentGuard::boundedString($principal, 'principal', 256);
		PanelAgentGuard::boundedString($session, 'session', 256);
		PanelAgentGuard::identifier($requestId, 'request id', 128);
	}

	public function panel(): string { return strtolower(trim($this->panel)); }
	public function tenant(): string { return trim($this->tenant); }
	public function principal(): string { return trim($this->principal); }
	public function session(): string { return trim($this->session); }
	public function requestId(): string { return strtolower(trim($this->requestId)); }
	public function scopeFingerprint(): string { return hash('sha256', "panel-agent-scope-v1\0".$this->panel()."\0".$this->tenant()."\0".$this->principal()."\0".$this->session()); }
	public function subjectFingerprint(): string { return hash('sha256', "panel-agent-subject-v1\0".$this->tenant()."\0".$this->principal()); }
	public function tenantFingerprint(): string { return hash('sha256', "panel-agent-tenant-v1\0".$this->tenant()); }

	/**
	 * Returns raw host identity for an encrypted execution boundary only.
	 *
	 * @return array<string,mixed>
	 */
	public function executionPayload(): array {
		return [
			'type'=>'panel_agent_request_context_execution','version'=>1,'panel'=>$this->panel(),
			'tenant'=>$this->tenant(),'principal'=>$this->principal(),'session'=>$this->session(),'request_id'=>$this->requestId(),
		];
	}

	/** @param array<string,mixed> $payload */
	public static function hydrateExecutionPayload(array $payload): self {
		$required=['type','version','panel','tenant','principal','session','request_id'];
		$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if(
			$keys!==$required
			||($payload['type']??null)!=='panel_agent_request_context_execution'
			||($payload['version']??null)!==1
			||!is_string($payload['panel']??null)
			||!is_string($payload['tenant']??null)
			||!is_string($payload['principal']??null)
			||!is_string($payload['session']??null)
			||!is_string($payload['request_id']??null)
		){
			throw new \UnexpectedValueException('Stored Panel agent execution context is invalid.');
		}
		try{return new self($payload['panel'],$payload['tenant'],$payload['principal'],$payload['session'],$payload['request_id']);}
		catch(\Throwable $error){throw new \UnexpectedValueException('Stored Panel agent execution context is invalid.',0,$error);}
	}

	public function jsonSerialize(): array {
		return [
			'type'=>'panel_agent_request_context','version'=>1,'panel'=>$this->panel(),
			'tenant_tag'=>$this->tenantFingerprint(),'subject_tag'=>$this->subjectFingerprint(),
			'scope_fingerprint'=>$this->scopeFingerprint(),'request_id'=>$this->requestId(),
			'raw_identity_exposed'=>false,
		];
	}
}
