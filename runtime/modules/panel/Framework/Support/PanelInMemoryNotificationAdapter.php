<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Stores panel inbox notifications in process memory for tests, previews, and ephemeral operator sessions.
 *
 * The adapter implements the same notification inbox contract as durable adapters while deliberately keeping every
 * notification, delivery receipt, and metadata override inside the current PHP process. It is useful for examples,
 * panel demos, and unit tests that need deterministic state transitions without touching a database, queue, or mailer.
 *
 * Ordering is derived from each notification's creation timestamp and never from insertion order. Read, unread,
 * dismissed, and restored transitions mutate the contained PanelInboxNotification object in place so callers holding the
 * returned instance observe the same lifecycle state as later adapter reads.
 *
 * @see PanelNotificationAdapter
 */
final class PanelInMemoryNotificationAdapter implements PanelNotificationAdapter {

	/** @var array<string, PanelInboxNotification> Notifications keyed by stable inbox identifier. */
	private array $items=[];
	/** @var array<int, array<string, mixed>> Delivery receipts appended by deliver() in channel iteration order. */
	private array $deliveries=[];
	/** @var array<int, string> Default delivery channels used when a notification does not provide channels. */
	private array $channels=[];
	/** @var array<string, mixed> Adapter metadata merged into manifest output. */
	private array $meta=[];

	/**
	 * Seeds the adapter with optional notifications and default delivery channels.
	 *
	 * Notifications may already be PanelInboxNotification objects or any value accepted by PanelInboxNotification::from().
	 * Each seeded item is stored through store(), so ids are normalized once and duplicate ids are replaced by the latest
	 * supplied notification. Channel names are normalized with Resource::normalizeName(), deduplicated, and kept in the
	 * order supplied by the caller.
	 *
	 * @param array<int, PanelInboxNotification|PanelNotification|array|string> $notifications Seed notifications.
	 * @param array<int, string> $channels Default channel names used by delivery calls.
	 */
	public function __construct(array $notifications=[], array $channels=['database']) {
		$this->channels=$this->normalizeChannels($channels);
		foreach($notifications as $notification){
			$this->store($notification);
		}
	}

	/**
	 * Builds an in-memory adapter for fluent panel resource configuration.
	 *
	 * This static constructor mirrors other panel support objects so fixtures can declare notification stores inline beside
	 * resources, tables, and forms.
	 *
	 * @param array<int, PanelInboxNotification|PanelNotification|array|string> $notifications Seed notifications.
	 * @param array<int, string> $channels Default channel names used by delivery calls.
	 * @return self Adapter instance with normalized channels and seeded inbox state.
	 */
	public static function make(array $notifications=[], array $channels=['database']): self {
		return new self($notifications, $channels);
	}

	/**
	 * Persists a notification in the in-memory inbox and returns the stored inbox item.
	 *
	 * Non-inbox notifications are converted with PanelInboxNotification::from() using the optional recipient and metadata
	 * supplied here. Existing ids are overwritten, which lets tests rehydrate exact inbox snapshots and lets panel previews
	 * update a notification without growing duplicate rows.
	 *
	 * @param PanelInboxNotification|PanelNotification|array|string $notification Inbox item or source notification.
	 * @param ?string $recipient Recipient identifier to attach when conversion is required.
	 * @param array<string, mixed> $meta Metadata merged into converted inbox notifications.
	 * @return PanelInboxNotification Stored notification instance keyed by id().
	 */
	public function store(PanelInboxNotification|PanelNotification|array|string $notification, ?string $recipient=null, array $meta=[]): PanelInboxNotification {
		$item=$notification instanceof PanelInboxNotification ? $notification : PanelInboxNotification::from($notification, $recipient, $meta);
		$this->items[$item->id()]=$item;
		return $item;
	}

	/**
	 * Lists inbox notifications in newest-first order.
	 *
	 * Dismissed notifications are hidden by default to match the operator inbox view. Passing true returns every stored
	 * notification, including dismissed entries, without mutating their lifecycle state.
	 *
	 * @param bool $includeDismissed Include dismissed notifications in the listing.
	 * @return array<int, PanelInboxNotification> Stored notifications sorted by createdAt() descending.
	 */
	public function all(bool $includeDismissed=false): array {
		$items=array_values($this->items);
		if(!$includeDismissed){
			$items=array_values(array_filter($items, static fn(PanelInboxNotification $item): bool => !$item->isDismissed()));
		}
		usort($items, static fn(PanelInboxNotification $a, PanelInboxNotification $b): int => strcmp($b->createdAt(), $a->createdAt()));
		return $items;
	}

	/**
	 * Lists notifications that have not been marked read.
	 *
	 * @param bool $includeDismissed Include dismissed unread notifications in the result.
	 * @return array<int, PanelInboxNotification> Unread notifications in newest-first order.
	 */
	public function unread(bool $includeDismissed=false): array {
		return array_values(array_filter($this->all($includeDismissed), static fn(PanelInboxNotification $item): bool => $item->isUnread()));
	}

	/**
	 * Lists notifications that have been marked read.
	 *
	 * @param bool $includeDismissed Include dismissed read notifications in the result.
	 * @return array<int, PanelInboxNotification> Read notifications in newest-first order.
	 */
	public function read(bool $includeDismissed=false): array {
		return array_values(array_filter($this->all($includeDismissed), static fn(PanelInboxNotification $item): bool => $item->isRead()));
	}

	/**
	 * Filters the inbox to notifications addressed to a recipient.
	 *
	 * Recipient values are trimmed before comparison. Passing null selects global notifications whose recipient is null,
	 * which keeps broadcast/system messages separate from explicitly addressed operator notifications.
	 *
	 * @param ?string $recipient Recipient identifier, or null for global notifications.
	 * @param bool $includeDismissed Include dismissed notifications for that recipient.
	 * @return array<int, PanelInboxNotification> Matching notifications in newest-first order.
	 */
	public function forRecipient(?string $recipient, bool $includeDismissed=false): array {
		$recipient=$recipient!==null ? trim($recipient) : null;
		return array_values(array_filter($this->all($includeDismissed), static function(PanelInboxNotification $item) use ($recipient): bool {
			return $recipient===null ? $item->recipient()===null : $item->recipient()===$recipient;
		}));
	}

	/**
	 * Finds one notification by its inbox id.
	 *
	 * @param string $id Stable notification id produced by PanelInboxNotification::id().
	 * @return ?PanelInboxNotification Stored notification, or null when the id is unknown.
	 */
	public function get(string $id): ?PanelInboxNotification {
		return $this->items[$id] ?? null;
	}

	/**
	 * Marks a stored notification as read.
	 *
	 * The timestamp is delegated to PanelInboxNotification::markRead(); callers may pass an ISO-8601 value for replayed
	 * fixtures or null to let the notification choose its current time.
	 *
	 * @param string $id Notification id to mutate.
	 * @param ?string $timestamp Optional read timestamp.
	 * @return bool Whether a stored notification with that id was found and mutated.
	 */
	public function markRead(string $id, ?string $timestamp=null): bool {
		if(!isset($this->items[$id])){
			return false;
		}
		$this->items[$id]->markRead($timestamp);
		return true;
	}

	/**
	 * Clears read state from a stored notification.
	 *
	 * @param string $id Notification id to mutate.
	 * @return bool Whether a stored notification with that id was found and mutated.
	 */
	public function markUnread(string $id): bool {
		if(!isset($this->items[$id])){
			return false;
		}
		$this->items[$id]->markUnread();
		return true;
	}

	/**
	 * Hides a stored notification from the default inbox listing.
	 *
	 * Dismissal does not remove the notification from memory; it only updates lifecycle state so counts, restore(), and
	 * include-dismissed listings can still inspect the item.
	 *
	 * @param string $id Notification id to mutate.
	 * @param ?string $timestamp Optional dismissal timestamp.
	 * @return bool Whether a stored notification with that id was found and mutated.
	 */
	public function dismiss(string $id, ?string $timestamp=null): bool {
		if(!isset($this->items[$id])){
			return false;
		}
		$this->items[$id]->dismiss($timestamp);
		return true;
	}

	/**
	 * Makes a dismissed notification visible in normal inbox listings again.
	 *
	 * @param string $id Notification id to mutate.
	 * @return bool Whether a stored notification with that id was found and mutated.
	 */
	public function restore(string $id): bool {
		if(!isset($this->items[$id])){
			return false;
		}
		$this->items[$id]->restore();
		return true;
	}

	/**
	 * Records queued delivery receipts for a stored notification.
	 *
	 * Delivery is intentionally simulated: no queue, mailer, push service, or database is contacted. The adapter appends one
	 * receipt per normalized channel with the notification id, recipient, channel, queued status, and UTC delivery timestamp.
	 * Passing a string looks up a stored notification by id; unknown ids return an empty receipt list.
	 *
	 * @param PanelInboxNotification|string $notification Stored inbox item or id to deliver.
	 * @param array<int, string>|string|null $channels Explicit channels, or null to use notification/default channels.
	 * @return array<int, array{notification_id:string, recipient:?string, channel:string, status:string, delivered_at:string}> Delivery receipts.
	 */
	public function deliver(PanelInboxNotification|string $notification, array|string|null $channels=null): array {
		$item=is_string($notification) ? $this->get($notification) : $notification;
		if(!$item instanceof PanelInboxNotification){
			return [];
		}
		$channels=$this->normalizeChannels($channels ?? $item->channels());
		if($channels===[]){
			$channels=$this->channels;
		}
		$records=[];
		foreach($channels as $channel){
			$records[]=[
				'notification_id'=>$item->id(),
				'recipient'=>$item->recipient(),
				'channel'=>$channel,
				'status'=>'queued',
				'delivered_at'=>gmdate('c'),
			];
		}
		array_push($this->deliveries, ...$records);
		return $records;
	}

	/**
	 * Summarizes inbox state for badges, dashboards, and adapter manifests.
	 *
	 * Counts are calculated after the same dismissal and recipient filtering rules used by listing methods. The dismissed
	 * count intentionally inspects all stored items for the recipient so the UI can show hidden work even when
	 * includeDismissed is false. Delivery totals count recorded receipts, not unique notifications.
	 *
	 * @param bool $includeDismissed Include dismissed notifications in total/read/unread/type/channel counts.
	 * @param ?string $recipient Restrict counts to a recipient, or null for all recipients.
	 * @return array{total:int, unread:int, read:int, dismissed:int, by_type:array<string, int>, by_channel:array<string, int>, deliveries:int} Count payload.
	 */
	public function counts(bool $includeDismissed=false, ?string $recipient=null): array {
		$items=$recipient===null ? $this->all($includeDismissed) : $this->forRecipient($recipient, $includeDismissed);
		$byType=[];
		$byChannel=[];
		foreach($items as $item){
			$byType[$item->type()]=($byType[$item->type()] ?? 0)+1;
			foreach($item->channels() as $channel){
				$byChannel[$channel]=($byChannel[$channel] ?? 0)+1;
			}
		}
		$allItems=$recipient===null ? array_values($this->items) : array_values(array_filter(array_values($this->items), static fn(PanelInboxNotification $item): bool => $item->recipient()===$recipient));
		return [
			'total'=>count($items),
			'unread'=>count(array_filter($items, static fn(PanelInboxNotification $item): bool => $item->isUnread())),
			'read'=>count(array_filter($items, static fn(PanelInboxNotification $item): bool => $item->isRead())),
			'dismissed'=>count(array_filter($allItems, static fn(PanelInboxNotification $item): bool => $item->isDismissed())),
			'by_type'=>$byType,
			'by_channel'=>$byChannel,
			'deliveries'=>count(array_filter($this->deliveries, static fn(array $delivery): bool => $recipient===null || ($delivery['recipient'] ?? null)===$recipient)),
		];
	}

	/**
	 * Adds adapter-level metadata included in manifest output.
	 *
	 * Array input is merged over existing metadata. Scalar key input sets a single entry and may intentionally store null,
	 * which lets callers clear or document a manifest field without replacing the whole metadata bag.
	 *
	 * @param array<string, mixed>|string $key Metadata map or metadata key.
	 * @param mixed $value Value used when $key is a string.
	 * @return self Same adapter instance for fluent fixture setup.
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
	 * Exposes adapter capabilities and current state for panel diagnostics.
	 *
	 * The manifest describes this adapter as non-durable but still persistence-capable because it supports the inbox
	 * lifecycle contract inside the current request/process. Runtime metadata passed to this call overrides stored metadata
	 * for the returned payload only.
	 *
	 * @param array<string, mixed> $meta One-time metadata merged over adapter metadata.
	 * @return array{type:string, adapter:string, durable:bool, channels:array<int, string>, counts:array<string, mixed>, deliveries:array<int, array<string, mixed>>, capabilities:array<string, bool>, meta:array<string, mixed>} Manifest payload.
	 */
	public function manifest(array $meta=[]): array {
		return [
			'type'=>'notification_adapter_manifest',
			'adapter'=>'memory',
			'durable'=>false,
			'channels'=>$this->channels,
			'counts'=>$this->counts(),
			'deliveries'=>$this->deliveries,
			'capabilities'=>[
				'persistence'=>true,
				'durable_persistence'=>false,
				'delivery_channels'=>true,
				'read_state'=>true,
				'dismissal'=>true,
				'recipients'=>true,
				'action_links'=>true,
				'timestamps'=>true,
				'manifest_serialization'=>true,
			],
			'meta'=>array_replace($this->meta, $meta),
		];
	}

	/**
	 * Normalizes channel input into unique adapter channel identifiers.
	 *
	 * Null becomes an empty list, empty normalized names are discarded, and the first occurrence of each channel wins. The
	 * returned order is significant because deliver() emits receipts in this sequence.
	 *
	 * @param array<int, string|null>|string|null $channels Channel names from a notification, adapter default, or caller override.
	 * @return array<int, string> Normalized non-empty channel names.
	 */
	private function normalizeChannels(array|string|null $channels): array {
		$channels=is_array($channels) ? $channels : [$channels];
		$normalized=[];
		foreach($channels as $channel){
			$channel=Resource::normalizeName((string)$channel);
			if($channel!=='' && !in_array($channel, $normalized, true)){
				$normalized[]=$channel;
			}
		}
		return $normalized;
	}
}
