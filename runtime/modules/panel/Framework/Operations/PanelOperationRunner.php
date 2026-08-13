<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Execution boundary for submitting and consuming persistent operations. */
interface PanelOperationRunner {
	/** @param array<string, mixed> $options */
	public function submit(string $type, string $name='operation', mixed $payload=[], array $options=[]): PanelOperationRecord;
	public function run(string $id): PanelOperationRecord;
	/** @return list<PanelOperationRecord> */
	public function work(?string $queue=null, int $maxJobs=1, string $worker='local'): array;
}
