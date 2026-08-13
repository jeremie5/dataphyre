<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** One immutable, normalized, policy-stamped step in an agent plan. */
final class PanelAgentPlanStep implements \JsonSerializable {
	/** @param array<string,mixed> $arguments */
	public function __construct(
		private readonly int $ordinal,
		private readonly string $tool,
		private readonly string $toolVersion,
		private readonly string $toolFingerprint,
		private readonly array $arguments,
		private readonly bool $dryRun,
		private readonly int $approvalCount,
		private readonly bool $confirmationRequired,
		private readonly bool $separationOfDuties
	){
		if($ordinal<1 || $ordinal>32){ throw new \InvalidArgumentException('Panel agent plan step ordinal is invalid.'); }
		PanelAgentGuard::identifier($tool, 'tool', 128);
		PanelAgentGuard::boundedString($toolVersion, 'tool version', 64);
		PanelAgentGuard::digest($toolFingerprint, 'tool fingerprint');
		PanelAgentGuard::assertJson($arguments);
		if($approvalCount<0 || $approvalCount>2){ throw new \InvalidArgumentException('Panel agent plan step approval count is invalid.'); }
		if($separationOfDuties && $approvalCount===0){ throw new \InvalidArgumentException('Panel agent plan step separation requires an approval.'); }
	}

	public function ordinal(): int { return $this->ordinal; }
	public function tool(): string { return strtolower(trim($this->tool)); }
	public function toolVersion(): string { return trim($this->toolVersion); }
	public function toolFingerprint(): string { return strtolower($this->toolFingerprint); }
	/** @return array<string,mixed> */ public function arguments(): array { return $this->arguments; }
	public function dryRun(): bool { return $this->dryRun; }
	public function approvalCount(): int { return $this->approvalCount; }
	public function confirmationRequired(): bool { return $this->confirmationRequired; }
	public function separationOfDuties(): bool { return $this->separationOfDuties; }

	/**
	 * Returns the complete step contract for an encrypted execution boundary.
	 *
	 * Unlike jsonSerialize(), this payload deliberately contains raw normalized
	 * arguments. It must never be exposed in a manifest, log, URL, or client-side
	 * diagnostic; the command fabric seals it before durable persistence.
	 *
	 * @return array<string,mixed>
	 */
	public function executionPayload(): array {
		return [
			'ordinal'=>$this->ordinal,'tool'=>$this->tool(),'tool_version'=>$this->toolVersion(),
			'tool_fingerprint'=>$this->toolFingerprint(),'arguments'=>$this->arguments,'dry_run'=>$this->dryRun,
			'approval_count'=>$this->approvalCount,'confirmation_required'=>$this->confirmationRequired,
			'separation_of_duties'=>$this->separationOfDuties,
		];
	}

	/** @param array<string,mixed> $payload */
	public static function hydrateExecutionPayload(array $payload): self {
		$required=['ordinal','tool','tool_version','tool_fingerprint','arguments','dry_run','approval_count','confirmation_required','separation_of_duties'];
		$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if(
			$keys!==$required
			||!is_int($payload['ordinal']??null)
			||!is_string($payload['tool']??null)
			||!is_string($payload['tool_version']??null)
			||!is_string($payload['tool_fingerprint']??null)
			||!is_array($payload['arguments']??null)
			||!is_bool($payload['dry_run']??null)
			||!is_int($payload['approval_count']??null)
			||!is_bool($payload['confirmation_required']??null)
			||!is_bool($payload['separation_of_duties']??null)
		){
			throw new \UnexpectedValueException('Stored Panel agent execution step is invalid.');
		}
		try{
			return new self(
				$payload['ordinal'],$payload['tool'],$payload['tool_version'],$payload['tool_fingerprint'],$payload['arguments'],
				$payload['dry_run'],$payload['approval_count'],$payload['confirmation_required'],$payload['separation_of_duties'],
			);
		}catch(\Throwable $error){
			throw new \UnexpectedValueException('Stored Panel agent execution step is invalid.',0,$error);
		}
	}

	public function jsonSerialize(): array {
		return [
			'ordinal'=>$this->ordinal,'tool'=>$this->tool(),'tool_version'=>$this->toolVersion(),
			'tool_fingerprint'=>$this->toolFingerprint(),'arguments'=>PanelAgentGuard::redact($this->arguments),'dry_run'=>$this->dryRun,
			'approval_count'=>$this->approvalCount,'confirmation_required'=>$this->confirmationRequired,
			'separation_of_duties'=>$this->separationOfDuties,
		];
	}
}
