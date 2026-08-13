<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Dependency-aware extension registry with deterministic load plans. */
final class PanelExtensionRegistry implements \JsonSerializable {
	/** @var array<string,PanelExtensionDescriptor> */
	private array $extensions=[];
	public function register(PanelExtensionDescriptor|array $extension): self { $extension=is_array($extension) ? PanelExtensionDescriptor::fromArray($extension) : $extension; $clone=clone $this; if(isset($clone->extensions[$extension->id()])){ throw new \DomainException('Panel extension is already registered: '.$extension->id()); } $clone->extensions[$extension->id()]=$extension; return $clone; }
	public function has(string $id): bool { return isset($this->extensions[strtolower(trim($id))]); }
	public function get(string $id): ?PanelExtensionDescriptor { return $this->extensions[strtolower(trim($id))] ?? null; }
	/** @return list<PanelExtensionDescriptor> */
	public function loadOrder(): array { $ordered=[]; $visiting=[]; $visited=[]; $visit=function(string $id)use(&$visit,&$ordered,&$visiting,&$visited): void { if(isset($visited[$id])){ return; } if(isset($visiting[$id])){ throw new \DomainException('Panel extension dependency cycle at '.$id.'.'); } $extension=$this->extensions[$id] ?? null; if(!$extension){ throw new \DomainException('Missing Panel extension dependency: '.$id); } $visiting[$id]=true; foreach($extension->requires() as $required=>$constraint){ $dependency=$this->extensions[$required] ?? null; if(!$dependency){ throw new \DomainException('Missing Panel extension dependency: '.$required); } if(!self::versionMatches($dependency->version(), $constraint)){ throw new \DomainException('Panel extension '.$id.' requires '.$required.' '.$constraint.'.'); } $visit($required); } unset($visiting[$id]); $visited[$id]=true; $ordered[]=$extension; }; foreach(array_keys($this->extensions) as $id){ $visit($id); } return $ordered; }
	public function manifest(): array { $order=$this->loadOrder(); $assets=[]; $hooks=[]; $provides=[]; foreach($order as $extension){ foreach($extension->assets() as $asset){ $assets[]=['extension'=>$extension->id()]+$asset; } foreach($extension->hooks() as $hook=>$handler){ $hooks[$hook][]= ['extension'=>$extension->id(), 'handler'=>$handler]; } foreach($extension->provides() as $capability){ $provides[$capability][]=$extension->id(); } } return ['type'=>'panel_extension_registry', 'api_version'=>1, 'extensions'=>array_map(static fn(PanelExtensionDescriptor $extension): array => $extension->jsonSerialize(), $order), 'load_order'=>array_map(static fn(PanelExtensionDescriptor $extension): string => $extension->id(), $order), 'assets'=>$assets, 'hooks'=>$hooks, 'provides'=>$provides]; }
	public function jsonSerialize(): array { return $this->manifest(); }
	private static function versionMatches(string $version, string $constraint): bool { $constraint=trim($constraint); if($constraint==='' || $constraint==='*'){ return true; } if(str_starts_with($constraint, '^')){ $required=ltrim($constraint, '^'); return explode('.', $version)[0]===explode('.', $required)[0] && version_compare($version, $required, '>='); } if(str_starts_with($constraint, '>=')){ return version_compare($version, trim(substr($constraint, 2)), '>='); } if(str_starts_with($constraint, '<=')){ return version_compare($version, trim(substr($constraint, 2)), '<='); } return version_compare($version, $constraint, '=='); }
}
