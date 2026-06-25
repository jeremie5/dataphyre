<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Adapter-backed notification inbox for panel operators.
 *
 * PanelNotificationInbox is the framework-facing service for storing,
 * retrieving, filtering, updating, delivering, and serializing operator
 * notifications. Persistence is delegated to a PanelNotificationAdapter so the
 * same inbox contract can be backed by memory, SQL, queues, or project-specific
 * delivery infrastructure.
 */
final class PanelNotificationInbox implements \JsonSerializable {

	private PanelNotificationAdapter $adapter;
	private array $meta=[];

	/**
	 * Creates an inbox with an optional adapter and seed notifications.
	 *
	 * Seed notifications are stored through add(), so array/string/notification
	 * normalization and adapter side effects are identical to runtime additions.
	 *
	 * @param array<int, PanelInboxNotification|PanelNotification|array|string> $notifications Initial notifications.
	 * @param PanelNotificationAdapter|null $adapter Persistence/delivery adapter, or in-memory adapter when null.
	 */
	public function __construct(array $notifications=[], ?PanelNotificationAdapter $adapter=null) {
		$this->adapter=$adapter ?? PanelInMemoryNotificationAdapter::make();
		foreach($notifications as $notification){
			$this->add($notification);
		}
	}

	/**
	 * Creates an inbox using the default or supplied adapter.
	 *
	 * @param array<int, PanelInboxNotification|PanelNotification|array|string> $notifications Initial notifications.
	 * @param PanelNotificationAdapter|null $adapter Optional persistence/delivery adapter.
	 * @return self New inbox instance.
	 */
	public static function make(array $notifications=[], ?PanelNotificationAdapter $adapter=null): self {
		return new self($notifications, $adapter);
	}

	/**
	 * Creates an inbox bound to a specific adapter.
	 *
	 * @param PanelNotificationAdapter $adapter Adapter that owns notification persistence and delivery.
	 * @param array<int, PanelInboxNotification|PanelNotification|array|string> $notifications Initial notifications.
	 * @return self New inbox instance using the supplied adapter.
	 */
	public static function using(PanelNotificationAdapter $adapter, array $notifications=[]): self {
		return new self($notifications, $adapter);
	}

	/**
	 * Returns the adapter backing this inbox.
	 *
	 * @return PanelNotificationAdapter Persistence and delivery adapter.
	 */
	public function adapter(): PanelNotificationAdapter {
		return $this->adapter;
	}

	/**
	 * Stores a notification in the inbox.
	 *
	 * The adapter normalizes strings, arrays, panel notifications, and inbox
	 * notifications into a PanelInboxNotification and assigns any required id/state.
	 *
	 * @param PanelInboxNotification|PanelNotification|array|string $notification Notification input.
	 * @param string|null $recipient Optional recipient identifier.
	 * @param array<string, mixed> $meta Metadata merged into the stored notification.
	 * @return PanelInboxNotification Stored inbox notification.
	 */
	public function add(PanelInboxNotification|PanelNotification|array|string $notification, ?string $recipient=null, array $meta=[]): PanelInboxNotification {
		return $this->adapter->store($notification, $recipient, $meta);
	}

	/**
	 * Returns all notifications visible in the inbox.
	 *
	 * @param bool $includeDismissed Whether dismissed notifications should be included.
	 * @return array<int, PanelInboxNotification> Inbox notifications.
	 */
	public function all(bool $includeDismissed=false): array {
		return $this->adapter->all($includeDismissed);
	}

	/**
	 * Returns unread notifications.
	 *
	 * @param bool $includeDismissed Whether dismissed unread notifications should be included.
	 * @return array<int, PanelInboxNotification> Unread notification list.
	 */
	public function unread(bool $includeDismissed=false): array {
		return $this->adapter->unread($includeDismissed);
	}

	/**
	 * Returns read notifications.
	 *
	 * @param bool $includeDismissed Whether dismissed read notifications should be included.
	 * @return array<int, PanelInboxNotification> Read notification list.
	 */
	public function read(bool $includeDismissed=false): array {
		return $this->adapter->read($includeDismissed);
	}

	/**
	 * Returns notifications addressed to a recipient.
	 *
	 * @param string|null $recipient Recipient identifier, or null for global/unassigned notifications.
	 * @param bool $includeDismissed Whether dismissed notifications should be included.
	 * @return array<int, PanelInboxNotification> Recipient-filtered notification list.
	 */
	public function forRecipient(?string $recipient, bool $includeDismissed=false): array {
		return $this->adapter->forRecipient($recipient, $includeDismissed);
	}

	/**
	 * Finds a notification by id.
	 *
	 * @param string $id Notification identifier.
	 * @return PanelInboxNotification|null Matching notification, or null when absent.
	 */
	public function get(string $id): ?PanelInboxNotification {
		return $this->adapter->get($id);
	}

	/**
	 * Marks a notification as read.
	 *
	 * @param string $id Notification identifier.
	 * @param string|null $timestamp Read timestamp, or adapter default when null.
	 * @return bool True when the adapter updates the notification.
	 */
	public function markRead(string $id, ?string $timestamp=null): bool {
		return $this->adapter->markRead($id, $timestamp);
	}

	/**
	 * Clears the read state for a notification.
	 *
	 * @param string $id Notification identifier.
	 * @return bool True when the adapter updates the notification.
	 */
	public function markUnread(string $id): bool {
		return $this->adapter->markUnread($id);
	}

	/**
	 * Dismisses a notification without deleting it.
	 *
	 * @param string $id Notification identifier.
	 * @param string|null $timestamp Dismissal timestamp, or adapter default when null.
	 * @return bool True when the adapter updates the notification.
	 */
	public function dismiss(string $id, ?string $timestamp=null): bool {
		return $this->adapter->dismiss($id, $timestamp);
	}

	/**
	 * Restores a dismissed notification.
	 *
	 * @param string $id Notification identifier.
	 * @return bool True when the adapter updates the notification.
	 */
	public function restore(string $id): bool {
		return $this->adapter->restore($id);
	}

	/**
	 * Delivers a notification through one or more adapter channels.
	 *
	 * Channel names are adapter-defined. The result payload is likewise adapter
	 * defined, but should describe per-channel delivery status for diagnostics.
	 *
	 * @param PanelInboxNotification|string $notification Notification object or notification id.
	 * @param array<int, string>|string|null $channels Channel list, single channel, or adapter default.
	 * @return array<string, mixed> Delivery result payload from the adapter.
	 */
	public function deliver(PanelInboxNotification|string $notification, array|string|null $channels=null): array {
		return $this->adapter->deliver($notification, $channels);
	}

	/**
	 * Returns aggregate notification counts.
	 *
	 * @param bool $includeDismissed Whether dismissed notifications should affect counts.
	 * @param string|null $recipient Optional recipient filter.
	 * @return array<string, int> Count payload, typically including total, read, unread, and dismissed counts.
	 */
	public function counts(bool $includeDismissed=false, ?string $recipient=null): array {
		return $this->adapter->counts($includeDismissed, $recipient);
	}

	/**
	 * Merges manifest-level metadata.
	 *
	 * Metadata is stored on the inbox wrapper and combined with manifest() call-time
	 * metadata, without altering adapter state.
	 *
	 * @param array<string, mixed>|string $key Metadata map or single key.
	 * @param mixed $value Value used when key is a string.
	 * @return self Same inbox instance for fluent configuration.
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
	 * Builds the panel manifest payload for this inbox.
	 *
	 * The manifest includes current counts, non-dismissed items, adapter metadata,
	 * capability flags, derived recipient/action-link counts, and merged metadata.
	 *
	 * @param array<string, mixed> $meta Additional metadata for this manifest render.
	 * @return array<string, mixed> Serializable inbox manifest for panel clients.
	 */
	public function manifest(array $meta=[]): array {
		return [
			'type'=>'notification_inbox_manifest',
			'counts'=>$this->counts(),
			'items'=>array_map(static fn(PanelInboxNotification $item): array => $item->toArray(), $this->all()),
			'adapter'=>$this->adapter->manifest(),
			'capabilities'=>[
				'read_state'=>true,
				'dismissal'=>true,
				'recipients'=>count(array_unique(array_filter(array_map(static fn(PanelInboxNotification $item): ?string => $item->recipient(), $this->all(true))))),
				'action_links'=>count(array_filter($this->all(true), static fn(PanelInboxNotification $item): bool => (string)($item->toArray()['action_url'] ?? '')!=='')),
				'delivery_channels'=>true,
				'adapter_ready'=>true,
			],
			'meta'=>array_replace($this->meta, $meta),
		];
	}

	/**
	 * Serializes the inbox to its panel manifest shape.
	 *
	 * @return array<string, mixed> Inbox manifest payload.
	 */
	public function toArray(): array {
		return $this->manifest();
	}

	/**
	 * Serializes the inbox for JSON encoding.
	 *
	 * @return array<string, mixed> Inbox manifest payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
