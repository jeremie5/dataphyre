<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Defines the smallest safe worker boundary for a case. */
enum TestIsolation: string {
	case CaseScope='case';
	case File='file';
	case Process='process';
	case Shared='shared';
}
