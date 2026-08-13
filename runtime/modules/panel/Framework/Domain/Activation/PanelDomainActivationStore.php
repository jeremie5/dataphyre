<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Durable optimistic state boundary for activated domain compilations. */
interface PanelDomainActivationStore {
	/** @return array<string,mixed> */public function payload():array;
	/** @param callable(array<string,mixed>&):mixed $mutation @param array<string,mixed> $event @return array{result:mixed,snapshot:array<string,mixed>} */public function transaction(callable $mutation,string $type,array $event=[]):array;
	/** @return array<string,mixed> */public function changesSince(int $cursor=0,int $limit=100):array;
}
