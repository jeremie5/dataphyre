<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Scoped priority event and render-hook runtime for first- and third-party extensions. */
final class PanelExtensionRuntime implements \JsonSerializable {
	private array $listeners=[];
	public function on(string $event, callable $listener, int $priority=0, array|string $scopes=['*']): self { $event=self::event($event); if($event===''){ throw new \InvalidArgumentException('An extension runtime event name is required.'); } $clone=clone $this; $scopes=is_array($scopes) ? $scopes : [$scopes]; $clone->listeners[$event][]= ['listener'=>$listener, 'priority'=>$priority, 'scopes'=>array_values(array_unique(array_map('strval', $scopes)))]; usort($clone->listeners[$event], static fn(array $left,array $right): int=>$right['priority']<=>$left['priority']); return $clone; }
	public function dispatch(string $event, mixed $payload=null, array|string $scopes=['*']): mixed { $event=self::event($event); $active=is_array($scopes) ? $scopes : [$scopes]; foreach($this->listeners[$event] ?? [] as $entry){ if(!self::scopesMatch($entry['scopes'], $active)){ continue; } $result=($entry['listener'])($payload, $event, $active); if($result!==null){ $payload=$result; } } return $payload; }
	public function render(string $hook, array $context=[], array|string $scopes=['*']): string { $fragments=$this->dispatch('render.'.self::event($hook), [], $scopes); if(!is_array($fragments)){ $fragments=[$fragments]; } return implode('', array_map(static fn(mixed $fragment): string => $fragment instanceof \Stringable || is_scalar($fragment) ? (string)$fragment : '', $fragments)); }
	public function manifest(): array { $events=[]; foreach($this->listeners as $event=>$listeners){ $events[$event]=array_map(static fn(array $entry): array => ['priority'=>$entry['priority'], 'scopes'=>$entry['scopes']], $listeners); } return ['type'=>'panel_extension_runtime', 'api_version'=>1, 'events'=>$events]; }
	public function jsonSerialize(): array { return $this->manifest(); }
	private static function event(string $event): string { return trim(preg_replace('/[^a-z0-9_.:-]+/', '-', strtolower(trim($event))) ?? '', '-'); }
	private static function scopesMatch(array $registered,array $active): bool { return in_array('*',$registered,true)||in_array('*',$active,true)||array_intersect($registered,$active)!==[]; }
}
