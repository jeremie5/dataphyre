<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes one command palette action exposed by a panel surface.
 *
 * PanelCommand is a clone-on-write builder for operator commands. It stores display metadata, grouping, sorting, search keywords, navigation target behavior, visibility rules, and arbitrary metadata before exporting command data or CommandManifest output for the command palette.
 */
final class PanelCommand {

	private string $name;
	private string $label;
	private string $group='Commands';
	private ?string $description=null;
	private ?string $icon=null;
	private ?string $url=null;
	private bool $newTab=false;
	private bool $hidden=false;
	private int $sort=100;
	private array $keywords=[];
	private array $meta=[];
	private ?\Closure $visibilityResolver=null;
	private ?\Closure $urlResolver=null;

	/**
	 * Initializes a command from a normalized name and derived label.
	 *
	 * @param string $name Command identifier supplied by the caller.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a command builder for a normalized command name.
	 *
	 *
	 * @return self New command with a humanized default label.
	 */
	public static function make(string $name): self {
		return new self($name);
	}

	/**
	 * Rehydrates a command builder from a manifest-like definition array.
	 *
	 * The array form accepts label/group/category/display metadata, sort order, keyword arrays, new-tab and hidden flags, and arbitrary metadata. Lazy URL and visibility closures cannot be represented in this array path and must be attached through url() or visibleUsing().
	 *
	 * @param array<string,mixed> $definition Command definition data.
	 * @return self Command configured from the definition.
	 */
	public static function fromArray(array $definition): self {
		$command=self::make((string)($definition['name'] ?? ''));
		foreach(['label', 'group', 'description', 'icon', 'url'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$command=$command->{$key}($definition[$key]);
			}
		}
		if(isset($definition['category']) && is_string($definition['category'])){
			$command=$command->group($definition['category']);
		}
		if(isset($definition['sort'])){
			$command=$command->sort((int)$definition['sort']);
		}
		if(isset($definition['keywords']) && is_array($definition['keywords'])){
			$command=$command->keywords($definition['keywords']);
		}
		if(!empty($definition['new_tab'])){
			$command=$command->newTab();
		}
		if(!empty($definition['hidden'])){
			$command=$command->hide();
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$command=$command->meta($definition['meta']);
		}
		return $command;
	}

	/**
	 * Reads the normalized command identifier.
	 *
	 *
	 * @return string Stable command name used for matching and manifests.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns a clone with a custom display label.
	 *
	 *
	 * @return self Cloned command with the trimmed label applied.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Returns a clone assigned to a command palette group.
	 *
	 * Blank group names fall back to Commands so every command can be grouped deterministically in palette output.
	 *
	 * @param string $group Group label.
	 * @return self Cloned command with the group updated.
	 */
	public function group(string $group): self {
		$clone=clone $this;
		$clone->group=trim($group) ?: 'Commands';
		return $clone;
	}

	/**
	 * Returns a clone assigned to a command category.
	 *
	 * Category is an alias for group(), preserving compatibility with manifests that use category terminology.
	 *
	 * @param string $category Category/group label.
	 * @return self Cloned command with the category applied as group.
	 */
	public function category(string $category): self {
		return $this->group($category);
	}

	/**
	 * Returns a clone with optional descriptive text.
	 *
	 * Blank descriptions collapse to null so palette data can distinguish absent supporting text from an intentional string.
	 *
	 * @param string $description Command description.
	 * @return self Cloned command with the description updated.
	 */
	public function description(string $description): self {
		$clone=clone $this;
		$clone->description=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Returns a clone with an optional icon identifier.
	 *
	 * The icon string is stored as supplied after trimming and is interpreted by the consuming panel UI.
	 *
	 * @param string $icon Icon identifier.
	 * @return self Cloned command with the icon updated.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Returns a clone with a static or lazily resolved command URL.
	 *
	 * Static URLs are trimmed and stored directly. Callable URLs are converted to closures and invoked during toArray() with the request, command, and manager; resolver failures are traced and serialized as null URLs.
	 *
	 * @param string|callable $url Static URL or resolver callback.
	 * @return self Cloned command with URL behavior updated.
	 */
	public function url(string|callable $url): self {
		$clone=clone $this;
		if(is_callable($url)){
			$clone->urlResolver=\Closure::fromCallable($url);
			$clone->url=null;
			return $clone;
		}
		$clone->url=trim($url);
		$clone->urlResolver=null;
		return $clone;
	}

	/**
	 * Returns a clone with browser target behavior updated.
	 *
	 *
	 * @return self Cloned command with the new-tab flag set.
	 */
	public function newTab(bool $newTab=true): self {
		$clone=clone $this;
		$clone->newTab=$newTab;
		return $clone;
	}

	/**
	 * Returns a clone hidden or shown before visibility resolver evaluation.
	 *
	 * Hidden commands are never visible, even if a visibility resolver would return true.
	 *
	 * @param bool $hidden True to suppress the command.
	 * @return self Cloned command with the hidden flag set.
	 */
	public function hide(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Returns a clone with a lazy visibility resolver.
	 *
	 * Resolvers receive the current request, command, and manager. Exceptions are caught in isVisible(), recorded through PanelTrace, and treated as invisible to avoid leaking broken commands into the palette.
	 *
	 * @param callable $resolver Visibility callback.
	 * @return self Cloned command with lazy visibility attached.
	 */
	public function visibleUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->visibilityResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Returns a clone with a command ordering weight.
	 *
	 *
	 * @param int $sort Lower values sort earlier within a group.
	 * @return self Cloned command with sort weight updated.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Returns a clone with normalized search keywords.
	 *
	 * String keywords are split on whitespace and commas. All keywords are trimmed, blank values are removed, and duplicates are collapsed while preserving first-seen order.
	 *
	 * @param array|string $keywords Keyword list or delimited keyword string.
	 * @return self Cloned command with search keywords updated.
	 */
	public function keywords(array|string $keywords): self {
		$clone=clone $this;
		$values=is_array($keywords) ? $keywords : preg_split('/[\s,]+/', $keywords);
		$clone->keywords=array_values(array_unique(array_filter(array_map(
			static fn(mixed $keyword): string => trim((string)$keyword),
			is_array($values) ? $values : []
		), static fn(string $keyword): bool => $keyword!=='')));
		return $clone;
	}

	/**
	 * Returns a clone with merged command metadata.
	 *
	 * Metadata is shallow-merged so later calls replace existing keys while preserving unrelated command metadata.
	 *
	 * @param array<string,mixed> $meta Command metadata.
	 * @return self Cloned command with metadata merged.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Determines whether the command should be exposed for the current panel context.
	 *
	 * Hidden commands return false immediately. Commands without a resolver are visible by default. Resolver exceptions are traced and treated as false so faulty visibility logic does not break command palette rendering.
	 *
	 * @param ?PanelRequest $request Optional request context.
	 * @param ?PanelManager $manager Optional panel manager context.
	 * @return bool True when the command is visible.
	 */
	public function isVisible(?PanelRequest $request=null, ?PanelManager $manager=null): bool {
		if($this->hidden){
			return false;
		}
		if($this->visibilityResolver===null){
			return true;
		}
		try{
			return (bool)($this->visibilityResolver)($request, $this, $manager);
		}
		catch(\Throwable $exception){
			PanelTrace::record('command.visibility_error', [
				'command'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Serializes the command for palette rendering, diagnostics, and examples.
	 *
	 * Lazy URL resolvers are evaluated at export time. The array includes both group and category for consumers using either naming convention, and exposes visible_lazy/url_lazy flags so tooling can distinguish static definitions from runtime-resolved commands.
	 *
	 * @param ?PanelRequest $request Optional request passed to lazy URL resolvers.
	 * @param ?PanelManager $manager Optional manager passed to lazy URL resolvers.
	 * @return array<string,mixed> Command data for palette rendering and diagnostics.
	 */
	public function toArray(?PanelRequest $request=null, ?PanelManager $manager=null): array {
		$url=$this->url;
		if($this->urlResolver!==null){
			try{
				$url=(string)($this->urlResolver)($request, $this, $manager);
			}
			catch(\Throwable $exception){
				PanelTrace::record('command.url_error', [
					'command'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$url=null;
			}
		}
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'group'=>$this->group,
			'category'=>$this->group,
			'description'=>$this->description,
			'icon'=>$this->icon,
			'url'=>$url,
			'new_tab'=>$this->newTab,
			'sort'=>$this->sort,
			'keywords'=>$this->keywords,
			'hidden'=>$this->hidden,
			'visible_lazy'=>$this->visibilityResolver!==null,
			'url_lazy'=>$this->urlResolver!==null,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Builds the full command manifest for the current context.
	 *
	 * CommandManifest owns any additional manifest-specific projection while this object remains the source of command configuration.
	 *
	 * @param ?PanelRequest $request Optional request context.
	 * @param ?PanelManager $manager Optional manager context.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @return array<string,mixed> Command manifest data.
	 */
	public function manifest(?PanelRequest $request=null, ?PanelManager $manager=null, array $meta=[]): array {
		return CommandManifest::from($this, $request, $manager, $meta)->toArray();
	}

	/**
	 * Converts a normalized command name into a default display label.
	 *
	 * Underscores, hyphens, and dots become spaces, then each word is title-cased for first-render readability before callers set a custom label.
	 *
	 * @param string $value Normalized command name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
