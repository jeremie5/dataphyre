<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;

/** One idempotent application-owned mutation proposed by a simulation rule. */
final class SimulationIntent implements JsonSerializable {
	private string $id;
	private string $type;
	private string $origin;
	/** @var array<int,string> */
	private array $affects;
	/** @var array<string,mixed> */
	private array $payload;
	private ?DateTimeImmutable $dueAt;
	private string $correlationId;
	private ?string $causationId;

	/** @param array<int,string> $affects @param array<string,mixed> $payload */
	public function __construct(
		string $type,
		array $payload=[],
		string $id='',
		string $origin='unknown',
		array $affects=[],
		?DateTimeImmutable $dueAt=null,
		string $correlationId='',
		?string $causationId=null,
	) {
		$this->type=self::name($type, 'simulation.intent');
		$this->payload=$payload;
		$this->id=trim($id);
		$this->origin=self::name($origin, 'unknown');
		$this->affects=self::names($affects);
		$this->dueAt=$dueAt;
		$this->correlationId=trim($correlationId);
		$this->causationId=($causationId=trim((string)$causationId))!=='' ? $causationId : null;
	}

	/** @param array<string,mixed> $payload */
	public static function make(string $type, array $payload=[]): self {
		return new self($type, $payload);
	}

	/** @param array<string,mixed> $state */
	public static function fromArray(array $state): ?self {
		$type=trim((string)($state['type'] ?? ''));
		if($type==='') return null;
		$dueAt=null;
		$due=trim((string)($state['due_at'] ?? ''));
		if($due!==''){
			try{ $dueAt=new DateTimeImmutable($due); }catch(\Throwable){ return null; }
		}
		return new self(
			$type,
			is_array($state['payload'] ?? null) ? $state['payload'] : [],
			(string)($state['id'] ?? ''),
			(string)($state['origin'] ?? 'unknown'),
			is_array($state['affects'] ?? null) ? $state['affects'] : [],
			$dueAt,
			(string)($state['correlation_id'] ?? ''),
			isset($state['causation_id']) ? (string)$state['causation_id'] : null,
		);
	}

	/** @param array<int,string> $affects */
	public function enveloped(string $id, string $origin, array $affects, string $correlationId, ?string $causationId=null): self {
		return new self($this->type, $this->payload, $id, $origin, $affects, $this->dueAt, $correlationId, $causationId ?? $this->causationId);
	}

	public function scheduledAt(DateTimeImmutable $dueAt): self {
		return new self($this->type, $this->payload, $this->id, $this->origin, $this->affects, $dueAt, $this->correlationId, $this->causationId);
	}

	public function afterSeconds(int $seconds, ?DateTimeImmutable $from=null): self {
		$from=$from ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
		return $this->scheduledAt($from->modify('+'.max(0, $seconds).' seconds'));
	}

	public function id(): string { return $this->id; }
	public function type(): string { return $this->type; }
	public function origin(): string { return $this->origin; }
	/** @return array<int,string> */
	public function affects(): array { return $this->affects; }
	/** @return array<string,mixed> */
	public function payload(): array { return $this->payload; }
	public function dueAt(): ?DateTimeImmutable { return $this->dueAt; }
	public function correlationId(): string { return $this->correlationId; }
	public function causationId(): ?string { return $this->causationId; }

	public function isDue(DateTimeImmutable $now): bool {
		return $this->dueAt===null || $this->dueAt<=$now;
	}

	/** Safe journal projection: application payloads are deliberately excluded. */
	public function evidence(): array {
		return [
			'id'=>$this->id,
			'type'=>$this->type,
			'origin'=>$this->origin,
			'affects'=>$this->affects,
			'due_at'=>$this->dueAt?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
			'correlation_id'=>$this->correlationId,
			'causation_id'=>$this->causationId,
		];
	}

	public function jsonSerialize(): array {
		return $this->evidence()+['payload'=>$this->payload];
	}

	/** @param array<int,mixed> $values @return array<int,string> */
	private static function names(array $values): array {
		$names=[];
		foreach($values as $value){
			$name=self::name((string)$value, '');
			if($name!=='') $names[]=$name;
		}
		return array_values(array_unique($names));
	}

	private static function name(string $value, string $fallback): string {
		$value=strtolower(trim($value));
		$value=preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?? '';
		$value=trim($value, '_.-');
		return $value!=='' ? substr($value, 0, 128) : $fallback;
	}
}
