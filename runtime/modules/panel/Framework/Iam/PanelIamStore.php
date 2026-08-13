<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Atomic tenant-scoped persistence boundary for the IAM control plane. */
interface PanelIamStore extends \JsonSerializable {
	/** @return array<string,mixed> */
	public function read(string|int $tenantId):array;
	/** @param callable(array<string,mixed>&):mixed $mutation @param array<string,mixed> $event */
	public function transaction(string|int $tenantId,callable $mutation,string $type,array $event=[]):mixed;
	public function cursor():int;
	/** @return array<string,mixed> */
	public function manifest():array;
}
