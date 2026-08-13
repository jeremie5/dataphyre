<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\TestContext;

/**
 * Concrete, discoverable test context assembled from explicit capabilities.
 *
 * There is no proxy dispatch for built-in behavior: every method is provided by
 * a named trait and checked against TestContext's compile-time contracts.
 */
final class Context extends AbstractContext implements TestContext {

	use InteractsWithExtensions,
		CreatesTestDoubles,
		ManagesRuntimeState,
		ManagesTemporaryFiles,
		RunsProcesses,
		ReadsStructuredData,
		AssertsValues,
		AssertsStructures,
		AssertsDomains,
		MeasuresQuality,
		MatchesSnapshots;
}
