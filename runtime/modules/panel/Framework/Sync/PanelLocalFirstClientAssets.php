<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Isolated browser and service-worker assets for Panel's local-first protocol. */
final class PanelLocalFirstClientAssets {
	private const CLIENT='PanelLocalFirstClient.js';
	public static function javascript():string{$body=file_get_contents(__DIR__.'/'.self::CLIENT);if(!is_string($body)||$body===''){throw new \RuntimeException('Panel local-first client asset is unavailable.');}return$body;}
	public static function serviceWorker():string{return self::javascript()."\nDataphyrePanelLocalFirst.installServiceWorker(self);\n";}
	public static function version(string $asset='client'):string{return substr(hash('sha256',$asset==='service_worker'?self::serviceWorker():self::javascript()),0,16);}
	/** @return array{content_type:string,body:string,etag:string,cache_control:string} */public static function content(string $asset='client',bool $versionedUrl=false):array{if(!in_array($asset,['client','service_worker'],true)){throw new \InvalidArgumentException('Unknown Panel local-first browser asset.');}$body=$asset==='service_worker'?self::serviceWorker():self::javascript();return['content_type'=>'application/javascript; charset=utf-8','body'=>$body,'etag'=>'"'.substr(hash('sha256',$body),0,32).'"','cache_control'=>$versionedUrl?'public, max-age=31536000, immutable':'no-cache'];}
	/** @return array<string,mixed> */public static function manifest():array{return PanelManifestContract::stamp(['type'=>'panel_local_first_client_asset_manifest','version'=>1,'client_version'=>self::version(),'service_worker_version'=>self::version('service_worker'),'shared_asset_registered'=>false,'host_routes_required'=>true,'gateway_protocol'=>'panel_local_first_request_v1','storage'=>'indexeddb','at_rest_encryption'=>'aes_256_gcm','device_signatures'=>'ecdsa_p256','response_authentication'=>'hmac_sha256','canonical_numbers'=>'ieee754_big_endian','capabilities'=>['encrypted_queue'=>true,'non_extractable_keys'=>true,'universal_mutations'=>true,'vector_clock_documents'=>true,'operation_branches'=>true,'exact_request_replay'=>true,'signed_response_before_dequeue'=>true,'background_sync'=>true,'service_worker'=>true,'web_locks'=>true,'indexeddb_fenced_fallback'=>true,'cross_tab_coordination'=>true,'conflict_center'=>true,'accessible_status_binding'=>true,'host_projected_scope'=>true,'same_origin_by_default'=>true,'credential_query_rejected'=>true,'local_storage'=>false,'html_evaluation'=>false],'integration'=>['client_route_registered'=>false,'service_worker_route_registered'=>false,'device_registration_route_registered'=>false,'gateway_route_registered'=>false,'host_authorization_required'=>true,'host_origin_policy_required'=>true,'response_verification_key_bootstrap_required'=>true],'secrets_exposed'=>false]);}
}
