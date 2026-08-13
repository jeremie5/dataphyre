<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * One named state in a Panel workflow definition.
 */
final class WorkflowState implements \JsonSerializable {
	/** @var list<string> */
	private array $assignmentRoles;

	/** @param list<string> $assignmentRoles @param array<string,mixed> $metadata */
	public function __construct(
		private readonly string $name,
		private readonly string $label='',
		private readonly bool $draft=false,
		private readonly bool $terminal=false,
		private readonly ?int $slaSeconds=null,
		array $assignmentRoles=[],
		private readonly array $metadata=[]
	){
		if(self::normalize($name)===''){
			throw new \InvalidArgumentException('Workflow states require a non-empty name.');
		}
		$this->assignmentRoles=array_values(array_unique(array_filter(array_map(self::normalize(...), $assignmentRoles))));
	}

	/** @param array<string,mixed> $options */
	public static function make(string $name, array $options=[]): self {
		return new self(
			self::normalize($name),
			trim((string)($options['label'] ?? self::humanize($name))),
			($options['draft'] ?? false)===true,
			($options['terminal'] ?? $options['final'] ?? false)===true,
			isset($options['sla_seconds']) ? max(1, (int)$options['sla_seconds']) : null,
			is_array($options['assignment_roles'] ?? null) ? $options['assignment_roles'] : [],
			is_array($options['metadata'] ?? null) ? $options['metadata'] : []
		);
	}

	public function name(): string { return self::normalize($this->name); }
	public function label(): string { return $this->label!=='' ? $this->label : self::humanize($this->name); }
	public function draft(): bool { return $this->draft; }
	public function terminal(): bool { return $this->terminal; }
	public function slaSeconds(): ?int { return $this->slaSeconds; }
	/** @return list<string> */
	public function assignmentRoles(): array { return $this->assignmentRoles; }
	/** @return array<string,mixed> */
	public function metadata(): array { return $this->metadata; }

	public function jsonSerialize(): array {
		return [
			'name'=>$this->name(), 'label'=>$this->label(), 'draft'=>$this->draft,
			'terminal'=>$this->terminal, 'sla_seconds'=>$this->slaSeconds,
			'assignment_roles'=>$this->assignmentRoles, 'metadata'=>$this->metadata,
		];
	}

	public static function normalize(string $value): string {
		$value=strtolower(trim($value));
		return trim((string)preg_replace('/[^a-z0-9]+/', '_', $value), '_');
	}

	private static function humanize(string $value): string {
		return ucwords(str_replace('_', ' ', self::normalize($value)));
	}
}
