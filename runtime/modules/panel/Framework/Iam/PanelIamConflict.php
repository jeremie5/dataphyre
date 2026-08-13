<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Optimistic-concurrency or idempotency conflict at the IAM control plane. */
final class PanelIamConflict extends \RuntimeException {}
