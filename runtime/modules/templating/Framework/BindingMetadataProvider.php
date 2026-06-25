<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Exposes serializable metadata for template data bindings.
 *
 * Implementations use this payload to describe cache identity, source queries,
 * trace labels, or other binding facts that should travel with render
 * diagnostics without forcing the manager to inspect provider internals.
 */
interface BindingMetadataProvider extends DataBinding {

	/**
	 * Returns metadata merged into binding trace and manifest records.
	 *
	 * @return array<string,mixed> Serializable binding metadata.
	 */
	public function metadata(): array;
}
