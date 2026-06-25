<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Module initialization');

dp_define_module_config('mailer', 'DP_MAILER_CFG', [
	'default_provider'=>'log',
	'from'=>[
		'email'=>'no-reply@example.invalid',
		'name'=>'Dataphyre',
	],
	'reply_to'=>null,
	'templates_path'=>null,
	'failover_providers'=>[],
	'idempotency'=>[
		'enabled'=>true,
		'metadata_key'=>'idempotency_key',
		'header'=>'Idempotency-Key',
	],
	'delivery_safety'=>[
		'enabled'=>false,
		'allowed_domains'=>[],
		'allowed_emails'=>[],
		'rewrite_to'=>null,
		'block_unmatched'=>true,
		'preserve_original_recipients_header'=>true,
		'reapply'=>false,
	],
	'providers'=>[
		'log'=>[
			'driver'=>'log',
			'path'=>null,
		],
		'cloudflare'=>[
			'driver'=>'cloudflare',
			'endpoint'=>null,
			'api_token'=>null,
			'headers'=>[],
		],
		'sendgrid'=>[
			'driver'=>'sendgrid',
			'api_key'=>null,
			'endpoint'=>'https://api.sendgrid.com/v3/mail/send',
			'headers'=>[],
		],
		'smtp'=>[
			'driver'=>'smtp',
			'host'=>null,
			'port'=>587,
			'secure'=>'tls',
			'username'=>null,
			'password'=>null,
			'auth'=>'login',
			'helo'=>null,
		],
		'mailgun'=>[
			'driver'=>'mailgun',
			'api_key'=>null,
			'domain'=>null,
			'base_url'=>'https://api.mailgun.net',
			'endpoint'=>null,
			'headers'=>[],
		],
		'postmark'=>[
			'driver'=>'postmark',
			'server_token'=>null,
			'endpoint'=>'https://api.postmarkapp.com/email',
			'batch_endpoint'=>'https://api.postmarkapp.com/email/batch',
			'headers'=>[],
		],
		'resend'=>[
			'driver'=>'resend',
			'api_key'=>null,
			'endpoint'=>'https://api.resend.com/emails',
			'batch_endpoint'=>'https://api.resend.com/emails/batch',
			'headers'=>[],
		],
		'brevo'=>[
			'driver'=>'brevo',
			'api_key'=>null,
			'endpoint'=>'https://api.brevo.com/v3/smtp/email',
			'sandbox'=>false,
			'headers'=>[],
		],
		'aws'=>[
			'driver'=>'aws_ses',
			'region'=>'us-east-1',
			'access_key'=>null,
			'secret_key'=>null,
			'session_token'=>null,
			'endpoint'=>null,
		],
	],
	'outbox'=>[
		'enabled'=>true,
		'table'=>'dataphyre.mailer_outbox',
		'events_table'=>'dataphyre.mailer_events',
		'persist_body'=>true,
		'default_priority'=>0,
		'default_max_attempts'=>3,
		'retry_backoff_seconds'=>[60, 300, 900, 1800, 3600],
		'track_events'=>true,
		'recover_stale_sending'=>[
			'enabled'=>true,
			'timeout_seconds'=>900,
			'batch_size'=>50,
		],
		'rate_limits'=>[
			'enabled'=>false,
			'default_per_flush'=>null,
			'defer_seconds'=>60,
			'providers'=>[],
		],
	],
	'suppression'=>[
		'enabled'=>true,
		'enforce'=>true,
		'table'=>'dataphyre.mailer_suppressions',
		'store_email'=>false,
		'hash_salt'=>null,
		'list'=>[],
	],
	'webhooks'=>[
		'require_signature'=>false,
		'default_hmac_secret'=>null,
		'signature_header'=>'x-dataphyre-mailer-signature',
		'dedupe_enabled'=>true,
		'events_table'=>'dataphyre.mailer_webhook_events',
		'providers'=>[],
	],
	'retention'=>[
		'outbox_sent_days'=>30,
		'outbox_failed_days'=>180,
		'outbox_suppressed_days'=>90,
		'events_days'=>180,
		'webhook_events_days'=>180,
		'expired_suppressions'=>true,
	],
	'async'=>[
		'enabled'=>false,
		'driver'=>null,
	],
	'scheduler'=>[
		'enabled'=>false,
		'name'=>'dataphyre_mailer_outbox',
		'frequency'=>60.0,
		'timeout'=>300.0,
		'memory_limit'=>'128M',
		'batch_size'=>25,
		'prune'=>[
			'enabled'=>false,
			'options'=>[],
		],
	],
]);

if(function_exists('sql_define_table')){
	$outbox_table=(string)(DP_MAILER_CFG['outbox']['table'] ?? 'dataphyre.mailer_outbox');
	$events_table=(string)(DP_MAILER_CFG['outbox']['events_table'] ?? 'dataphyre.mailer_events');
	$suppressions_table=(string)(DP_MAILER_CFG['suppression']['table'] ?? 'dataphyre.mailer_suppressions');
	$webhook_events_table=(string)(DP_MAILER_CFG['webhooks']['events_table'] ?? 'dataphyre.mailer_webhook_events');
	sql_define_table($outbox_table, __DIR__.'/mailer.tables.php', 'outbox');
	sql_define_table($events_table, __DIR__.'/mailer.tables.php', 'events');
	sql_define_table($suppressions_table, __DIR__.'/mailer.tables.php', 'suppressions');
	sql_define_table($webhook_events_table, __DIR__.'/mailer.tables.php', 'webhook_events');
}

/**
 * Kernel mailer entry point for provider delivery, outbox queues, and webhooks.
 *
 * The class reads DP_MAILER_CFG, routes messages through providers and failover,
 * persists queued outbox work, enforces suppressions, ingests delivery events,
 * and exposes operational health and trace reports for scheduler callers. Each
 * public method returns deterministic fallback values when the framework class
 * cannot be loaded.
 */
class mailer {

	/**
	 * Reads mailer configuration with dot-path support over DP_MAILER_CFG.
	 *
	 * Direct keys in DP_MAILER_CFG win before dotted traversal, so provider names
	 * or config keys containing dots can still be read literally.
	 *
	 * @param string $key Dot-path mailer configuration key; empty string returns the full config.
	 * @param mixed $default Fallback value returned when a configuration path is absent.
	 * @return mixed Mailer configuration value from a literal or dotted key, or the caller default.
	 */
	public static function config(string $key='', mixed $default=null): mixed {
		$config=defined('DP_MAILER_CFG') && is_array(DP_MAILER_CFG) ? DP_MAILER_CFG : [];
		if($key===''){
			return $config;
		}
		if(array_key_exists($key, $config)){
			return $config[$key];
		}
		$current=$config;
		foreach(explode('.', $key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return $default;
			}
			$current=$current[$segment];
		}
		return $current;
	}

	/**
	 * Normalizes and sends one message through the selected provider and failover policy.
	 *
	 * When the framework module is unavailable, this method does not throw; it returns a failed
	 * result array with the requested provider and status 500.
	 *
	 * @param array<string,mixed> $message Message data containing recipients, subject, body/template data, headers, metadata, and attachments.
	 * @param ?string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
	 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
	 * @return array<string,mixed> Structured mailer result with status, provider, message, queue, suppression, or error fields.
	 */
	public static function send(array $message, ?string $provider=null, array $options=[]): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'provider'=>$provider,
				'status'=>500,
				'message'=>'Mailer framework is unavailable.',
			];
		}
		return \Dataphyre\Mailer\Mailer::send($message, $provider, $options)->toArray();
	}

	/**
	 * Normalizes and sends multiple messages while preserving per-message result details.
	 *
	 * Batch results preserve input order. If the framework module is unavailable,
	 * a single failed result is returned because no messages can be normalized.
	 *
	 * @param list<array<string,mixed>> $messages Batch of message data to send or queue.
	 * @param ?string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
	 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
	 * @return list<array<string,mixed>> Per-message structured mailer results.
	 */
	public static function send_batch(array $messages, ?string $provider=null, array $options=[]): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [[
				'ok'=>false,
				'provider'=>$provider,
				'status'=>500,
				'message'=>'Mailer framework is unavailable.',
			]];
		}
		return array_map(static fn($result): array => $result->toArray(), \Dataphyre\Mailer\Mailer::send_batch($messages, $provider, $options));
	}

	/**
	 * Stores one message in the outbox for later delivery with priority, attempts, and provider metadata.
	 *
	 * Queueing depends on the framework and configured SQL-backed outbox storage;
	 * unavailable dependencies are reported as failed result arrays instead of exceptions.
	 *
	 * @param array<string,mixed> $message Message data containing recipients, subject, body/template data, headers, metadata, and attachments.
	 * @param ?string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
	 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
	 * @return array<string,mixed> Structured queue result with outbox metadata or error details.
	 */
	public static function queue(array $message, ?string $provider=null, array $options=[]): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'provider'=>$provider,
				'status'=>500,
				'message'=>'Mailer framework is unavailable.',
			];
		}
		return \Dataphyre\Mailer\Mailer::queue($message, $provider, $options)->toArray();
	}

	/**
	 * Processes queued outbox messages up to the requested limit and records attempts/events.
	 *
	 * The framework implementation recovers stale sending rows, applies rate limits,
	 * updates attempt counts, and records telemetry when configured storage exists.
	 *
	 * @param int $limit Maximum queued outbox messages processed in one flush.
	 * @return array<string,mixed> Flush summary with processed counts and delivery results.
	 */
	public static function flush(int $limit=25): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'processed'=>0,
				'message'=>'Mailer framework is unavailable.',
			];
		}
		return \Dataphyre\Mailer\Mailer::flush($limit);
	}

	/**
	 * Renders a mail template into subject/body fragments without sending it.
	 *
	 * This path renders only template fragments. It does not apply provider delivery,
	 * suppression, outbox persistence, or webhook side effects.
	 *
	 * @param string $template Template name or path rendered into mail body content.
	 * @param array<string,mixed> $data Template variables used during mail rendering.
	 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
	 * @return array{subject?:string,html?:string,text?:string}|array<string,mixed> Rendered mail fragments and template metadata.
	 */
	public static function render(string $template, array $data=[], array $options=[]): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'subject'=>'',
				'html'=>'',
				'text'=>'',
			];
		}
		return \Dataphyre\Mailer\Mailer::render($template, $data, $options);
	}

	/**
	 * Returns aggregate outbox counts by state, provider, priority, and retry readiness.
	 *
	 * Requires the framework and configured outbox storage. When unavailable, the
	 * returned summary includes ok=false and an empty status map.
	 *
	 * @return array<string,mixed> Outbox summary grouped by status, provider, priority, and retry readiness.
	 */
	public static function outbox_summary(): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'message'=>'Mailer framework is unavailable.',
				'statuses'=>[],
			];
		}
		return \Dataphyre\Mailer\Mailer::outbox_summary();
	}

	/**
	 * Deletes or expires old outbox, event, webhook, and suppression records according to retention options.
	 *
	 * Retention rules are interpreted by the framework manager. Missing framework
	 * support returns a no-op report with no deleted sections.
	 *
	 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
	 * @return array<string,mixed> Prune report keyed by retained or deleted storage section.
	 */
	public static function prune(array $options=[]): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'message'=>'Mailer framework is unavailable.',
				'sections'=>[],
			];
		}
		return \Dataphyre\Mailer\Mailer::prune($options);
	}

	/**
	 * Aggregates campaign delivery metrics from outbox and event tables.
	 *
	 * Reporting reads decoded outbox message data and event rows; missing SQL/framework
	 * support returns an empty report with ok=false.
	 *
	 * @param array<string,mixed> $filters Campaign/outbox/event filters used by reporting helpers.
	 * @return array<string,mixed> Campaign metrics grouped by status, provider, and event type.
	 */
	public static function campaign_summary(array $filters=[]): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'message'=>'Mailer framework is unavailable.',
				'matches'=>0,
				'statuses'=>[],
				'events'=>[],
			];
		}
		return \Dataphyre\Mailer\Mailer::campaign_summary($filters);
	}

	/**
	 * Adds or updates a suppression record so future sends can be blocked or rewritten.
	 *
	 * Persistent suppressions are stored by normalized email hash unless configuration
	 * explicitly keeps the raw email address.
	 *
	 * @param string $email Recipient address normalized for suppression lookup or mutation.
	 * @param string $reason Suppression reason stored for audit and health reporting.
	 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
	 * @return bool True when the suppression row was inserted or updated.
	 */
	public static function suppress(string $email, string $reason='manual', array $options=[]): bool {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return false;
		}
		return \Dataphyre\Mailer\Mailer::suppress($email, $reason, $options);
	}

	/**
	 * Removes a suppression entry for an address.
	 *
	 * Returns false when the framework or suppression storage is unavailable.
	 *
	 * @param string $email Recipient address normalized for suppression lookup or mutation.
	 * @return bool True when a matching suppression row was removed.
	 */
	public static function unsuppress(string $email): bool {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return false;
		}
		return \Dataphyre\Mailer\Mailer::unsuppress($email);
	}

	/**
	 * Checks whether an address is currently suppressed by config or persisted state.
	 *
	 * Configured suppressions and unexpired persistent suppressions are both considered.
	 *
	 * @param string $email Recipient address normalized for suppression lookup or mutation.
	 * @return bool True when the address is actively suppressed.
	 */
	public static function is_suppressed(string $email): bool {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return false;
		}
		return \Dataphyre\Mailer\Mailer::is_suppressed($email);
	}

	/**
	 * Normalizes and stores a single provider delivery event.
	 *
	 * Provider-specific event shapes are normalized by the framework manager before dedupe,
	 * telemetry writes, and optional suppression creation.
	 *
	 * @param string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
	 * @param array<string,mixed> $payload Provider delivery-event data after JSON/body parsing.
	 * @param ?string $event Provider event type override when the event data does not include one.
	 * @return array<string,mixed> Delivery-event ingest result with normalized recipients, suppression count, and event metadata.
	 */
	public static function ingest_delivery_event(string $provider, array $payload, ?string $event=null): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'message'=>'Mailer framework is unavailable.',
				'suppressed'=>0,
				'recipients'=>[],
			];
		}
		return \Dataphyre\Mailer\Mailer::ingest_delivery_event($provider, $payload, $event);
	}

	/**
	 * Normalizes and stores multiple provider delivery events as a batch.
	 *
	 * Wrapped webhook event arrays are flattened by the framework manager before each
	 * event is processed through the single-event ingestion path.
	 *
	 * @param string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
	 * @param list<array<string,mixed>> $payloads List of provider delivery-event data.
	 * @param ?string $event Provider event type override when event data does not include one.
	 * @return array<string,mixed> Batch ingest summary with processed counts, suppressions, and normalized events.
	 */
	public static function ingest_delivery_events(string $provider, array $payloads, ?string $event=null): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'message'=>'Mailer framework is unavailable.',
				'processed'=>0,
				'suppressed'=>0,
				'events'=>[],
			];
		}
		return \Dataphyre\Mailer\Mailer::ingest_delivery_events($provider, $payloads, $event);
	}

	/**
	 * Verifies, decodes, de-duplicates, and stores provider webhook delivery events.
	 *
	 * Signature verification is enforced only when configured. The raw body is decoded
	 * as JSON before provider-specific event normalization and dedupe.
	 *
	 * @param string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
	 * @param string $body Raw webhook request body before provider decoding and signature checks.
	 * @param array<string,string|list<string>> $headers Webhook headers used for signature verification and provider metadata.
	 * @param ?string $event Provider event type override when the decoded event data does not include one.
	 * @return array<string,mixed> Webhook ingest summary with signature, dedupe, processed, and suppression details.
	 */
	public static function ingest_delivery_webhook(string $provider, string $body, array $headers=[], ?string $event=null): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'message'=>'Mailer framework is unavailable.',
				'processed'=>0,
				'suppressed'=>0,
				'events'=>[],
			];
		}
		return \Dataphyre\Mailer\Mailer::ingest_delivery_webhook($provider, $body, $headers, $event);
	}

	/**
	 * Builds a delivery health report across recent outbox, provider, suppression, and event activity.
	 *
	 * Health reads provider readiness, outbox summaries, suppression totals, webhook
	 * dedupe counts, and recent event activity when the framework can load them.
	 *
	 * @param int $window_hours Health window, in hours, used to aggregate outbox and delivery metrics.
	 * @return array<string,mixed> Health report across outbox, provider, suppression, and event activity.
	 */
	public static function health(int $window_hours=24): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'message'=>'Mailer framework is unavailable.',
			];
		}
		return \Dataphyre\Mailer\Mailer::health($window_hours);
	}

	/**
	 * Returns trace details for one message id, including outbox attempts and delivery events.
	 *
	 * Trace decodes stored JSON columns for outbox rows, events, and webhook records.
	 * It does not contact providers or mutate delivery state.
	 *
	 * @param string $message_id Mailer message id used to trace outbox attempts and delivery events.
	 * @return array<string,mixed> Trace data with message id, outbox attempts, delivery events, and framework-load errors when unavailable.
	 */
	public static function trace(string $message_id): array {
		if(core::load_framework_module('mailer')!==true || class_exists('\Dataphyre\Mailer\Mailer')!==true){
			return [
				'ok'=>false,
				'message'=>'Mailer framework is unavailable.',
				'message_id'=>$message_id,
			];
		}
		return \Dataphyre\Mailer\Mailer::trace($message_id);
	}

	/**
	 * Registers or runs the configured outbox scheduler when scheduler support is enabled.
	 *
	 * Scheduling is attempted during module load. It requires the scheduling module,
	 * the generated scheduler runner file, and scheduler.enabled=true.
	 *
	 * @return bool True when the scheduler runner was accepted by the scheduling module.
	 */
	public static function schedule(): bool {
		if(empty(DP_MAILER_CFG['scheduler']['enabled']) || !function_exists('dp_module_present') || dp_module_present('scheduling')===false){
			return false;
		}
		$scheduler=DP_MAILER_CFG['scheduler'];
		$file=__DIR__.'/mailer.scheduler.php';
		if(!is_file($file)){
			return false;
		}
		return scheduling::run(
			(string)($scheduler['name'] ?? 'dataphyre_mailer_outbox'),
			$file,
			(float)($scheduler['frequency'] ?? 60.0),
			(float)($scheduler['timeout'] ?? 300.0),
			(string)($scheduler['memory_limit'] ?? '128M'),
			[$file, __DIR__.'/mailer.main.php']
		);
	}
}

/**
 * Normalizes and sends one message through the selected provider and failover policy.
 *
 * Legacy function wrapper around mailer::send().
 *
 * @param array<string,mixed> $message Message data containing recipients, subject, body/template data, headers, metadata, and attachments.
 * @param ?string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
 * @return array<string,mixed> Structured mailer result with status, provider, message, queue, suppression, or error fields.
 */
function mailer_send(array $message, ?string $provider=null, array $options=[]): array {
	return mailer::send($message, $provider, $options);
}

/**
 * Normalizes and sends multiple messages while preserving per-message result details.
 *
 * Legacy function wrapper around mailer::send_batch().
 *
 * @param list<array<string,mixed>> $messages Batch of message data to send or queue.
 * @param ?string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
 * @return list<array<string,mixed>> Per-message structured mailer results.
 */
function mailer_send_batch(array $messages, ?string $provider=null, array $options=[]): array {
	return mailer::send_batch($messages, $provider, $options);
}

/**
 * Stores one message in the outbox for later delivery with priority, attempts, and provider metadata.
 *
 * Legacy function wrapper around mailer::queue().
 *
 * @param array<string,mixed> $message Message data containing recipients, subject, body/template data, headers, metadata, and attachments.
 * @param ?string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
 * @return array<string,mixed> Structured queue result with outbox metadata or error details.
 */
function mailer_queue(array $message, ?string $provider=null, array $options=[]): array {
	return mailer::queue($message, $provider, $options);
}

/**
 * Returns aggregate outbox counts by state, provider, priority, and retry readiness.
 *
 * Legacy function wrapper around mailer::outbox_summary().
 *
 * @return array<string,mixed> Outbox summary grouped by status, provider, priority, and retry readiness.
 */
function mailer_outbox_summary(): array {
	return mailer::outbox_summary();
}

/**
 * Deletes or expires old outbox, event, webhook, and suppression records according to retention options.
 *
 * Legacy function wrapper around mailer::prune().
 *
 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
 * @return array<string,mixed> Prune report keyed by retained or deleted storage section.
 */
function mailer_prune(array $options=[]): array {
	return mailer::prune($options);
}

/**
 * Aggregates campaign delivery metrics from outbox and event tables.
 *
 * Legacy function wrapper around mailer::campaign_summary().
 *
 * @param array<string,mixed> $filters Campaign/outbox/event filters used by reporting helpers.
 * @return array<string,mixed> Campaign metrics grouped by status, provider, and event type.
 */
function mailer_campaign_summary(array $filters=[]): array {
	return mailer::campaign_summary($filters);
}

/**
 * Adds or updates a suppression record so future sends can be blocked or rewritten.
 *
 * Legacy function wrapper around mailer::suppress().
 *
 * @param string $email Recipient address normalized for suppression lookup or mutation.
 * @param string $reason Suppression reason stored for audit and health reporting.
 * @param array<string,mixed> $options Runtime options for delivery, queue priority, attempts, pruning, suppression, webhook, or scheduler behavior.
 * @return bool True when the suppression row was inserted or updated.
 */
function mailer_suppress(string $email, string $reason='manual', array $options=[]): bool {
	return mailer::suppress($email, $reason, $options);
}

/**
 * Removes a suppression entry for an address.
 *
 * Legacy function wrapper around mailer::unsuppress().
 *
 * @param string $email Recipient address normalized for suppression lookup or mutation.
 * @return bool True when a matching suppression row was removed.
 */
function mailer_unsuppress(string $email): bool {
	return mailer::unsuppress($email);
}

/**
 * Checks whether an address is currently suppressed by config or persisted state.
 *
 * Legacy function wrapper around mailer::is_suppressed().
 *
 * @param string $email Recipient address normalized for suppression lookup or mutation.
 * @return bool True when the address is actively suppressed.
 */
function mailer_is_suppressed(string $email): bool {
	return mailer::is_suppressed($email);
}

/**
 * Normalizes and stores a single provider delivery event.
 *
 * Legacy function wrapper around mailer::ingest_delivery_event().
 *
 * @param string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
 * @param array<string,mixed> $payload Provider delivery-event data after JSON/body parsing.
 * @param ?string $event Provider event type override when the event data does not include one.
 * @return array<string,mixed> Delivery-event ingest summary with processed, suppressed, event, and error counts.
 */
function mailer_ingest_delivery_event(string $provider, array $payload, ?string $event=null): array {
	return mailer::ingest_delivery_event($provider, $payload, $event);
}

/**
 * Normalizes and stores multiple provider delivery events as a batch.
 *
 * Legacy function wrapper around mailer::ingest_delivery_events().
 *
 * @param string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
 * @param list<array<string,mixed>> $payloads List of provider delivery-event data.
 * @param ?string $event Provider event type override when event data does not include one.
 * @return array<string,mixed> Batch delivery-event ingest summary with processed, suppressed, event, and error counts.
 */
function mailer_ingest_delivery_events(string $provider, array $payloads, ?string $event=null): array {
	return mailer::ingest_delivery_events($provider, $payloads, $event);
}

/**
 * Verifies, decodes, de-duplicates, and stores provider webhook delivery events.
 *
 * Legacy function wrapper around mailer::ingest_delivery_webhook().
 *
 * @param string $provider Provider id from DP_MAILER_CFG, or null to use the configured default/failover chain.
 * @param string $body Raw webhook request body before provider decoding and signature checks.
 * @param array<string,string|list<string>> $headers Webhook headers used for signature verification and provider metadata.
 * @param ?string $event Provider event type override when the decoded event data does not include one.
 * @return array<string,mixed> Webhook ingest summary with signature/decoding status, processed events, suppressions, and errors.
 */
function mailer_ingest_delivery_webhook(string $provider, string $body, array $headers=[], ?string $event=null): array {
	return mailer::ingest_delivery_webhook($provider, $body, $headers, $event);
}

/**
 * Builds a delivery health report across recent outbox, provider, suppression, and event activity.
 *
 * Legacy function wrapper around mailer::health().
 *
 * @param int $window_hours Health window, in hours, used to aggregate outbox and delivery metrics.
 * @return array<string,mixed> Health report across outbox, provider, suppression, webhook, and recent error activity.
 */
function mailer_health(int $window_hours=24): array {
	return mailer::health($window_hours);
}

/**
 * Returns trace details for one message id, including outbox attempts and delivery events.
 *
 * Legacy function wrapper around mailer::trace().
 *
 * @param string $message_id Mailer message id used to trace outbox attempts and delivery events.
 * @return array<string,mixed> Trace data with message id, outbox attempts, delivery events, and framework-load errors when unavailable.
 */
function mailer_trace(string $message_id): array {
	return mailer::trace($message_id);
}

mailer::schedule();
