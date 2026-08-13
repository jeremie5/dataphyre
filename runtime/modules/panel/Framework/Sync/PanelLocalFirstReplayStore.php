<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Durable boundary used to make browser request sequences exact-replay safe. */
interface PanelLocalFirstReplayStore {
	public function claim(string $credentialId,int $sequence,string $requestDigest,string|int|\DateTimeInterface $now,int $leaseSeconds=30):PanelLocalFirstReplayClaim;
	public function complete(PanelLocalFirstReplayClaim $claim,PanelLocalFirstResponse $response):void;
	public function abandon(PanelLocalFirstReplayClaim $claim):void;
	public function response(string $credentialId,int $sequence):?PanelLocalFirstResponse;
	public function latestSequence(string $credentialId):int;
}
