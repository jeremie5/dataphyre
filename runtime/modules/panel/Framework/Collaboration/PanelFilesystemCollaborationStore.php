<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Cross-process atomic JSON collaboration store with ordered cursor replay. */
final class PanelFilesystemCollaborationStore implements PanelCollaborationStore, \JsonSerializable {
	private PanelAtomicSnapshotStore $store;
	public function __construct(string $directory, int $snapshotRetention=512) {
		$this->store=new PanelAtomicSnapshotStore($directory, 'dataphyre.panel.collaboration.v1', PanelCollaborationStateEngine::initialState(), $snapshotRetention);
	}
	public function state(): array { return $this->store->payload(); }
	public function transaction(callable $mutation, string $type, array $event=[]): mixed {
		$guarded=static function(array &$state) use ($mutation): mixed {
			$before=$state;
			$result=$mutation($state);
			PanelCollaborationStateEngine::assertReceiptAppendOnly($before, $state);
			return $result;
		};
		$result=$this->store->transaction($guarded, $type, PanelCollaborationStateEngine::sanitize($event));
		return $result['result'];
	}
	public function cursor(): int { return $this->store->cursor(); }
	public function changesSince(int $cursor=0, int $limit=100): array {
		$feed=$this->store->changesSince($cursor, $limit);
		if(is_array($feed['snapshot']['payload'] ?? null)){
			$feed['snapshot']['payload']=PanelCollaborationStateEngine::publicState($feed['snapshot']['payload']);
		}
		return $feed;
	}
	public function manifest(array $meta=[]): array {
		return [
			'type'=>'panel_collaboration_store', 'adapter'=>'filesystem_atomic_json', 'cursor'=>$this->cursor(),
			'capabilities'=>['atomic'=>'cross_process', 'ordered_cursor'=>true, 'stale_cursor_reset'=>true, 'crash_recovery'=>true],
			'store'=>$this->store->manifest(), 'meta'=>PanelCollaborationStateEngine::sanitize($meta),
		];
	}
	public function jsonSerialize(): array { return $this->manifest(); }
}
