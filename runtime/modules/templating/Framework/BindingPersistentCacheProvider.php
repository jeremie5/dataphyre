<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Lets a data binding describe its persistent cache policy.
 *
 * Implementations return cache metadata for bindings whose rendered data can
 * survive beyond the current request. The templating layer owns persistence and
 * invalidation; the binding supplies only the policy shape.
 */
interface BindingPersistentCacheProvider extends DataBinding {

	/**
	 * Returns the persistent cache policy for this binding invocation.
	 *
	 * @param BindingContext $context Binding invocation context.
	 * @return ?array Cache policy payload, or null when persistent caching is disabled.
	 */
	public function persistentCache(BindingContext $context): ?array;
}
