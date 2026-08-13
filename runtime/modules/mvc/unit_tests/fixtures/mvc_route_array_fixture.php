<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

return [
	[
		'method'=>'GET',
		'path'=>'/fixture-array',
		'handler'=>static fn(): string=>'array',
		'name'=>'fixture.array',
	],
];
