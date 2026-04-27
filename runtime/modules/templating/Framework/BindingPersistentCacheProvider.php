<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

interface BindingPersistentCacheProvider extends DataBinding {

	public function persistentCache(BindingContext $context): ?array;
}
