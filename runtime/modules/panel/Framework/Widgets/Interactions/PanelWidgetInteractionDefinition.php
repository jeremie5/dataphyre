<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Immutable public definition for a progressively-enhanced widget.
 *
 * The component is an adapter-owned public key, never a PHP class or callback.
 * Endpoints, snapshots, authorization context, and executable handlers are not
 * part of this serializable definition and can only come from a trusted adapter.
 */
final class PanelWidgetInteractionDefinition implements \JsonSerializable {
	public const SCHEMA_VERSION=1;
	private const KEYS=['schema_version','adapter','component','actions','refresh','refresh_interval_ms','retry_limit'];
	/** @var array<string,string> */
	private readonly array $actions;

	/** @param array<string,string> $actions */
	private function __construct(
		private readonly string $adapter,
		private readonly string $component,
		array $actions=[],
		private readonly string $refresh='manual',
		private readonly int $refreshIntervalMs=0,
		private readonly int $retryLimit=2
	){ $this->actions=$actions; }

	public static function make(string $adapter, string $component): self {
		return new self(
			PanelWidgetInteractionValue::safeIdentifier($adapter, 'Widget runtime adapter', 64),
			PanelWidgetInteractionValue::safeIdentifier($component, 'Widget runtime component', 96)
		);
	}

	/** @param array<string,mixed> $definition */
	public static function fromArray(array $definition): self {
		self::assertExactKeys($definition);
		$version=$definition['schema_version'] ?? self::SCHEMA_VERSION;
		if(!is_int($version) || $version!==self::SCHEMA_VERSION){
			throw new \UnexpectedValueException('Unsupported widget interaction definition version.');
		}
		$instance=self::make(
			is_string($definition['adapter'] ?? null) ? $definition['adapter'] : '',
			is_string($definition['component'] ?? null) ? $definition['component'] : ''
		);
		if(array_key_exists('actions', $definition)){
			if(!is_array($definition['actions']) || ($definition['actions']!==[] && array_is_list($definition['actions']))){
				throw new \InvalidArgumentException('Widget interaction actions must be a name-to-label map.');
			}
			$instance=$instance->actions($definition['actions']);
		}
		if(array_key_exists('refresh', $definition) || array_key_exists('refresh_interval_ms', $definition)){
			$refresh=$definition['refresh'] ?? 'manual';
			$interval=$definition['refresh_interval_ms'] ?? 0;
			if(!is_string($refresh) || !is_int($interval)){
				throw new \InvalidArgumentException('Widget refresh settings have invalid types.');
			}
			$instance=$instance->refresh($refresh, $interval);
		}
		if(array_key_exists('retry_limit', $definition)){
			if(!is_int($definition['retry_limit'])){ throw new \InvalidArgumentException('Widget retry_limit must be an integer.'); }
			$instance=$instance->retryLimit($definition['retry_limit']);
		}
		return $instance;
	}

	/** @param array<string,string> $actions */
	public function actions(array $actions): self {
		if(count($actions)>32){ throw new \LengthException('Widgets support at most 32 named interactions.'); }
		$normalized=[];
		foreach($actions as $name=>$label){
			if(!is_string($name) || !is_string($label)){
				throw new \InvalidArgumentException('Widget interaction actions require string names and labels.');
			}
			$name=PanelWidgetInteractionValue::safeIdentifier($name, 'Widget interaction action', 64);
			$normalized[$name]=PanelWidgetInteractionValue::boundedString($label, 'Widget interaction label', 120);
		}
		ksort($normalized, SORT_STRING);
		return new self($this->adapter, $this->component, $normalized, $this->refresh, $this->refreshIntervalMs, $this->retryLimit);
	}

	public function action(string $name, ?string $label=null): self {
		$name=PanelWidgetInteractionValue::safeIdentifier($name, 'Widget interaction action', 64);
		$actions=$this->actions;
		$actions[$name]=$label===null ? self::humanize($name) : $label;
		return $this->actions($actions);
	}

	public function refresh(string $mode='manual', int $intervalMs=0): self {
		$mode=PanelWidgetInteractionValue::safeIdentifier($mode, 'Widget refresh mode', 16);
		if(!in_array($mode, ['manual','interval','none'], true)){
			throw new \InvalidArgumentException('Widget refresh mode must be manual, interval, or none.');
		}
		if($mode==='interval' && ($intervalMs<5000 || $intervalMs>3600000)){
			throw new \InvalidArgumentException('Widget refresh intervals must be between 5 seconds and 1 hour.');
		}
		if($mode!=='interval'){ $intervalMs=0; }
		return new self($this->adapter, $this->component, $this->actions, $mode, $intervalMs, $this->retryLimit);
	}

	public function retryLimit(int $limit): self {
		if($limit<0 || $limit>5){ throw new \InvalidArgumentException('Widget retry limits must be between 0 and 5.'); }
		return new self($this->adapter, $this->component, $this->actions, $this->refresh, $this->refreshIntervalMs, $limit);
	}

	public function adapter(): string { return $this->adapter; }
	public function component(): string { return $this->component; }
	/** @return array<string,string> */ public function namedActions(): array { return $this->actions; }
	public function allows(string $action): bool { return array_key_exists(strtolower(trim($action)), $this->actions); }
	public function refreshMode(): string { return $this->refresh; }
	public function refreshIntervalMs(): int { return $this->refreshIntervalMs; }
	public function retryLimitValue(): int { return $this->retryLimit; }
	public function fingerprint(): string { return hash('sha256', PanelWidgetInteractionValue::canonical($this->toArray())); }

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'schema_version'=>self::SCHEMA_VERSION,
			'adapter'=>$this->adapter,
			'component'=>$this->component,
			'actions'=>$this->actions,
			'refresh'=>$this->refresh,
			'refresh_interval_ms'=>$this->refreshIntervalMs,
			'retry_limit'=>$this->retryLimit,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	/** @param array<string,mixed> $definition */
	private static function assertExactKeys(array $definition): void {
		if(array_is_list($definition)){ throw new \InvalidArgumentException('Widget interaction definitions must be object-like maps.'); }
		$unknown=array_diff(array_keys($definition), self::KEYS);
		if($unknown!==[]){ throw new \InvalidArgumentException('Unknown widget interaction definition key: '.(string)reset($unknown)); }
	}

	private static function humanize(string $value): string {
		return ucwords(str_replace(['_','-','.'], ' ', $value));
	}
}
