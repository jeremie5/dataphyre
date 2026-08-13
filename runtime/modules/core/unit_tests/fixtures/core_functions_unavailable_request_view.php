<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$state=\Dataphyre\Test\TestState::channel('core.functions.unavailable');
if($state->get('view_throws', false)===true){
	throw new RuntimeException('unavailable fixture failed');
}
$state->put('view_loaded', true);
