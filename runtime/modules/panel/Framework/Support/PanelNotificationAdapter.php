<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Defines the persistence and delivery contract for Panel notifications.
 *
 * Adapters bridge the operator UI inbox with whichever backing store or
 * delivery service a host application chooses. Implementations must preserve
 * notification identity, read/dismissed state, recipient scoping, and manifest
 * counts while accepting the flexible payload types emitted by Panel actions,
 * forms, jobs, and package integrations.
 */
interface PanelNotificationAdapter {

	/**
	 * Persists a notification in the Panel inbox.
	 *
	 * Implementations normalize strings, arrays, transient PanelNotification
	 * instances, and already-built inbox notifications into a durable
	 * PanelInboxNotification. The recipient argument scopes visibility without
	 * requiring every notification payload to carry its own user identifier.
	 *
	 * @param PanelInboxNotification|PanelNotification|array|string $notification Inbox payload or source notification.
	 * @param ?string $recipient Optional recipient identifier for scoped inboxes.
	 * @param array<string,mixed> $meta Extra adapter metadata such as source, severity, or tenancy context.
	 * @return PanelInboxNotification Stored notification including its durable id and state.
	 */
	public function store(PanelInboxNotification|PanelNotification|array|string $notification, ?string $recipient=null, array $meta=[]): PanelInboxNotification;

	/**
	 * Lists stored notifications visible to the current adapter scope.
	 *
	 * @param bool $includeDismissed Whether dismissed notifications should remain in the result.
	 * @return array<int, PanelInboxNotification> Notifications ordered according to the adapter's inbox policy.
	 */
	public function all(bool $includeDismissed=false): array;

	/**
	 * Lists notifications that have not been marked read.
	 *
	 * @param bool $includeDismissed Whether dismissed unread notifications should be included.
	 * @return array<int, PanelInboxNotification> Unread notifications for badge counts and inbox views.
	 */
	public function unread(bool $includeDismissed=false): array;

	/**
	 * Lists notifications that have a read timestamp or equivalent state.
	 *
	 * @param bool $includeDismissed Whether dismissed read notifications should be included.
	 * @return array<int, PanelInboxNotification> Read notifications for archive-style inbox views.
	 */
	public function read(bool $includeDismissed=false): array;

	/**
	 * Lists notifications for a specific recipient.
	 *
	 * A null recipient represents global notifications rather than every
	 * recipient. Implementations that support tenancy or user scoping should
	 * apply those constraints before returning global entries.
	 *
	 * @param ?string $recipient Recipient identifier or null for global notifications.
	 * @param bool $includeDismissed Whether dismissed notifications should remain visible.
	 * @return array<int, PanelInboxNotification> Recipient-scoped inbox entries.
	 */
	public function forRecipient(?string $recipient, bool $includeDismissed=false): array;

	/**
	 * Finds one stored notification by id.
	 *
	 * @param string $id Durable notification identifier.
	 * @return ?PanelInboxNotification Matching notification, or null when it is unavailable in this adapter scope.
	 */
	public function get(string $id): ?PanelInboxNotification;

	/**
	 * Marks a notification as read.
	 *
	 * Adapters should treat repeated calls as idempotent success when the
	 * notification already has a read state. The optional timestamp allows the
	 * Panel UI, tests, or synchronization jobs to preserve an authoritative time.
	 *
	 * @param string $id Durable notification identifier.
	 * @param ?string $timestamp ISO-like timestamp to store, or null for adapter time.
	 * @return bool True when the notification exists and the state is read after the call.
	 */
	public function markRead(string $id, ?string $timestamp=null): bool;

	/**
	 * Clears the read state for a notification.
	 *
	 *
	 * @param string $id Durable notification identifier.
	 * @return bool True when the notification exists and is unread after the call.
	 */
	public function markUnread(string $id): bool;

	/**
	 * Hides a notification from normal inbox listings.
	 *
	 * Dismissal is a reversible visibility state, not necessarily deletion.
	 * Implementations may retain the notification for audit trails, counts, or
	 * restored inbox views.
	 *
	 * @param string $id Durable notification identifier.
	 * @param ?string $timestamp ISO-like dismissal timestamp, or null for adapter time.
	 * @return bool True when the notification exists and is dismissed after the call.
	 */
	public function dismiss(string $id, ?string $timestamp=null): bool;

	/**
	 * Restores a previously dismissed notification.
	 *
	 *
	 * @param string $id Durable notification identifier.
	 * @return bool True when the notification exists and is visible after the call.
	 */
	public function restore(string $id): bool;

	/**
	 * Sends a notification through one or more delivery channels.
	 *
	 * Delivery supplements inbox persistence and may target email, webhook,
	 * browser push, SMS, or application-defined channels. Each result item
	 * should be serializable and include enough status information for Panel
	 * traces and operator diagnostics.
	 *
	 * @param PanelInboxNotification|string $notification Stored notification or message body.
	 * @param array|string|null $channels Channel name, channel list, or null for defaults.
	 * @return array<int, array<string, mixed>> Per-channel delivery result payloads.
	 */
	public function deliver(PanelInboxNotification|string $notification, array|string|null $channels=null): array;

	/**
	 * Returns inbox counters for badges and manifests.
	 *
	 * @param bool $includeDismissed Whether dismissed notifications contribute to totals.
	 * @param ?string $recipient Optional recipient scope for user-specific badges.
	 * @return array<string, mixed> Serializable counts such as total, unread, read, and dismissed.
	 */
	public function counts(bool $includeDismissed=false, ?string $recipient=null): array;

	/**
	 * Exports adapter capabilities and state for Panel diagnostics.
	 *
	 * @param array<string,mixed> $meta Caller-supplied manifest metadata to merge or echo.
	 * @return array<string,mixed> Serializable adapter manifest for health checks and UI.
	 */
	public function manifest(array $meta=[]): array;
}
