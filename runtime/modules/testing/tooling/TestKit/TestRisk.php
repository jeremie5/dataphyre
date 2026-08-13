<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Communicates the consequence of a false green result to selection and CI. */
enum TestRisk: string {
	case Low='low';
	case Medium='medium';
	case High='high';
	case Critical='critical';
}
