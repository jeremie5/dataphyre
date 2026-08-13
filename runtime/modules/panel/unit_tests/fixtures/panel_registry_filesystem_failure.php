<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Test-only writability adapter controlled by the owning TestState scenario. */
function is_writable(string $filename): bool {
	$scenario=\Dataphyre\Test\TestState::channelIfActive('panel.package-registry.filesystem');
	$denied=$scenario?->get('deny_writable_path');
	$normalize=static fn(string $path): string=>rtrim(str_replace('\\', '/', $path), '/');
	if(is_string($denied) && $denied!=='' && $normalize($filename)===$normalize($denied)){
		return false;
	}
	return \is_writable($filename);
}
