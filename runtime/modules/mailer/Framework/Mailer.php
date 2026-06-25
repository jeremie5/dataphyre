<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer;

use Dataphyre\Mailer\Contracts\MailProvider;

/**
 * Static facade for mail delivery, queueing, templates, suppression, and telemetry.
 *
 * Mailer delegates to MailerManager while keeping application code on a compact
 * API for provider resolution, sending, batch delivery, async/queued delivery,
 * outbox maintenance, campaign summaries, webhooks, health checks, and traces.
 */
final class Mailer {

	/**
	 * Returns the shared mailer manager instance.
	 *
	 * The manager owns provider factories, cached provider instances, outbox access,
	 * delivery safety, suppression, webhook ingestion, and diagnostics.
	 *
	 * @return MailerManager Shared mailer manager.
	 */
	public static function manager(): MailerManager {
		return MailerManager::instance();
	}

	/**
	 * Drops the cached mailer manager instance.
	 *
	 * Use this after changing mailer configuration or provider extensions in tests and
	 * long-lived processes.
	 *
	 * @return void
	 */
	public static function flushManager(): void {
		MailerManager::flushInstance();
	}

	/**
	 * Resolves a configured mail provider.
	 *
	 * Null selects the configured default provider. Provider instances are cached by
	 * the manager after their factory creates them.
	 *
	 * @param string|null $name Provider name, or null for the configured default.
	 * @return MailProvider Resolved provider implementation.
	 */
	public static function provider(?string $name=null): MailProvider {
		return self::manager()->provider($name);
	}

	/**
	 * Registers or replaces a provider driver factory.
	 *
	 * Factories are manager-level extension points used when provider configuration
	 * references a custom driver name.
	 *
	 * @param string $driver Driver key used in provider configuration.
	 * @param callable $factory Factory returning a MailProvider.
	 * @return void
	 */
	public static function extend(string $driver, callable $factory): void {
		self::manager()->extend($driver, $factory);
	}

	/**
	 * Builds a normalized mail message value object.
	 *
	 * @param array<string, mixed> $payload Raw message payload.
	 * @return Message Normalized message value.
	 */
	public static function message(array $payload=[]): Message {
		return Message::make($payload);
	}

	/**
	 * Sends one message through the selected provider chain.
	 *
	 * Array payloads are rendered/normalized into Message objects first. Delivery
	 * safety, validation, suppression, idempotency, optional queueing, and provider
	 * fallback are handled by the manager.
	 *
	 * @param array<string, mixed>|Message $message Message payload or value object.
	 * @param string|null $provider Preferred provider name, or null for default chain.
	 * @param array<string, mixed> $options Send options such as queue, tags, metadata, idempotency, or failover.
	 * @return SendResult Provider result or framework-level failure result.
	 */
	public static function send(array|Message $message, ?string $provider=null, array $options=[]): SendResult {
		return self::manager()->send($message, $provider, $options);
	}

	/**
	 * Sends or queues a batch of messages.
	 *
	 * Each item is normalized and validated independently. Batch-capable providers are
	 * used when possible; otherwise messages fall back to individual send handling.
	 *
	 * @param array<int, array<string, mixed>|Message> $messages Message payloads or value objects.
	 * @param string|null $provider Preferred provider name, or null for default chain.
	 * @param array<string, mixed> $options Batch send options.
	 * @return array<int, SendResult> Result for each input message.
	 */
	public static function sendBatch(array $messages, ?string $provider=null, array $options=[]): array {
		return self::manager()->sendBatch($messages, $provider, $options);
	}

	/**
	 * Dispatches a send operation through async support when available.
	 *
	 * If the async framework module is unavailable, the message is queued through the
	 * outbox instead.
	 *
	 * @param array<string, mixed>|Message $message Message payload or value object.
	 * @param string|null $provider Preferred provider name, or null for default chain.
	 * @param array<string, mixed> $options Async, queue, and send options.
	 * @return mixed Async dispatch handle/result, or a SendResult from queue fallback.
	 */
	public static function sendAsync(array|Message $message, ?string $provider=null, array $options=[]): mixed {
		return self::manager()->sendAsync($message, $provider, $options);
	}

	/**
	 * Stores a message in the mail outbox for later delivery.
	 *
	 * The manager validates message data, applies delivery safety and suppression, and
	 * records queue metadata such as priority, attempts, not-before, and idempotency.
	 *
	 * @param array<string, mixed>|Message $message Message payload or value object.
	 * @param string|null $provider Provider to use when the outbox is flushed.
	 * @param array<string, mixed> $options Queue and send options.
	 * @return SendResult Queue result containing the generated outbox message id on success.
	 */
	public static function queue(array|Message $message, ?string $provider=null, array $options=[]): SendResult {
		return self::manager()->queue($message, $provider, $options);
	}

	/**
	 * Attempts delivery for queued outbox messages.
	 *
	 * The limit caps how many eligible queued messages are processed in one call.
	 *
	 * @param int $limit Maximum queued messages to process.
	 * @return array<int, SendResult> Delivery results for processed outbox messages.
	 */
	public static function flush(int $limit=25): array {
		return self::manager()->flush($limit);
	}

	/**
	 * Renders a configured mail template with data.
	 *
	 * Rendering can resolve template files, template strings, translations, and token
	 * replacement depending on mailer configuration and options.
	 *
	 * @param string $template Template key, file, or inline template identifier.
	 * @param array<string, mixed> $data Template variables.
	 * @param array<string, mixed> $options Rendering options.
	 * @return array<string, mixed> Rendered message payload fragments.
	 */
	public static function render(string $template, array $data=[], array $options=[]): array {
		return self::manager()->render($template, $data, $options);
	}

	/**
	 * Summarizes outbox state for diagnostics and operations dashboards.
	 *
	 * @return array<string, mixed> Counts and status metrics for queued mail.
	 */
	public static function outboxSummary(): array {
		return self::manager()->outboxSummary();
	}

	/**
	 * Prunes retained mailer storage according to retention options.
	 *
	 * This can delete old outbox rows, event rows, webhook dedupe rows, or suppression
	 * records depending on configured retention policy.
	 *
	 * @param array<string, mixed> $options Retention overrides.
	 * @return array<string, mixed> Per-table prune counts and cutoff metadata.
	 */
	public static function prune(array $options=[]): array {
		return self::manager()->prune($options);
	}

	/**
	 * Builds a campaign delivery summary from outbox and event data.
	 *
	 * @param array<string, mixed> $filters Campaign, tag, provider, date, or status filters.
	 * @return array<string, mixed> Campaign send and event metrics.
	 */
	public static function campaignSummary(array $filters=[]): array {
		return self::manager()->campaignSummary($filters);
	}

	/**
	 * Adds an email address to the suppression list.
	 *
	 * Suppressed recipients are blocked by send and queue operations when suppression
	 * storage is enabled.
	 *
	 * @param string $email Email address to suppress.
	 * @param string $reason Suppression reason, such as manual, bounce, or complaint.
	 * @param array<string, mixed> $options Additional suppression metadata.
	 * @return bool True when the address is recorded as suppressed.
	 */
	public static function suppress(string $email, string $reason='manual', array $options=[]): bool {
		return self::manager()->suppress($email, $reason, $options);
	}

	/**
	 * Removes an email address from the suppression list.
	 *
	 * @param string $email Email address to unsuppress.
	 * @return bool True when the suppression entry is removed or absent.
	 */
	public static function unsuppress(string $email): bool {
		return self::manager()->unsuppress($email);
	}

	/**
	 * Checks whether an email address is currently suppressed.
	 *
	 * @param string $email Email address to inspect.
	 * @return bool True when the normalized address is suppressed.
	 */
	public static function isSuppressed(string $email): bool {
		return self::manager()->isSuppressed($email);
	}

	/**
	 * Normalizes and records one provider delivery event.
	 *
	 * Events can update delivery traces, webhook dedupe state, severity summaries, and
	 * suppression state for bounce/complaint style events.
	 *
	 * @param string $provider Provider that emitted the event.
	 * @param array<string, mixed> $payload Raw provider event payload.
	 * @param string|null $event Explicit event name override.
	 * @return array<string, mixed> Normalized ingestion result.
	 */
	public static function ingestDeliveryEvent(string $provider, array $payload, ?string $event=null): array {
		return self::manager()->ingestDeliveryEvent($provider, $payload, $event);
	}

	/**
	 * Normalizes and records multiple provider delivery events.
	 *
	 * @param string $provider Provider that emitted the events.
	 * @param array<int, array<string, mixed>> $payloads Raw provider event payloads.
	 * @param string|null $event Explicit event name override applied to each payload.
	 * @return array<int, array<string, mixed>> Normalized ingestion results.
	 */
	public static function ingestDeliveryEvents(string $provider, array $payloads, ?string $event=null): array {
		return self::manager()->ingestDeliveryEvents($provider, $payloads, $event);
	}

	/**
	 * Ingests a raw provider webhook body.
	 *
	 * The manager verifies supported webhook signatures, decodes event payloads, and
	 * records each normalized delivery event.
	 *
	 * @param string $provider Provider that sent the webhook.
	 * @param string $body Raw webhook body.
	 * @param array<string, mixed> $headers Webhook request headers.
	 * @param string|null $event Explicit event name override.
	 * @return array<string, mixed> Webhook ingestion summary.
	 */
	public static function ingestDeliveryWebhook(string $provider, string $body, array $headers=[], ?string $event=null): array {
		return self::manager()->ingestDeliveryWebhook($provider, $body, $headers, $event);
	}

	/**
	 * Returns mailer health metrics for a recent time window.
	 *
	 * @param int $windowHours Number of hours included in event/outbox summaries.
	 * @return array<string, mixed> Provider readiness, outbox, event, webhook, and suppression health data.
	 */
	public static function health(int $windowHours=24): array {
		return self::manager()->health($windowHours);
	}

	/**
	 * Returns delivery trace details for one message id.
	 *
	 * Trace output combines outbox row details and recorded delivery events when
	 * available.
	 *
	 * @param string $messageId Mailer message or outbox id.
	 * @return array<string, mixed> Trace payload for diagnostics.
	 */
	public static function trace(string $messageId): array {
		return self::manager()->trace($messageId);
	}
}
