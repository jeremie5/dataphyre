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
 * Nested relation workspace with bulk operations, policy checks, concurrency,
 * history, idempotency, breadcrumbs, and snapshot rollback.
 */
final class PanelRelationWorkspace implements \JsonSerializable {
	private $authorizer=null;
	private array $receipts=[];
	private array $history=[];
	private array $breadcrumbs=[];

	public function __construct(
		private readonly string $name,
		private readonly string|int $parentKey,
		private readonly PanelRelationAdapter $adapter
	){
		if(trim($name)===''){ throw new \InvalidArgumentException('A relation workspace name is required.'); }
	}

	public static function make(string $name, string|int $parentKey, PanelRelationAdapter $adapter): self { return new self($name, $parentKey, $adapter); }
	public function authorize(?callable $authorizer): self { $clone=clone $this; $clone->authorizer=$authorizer; return $clone; }
	public function breadcrumbs(array $breadcrumbs): self { $clone=clone $this; $clone->breadcrumbs=array_values(array_filter($breadcrumbs, 'is_array')); return $clone; }

	public function execute(PanelRelationWorkspaceCommand $command): PanelRelationWorkspaceResult {
		if(isset($this->receipts[$command->idempotencyKey()])){
			$receipt=$this->receipts[$command->idempotencyKey()];
			return new PanelRelationWorkspaceResult('duplicate', $command->operation(), $receipt->version(), $receipt->records(), $receipt->snapshot(), [], ['idempotency_key'=>$command->idempotencyKey()]);
		}
		$version=$this->adapter->version($this->parentKey);
		if($command->expectedVersion()!==null && $command->expectedVersion()!==$version){
			return new PanelRelationWorkspaceResult('conflict', $command->operation(), $version, $this->adapter->records($this->parentKey), [], ['Relation version changed.'], ['expected_version'=>$command->expectedVersion()]);
		}
		if($this->authorizer!==null){
			$decision=($this->authorizer)($command, $this->parentKey, $this->name, $this->adapter->records($this->parentKey));
			if($decision!==true){
				$errors=is_array($decision) ? $decision : [(string)($decision ?: 'Relation operation is not authorized.')];
				return new PanelRelationWorkspaceResult('denied', $command->operation(), $version, $this->adapter->records($this->parentKey), [], $errors);
			}
		}

		$snapshot=$this->adapter->snapshot($this->parentKey);
		try {
			switch($command->operation()){
				case 'attach': foreach($command->keys() as $key){ $this->adapter->attach($this->parentKey, $key, self::valuesFor($command->values(), $key)); } break;
				case 'detach': foreach($command->keys() as $key){ $this->adapter->detach($this->parentKey, $key); } break;
				case 'update_pivot': foreach($command->keys() as $key){ $this->adapter->updatePivot($this->parentKey, $key, self::valuesFor($command->values(), $key)); } break;
				case 'reorder': $this->adapter->reorder($this->parentKey, $command->keys()); break;
				case 'restore': $this->adapter->restore($this->parentKey, $command->values()); break;
			}
		} catch(\Throwable $exception){
			$this->adapter->restore($this->parentKey, $snapshot);
			return new PanelRelationWorkspaceResult('failed', $command->operation(), $this->adapter->version($this->parentKey), $this->adapter->records($this->parentKey), $snapshot, [$exception->getMessage()], ['rolled_back'=>true]);
		}

		$result=new PanelRelationWorkspaceResult('committed', $command->operation(), $this->adapter->version($this->parentKey), $this->adapter->records($this->parentKey), $snapshot, [], [
			'idempotency_key'=>$command->idempotencyKey(), 'actor'=>$command->actor(), 'timestamp'=>gmdate('c'), 'command'=>$command->jsonSerialize(),
		]);
		$this->receipts[$command->idempotencyKey()]=$result;
		$this->history[]=$result->jsonSerialize();
		return $result;
	}

	public function undo(PanelRelationWorkspaceResult $receipt, string $idempotencyKey): PanelRelationWorkspaceResult {
		return $this->execute(PanelRelationWorkspaceCommand::make('restore', [], $receipt->snapshot(), ['expected_version'=>$this->adapter->version($this->parentKey), 'idempotency_key'=>$idempotencyKey, 'metadata'=>['undo_operation'=>$receipt->jsonSerialize()['operation']]]));
	}

	public function records(): array { return $this->adapter->records($this->parentKey); }
	public function available(): array { return $this->adapter->available($this->parentKey); }
	public function history(): array { return $this->history; }

	public function manifest(): array {
		return [
			'type'=>'relation_workspace', 'name'=>$this->name, 'parent_key'=>(string)$this->parentKey,
			'version'=>$this->adapter->version($this->parentKey), 'records'=>$this->records(), 'available'=>$this->available(),
			'breadcrumbs'=>$this->breadcrumbs, 'history'=>$this->history,
			'capabilities'=>['bulk'=>true, 'attach'=>true, 'detach'=>true, 'pivot'=>true, 'reorder'=>true, 'undo'=>true, 'optimistic_concurrency'=>true, 'idempotency'=>true],
		];
	}
	public function jsonSerialize(): array { return $this->manifest(); }

	private static function valuesFor(array $values, string $key): array {
		return is_array($values[$key] ?? null) ? $values[$key] : $values;
	}
}
