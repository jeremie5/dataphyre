<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Narrow SQL execution seam used by the reference PDO adapter and conformance fakes. */
interface PanelSqlExecutor {
	public function driver(): string;

	/** @param array<string, null|bool|int|float|string> $parameters @return list<array<string,mixed>> */
	public function rows(string $sql, array $parameters=[]): array;

	/** @param array<string, null|bool|int|float|string> $parameters */
	public function scalar(string $sql, array $parameters=[]): mixed;

	/** @return array<string,mixed> */
	public function manifest(): array;
}
