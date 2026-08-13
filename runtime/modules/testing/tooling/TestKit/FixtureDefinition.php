<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Closure;

final class FixtureDefinition {

	public function __construct(public string $name, public Closure $setup, public ?Closure $teardown=null) {}
}
