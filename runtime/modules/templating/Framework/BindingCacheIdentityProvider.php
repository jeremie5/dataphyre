<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

interface BindingCacheIdentityProvider extends DataBinding {

	public function cacheIdentity(BindingContext $context): mixed;
}
