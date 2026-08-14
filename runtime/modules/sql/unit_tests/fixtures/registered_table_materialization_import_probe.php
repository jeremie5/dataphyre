<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$internal='Dataphyre\\InternalApplicationBootstrapOnly';
$before=\class_exists($internal,false);
require_once \dirname(__DIR__,2).'/Framework/RegisteredTableMaterializationCommand.php';
$after=\class_exists($internal,false);
$unrelatedContext=$after ? $internal::context() : null;

\fwrite(\STDOUT,\json_encode([
	'after'=>$after,
	'before'=>$before,
	'unrelated_context'=>$unrelatedContext,
],\JSON_THROW_ON_ERROR|\JSON_UNESCAPED_SLASHES).\PHP_EOL);
