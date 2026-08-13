<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Standalone cacheable assets for the route-free progressive Studio editor. */
final class PanelStudioEditorAssets implements \JsonSerializable {
	public const VERSION=2;
	public static function css():string{return self::read('panel-studio-editor.css');}
	public static function javascript():string{return self::read('panel-studio-editor.js');}
	public static function styleTag(string $nonce=''):string{return'<style'.self::nonce($nonce).'>'.self::css().'</style>';}
	public static function scriptTag(string $nonce=''):string{return'<script'.self::nonce($nonce).'>'.self::javascript().'</script>';}
	public static function manifest():array{
		$css=self::css();$javascript=self::javascript();return['type'=>'panel_studio_editor_assets','version'=>self::VERSION,'capability'=>'studio-editor','styles'=>['name'=>'panel-studio-editor.css','bytes'=>strlen($css),'sha256'=>hash('sha256',$css),'integrity'=>'sha384-'.base64_encode(hash('sha384',$css,true))],'scripts'=>['name'=>'panel-studio-editor.js','bytes'=>strlen($javascript),'sha256'=>hash('sha256',$javascript),'integrity'=>'sha384-'.base64_encode(hash('sha384',$javascript,true))],'dependencies'=>[],'progressive_enhancement'=>true,'live_collaboration'=>['signed_intents'=>true,'visibility_aware_polling'=>true,'presence_heartbeat'=>true,'typing_idle'=>true,'mutation_retries'=>false,'server_rendered_fragments'=>true],'security'=>['eval'=>false,'raw_html'=>false,'inline_handlers'=>false,'arbitrary_callbacks'=>false,'same_origin_transport'=>true,'dom_parser_fragments'=>true]];
	}
	public function jsonSerialize():array{return self::manifest();}
	private static function read(string $file):string{$path=__DIR__.'/Assets/'.$file;$content=@file_get_contents($path);if(!is_string($content)||$content===''){throw new \RuntimeException('Studio editor asset is unavailable: '.$file.'.');}return$content;}
	private static function nonce(string $nonce):string{if($nonce===''){return'';}if(strlen($nonce)>256||preg_match('/^[A-Za-z0-9+\/_=-]+$/D',$nonce)!==1){throw new \InvalidArgumentException('Studio editor asset nonce is invalid.');}return' nonce="'.htmlspecialchars($nonce,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"';}
}
