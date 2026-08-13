<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Storage-neutral contract used by Panel upload and media processing runtimes. */
interface PanelMediaDisk {
	public function name(): string;
	public function normalizePath(string $path): string;
	/** @param array<string,mixed> $options @return array<string,mixed> */
	public function write(string $path, string $contents, array $options=[]): array;
	/** @param resource $stream @param array<string,mixed> $options @return array<string,mixed> */
	public function writeStream(string $path, mixed $stream, array $options=[]): array;
	public function read(string $path, int $maxBytes=0): string;
	/** @return resource */
	public function readStream(string $path): mixed;
	public function exists(string $path): bool;
	public function delete(string $path): bool;
	/** @return array<string,mixed> */
	public function move(string $from, string $to, bool $overwrite=false): array;
	/** @return array<string,mixed> */
	public function copy(string $from, string $to, bool $overwrite=false): array;
	public function size(string $path): int;
	public function checksum(string $path, string $algorithm='sha256'): string;
	public function modifiedAt(string $path): int;
	/** @return array<int,array<string,mixed>> */
	public function list(string $prefix='', bool $recursive=true): array;
	/** @return array<string,mixed> */
	public function descriptor(string $path): array;
	/** @return array<string,mixed> */
	public function manifest(): array;
}
