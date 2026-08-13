<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

// The built-in PHP server does not consistently copy container environment
// variables into $_SERVER for every request. Dataphyre intentionally reads the
// project root from $_SERVER, so the local router makes that boundary explicit.
$_SERVER['DATAPHYRE_PROJECT_ROOT']='/workspace';

require '/opt/dataphyre/runtime/bootstrap.php';
