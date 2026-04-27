<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

interface DataBinding {

	public function name(): string;

	public function resolve(BindingContext $context): mixed;
}
