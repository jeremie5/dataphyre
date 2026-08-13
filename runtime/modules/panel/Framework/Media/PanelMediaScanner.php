<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Pluggable malware/content scanner contract for Panel media pipelines. */
interface PanelMediaScanner {
	/** @param array<string,mixed> $context @return array<string,mixed> Result containing at least `clean` boolean. */
	public function scan(PanelMediaDisk $disk, string $path, array $context=[]): array;
}
