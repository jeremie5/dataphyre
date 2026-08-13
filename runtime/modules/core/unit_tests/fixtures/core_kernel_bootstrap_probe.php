<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$source=(string)($argv[1] ?? '');
require $source;
echo json_encode([
	'application_definition'=>class_exists(\dataphyre\application_definition::class, false),
	'app_locator'=>class_exists(\dataphyre\app_locator::class, false),
	'autoloader'=>class_exists(\dataphyre\autoloader::class, false),
	'runtime'=>class_exists(\dataphyre\runtime::class, false),
], JSON_THROW_ON_ERROR);
