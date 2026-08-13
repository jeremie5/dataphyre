<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test\Contracts;

/** The complete stable capability surface supplied to a Dataphyre test. */
interface TestContext extends ExtensibleContext, DoubleContext, RuntimeContext, ProcessContext, AssertionContext {}
