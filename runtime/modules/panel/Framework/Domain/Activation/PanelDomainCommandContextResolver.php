<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Trusted host bridge from a rendered Panel action into a scoped command invocation. */
interface PanelDomainCommandContextResolver {
	/** @param array<string,mixed> $data */
	public function resolve(PanelDomainCommandDefinition $command,mixed $record,array $data,mixed $request,?Resource $resource):PanelDomainCommandInvocation;
}
