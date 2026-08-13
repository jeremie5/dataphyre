<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Named client/server editor extension advertised without serializing executable state. */
final class PanelEditorPlugin implements \JsonSerializable {
	/** @var list<string> */
	private array $commands=[];
	private array $options=[];
	private bool $ready=true;

	private function __construct(private string $name) { $this->name=PanelEditorManifest::name($name); }
	public static function make(string $name): self { return new self($name); }

	public static function fromArray(array $definition): self {
		$plugin=self::make((string)($definition['name'] ?? ''));
		$plugin=$plugin->commands(is_array($definition['commands'] ?? null) ? $definition['commands'] : []);
		$plugin=$plugin->options(is_array($definition['options'] ?? null) ? $definition['options'] : []);
		return $plugin->ready((bool)($definition['ready'] ?? true));
	}

	public function commands(array|string $commands): self {
		$clone=clone $this;
		foreach((array)$commands as $command){
			$command=PanelEditorManifest::name((string)$command);
			if($command!=='' && !in_array($command, $clone->commands, true)){ $clone->commands[]=$command; }
		}
		return $clone;
	}

	public function options(array $options): self { $clone=clone $this; $clone->options=PanelEditorManifest::sanitize($options); return $clone; }
	public function ready(bool $ready=true): self { $clone=clone $this; $clone->ready=$ready; return $clone; }
	public function name(): string { return $this->name; }
	public function isReady(): bool { return $this->ready && $this->name!==''; }
	public function toArray(): array { return ['name'=>$this->name, 'commands'=>$this->commands, 'options'=>$this->options, 'ready'=>$this->isReady()]; }
	public function jsonSerialize(): array { return $this->toArray(); }
}
