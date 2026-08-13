<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Host-pluggable, read-only identity directory for Studio collaboration. */
interface PanelStudioIdentityConnector extends \JsonSerializable {
	/** Null means that the host connector is already scoped outside Panel. */
	public function tenantId():?string;
	/** @param list<string|int> $ids @return array<string,PanelStudioIdentityProfile> */
	public function resolve(array $ids):array;
	/** @return list<PanelStudioIdentityProfile> */
	public function search(string $query='',int $limit=25):array;
	/** @return array<string,mixed> */
	public function manifest():array;
}
