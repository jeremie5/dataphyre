<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Pluggable media transformation/variant generation contract. */
interface PanelMediaTransformer {
	/** @param array<string,array<string,mixed>> $variants @param array<string,mixed> $context @return array<string,mixed> */
	public function transform(PanelMediaDisk $disk, string $path, array $variants=[], array $context=[]): array;
}
