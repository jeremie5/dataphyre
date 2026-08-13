<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, machine-readable declaration for one bounded host tool. */
final class PanelAgentTool implements \JsonSerializable {
	/**
	 * @param array<string,mixed> $inputSchema
	 * @param array<string,mixed> $metadata
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $version,
		private readonly string $description,
		private readonly string $permission,
		private readonly string $risk='low',
		private readonly bool $dryRunSupported=true,
		private readonly bool $confirmationRequired=false,
		private readonly int $approvalCount=0,
		private readonly bool $separationOfDuties=false,
		private readonly array $inputSchema=['type'=>'object','properties'=>[],'additionalProperties'=>false],
		private readonly bool $hidden=false,
		private readonly int $outputByteLimit=65536,
		private readonly int $errorByteLimit=2048,
		private readonly array $metadata=[]
	){
		PanelAgentGuard::identifier($name, 'tool', 128);
		PanelAgentGuard::boundedString($version, 'tool version', 64);
		PanelAgentGuard::boundedString($description, 'tool description', 2048);
		PanelAgentGuard::identifier($permission, 'tool permission', 160);
		if(!in_array($risk, ['low','medium','high','critical'], true)){ throw new \InvalidArgumentException('Panel agent tool risk is invalid.'); }
		if($approvalCount<0 || $approvalCount>2){ throw new \InvalidArgumentException('Panel agent approval count must be between zero and two.'); }
		if($separationOfDuties && $approvalCount===0){ throw new \InvalidArgumentException('Panel agent separation of duties requires at least one approval.'); }
		if($outputByteLimit<256 || $outputByteLimit>1048576){ throw new \InvalidArgumentException('Panel agent output byte limit must be between 256 and 1048576.'); }
		if($errorByteLimit<128 || $errorByteLimit>8192){ throw new \InvalidArgumentException('Panel agent error byte limit must be between 128 and 8192.'); }
		PanelAgentGuard::assertSchema($inputSchema);
		PanelAgentGuard::assertJson($metadata, 32768);
	}

	public function name(): string { return strtolower(trim($this->name)); }
	public function version(): string { return trim($this->version); }
	public function description(): string { return trim($this->description); }
	public function permission(): string { return strtolower(trim($this->permission)); }
	public function risk(): string { return $this->risk; }
	public function dryRunSupported(): bool { return $this->dryRunSupported; }
	public function confirmationRequired(): bool { return $this->confirmationRequired; }
	public function approvalCount(): int { return $this->approvalCount; }
	public function separationOfDuties(): bool { return $this->separationOfDuties; }
	/** @return array<string,mixed> */ public function inputSchema(): array { return $this->inputSchema; }
	public function hidden(): bool { return $this->hidden; }
	public function outputByteLimit(): int { return $this->outputByteLimit; }
	public function errorByteLimit(): int { return $this->errorByteLimit; }
	/** @return array<string,mixed> */ public function metadata(): array { return $this->metadata; }

	/** @param array<string,mixed> $arguments @return array<string,mixed> */
	public function normalize(array $arguments): array { return PanelAgentGuard::normalizeArguments($arguments, $this->inputSchema); }
	public function fingerprint(): string { $manifest=$this->manifest(); unset($manifest['fingerprint']); return hash('sha256', PanelAgentGuard::canonicalJson($manifest)); }

	/** @return array<string,mixed> */
	public function manifest(): array {
		$manifest=[
			'type'=>'panel_agent_tool','version'=>1,'name'=>$this->name(),'tool_version'=>$this->version(),
			'description'=>$this->description(),'permission'=>$this->permission(),'risk'=>$this->risk,
			'dry_run_supported'=>$this->dryRunSupported,'confirmation_required'=>$this->confirmationRequired,
			'approval_count'=>$this->approvalCount,'separation_of_duties'=>$this->separationOfDuties,
			'input_schema'=>$this->inputSchema,'hidden'=>$this->hidden,
			'limits'=>['output_bytes'=>$this->outputByteLimit,'error_bytes'=>$this->errorByteLimit],
			'metadata'=>PanelAgentGuard::redact($this->metadata),'callbacks_exposed'=>false,'classes_exposed'=>false,
		];
		$manifest['fingerprint']=hash('sha256', PanelAgentGuard::canonicalJson($manifest));
		return $manifest;
	}

	public function jsonSerialize(): array { return $this->manifest(); }
}
