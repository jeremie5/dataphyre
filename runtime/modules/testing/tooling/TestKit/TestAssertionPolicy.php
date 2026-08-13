<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

enum TestAssertionPolicy: string {
	case Inherit='inherit';
	case Require='require';
	case AllowNone='allow_none';
}
