<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Declarative, renderer-neutral editor toolbar contract. */
final class PanelEditorToolbar implements \JsonSerializable {
	/** @var list<array<string,mixed>> */
	private array $commands=[];

	private function __construct(private string $name='default') {
		$this->name=PanelEditorManifest::name($name, 'default');
	}

	public static function make(string $name='default'): self { return new self($name); }

	public static function fromArray(array $definition): self {
		$toolbar=self::make((string)($definition['name'] ?? 'default'));
		foreach(($definition['commands'] ?? []) as $command){
			if(!is_array($command)){ continue; }
			$toolbar=$toolbar->command(
				(string)($command['command'] ?? $command['name'] ?? ''),
				(string)($command['label'] ?? $command['command'] ?? ''),
				(string)($command['group'] ?? 'custom'),
				(string)($command['title'] ?? ''),
				(string)($command['plugin'] ?? ''),
				(bool)($command['pressed_state'] ?? false),
			);
		}
		return $toolbar;
	}

	public function command(string $command, string $label='', string $group='custom', string $title='', string $plugin='', bool $pressedState=false): self {
		$command=PanelEditorManifest::name($command);
		if($command===''){ return $this; }
		$clone=clone $this;
		$entry=[
			'command'=>$command,
			'label'=>trim($label) ?: self::humanize($command),
			'group'=>PanelEditorManifest::name($group, 'custom'),
			'title'=>trim($title) ?: (trim($label) ?: self::humanize($command)),
			'plugin'=>PanelEditorManifest::name($plugin),
			'pressed_state'=>$pressedState,
		];
		foreach($clone->commands as $index=>$existing){
			if(($existing['command'] ?? '')===$command){ $clone->commands[$index]=$entry; return $clone; }
		}
		$clone->commands[]=$entry;
		return $clone;
	}

	public function name(): string { return $this->name; }
	public function commands(): array { return $this->commands; }
	public function toArray(): array { return ['name'=>$this->name, 'commands'=>$this->commands]; }
	public function jsonSerialize(): array { return $this->toArray(); }

	private static function humanize(string $value): string {
		return ucwords(str_replace('_', ' ', $value));
	}
}
