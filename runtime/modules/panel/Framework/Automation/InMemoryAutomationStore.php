<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Process-local automation receipt store with idempotency indexing.
 */
final class InMemoryAutomationStore implements AutomationStore, \JsonSerializable {
	/** @var array<string,array<string,mixed>> */
	private array $receipts=[];
	/** @var array<string,string> */
	private array $idempotency=[];

	public function save(AutomationReceipt $receipt): bool {
		if(isset($this->receipts[$receipt->id()])){ return false; }
		if($receipt->idempotencyHash()!==null){
			$key=$receipt->action()."\0".$receipt->idempotencyHash();
			if(isset($this->idempotency[$key])){ return false; }
			$this->idempotency[$key]=$receipt->id();
		}
		$this->receipts[$receipt->id()]=$receipt->jsonSerialize();
		return true;
	}

	public function get(string $receiptId): ?AutomationReceipt {
		$value=$this->receipts[trim($receiptId)] ?? null;
		return is_array($value) ? AutomationReceipt::fromArray($value) : null;
	}

	public function findByIdempotency(string $action, string $idempotencyKey): ?AutomationReceipt {
		$key=WorkflowState::normalize($action)."\0".hash('sha256', trim($idempotencyKey));
		return isset($this->idempotency[$key]) ? $this->get($this->idempotency[$key]) : null;
	}

	public function all(?string $action=null): array {
		$action=$action===null ? null : WorkflowState::normalize($action);
		$result=[];
		foreach($this->receipts as $receipt){
			if($action===null || ($receipt['action'] ?? null)===$action){ $result[]=AutomationReceipt::fromArray($receipt); }
		}
		usort($result, static fn(AutomationReceipt $left, AutomationReceipt $right): int=>strcmp($left->startedAt(), $right->startedAt()) ?: strcmp($left->id(), $right->id()));
		return $result;
	}

	public function jsonSerialize(): array { return ['type'=>'in_memory_automation_store', 'count'=>count($this->receipts)]; }
}
