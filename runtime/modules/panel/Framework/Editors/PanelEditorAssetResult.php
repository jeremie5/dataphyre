<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable public result envelope for editor asset mutations and delivery. */
final class PanelEditorAssetResult implements \JsonSerializable {
	public function __construct(
		private bool $ok,
		private string $code,
		private string $message,
		private int $status=200,
		private ?PanelEditorAsset $asset=null,
		private ?PanelEditorAssetPage $page=null,
		private array $warnings=[],
		private array $meta=[],
	) {
		$this->code=PanelEditorManifest::name($code, $ok ? 'ok' : 'failed');
		$this->message=self::text($message, 512);
		$this->status=max(200, min(599, $status));
		$this->warnings=array_values(array_unique(array_filter(array_map(static fn(mixed $warning): string=>self::text((string)$warning, 256), array_slice($warnings, 0, 32)))));
		$this->meta=PanelEditorManifest::sanitize($meta);
	}
	public static function success(string $code='ok', string $message='Editor asset operation completed.', ?PanelEditorAsset $asset=null, ?PanelEditorAssetPage $page=null, array $meta=[]): self { return new self(true,$code,$message,200,$asset,$page,[],$meta); }
	public static function failure(string $code, string $message='Editor asset operation failed.', int $status=422, array $meta=[]): self { return new self(false,$code,$message,$status,null,null,[],$meta); }
	public static function fromArray(array $result): self {
		$asset=is_array($result['asset'] ?? null) ? PanelEditorAsset::fromArray($result['asset']) : null;
		$page=is_array($result['page'] ?? null) ? PanelEditorAssetPage::fromArray($result['page']) : null;
		return new self(($result['ok'] ?? false)===true,(string)($result['code'] ?? 'failed'),(string)($result['message'] ?? 'Editor asset operation failed.'),(int)($result['status'] ?? (($result['ok'] ?? false)===true ? 200 : 422)),$asset,$page,is_array($result['warnings'] ?? null)?$result['warnings']:[],is_array($result['meta'] ?? null)?$result['meta']:[]);
	}
	public function ok(): bool { return $this->ok; }
	public function code(): string { return $this->code; }
	public function message(): string { return $this->message; }
	public function status(): int { return $this->status; }
	public function asset(): ?PanelEditorAsset { return $this->asset; }
	public function page(): ?PanelEditorAssetPage { return $this->page; }
	public function warnings(): array { return $this->warnings; }
	public function meta(): array { return $this->meta; }
	public function toArray(): array { return ['type'=>'panel_editor_asset_result','schema_version'=>1,'ok'=>$this->ok,'code'=>$this->code,'message'=>$this->message,'status'=>$this->status,'asset'=>$this->asset?->toArray(),'page'=>$this->page?->toArray(),'warnings'=>$this->warnings,'meta'=>$this->meta]; }
	public function jsonSerialize(): array { return $this->toArray(); }
	private static function text(string $value,int $limit): string { $value=trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value) ?? ''); if(strlen($value)<=$limit){ return $value; } $value=substr($value,0,$limit); while($value!==''&&preg_match('//u',$value)!==1){$value=substr($value,0,-1);} return $value; }
}
