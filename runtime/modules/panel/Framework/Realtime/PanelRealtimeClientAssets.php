<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Isolated fetch-streamed SSE client asset facade. Host applications own registration and URLs. */
final class PanelRealtimeClientAssets {
	public static function javascript(): string {
		$body=file_get_contents(__DIR__.'/PanelRealtimeClient.js');
		if(!is_string($body) || $body===''){ throw new \RuntimeException('Panel realtime client asset is unavailable.'); }
		return $body;
	}
	public static function version(): string { return substr(hash('sha256',self::javascript()),0,16); }
	/** @return array{content_type:string,body:string,etag:string,cache_control:string} */
	public static function content(bool $versionedUrl=false): array { $body=self::javascript(); return ['content_type'=>'application/javascript; charset=utf-8','body'=>$body,'etag'=>'"'.substr(hash('sha256',$body),0,32).'"','cache_control'=>$versionedUrl?'public, max-age=31536000, immutable':'no-cache']; }
	/** @return array<string,mixed> */
	public static function manifest(): array { return ['type'=>'panel_realtime_client_asset','version'=>1,'asset_version'=>self::version(),'transport'=>'fetch_streamed_sse','native_event_source'=>false,'shared_asset_registered'=>false,'host_route_required'=>true,'connect_timeout'=>true,'query_requires_opt_in'=>true,'credential_named_query_keys_rejected'=>true,'cross_origin_requires_exact_allowlist'=>true,'https_downgrade_rejected'=>true,'host_owned_subscription_intent_provider'=>true,'public_credentials_exposed'=>false,'html_evaluation'=>false]; }
}
