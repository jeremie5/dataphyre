<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

enum TestOutputPolicy: string {
	case Inherit='inherit';
	case Forbid='forbid';
	case Allow='allow';
	case Expect='expect';
}
