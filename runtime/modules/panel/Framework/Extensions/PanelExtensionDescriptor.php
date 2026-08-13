<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable public contract for a Panel extension and its runtime contributions. */
final class PanelExtensionDescriptor implements \JsonSerializable {
	private function __construct(
		private readonly string $id,
		private readonly string $version,
		private readonly array $requires,
		private readonly array $provides,
		private readonly array $assets,
		private readonly array $hooks,
		private readonly array $permissions,
		private readonly array $metadata
	){}
	public static function make(string $id, string $version='1.0.0', array $options=[]): self {
		$id=self::name($id); if($id===''){ throw new \InvalidArgumentException('A Panel extension id is required.'); }
		$version=trim($version); if(preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version)!==1){ throw new \InvalidArgumentException('Panel extension versions must use semantic versioning.'); }
		return new self($id, $version, self::map($options['requires'] ?? []), self::names($options['provides'] ?? []), self::normalizeAssets($options['assets'] ?? []), self::map($options['hooks'] ?? []), self::names($options['permissions'] ?? []), is_array($options['metadata'] ?? null) ? $options['metadata'] : []);
	}
	public static function fromArray(array $data): self { return self::make((string)($data['id'] ?? ''), (string)($data['version'] ?? '1.0.0'), $data); }
	public function id(): string { return $this->id; }
	public function version(): string { return $this->version; }
	public function requires(): array { return $this->requires; }
	public function provides(): array { return $this->provides; }
	public function assets(): array { return $this->assets; }
	public function hooks(): array { return $this->hooks; }
	public function jsonSerialize(): array { return ['type'=>'panel_extension', 'api_version'=>1, 'id'=>$this->id, 'version'=>$this->version, 'requires'=>$this->requires, 'provides'=>$this->provides, 'assets'=>$this->assets, 'hooks'=>$this->hooks, 'permissions'=>$this->permissions, 'metadata'=>$this->metadata]; }
	private static function name(string $name): string { return trim(preg_replace('/[^a-z0-9_.-]+/', '-', strtolower(trim($name))) ?? '', '-'); }
	private static function names(mixed $values): array { return array_values(array_unique(array_filter(array_map(static fn(mixed $value): string => self::name((string)$value), is_array($values) ? $values : [$values])))); }
	private static function map(mixed $values): array { $map=[]; foreach(is_array($values) ? $values : [] as $key=>$value){ if(is_int($key)){ $key=(string)$value; $value='*'; } $key=self::name((string)$key); if($key!==''){ $map[$key]=is_scalar($value) ? (string)$value : '*'; } } ksort($map); return $map; }
	private static function normalizeAssets(mixed $assets): array { $normalized=[]; foreach(is_array($assets) ? $assets : [] as $asset){ if(!is_array($asset)){ continue; } $type=strtolower((string)($asset['type'] ?? 'script')); $url=trim((string)($asset['url'] ?? '')); if($url==='' || !in_array($type, ['script','style','module'], true)){ continue; } $normalized[]=['type'=>$type, 'url'=>$url, 'integrity'=>trim((string)($asset['integrity'] ?? '')), 'defer'=>($asset['defer'] ?? true)!==false, 'scope'=>trim((string)($asset['scope'] ?? 'global')) ?: 'global']; } return $normalized; }
}
