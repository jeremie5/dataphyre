<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$login=(string)($argv[1] ?? '');
if($login===''){
	throw new InvalidArgumentException('DataDoc login entrypoint argument is required.');
}
require $login;
