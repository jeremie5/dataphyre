<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Routing;

/**
 * Exposes a route definition as compiler-ready metadata.
 *
 * Compiled route metadata is consumed by dispatchers and cache warmers that
 * need stable method, path, middleware, and controller metadata without keeping
 * the original route object alive.
 */
interface CompilableRoute {

	/**
	 * Returns route metadata for dispatchers and diagnostics.
	 *
	 * @return array<string,mixed> Compiler-ready route metadata.
	 */
	public function compile(): array;
}
