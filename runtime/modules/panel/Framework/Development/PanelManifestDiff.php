<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Deterministic structural diff for manifests, snapshots, and package contracts. */
final class PanelManifestDiff implements \JsonSerializable {
	private array $changes=[];
	private function __construct(private readonly array $before, private readonly array $after) { $this->compare($before, $after, ''); }
	public static function between(array|\JsonSerializable $before, array|\JsonSerializable $after): self { return new self(self::array($before), self::array($after)); }
	public function changed(): bool { return $this->changes!==[]; }
	public function changes(?string $type=null): array { return $type===null ? $this->changes : array_values(array_filter($this->changes, static fn(array $change): bool => $change['type']===$type)); }
	public function summary(): array { return ['added'=>count($this->changes('added')), 'removed'=>count($this->changes('removed')), 'changed'=>count($this->changes('changed')), 'total'=>count($this->changes)]; }
	public function jsonSerialize(): array { return ['type'=>'panel_manifest_diff', 'changed'=>$this->changed(), 'summary'=>$this->summary(), 'changes'=>$this->changes, 'before_hash'=>self::hash($this->before), 'after_hash'=>self::hash($this->after)]; }
	private function compare(mixed $before, mixed $after, string $path): void {
		if(is_array($before) && is_array($after)){
			foreach(array_diff_key($before, $after) as $key=>$value){ $this->changes[]=['type'=>'removed', 'path'=>self::path($path, $key), 'before'=>$value, 'after'=>null]; }
			foreach(array_diff_key($after, $before) as $key=>$value){ $this->changes[]=['type'=>'added', 'path'=>self::path($path, $key), 'before'=>null, 'after'=>$value]; }
			foreach(array_intersect_key($before, $after) as $key=>$value){ $this->compare($value, $after[$key], self::path($path, $key)); }
			return;
		}
		if($before!==$after){ $this->changes[]=['type'=>'changed', 'path'=>$path, 'before'=>$before, 'after'=>$after]; }
	}
	private static function path(string $parent, string|int $key): string { return $parent==='' ? (string)$key : $parent.'.'.$key; }
	private static function array(array|\JsonSerializable $value): array { return $value instanceof \JsonSerializable ? (array)$value->jsonSerialize() : $value; }
	private static function hash(array $value): string { self::sort($value); return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
	private static function sort(array &$value): void { if(!array_is_list($value)){ ksort($value); } foreach($value as &$item){ if(is_array($item)){ self::sort($item); } } }
}
