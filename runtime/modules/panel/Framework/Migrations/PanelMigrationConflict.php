<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Base conflict for lease, plan, and atomic state races. */
class PanelMigrationConflict extends \RuntimeException {}
