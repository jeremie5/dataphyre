<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Fully scoped, idempotent request to execute one materialized domain command. */
final class PanelDomainCommandInvocation implements \JsonSerializable {
	/** @param array<string,mixed> $input @param array<string,mixed> $context */
	public function __construct(
		private readonly PanelDomainCommandDefinition $command,
		private readonly string $tenantId,
		private readonly string $actorId,
		private readonly string $idempotencyKey,
		private readonly array $input=[],
		private readonly ?string $recordId=null,
		private readonly bool $dryRun=false,
		private readonly bool $confirmed=false,
		private readonly array $context=[],
	){
		PanelOperationsGuard::identifier($tenantId,'domain command tenant id');
		PanelOperationsGuard::identifier($actorId,'domain command actor id');
		PanelOperationsGuard::identifier($idempotencyKey,'domain command idempotency key');
		if($recordId!==null){PanelOperationsGuard::identifier($recordId,'domain command record id');}
		PanelOperationsGuard::object($input,'domain command invocation input',2048);
		PanelOperationsGuard::safeMetadata($context,512);
	}

	public function command():PanelDomainCommandDefinition{return$this->command;}
	public function tenantId():string{return$this->tenantId;}
	public function actorId():string{return$this->actorId;}
	public function idempotencyKey():string{return$this->idempotencyKey;}
	/** @return array<string,mixed> */public function input():array{return$this->input;}
	public function recordId():?string{return$this->recordId;}
	public function dryRun():bool{return$this->dryRun;}
	public function confirmed():bool{return$this->confirmed;}
	/** @return array<string,mixed> */public function context():array{return$this->context;}
	public function fingerprint():string{return PanelOperationsGuard::digest($this->values());}
	public function jsonSerialize():array{return PanelManifestContract::stamp($this->values()+['fingerprint'=>$this->fingerprint()]);}
	/** @return array<string,mixed> */private function values():array{return[
		'type'=>'panel_domain_command_invocation','version'=>1,'command'=>$this->command->jsonSerialize(),
		'tenant_id'=>$this->tenantId,'actor_id'=>$this->actorId,'idempotency_key_hash'=>hash('sha256',$this->idempotencyKey),
		'input'=>PanelSensitiveDataSanitizer::sanitize($this->input),'record_id'=>$this->recordId,'dry_run'=>$this->dryRun,
		'confirmed'=>$this->confirmed,'context'=>PanelSensitiveDataSanitizer::sanitize($this->context),
	];}
}
