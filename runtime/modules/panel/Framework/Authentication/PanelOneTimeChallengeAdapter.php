<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Delivery boundary for email/SMS/application one-time step-up challenges. */
interface PanelOneTimeChallengeAdapter {
	/** @param array<string,mixed> $context */
	public function dispatch(string $challengeId,string $recipient,string $purpose,int $expiresAt,array $context=[]): PanelOneTimeChallengeDispatch;
}
