<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 *
 * Runtime-inert TestKit bootstrap. Classes autoload only when a test uses them;
 * the DSL functions are declared eagerly because PHP cannot autoload functions.
 */

namespace Dataphyre\Test;

require_once __DIR__.'/PhpRuntime.php';
require_once __DIR__.'/TypeInventory.php';
require_once __DIR__.'/PathSemantics.php';
require_once __DIR__.'/TestKit/TestKitAutoloader.php';

TestKitAutoloader::register(__DIR__.'/TestKit');

require_once __DIR__.'/TestKit/functions.php';
