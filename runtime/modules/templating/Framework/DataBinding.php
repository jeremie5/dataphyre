<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Describes a lazily resolved value in a template data graph.
 *
 * Data bindings let render callers defer SQL, search, cacheable, or conditional
 * data work until the templating manager has a complete BindingContext with
 * template identity, theme values, slots, overrides, and trace metadata.
 */
interface DataBinding {

	/**
	 * Returns the human-readable binding name used in manifests and traces.
	 *
	 * @return string Stable label for diagnostics, cache reports, and debug output.
	 */
	public function name(): string;

	/**
	 * Resolves the binding for the current render context.
	 *
	 * Implementations may return scalars, arrays, objects, or BindingResolution
	 * wrappers. Thrown errors are caught by the manager and represented in the
	 * binding manifest rather than aborting the whole render.
	 *
	 * @param BindingContext $context Render-time context and trace metadata.
	 * @return mixed scalar, array, object, or BindingResolution consumed by the template data merge.
	 */
	public function resolve(BindingContext $context): mixed;
}
