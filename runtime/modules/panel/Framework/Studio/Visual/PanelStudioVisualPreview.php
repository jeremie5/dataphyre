<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable visual preview with deterministic conditional-refresh state. */
final class PanelStudioVisualPreview implements \JsonSerializable,\Stringable {
	public const MAX_SURFACES=64;
	public const MAX_TOTAL_FRAME_BYTES=16777216;
	/** @var list<PanelStudioVisualSurface> */ private readonly array $surfaces;
	private readonly string $etag;

	/** @param list<PanelStudioVisualSurface> $surfaces */
	public function __construct(
		private readonly string $source,
		private readonly ?int $revision,
		private readonly string $selectedPath,
		private readonly PanelStudioMaterialization $materialization,
		private readonly PanelStudioVisualDataset $dataset,
		array $surfaces,
	){
		if(!in_array($source,['session','signed','published'],true)||($revision!==null&&$revision<1)){throw new \InvalidArgumentException('Studio visual preview source identity is invalid.');}
		if(preg_match('/^[a-z][a-z0-9_.-]{0,127}(?:\/[a-z][a-z0-9_.-]{0,127})*$/',$selectedPath)!==1){throw new \InvalidArgumentException('Studio visual preview selection is invalid.');}
		if($surfaces===[]||count($surfaces)>self::MAX_SURFACES){throw new \LengthException('Studio visual previews require a bounded non-empty surface list.');}
		$paths=[];$bytes=0;foreach($surfaces as$surface){if(!$surface instanceof PanelStudioVisualSurface){throw new \InvalidArgumentException('Studio visual previews accept only visual surfaces.');}if(isset($paths[$surface->path()])){throw new \InvalidArgumentException('Studio visual preview paths must be unique.');}$paths[$surface->path()]=true;$bytes+=strlen($surface->result()->content());}
		if($bytes>self::MAX_TOTAL_FRAME_BYTES){throw new \LengthException('Studio visual preview frame content exceeds its total byte budget.');}
		$this->surfaces=array_values($surfaces);
		$identity=['source'=>$source,'revision'=>$revision,'selected_path'=>$selectedPath,'artifact'=>$materialization->artifact()->fingerprint(),'dataset'=>$dataset->digest(),'surfaces'=>array_map(static fn(PanelStudioVisualSurface $surface):array=>[$surface->path(),$surface->contentHash(),$surface->selected()],$this->surfaces)];
		$this->etag='"'.hash('sha256',json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'"';
	}

	public function source():string{return$this->source;}
	public function revision():?int{return$this->revision;}
	public function selectedPath():string{return$this->selectedPath;}
	public function materialization():PanelStudioMaterialization{return$this->materialization;}
	public function dataset():PanelStudioVisualDataset{return$this->dataset;}
	/** @return list<PanelStudioVisualSurface> */ public function surfaces():array{return$this->surfaces;}
	public function surface(string $path):?PanelStudioVisualSurface{foreach($this->surfaces as$surface){if($surface->path()===$path){return$surface;}}return null;}
	public function etag():string{return$this->etag;}
	public function notModified(?string $candidate):bool{if($candidate===null||trim($candidate)===''){return false;}$candidate=trim($candidate);if(str_starts_with($candidate,'W/')){$candidate=substr($candidate,2);}return hash_equals($this->etag,$candidate);}

	/** Returns a full route-neutral preview document. */
	public function html():string{
		$manifest=json_encode($this->jsonSerialize(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
		$frames=implode('',array_map(static fn(PanelStudioVisualSurface $surface):string=>$surface->frameHtml(),$this->surfaces));
		$revision=$this->revision===null?'Unsaved session':'Revision '.number_format($this->revision);
			return'<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; frame-src \'self\'"><title>Panel Studio visual preview</title><style>'.self::CSS.'</style></head><body><a class="skip" href="#preview-surfaces">Skip to preview surfaces</a><header class="dp-studio-visual-header"><div><span class="eyebrow">Panel Studio visual runtime</span><h1>Visual preview</h1><p>'.self::e($revision).' · '.self::e($this->source).' · '.count($this->surfaces).' surface'.(count($this->surfaces)===1?'':'s').'</p></div><div class="state"><span>Selected</span><code>'.self::e($this->selectedPath).'</code></div></header><main id="preview-surfaces" class="dp-studio-visual-grid" inert>'.$frames.'</main><script type="application/json" id="dp-studio-visual-manifest">'.$manifest.'</script></body></html>';
	}

	/** Emits 304 for a matching ETag without rerendering or serializing frame content. */
	public function response(?string $ifNoneMatch=null):PanelPageResult{
		$headers=['Content-Type'=>'text/html; charset=utf-8','ETag'=>$this->etag,'Cache-Control'=>'private, no-store','Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-src 'self'"];
		return$this->notModified($ifNoneMatch)?new PanelPageResult('',304,$headers,['studio_visual_preview'=>$this->jsonSerialize()]):new PanelPageResult($this->html(),200,$headers,['studio_visual_preview'=>$this->jsonSerialize()]);
	}

	public function jsonSerialize():array{return[
		'type'=>'panel_studio_visual_preview','version'=>1,'source'=>$this->source,'revision'=>$this->revision,'selected_path'=>$this->selectedPath,
		'artifact_fingerprint'=>$this->materialization->artifact()->fingerprint(),'definition_hash'=>$this->materialization->artifact()->definitionHash(),
		'dataset'=>$this->dataset->jsonSerialize(),'surface_count'=>count($this->surfaces),'surfaces'=>array_map(static fn(PanelStudioVisualSurface $surface):array=>$surface->jsonSerialize(),$this->surfaces),
		'refresh'=>['etag'=>$this->etag,'conditional_get'=>true,'state_bounded'=>true],'content_serialized'=>false,
		'security'=>['sandboxed_frames'=>true,'same_origin'=>false,'self_contained_frames'=>true,'scripts'=>false,'forms'=>false,'external_assets_loaded'=>false,'mutation_authority'=>false,'routes_registered'=>false,'preview_token_serialized'=>false],
	];}

	public function __toString():string{return$this->html();}

	private const CSS=':root{color-scheme:light dark;font-family:Inter,ui-sans-serif,system-ui,sans-serif;background:#07101f;color:#edf4ff}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(circle at top right,#16284a 0,transparent 38rem),#07101f}.skip{position:fixed;left:1rem;top:-5rem;z-index:5;padding:.75rem 1rem;border-radius:.5rem;background:#fff;color:#07101f}.skip:focus{top:1rem}.dp-studio-visual-header{display:flex;justify-content:space-between;gap:2rem;align-items:end;padding:clamp(1.25rem,3vw,3rem);border-bottom:1px solid #263957;background:#0b1628cc}.eyebrow,.state span{display:block;color:#7aa7ff;font-size:.72rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}h1{margin:.35rem 0 0;font-size:clamp(1.8rem,4vw,3.5rem);line-height:1}p{margin:.7rem 0 0;color:#a9b7cb}.state{text-align:right}.state code{display:block;margin-top:.4rem;color:#d7e5ff}.dp-studio-visual-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,34rem),1fr));gap:1rem;padding:clamp(1rem,2.5vw,2rem)}.dp-studio-visual-surface{min-width:0;overflow:hidden;border:1px solid #2c4162;border-radius:1rem;background:#0b1628;box-shadow:0 1.25rem 3rem #0006}.dp-studio-visual-surface[data-selected=true]{border-color:#4f8cff;box-shadow:0 0 0 2px #4f8cff55,0 1.25rem 3rem #0006}.dp-studio-visual-surface[data-status=error]{border-color:#ef6a74}.dp-studio-visual-surface header{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:.8rem 1rem;border-bottom:1px solid #263957}.dp-studio-visual-surface header div{min-width:0}.dp-studio-visual-surface strong,.dp-studio-visual-surface span{display:block}.dp-studio-visual-surface span,.dp-studio-visual-surface code{color:#8fa5c2;font-size:.72rem}.dp-studio-visual-surface iframe{display:block;width:100%;height:clamp(28rem,68vh,60rem);border:0;background:#fff}@media(max-width:700px){.dp-studio-visual-header{display:block}.state{margin-top:1.5rem;text-align:left}.dp-studio-visual-grid{padding:.65rem}.dp-studio-visual-surface iframe{height:38rem}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}';
	private static function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8');}
}
