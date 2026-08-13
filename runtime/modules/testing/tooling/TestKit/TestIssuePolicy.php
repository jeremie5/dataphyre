<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

enum TestIssuePolicy: string {
	case Inherit='inherit';
	case Fail='fail';
	case Allow='allow';
	case Expect='expect';
}
