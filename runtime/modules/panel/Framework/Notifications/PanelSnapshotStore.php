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
 * Atomic state and retained-change-feed boundary used by Panel managers.
 *
 * Implementations may use local snapshots, SQL, or another durable substrate,
 * but transactions must either publish one complete payload or leave the prior
 * payload untouched. A transaction implementation must invoke the supplied
 * mutation callback at most once per transaction() call; commit uncertainty and
 * post-callback conflicts are surfaced rather than hidden through callback
 * replay.
 */
interface PanelSnapshotStore extends \JsonSerializable {
	/** @return array{schema:string,sequence:int,committed_at:?string,payload:array<string,mixed>,event:?array<string,mixed>} */
	public function snapshot(): array;
	/** @return array<string,mixed> */
	public function payload(): array;
	public function cursor(): int;
	/**
	 * @param callable(array<string,mixed>&):mixed $mutation
	 * @param array<string,mixed> $event
	 * @return array{result:mixed,snapshot:array<string,mixed>}
	 */
	public function transaction(callable $mutation, string $type, array $event=[]): array;
	/** @return array{cursor:int,oldest_cursor:int,reset_required:bool,reset_reason?:?string,changes:array<int,array<string,mixed>>,snapshot:?array<string,mixed>} */
	public function changesSince(int $cursor=0, int $limit=100): array;
	/** @return array<string,mixed> */
	public function manifest(): array;
	/** @return array<string,mixed> */
	public function jsonSerialize(): array;
}
