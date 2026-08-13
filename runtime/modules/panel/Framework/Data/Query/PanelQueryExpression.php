<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Public immutable node contract for Panel query-expression trees. */
interface PanelQueryExpression extends \JsonSerializable {
	public function type(): string;
	public function depth(): int;
	/** @return list<string> */
	public function fields(): array;
	/** @return list<string> */
	public function operators(): array;
}
