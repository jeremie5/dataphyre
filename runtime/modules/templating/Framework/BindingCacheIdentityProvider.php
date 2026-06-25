<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Lets a data binding provide a stable identity for cache partitioning.
 *
 * Bindings implement this contract when their output should be cached per
 * tenant, user, route, argument set, or another domain-specific identity instead
 * of only by binding name. The returned value must be deterministic for the
 * current BindingContext.
 */
interface BindingCacheIdentityProvider extends DataBinding {

	/**
	 * Returns the cache identity associated with this binding invocation.
	 *
	 * @param BindingContext $context Binding invocation context.
	 * @return mixed deterministic scalar, array, or object identity used to partition binding cache entries.
	 */
	public function cacheIdentity(BindingContext $context): mixed;
}
