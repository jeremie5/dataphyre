<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Composable Stripe webhook entrypoint for routing and direct legacy includes. */
final class dataphyre_stripe_webhook_endpoint {
	/** @param array<string,mixed> $runtime */
	public static function bootstrap(?bool $dispatch=null, array $runtime=[]): mixed {
		$dispatch ??=!defined('DATAPHYRE_STRIPE_WEBHOOK_NO_DISPATCH');
		if(!$dispatch){
			return null;
		}
		return \dataphyre\stripe::handle_webhook($runtime);
	}
}

dataphyre_stripe_webhook_endpoint::bootstrap();
