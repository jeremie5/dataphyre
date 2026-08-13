<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** One sandbox-ready visual surface produced from an actual Panel builder. */
final class PanelStudioVisualSurface implements \JsonSerializable {
	public const MAX_FRAME_BYTES=4194304;
	private readonly string $contentHash;

	private function __construct(
		private readonly string $path,
		private readonly string $symbol,
		private readonly string $label,
		private readonly bool $selected,
		private readonly PanelPageResult $result,
		private readonly ?string $errorCode=null,
	){
		if(preg_match('/^root(?:\.children\[\d+\])*$/',$path)!==1||preg_match('/^[a-z][a-z0-9_]{1,63}$/',$symbol)!==1){throw new \InvalidArgumentException('Studio visual surface identity is invalid.');}
		if(trim($label)===''||strlen($label)>160||preg_match('//u',$label)!==1||preg_match('/<[^>]*>/',$label)===1){throw new \InvalidArgumentException('Studio visual surface labels must be safe non-empty text.');}
		if($errorCode!==null&&preg_match('/^[a-z][a-z0-9_]{2,63}$/',$errorCode)!==1){throw new \InvalidArgumentException('Studio visual surface error codes are invalid.');}
		$this->contentHash=hash('sha256',$result->content());
	}

	public static function success(string $path,string $symbol,string $label,bool $selected,PanelPageResult $result):self{
		if(strlen($result->content())>self::MAX_FRAME_BYTES){return self::failure($path,$symbol,$label,$selected,'surface_too_large');}
		return new self($path,$symbol,$label,$selected,$result);
	}

	public static function failure(string $path,string $symbol,string $label,bool $selected,string $errorCode='render_failed'):self{
		$safeLabel=self::e($label);$safeCode=self::e($errorCode);
		$content='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>html{color-scheme:light dark}body{margin:0;padding:2rem;font:500 15px/1.5 system-ui;background:#111827;color:#e5e7eb}.notice{max-width:42rem;padding:1.25rem;border:1px solid #475569;border-radius:.75rem;background:#172033}small{color:#94a3b8}</style></head><body><section class="notice"><strong>'.$safeLabel.'</strong><p>This surface could not be rendered safely.</p><small>'.$safeCode.'</small></section></body></html>';
		return new self($path,$symbol,$label,$selected,PanelPageResult::html($content,500),$errorCode);
	}

	public function path():string{return$this->path;}
	public function symbol():string{return$this->symbol;}
	public function selected():bool{return$this->selected;}
	public function result():PanelPageResult{return$this->result;}
	public function contentHash():string{return$this->contentHash;}
	public function failed():bool{return$this->errorCode!==null;}

	/** Renders an inert iframe. The empty sandbox disables scripts, forms, popups, and top navigation. */
	public function frameHtml():string{
		$selected=$this->selected?' data-selected="true"':'';$status=$this->failed()?' data-status="error"':' data-status="ready"';
		return'<article class="dp-studio-visual-surface" data-path="'.self::e($this->path).'"'.$selected.$status.'><header><div><strong>'.self::e($this->label).'</strong><span>'.self::e($this->symbol).'</span></div><code>'.self::e($this->path).'</code></header><iframe sandbox="" referrerpolicy="no-referrer" loading="eager" title="'.self::e($this->label).' visual preview" srcdoc="'.self::e($this->result->content()).'"></iframe></article>';
	}

	public function jsonSerialize():array{return[
		'type'=>'panel_studio_visual_surface','version'=>1,'path'=>$this->path,'symbol'=>$this->symbol,'label'=>$this->label,'selected'=>$this->selected,
		'status'=>$this->failed()?'error':'ready','http_status'=>$this->result->status(),'content_bytes'=>strlen($this->result->content()),'content_sha256'=>$this->contentHash,
		'content_serialized'=>false,'error_code'=>$this->errorCode,'sandbox'=>['same_origin'=>false,'scripts'=>false,'forms'=>false,'popups'=>false,'top_navigation'=>false],
	];}

	private static function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');}
}
