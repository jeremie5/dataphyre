<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Describes the narrowest boundary exercised by a test contract. */
enum TestLayer: string {
	case Unit='unit';
	case Integration='integration';
	case Contract='contract';
	case Feature='feature';
	case Browser='browser';
	case Architecture='architecture';
	case Performance='performance';
	case Security='security';
	case System='system';
}
