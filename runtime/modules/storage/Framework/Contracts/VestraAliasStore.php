<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Contracts;

/**
 * Durable logical paths for Vestra object references.
 *
 * Implementations must isolate their application/disk namespace and commit each
 * put or delete atomically. Store references, never credentials or signed URLs.
 * Operational failures must return false for writes or throw for reads; they
 * must not be reported as an absent alias. List returns path => reference pairs.
 */
interface VestraAliasStore {
	/** @return array<string,mixed>|null Null only when the path does not exist. */
	public function lookup(string $path): ?array;
	/** @param array<string,mixed> $reference */
	public function put(string $path, array $reference): bool;
	public function delete(string $path): bool;
	/** @return array<string,array<string,mixed>> */
	public function list(string $prefix=''): array;
}
