<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Atomic state and ordered event-feed contract for Panel collaboration. */
interface PanelCollaborationStore {
	/** @return array<string,mixed> */
	public function state(): array;
	/** @param callable(array<string,mixed>&):mixed $mutation @param array<string,mixed> $event */
	public function transaction(callable $mutation, string $type, array $event=[]): mixed;
	public function cursor(): int;
	/** @return array<string,mixed> */
	public function changesSince(int $cursor=0, int $limit=100): array;
	/** @return array<string,mixed> */
	public function manifest(array $meta=[]): array;
}
