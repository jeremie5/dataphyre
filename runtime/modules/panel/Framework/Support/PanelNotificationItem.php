<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable builder for one notification entry in the panel chrome.
 *
 * Notification items describe title, message, type, icon, destination URL,
 * count badge, visibility, sort order, and metadata. URL, count, and visibility
 * can be lazy closures evaluated against the current panel request and manager;
 * resolver failures are traced and converted to hidden or null output values.
 */
final class PanelNotificationItem {

	private string $name;
	private string $title;
	private string $message='';
	private string $type='info';
	private ?string $icon=null;
	private ?string $url=null;
	private mixed $count=null;
	private bool $hidden=false;
	private int $sort=100;
	private array $meta=[];
	private ?\Closure $visibilityResolver=null;
	private ?\Closure $urlResolver=null;
	private ?\Closure $countResolver=null;

	/**
	 * Creates a notification with normalized identity and a humanized default title.
	 *
	 * @param string $name Stable notification key used in renderer data and trace events.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->title=self::humanize($this->name);
	}

	/**
	 * Starts a notification item definition.
	*
	 * @param string $name Stable notification key; normalized with `Resource::normalizeName()`.
	 * @return self New notification item with default title and `info` type.
	 */
	public static function make(string $name): self {
		return new self($name);
	}

	/**
	 * Builds a notification item from a declarative configuration array.
	*
	 * Supported keys include `name`, `title` or `label`, `message`, `type`, `icon`,
	 * `url`, `sort`, `count`, `hidden`, and `meta`. Callable resolvers are not
	 * accepted through this array path so config remains serializable.
	 *
	 * @param array<string, mixed> $definition Notification definition.
	 * @return self Configured notification item.
	 */
	public static function fromArray(array $definition): self {
		$item=self::make((string)($definition['name'] ?? ''));
		foreach(['title', 'message', 'type', 'icon', 'url'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$item=$item->{$key}($definition[$key]);
			}
		}
		if(isset($definition['label']) && is_string($definition['label'])){
			$item=$item->title($definition['label']);
		}
		if(isset($definition['sort'])){
			$item=$item->sort((int)$definition['sort']);
		}
		if(array_key_exists('count', $definition)){
			$item=$item->count($definition['count']);
		}
		if(!empty($definition['hidden'])){
			$item=$item->hide();
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$item=$item->meta($definition['meta']);
		}
		return $item;
	}

	/**
	 * Returns the normalized notification key.
	 *
	 * @return string Stable item name used by panel payloads.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns a clone with the display title replaced.
	*
	 * @param string $title Operator-facing title.
	 * @return self Cloned item with the trimmed title.
	 */
	public function title(string $title): self {
		$clone=clone $this;
		$clone->title=trim($title);
		return $clone;
	}

	/**
	 * Returns a clone with the notification body text replaced.
	*
	 * @param string $message Operator-facing detail text.
	 * @return self Cloned item with the trimmed message.
	 */
	public function message(string $message): self {
		$clone=clone $this;
		$clone->message=trim($message);
		return $clone;
	}

	/**
	 * Returns a clone with a normalized notification severity.
	*
	 * Only `success`, `error`, `warning`, and `info` are emitted. Unknown values
	 * normalize to `info` so the panel renderer always receives a known tone.
	 *
	 * @param string $type Requested notification type.
	 * @return self Cloned item with a safe notification type.
	 */
	public function type(string $type): self {
		$type=Resource::normalizeName($type);
		$clone=clone $this;
		$clone->type=in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
		return $clone;
	}

	/**
	 * Returns a clone with the optional icon identifier replaced.
	*
	 * @param string $icon Panel icon name; empty values clear the icon.
	 * @return self Cloned item with the icon setting.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Returns a clone with either a fixed URL or a lazy URL resolver.
	*
	 * Resolver callbacks receive `(?PanelRequest, self, ?PanelManager)` during
	 * serialization. If a resolver throws, the error is traced and the URL becomes
	 * `null` for that renderer pass.
	 *
	 * @param string|callable $url Static URL or resolver callable.
	 * @return self Cloned item with static URL or URL resolver.
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
	 * Returns a clone with a fixed badge count or lazy count resolver.
	*
	 * Resolver callbacks receive `(?PanelRequest, self, ?PanelManager)` during
	 * serialization. Failures are traced and collapse to `null`.
	 *
	 * @param mixed $count Static count value or resolver callable.
	 * @return self Cloned item with count behavior.
	 */
	public function count(mixed $count): self {
		$clone=clone $this;
		if(is_callable($count)){
			$clone->countResolver=\Closure::fromCallable($count);
			$clone->count=null;
			return $clone;
		}
		$clone->count=$count;
		$clone->countResolver=null;
		return $clone;
	}

	/**
	 * Returns a clone with the navigation sort weight replaced.
	*
	 * @param int $sort Lower values appear earlier in notification lists.
	 * @return self Cloned item with the new sort weight.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Returns a clone with the static hidden flag changed.
	*
	 * @param bool $hidden Whether the item should be suppressed before resolver checks.
	 * @return self Cloned item with static visibility changed.
	 */
	public function hide(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Returns a clone with a lazy visibility resolver.
	*
	 * The resolver receives `(?PanelRequest, self, ?PanelManager)`. Exceptions are
	 * traced and treated as not visible, keeping failing notification providers
	 * from breaking the panel shell.
	 *
	 * @param callable $resolver Visibility callback.
	 * @return self Cloned item with lazy visibility behavior.
	 */
	public function visibleUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->visibilityResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Returns a clone with additional renderer metadata merged.
	*
	 * Later calls override existing keys while preserving unrelated metadata.
	 *
	 * @param array<string, mixed> $meta Extra renderer or extension metadata.
	 * @return self Cloned item with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Evaluates whether this notification should be included for the current panel view.
	*
	 * Static hidden items are always excluded. Lazy resolver exceptions are traced
	 * under `notification_item.visibility_error` and treated as invisible.
	 *
	 * @param ?PanelRequest $request Current panel request, when available.
	 * @param ?PanelManager $manager Active panel manager, when available.
	 * @return bool `true` when the item should be rendered.
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
			PanelTrace::record('notification_item.visibility_error', [
				'item'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Resolves lazy fields and exports the notification item for panel rendering.
	 *
	 * @param ?PanelRequest $request Current panel request used by lazy resolvers.
	 * @param ?PanelManager $manager Active panel manager used by lazy resolvers.
	 * @return array{name:string,title:string,message:string,type:string,icon:?string,url:?string,count:mixed,sort:int,hidden:bool,visible_lazy:bool,url_lazy:bool,count_lazy:bool,meta:array}
	 */
	public function toArray(?PanelRequest $request=null, ?PanelManager $manager=null): array {
		$url=$this->url;
		if($this->urlResolver!==null){
			try{
				$url=(string)($this->urlResolver)($request, $this, $manager);
			}
			catch(\Throwable $exception){
				PanelTrace::record('notification_item.url_error', [
					'item'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$url=null;
			}
		}
		$count=$this->count;
		if($this->countResolver!==null){
			try{
				$count=($this->countResolver)($request, $this, $manager);
			}
			catch(\Throwable $exception){
				PanelTrace::record('notification_item.count_error', [
					'item'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$count=null;
			}
		}
		return [
			'name'=>$this->name,
			'title'=>$this->title,
			'message'=>$this->message,
			'type'=>$this->type,
			'icon'=>$this->icon,
			'url'=>$url,
			'count'=>$count,
			'sort'=>$this->sort,
			'hidden'=>$this->hidden,
			'visible_lazy'=>$this->visibilityResolver!==null,
			'url_lazy'=>$this->urlResolver!==null,
			'count_lazy'=>$this->countResolver!==null,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Converts normalized identifiers into default operator-facing labels.
	 *
	 * @param string $value Normalized resource-style identifier.
	 * @return string Title-cased label with separators converted to spaces.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
