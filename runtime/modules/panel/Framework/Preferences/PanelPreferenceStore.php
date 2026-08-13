<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Atomic persistence contract for versioned per-user Panel workspaces. */
interface PanelPreferenceStore {
	public function load(string $userId, string $profile='default'): ?PanelWorkspacePreferenceProfile;
	public function save(PanelWorkspacePreferenceProfile $profile, ?int $expectedRevision=null, string $strategy='reject'): PanelWorkspacePreferenceProfile;
	public function delete(string $userId, string $profile='default', ?int $expectedRevision=null): bool;
	/** @return array<int,PanelWorkspacePreferenceProfile> */
	public function profiles(string $userId): array;
	/** @return array<int,PanelWorkspacePreferenceProfile> */
	public function history(string $userId, string $profile='default', int $limit=100): array;
	/** @return array<string,mixed> */
	public function export(string $userId, ?string $profile=null): array;
	/** @param array<string,mixed> $payload @return array<int,PanelWorkspacePreferenceProfile> */
	public function import(array $payload, string $strategy='merge'): array;
	public function cursor(): int;
	/** @return array<string,mixed> */
	public function changesSince(int $cursor=0, int $limit=100): array;
	/** @return array<string,mixed> */
	public function manifest(array $meta=[]): array;
}
