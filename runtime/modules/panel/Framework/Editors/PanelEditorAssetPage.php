<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded page returned by editor asset-library queries. */
final class PanelEditorAssetPage implements \JsonSerializable {
	/** @var list<PanelEditorAsset> */ private array $assets=[];
	public function __construct(array $assets=[], private ?string $nextCursor=null, private bool $hasMore=false, private ?int $total=null, private array $meta=[]) {
		foreach(array_slice($assets, 0, 100) as $asset){ if($asset instanceof PanelEditorAsset){ $this->assets[]=$asset; } elseif(is_array($asset)){ try{ $this->assets[]=PanelEditorAsset::fromArray($asset); }catch(\Throwable){} } }
		$this->nextCursor=$this->cursor($nextCursor);
		$this->hasMore=$hasMore && $this->nextCursor!==null;
		$this->total=$total!==null ? max(0, $total) : null;
		$this->meta=PanelEditorManifest::sanitize($meta);
	}
	public static function fromArray(array $page): self { return new self(is_array($page['assets'] ?? $page['items'] ?? null) ? ($page['assets'] ?? $page['items']) : [], isset($page['next_cursor']) ? (string)$page['next_cursor'] : null, ($page['has_more'] ?? false)===true, isset($page['total']) ? (int)$page['total'] : null, is_array($page['meta'] ?? null) ? $page['meta'] : []); }
	public function assets(): array { return $this->assets; }
	public function nextCursor(): ?string { return $this->nextCursor; }
	public function hasMore(): bool { return $this->hasMore; }
	public function total(): ?int { return $this->total; }
	public function meta(): array { return $this->meta; }
	public function toArray(): array { return ['type'=>'panel_editor_asset_page','schema_version'=>1,'assets'=>array_map(static fn(PanelEditorAsset $asset): array=>$asset->toArray(), $this->assets),'next_cursor'=>$this->nextCursor,'has_more'=>$this->hasMore,'total'=>$this->total,'meta'=>$this->meta]; }
	public function jsonSerialize(): array { return $this->toArray(); }
	private function cursor(?string $cursor): ?string { if($cursor===null || preg_match('/[\x00-\x20\x7f]/', $cursor)===1){ return null; } $cursor=trim($cursor); return $cursor!=='' && strlen($cursor)<=4096 ? $cursor : null; }
}
