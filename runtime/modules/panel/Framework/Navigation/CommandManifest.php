<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes a Panel command for command palette and diagnostic consumers.
 *
 * The manifest normalizes command definitions from PanelCommand instances,
 * arrays, or plain strings into presentation, target, search, visibility, and
 * capability metadata.
 */
final class CommandManifest {

	/**
	 * Stores the command source and context used during manifest generation.
	 *
	 * @param PanelCommand|array<string, mixed>|string $command Command source definition.
	 * @param ?PanelRequest $request Request passed to dynamic command definitions.
	 * @param ?PanelManager $manager Panel manager passed to dynamic command definitions.
	 * @param array<string, mixed> $meta Caller metadata carried into the manifest.
	 */
	private function __construct(
		private readonly PanelCommand|array|string $command,
		private readonly ?PanelRequest $request=null,
		private readonly ?PanelManager $manager=null,
		private readonly array $meta=[]
	){}

	/**
	 * Creates a command manifest descriptor.
	 *
	 * @param PanelCommand|array<string, mixed>|string $command Command source definition.
	 * @param ?PanelRequest $request Current panel request used by dynamic commands.
	 * @param ?PanelManager $manager Manager used by dynamic commands.
	 * @param array<string, mixed> $meta Caller metadata merged into the manifest.
	 * @return self Immutable manifest builder.
	 */
	public static function from(PanelCommand|array|string $command, ?PanelRequest $request=null, ?PanelManager $manager=null, array $meta=[]): self {
		return new self($command, $request, $manager, $meta);
	}

	/**
	 * Builds the command palette entry used by panel navigation.
	 */
	public function toArray(): array {
		$definition=$this->definition();
		$name=(string)($definition['name'] ?? 'command');
		$keywords=is_array($definition['keywords'] ?? null) ? array_values(array_filter(array_map('strval', $definition['keywords']))) : [];
		$target=[
			'url'=>$definition['url'] ?? $definition['href'] ?? null,
			'href'=>$definition['href'] ?? $definition['url'] ?? null,
			'new_tab'=>($definition['new_tab'] ?? false)===true,
			'client_action'=>$definition['client_action'] ?? ($definition['meta']['client_action'] ?? null),
			'url_lazy'=>($definition['url_lazy'] ?? false)===true,
		];
		$manifest=[
			'type'=>'command_manifest',
			'name'=>$name,
			'label'=>(string)($definition['label'] ?? self::humanize($name)),
			'group'=>(string)($definition['group'] ?? $definition['category'] ?? 'Commands'),
			'presentation'=>[
				'description'=>$definition['description'] ?? null,
				'icon'=>$definition['icon'] ?? null,
				'tone'=>(string)($definition['tone'] ?? 'neutral'),
				'sort'=>(int)($definition['sort'] ?? 100),
			],
			'target'=>$target,
			'search'=>[
				'keywords'=>$keywords,
				'keyword_count'=>count($keywords),
				'text'=>trim(implode(' ', array_filter([
					(string)($definition['label'] ?? ''),
					(string)($definition['group'] ?? $definition['category'] ?? ''),
					(string)($definition['description'] ?? ''),
					implode(' ', $keywords),
				]))),
			],
			'visibility'=>[
				'hidden'=>($definition['hidden'] ?? false)===true,
				'visible_lazy'=>($definition['visible_lazy'] ?? false)===true,
				'resolved'=>($definition['hidden'] ?? false)!==true,
			],
			'capabilities'=>self::capabilities($definition, $keywords, $target),
			'meta'=>array_replace(is_array($definition['meta'] ?? null) ? $definition['meta'] : [], $this->meta),
		];
		PanelTrace::record('command.manifest.described', [
			'name'=>$manifest['name'],
			'group'=>$manifest['group'],
			'keywords'=>count($keywords),
			'lazy'=>($manifest['visibility']['visible_lazy'] ?? false) || ($target['url_lazy'] ?? false),
		]);
		return $manifest;
	}

	/**
	 * Normalizes the command source into an array definition.
	 *
	 * @return array<string, mixed> Command definition used by toArray().
	 */
	private function definition(): array {
		if($this->command instanceof PanelCommand){
			return $this->command->toArray($this->request, $this->manager);
		}
		if(is_array($this->command)){
			return $this->command;
		}
		return [
			'name'=>$this->command,
			'label'=>self::humanize($this->command),
		];
	}

	/**
	 * Summarizes command features for diagnostics and UI tooling.
	 *
	 * @param array<string, mixed> $definition Normalized command definition.
	 * @param array<int, string> $keywords Search keywords.
	 * @param array<string, mixed> $target Normalized navigation or client-action target.
	 * @return array<string, array<string, mixed>> Capability flags grouped by surface.
	 */
	private static function capabilities(array $definition, array $keywords, array $target): array {
		$url=trim((string)($target['url'] ?? $target['href'] ?? ''));
		$clientAction=trim((string)($target['client_action'] ?? ''));
		$description=trim((string)($definition['description'] ?? ''));
		return [
			'presentation'=>[
				'has_description'=>$description!=='',
				'has_icon'=>trim((string)($definition['icon'] ?? ''))!=='',
				'has_tone'=>trim((string)($definition['tone'] ?? ''))!=='' && (string)($definition['tone'] ?? 'neutral')!=='neutral',
			],
			'target'=>[
				'linked'=>$url!=='',
				'client_action'=>$clientAction!=='',
				'new_tab'=>($target['new_tab'] ?? false)===true,
				'lazy_url'=>($target['url_lazy'] ?? false)===true,
			],
			'search'=>[
				'keywords'=>count($keywords),
				'indexed'=>count($keywords)>0 || $description!=='',
			],
			'visibility'=>[
				'hidden'=>($definition['hidden'] ?? false)===true,
				'lazy'=>($definition['visible_lazy'] ?? false)===true,
			],
		];
	}

	/**
	 * Converts a command key into a fallback label.
	 *
	 * @param string $value Command key or slug.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Command' : ucwords($value);
	}
}
