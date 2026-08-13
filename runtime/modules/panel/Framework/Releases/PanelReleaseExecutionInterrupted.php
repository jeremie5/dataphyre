<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Testable equivalent of a worker/process interruption after an adapter side effect. */
final class PanelReleaseExecutionInterrupted extends \RuntimeException {}
