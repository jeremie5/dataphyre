<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Raised when optimistic persistence detects a stale operation revision. */
final class PanelOperationConflict extends \RuntimeException {}
