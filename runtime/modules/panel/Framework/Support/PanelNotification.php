<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable panel notification payload for operator UI and inbox delivery.
 *
 * PanelNotification carries a normalized severity type, message, optional title,
 * and renderer metadata such as action links, icons, persistence, and duration.
 * Fluent modifiers return new instances so notifications can be shared safely
 * between transient UI flashes and inbox records.
 */
final class PanelNotification implements \JsonSerializable {

	/**
	 * Creates a normalized notification value.
	 *
	 * @param string $type Normalized severity type.
	 * @param string $message Operator-facing message text.
	 * @param string|null $title Optional heading text.
	 * @param array<string, mixed> $meta Renderer and inbox metadata.
	 */
	private function __construct(
		private readonly string $type,
		private readonly string $message,
		private readonly ?string $title=null,
		private readonly array $meta=[]
	){}

	/**
	 * Creates a notification with an explicit severity type.
	 *
	 * @param string $message Operator-facing message text.
	 * @param string $type Severity type; invalid values normalize to info.
	 * @param string|null $title Optional heading text.
	 * @param array<string, mixed> $meta Renderer and inbox metadata.
	 * @return self Immutable notification value.
	 */
	public static function make(string $message, string $type='info', ?string $title=null, array $meta=[]): self {
		return new self(self::normalizeType($type), trim($message), $title!==null ? trim($title) : null, $meta);
	}

	/**
	 * Creates a success notification.
	 *
	 * @param string $message Operator-facing message text.
	 * @param string|null $title Optional heading text.
	 * @param array<string, mixed> $meta Renderer and inbox metadata.
	 * @return self Success notification.
	 */
	public static function success(string $message, ?string $title=null, array $meta=[]): self {
		return self::make($message, 'success', $title, $meta);
	}

	/**
	 * Creates an error notification.
	 *
	 * @param string $message Operator-facing message text.
	 * @param string|null $title Optional heading text.
	 * @param array<string, mixed> $meta Renderer and inbox metadata.
	 * @return self Error notification.
	 */
	public static function error(string $message, ?string $title=null, array $meta=[]): self {
		return self::make($message, 'error', $title, $meta);
	}

	/**
	 * Creates an error notification using the danger alias.
	 *
	 * @param string $message Operator-facing message text.
	 * @param string|null $title Optional heading text.
	 * @param array<string, mixed> $meta Renderer and inbox metadata.
	 * @return self Error notification.
	 */
	public static function danger(string $message, ?string $title=null, array $meta=[]): self {
		return self::make($message, 'error', $title, $meta);
	}

	/**
	 * Creates a warning notification.
	 *
	 * @param string $message Operator-facing message text.
	 * @param string|null $title Optional heading text.
	 * @param array<string, mixed> $meta Renderer and inbox metadata.
	 * @return self Warning notification.
	 */
	public static function warning(string $message, ?string $title=null, array $meta=[]): self {
		return self::make($message, 'warning', $title, $meta);
	}

	/**
	 * Creates an informational notification.
	 *
	 * @param string $message Operator-facing message text.
	 * @param string|null $title Optional heading text.
	 * @param array<string, mixed> $meta Renderer and inbox metadata.
	 * @return self Info notification.
	 */
	public static function info(string $message, ?string $title=null, array $meta=[]): self {
		return self::make($message, 'info', $title, $meta);
	}

	/**
	 * Rehydrates a notification from a serialized payload.
	 *
	 * Top-level action, duration, persistence, and icon keys are folded into meta so
	 * payloads from older clients and inbox records share the same runtime shape.
	 *
	 * @param array<string, mixed> $notification Serialized notification payload.
	 * @return self Immutable notification value.
	 */
	public static function fromArray(array $notification): self {
		$meta=is_array($notification['meta'] ?? null) ? $notification['meta'] : [];
		foreach(['action_label', 'action_url', 'url', 'duration_ms', 'persistent', 'icon'] as $key){
			if(array_key_exists($key, $notification) && !array_key_exists($key, $meta)){
				$meta[$key]=$notification[$key];
			}
		}
		return self::make(
			(string)($notification['message'] ?? ''),
			(string)($notification['type'] ?? 'info'),
			isset($notification['title']) ? (string)$notification['title'] : null,
			$meta
		);
	}

	/**
	 * Returns a copy with a new title.
	 *
	 * @param string $title Heading text, or blank to clear.
	 * @return self Notification copy with updated title.
	 */
	public function titleText(string $title): self {
		return new self($this->type, $this->message, trim($title) ?: null, $this->meta);
	}

	/**
	 * Returns a copy with a primary action link.
	 *
	 * @param string $label Button/link label.
	 * @param string $url Target URL.
	 * @return self Notification copy with action metadata.
	 */
	public function action(string $label, string $url): self {
		return $this->meta([
			'action_label'=>trim($label),
			'action_url'=>trim($url),
		]);
	}

	/**
	 * Returns a copy with an action URL and optional label.
	 *
	 * When a label is omitted, JSON serialization exposes Open as the default
	 * action label for non-empty URLs.
	 *
	 * @param string $url Target URL.
	 * @param string|null $label Optional button/link label.
	 * @return self Notification copy with action URL metadata.
	 */
	public function url(string $url, ?string $label=null): self {
		$meta=['action_url'=>trim($url)];
		if($label!==null){
			$meta['action_label']=trim($label);
		}
		return $this->meta($meta);
	}

	/**
	 * Returns a copy with display duration metadata.
	 *
	 * Duration is clamped between 0 and 60000 milliseconds to keep client timers
	 * bounded.
	 *
	 * @param int $milliseconds Requested display duration.
	 * @return self Notification copy with duration metadata.
	 */
	public function duration(int $milliseconds): self {
		return $this->meta(['duration_ms'=>max(0, min(60000, $milliseconds))]);
	}

	/**
	 * Returns a copy marked as persistent or auto-dismissable.
	 *
	 * @param bool $persistent Whether the client should keep the notification visible until dismissed.
	 * @return self Notification copy with persistence metadata.
	 */
	public function persistent(bool $persistent=true): self {
		return $this->meta(['persistent'=>$persistent]);
	}

	/**
	 * Returns a copy with an icon hint.
	 *
	 * @param string $icon Icon name understood by panel clients.
	 * @return self Notification copy with icon metadata.
	 */
	public function icon(string $icon): self {
		return $this->meta(['icon'=>trim($icon)]);
	}

	/**
	 * Returns a copy with merged metadata.
	 *
	 * @param array<string, mixed> $meta Metadata merged over existing keys.
	 * @return self Notification copy with merged metadata.
	 */
	public function meta(array $meta): self {
		return new self($this->type, $this->message, $this->title, array_replace($this->meta, $meta));
	}

	/**
	 * Returns the normalized severity type.
	 *
	 * @return string One of success, error, warning, or info.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Returns the operator-facing message.
	 *
	 * @return string Notification message text.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Returns the optional notification title.
	 *
	 * @return string|null Heading text, or null when absent.
	 */
	public function title(): ?string {
		return $this->title;
	}

	/**
	 * Returns renderer and inbox metadata.
	 *
	 * @return array<string, mixed> Metadata such as action_url, action_label, duration_ms, persistent, and icon.
	 */
	public function metaData(): array {
		return $this->meta;
	}

	/**
	 * Converts this transient notification into an inbox notification.
	 *
	 * @param string|null $recipient Optional recipient identifier.
	 * @param array<string, mixed> $meta Extra inbox metadata merged during conversion.
	 * @return PanelInboxNotification Inbox-ready notification value.
	 */
	public function inbox(?string $recipient=null, array $meta=[]): PanelInboxNotification {
		return PanelInboxNotification::from($this, $recipient, $meta);
	}

	/**
	 * Serializes the notification for panel clients.
	 *
	 * @return array<string, mixed> Notification type, title, message, action, duration, persistence, icon, and metadata.
	 */
	public function jsonSerialize(): array {
		$actionUrl=isset($this->meta['action_url']) ? (string)$this->meta['action_url'] : (isset($this->meta['url']) ? (string)$this->meta['url'] : null);
		return [
			'type'=>$this->type,
			'title'=>$this->title,
			'message'=>$this->message,
			'action_label'=>isset($this->meta['action_label']) ? (string)$this->meta['action_label'] : ($actionUrl!==null && $actionUrl!=='' ? 'Open' : null),
			'action_url'=>$actionUrl,
			'duration_ms'=>isset($this->meta['duration_ms']) ? (int)$this->meta['duration_ms'] : null,
			'persistent'=>($this->meta['persistent'] ?? false)===true,
			'icon'=>isset($this->meta['icon']) ? (string)$this->meta['icon'] : null,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Normalizes notification severity aliases.
	 *
	 * @param string $type Candidate severity type.
	 * @return string Supported severity type, with danger mapped to error and unknown values mapped to info.
	 */
	private static function normalizeType(string $type): string {
		$type=strtolower(trim($type));
		if($type==='danger'){
			$type='error';
		}
		return in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
	}
}
