<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Mvc\RouteDefinition;

return RouteDefinition::make(
	'GET',
	'/fixture-definition',
	static fn(): string=>'definition',
	['name'=>'fixture.definition']
);
