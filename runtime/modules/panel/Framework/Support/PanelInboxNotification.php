<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Represents a normalized Panel inbox notification ready for storage, rendering, or JSON transport.
 *
 * Inbox notifications keep the operator-facing message, routing channels, recipient scope, read/dismissal timestamps, and
 * arbitrary metadata in one small mutable value. The constructor accepts loose framework payloads and normalizes them into
 * a stable serialized shape so queue adapters, database inboxes, and Panel surfaces can exchange notifications without
 * needing to understand the source that produced them.
 */
final class PanelInboxNotification implements \JsonSerializable {

	private string $id;
	private string $type;
	private ?string $title;
	private string $message;
	private ?string $recipient;
	private string $channel;
	private array $channels;
	private ?string $actionLabel;
	private ?string $actionUrl;
	private ?string $icon;
	private string $createdAt;
	private ?string $readAt;
	private ?string $dismissedAt;
	private array $meta;

	/**
	 * Normalizes a loose notification payload into the inbox notification schema.
	 *
	 * Missing identifiers are deterministically derived from the normalized message fields and creation timestamp. Empty
	 * optional strings become null, channels collapse to a unique normalized list, the first channel remains the legacy
	 * primary channel, and timestamps are stored as caller-provided strings so persisted records can round-trip exactly.
	 *
	 * @param array<string, mixed> $attributes Notification fields from Panel emitters, persistence, or API input.
	 */
	public function __construct(array $attributes=[]) {
		$this->type=self::normalizeType((string)($attributes['type'] ?? 'info'));
		$this->title=isset($attributes['title']) && trim((string)$attributes['title'])!=='' ? trim((string)$attributes['title']) : null;
		$this->message=trim((string)($attributes['message'] ?? ''));
		$this->recipient=isset($attributes['recipient']) && trim((string)$attributes['recipient'])!=='' ? trim((string)$attributes['recipient']) : null;
		$this->channels=self::normalizeChannels($attributes['channels'] ?? ($attributes['channel'] ?? 'database'));
		$this->channel=$this->channels[0] ?? 'database';
		$this->actionLabel=isset($attributes['action_label']) && trim((string)$attributes['action_label'])!=='' ? trim((string)$attributes['action_label']) : null;
		$this->actionUrl=isset($attributes['action_url']) && trim((string)$attributes['action_url'])!=='' ? trim((string)$attributes['action_url']) : null;
		$this->icon=isset($attributes['icon']) && trim((string)$attributes['icon'])!=='' ? trim((string)$attributes['icon']) : null;
		$this->createdAt=isset($attributes['created_at']) && trim((string)$attributes['created_at'])!=='' ? trim((string)$attributes['created_at']) : gmdate('c');
		$this->readAt=isset($attributes['read_at']) && trim((string)$attributes['read_at'])!=='' ? trim((string)$attributes['read_at']) : null;
		$this->dismissedAt=isset($attributes['dismissed_at']) && trim((string)$attributes['dismissed_at'])!=='' ? trim((string)$attributes['dismissed_at']) : null;
		$this->meta=is_array($attributes['meta'] ?? null) ? $attributes['meta'] : [];
		$this->id=(string)($attributes['id'] ?? substr(sha1($this->type.'|'.$this->title.'|'.$this->message.'|'.$this->recipient.'|'.$this->createdAt), 0, 24));
	}

	/**
	 * Builds an inbox notification from a Panel notification object, raw payload, or message string.
	 *
	 * Existing PanelNotification instances are converted through their JSON payload, arrays are treated as pre-shaped
	 * attributes, and strings become informational messages. Explicit recipient and metadata arguments override or extend
	 * the source payload so callers can target a notification at delivery time without mutating the original object.
	 *
	 * @param PanelNotification|array<string, mixed>|string $notification Source notification or message text.
	 * @param ?string $recipient Recipient identifier to attach when non-empty.
	 * @param array<string, mixed> $meta Metadata merged over any metadata already present in the source payload.
	 * @return self Normalized inbox notification instance.
	 */
	public static function from(PanelNotification|array|string $notification, ?string $recipient=null, array $meta=[]): self {
		if($notification instanceof PanelNotification){
			$payload=$notification->jsonSerialize();
		}
		elseif(is_array($notification)){
			$payload=$notification;
		}
		else {
			$payload=['message'=>$notification, 'type'=>'info'];
		}
		if($recipient!==null && trim($recipient)!==''){
			$payload['recipient']=$recipient;
		}
		if($meta!==[]){
			$payload['meta']=array_replace(is_array($payload['meta'] ?? null) ? $payload['meta'] : [], $meta);
		}
		return new self($payload);
	}

	/**
	 * Returns the stable notification identifier used by storage and UI actions.
	 *
	 * @return string Caller-provided identifier or deterministic constructor fallback.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the normalized severity type understood by Panel notification styles.
	 *
	 * @return string One of success, error, warning, or info.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Returns the optional short heading shown above the notification message.
	 *
	 * @return ?string Trimmed title, or null when the notification is message-only.
	 */
	public function title(): ?string {
		return $this->title;
	}

	/**
	 * Returns the required body text rendered in the inbox row or detail surface.
	 *
	 * @return string Trimmed operator-facing notification message.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Returns the recipient scope attached to this inbox entry.
	 *
	 * @return ?string Recipient identifier, or null when the notification is global or adapter-scoped.
	 */
	public function recipient(): ?string {
		return $this->recipient;
	}

	/**
	 * Returns the legacy primary delivery channel.
	 *
	 * @return string First normalized channel, defaulting to database when none are supplied.
	 */
	public function channel(): string {
		return $this->channel;
	}

	/**
	 * Returns every normalized delivery channel requested for this notification.
	 *
	 * @return list<string> Unique channel names in dispatch order.
	 */
	public function channels(): array {
		return $this->channels;
	}

	/**
	 * Returns the creation timestamp stored on the inbox entry.
	 *
	 * @return string Caller-provided timestamp or constructor-generated UTC ISO 8601 value.
	 */
	public function createdAt(): string {
		return $this->createdAt;
	}

	/**
	 * Returns the timestamp that marks the notification as read.
	 *
	 * @return ?string Read timestamp, or null while unread.
	 */
	public function readAt(): ?string {
		return $this->readAt;
	}

	/**
	 * Returns the timestamp that hides the notification from active inbox views.
	 *
	 * @return ?string Dismissal timestamp, or null while visible.
	 */
	public function dismissedAt(): ?string {
		return $this->dismissedAt;
	}

	/**
	 * Reports whether the notification has been acknowledged by the recipient.
	 *
	 * @return bool True once a read timestamp is present.
	 */
	public function isRead(): bool {
		return $this->readAt!==null;
	}

	/**
	 * Reports whether the notification is still awaiting acknowledgement.
	 *
	 * @return bool True while no read timestamp is present.
	 */
	public function isUnread(): bool {
		return !$this->isRead();
	}

	/**
	 * Reports whether the notification has been removed from active inbox display.
	 *
	 * @return bool True once a dismissal timestamp is present.
	 */
	public function isDismissed(): bool {
		return $this->dismissedAt!==null;
	}

	/**
	 * Marks the notification as read in place.
	 *
	 * Empty timestamps are replaced with the current UTC ISO 8601 time. The same object is returned so repository
	 * adapters and controllers can update state fluently before persisting the serialized payload.
	 *
	 * @param ?string $timestamp Explicit read timestamp to preserve from persistence or tests.
	 * @return self This notification after updating its read timestamp.
	 */
	public function markRead(?string $timestamp=null): self {
		$this->readAt=$timestamp!==null && trim($timestamp)!=='' ? trim($timestamp) : gmdate('c');
		return $this;
	}

	/**
	 * Clears the read timestamp so the notification becomes unread again.
	 *
	 * @return self This notification after removing acknowledgement state.
	 */
	public function markUnread(): self {
		$this->readAt=null;
		return $this;
	}

	/**
	 * Marks the notification as dismissed in place.
	 *
	 * Dismissal affects visibility only; it does not imply that the notification was read. Callers that want both states
	 * should mark the notification read explicitly before or after dismissal.
	 *
	 * @param ?string $timestamp Explicit dismissal timestamp to preserve from persistence or tests.
	 * @return self This notification after updating its dismissal timestamp.
	 */
	public function dismiss(?string $timestamp=null): self {
		$this->dismissedAt=$timestamp!==null && trim($timestamp)!=='' ? trim($timestamp) : gmdate('c');
		return $this;
	}

	/**
	 * Restores the notification to active inbox visibility.
	 *
	 * @return self This notification after clearing dismissal state.
	 */
	public function restore(): self {
		$this->dismissedAt=null;
		return $this;
	}

	/**
	 * Adds metadata that adapters and Panel surfaces can use without changing the core notification schema.
	 *
	 * Array input merges over existing metadata, while scalar keys assign a single value. Metadata is intentionally not
	 * normalized so callers can attach typed identifiers, action context, or diagnostics that survive JSON serialization.
	 *
	 * @param array<string, mixed>|string $key Metadata map to merge, or one metadata key to assign.
	 * @param mixed $value Value stored when a single key is supplied.
	 * @return self This notification after metadata mutation.
	 */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->meta=array_replace($this->meta, $key);
			return $this;
		}
		$this->meta[$key]=$value;
		return $this;
	}

	/**
	 * Exports the canonical payload persisted by inbox repositories and returned to Panel clients.
	 *
	 * The array includes both timestamp fields and derived booleans so consumers that only need display state do not need
	 * to duplicate read/dismissal rules. Null optional fields are retained to keep API responses and snapshots stable.
	 *
	 * @return array{id: string, type: string, title: ?string, message: string, recipient: ?string, channel: string, channels: list<string>, action_label: ?string, action_url: ?string, icon: ?string, created_at: string, read_at: ?string, dismissed_at: ?string, read: bool, unread: bool, dismissed: bool, meta: array<string, mixed>}
	 */
	public function toArray(): array {
		return [
			'id'=>$this->id,
			'type'=>$this->type,
			'title'=>$this->title,
			'message'=>$this->message,
			'recipient'=>$this->recipient,
			'channel'=>$this->channel,
			'channels'=>$this->channels,
			'action_label'=>$this->actionLabel,
			'action_url'=>$this->actionUrl,
			'icon'=>$this->icon,
			'created_at'=>$this->createdAt,
			'read_at'=>$this->readAt,
			'dismissed_at'=>$this->dismissedAt,
			'read'=>$this->isRead(),
			'unread'=>$this->isUnread(),
			'dismissed'=>$this->isDismissed(),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Exposes the canonical payload to json_encode().
	 *
	 * @return array{id: string, type: string, title: ?string, message: string, recipient: ?string, channel: string, channels: list<string>, action_label: ?string, action_url: ?string, icon: ?string, created_at: string, read_at: ?string, dismissed_at: ?string, read: bool, unread: bool, dismissed: bool, meta: array<string, mixed>}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Converts loose severity input into the Panel notification severity set.
	 *
	 * The legacy danger alias is preserved as error so older emitters keep rendering with the destructive style while the
	 * serialized contract remains small and predictable.
	 *
	 * @param string $type Raw severity from caller input.
	 * @return string One of success, error, warning, or info.
	 */
	private static function normalizeType(string $type): string {
		$type=strtolower(trim($type));
		if($type==='danger'){
			$type='error';
		}
		return in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
	}

	/**
	 * Converts one or more channel names into a dispatch-safe ordered list.
	 *
	 * Channel names pass through Resource::normalizeName() so delivery adapters receive identifiers in the same format
	 * used by other Panel resources. Empty names are discarded, duplicates keep their first position, and database remains
	 * the fallback channel for incomplete payloads.
	 *
	 * @param array<int|string, mixed>|string|null $channels Raw channel collection or single channel name.
	 * @return list<string> Unique normalized channel names.
	 */
	private static function normalizeChannels(array|string|null $channels): array {
		$channels=is_array($channels) ? $channels : [$channels];
		$normalized=[];
		foreach($channels as $channel){
			$channel=Resource::normalizeName((string)$channel);
			if($channel!=='' && !in_array($channel, $normalized, true)){
				$normalized[]=$channel;
			}
		}
		return $normalized!==[] ? $normalized : ['database'];
	}
}
