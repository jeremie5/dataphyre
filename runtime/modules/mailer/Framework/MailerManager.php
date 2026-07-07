<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer;

use Dataphyre\Mailer\Contracts\MailProvider;
use Dataphyre\Mailer\Contracts\BatchMailProvider;
use Dataphyre\Mailer\Providers\AwsSesProvider;
use Dataphyre\Mailer\Providers\BrevoProvider;
use Dataphyre\Mailer\Providers\CloudflareProvider;
use Dataphyre\Mailer\Providers\LogProvider;
use Dataphyre\Mailer\Providers\MailgunProvider;
use Dataphyre\Mailer\Providers\PostmarkProvider;
use Dataphyre\Mailer\Providers\ResendProvider;
use Dataphyre\Mailer\Providers\SendGridProvider;
use Dataphyre\Mailer\Providers\SmtpProvider;

/**
 * Mail delivery coordinator for providers, outbox queues, suppression, delivery events, health, and template rendering.
 *
 * The manager owns provider factory registration and caching, normalizes array message data into Message objects,
 * applies delivery safety and idempotency rules, records outbox/event telemetry when SQL is available, and
 * converts provider or validation outcomes into SendResult objects.
 */
final class MailerManager {

	private static ?self $instance=null;
	/** @var array<string, MailProvider> */
	private array $providers=[];
	/** @var array<string, callable> */
	private array $providerFactories=[];

	/**
	 * Registers built-in provider factories for supported mail drivers.
	 *
	 * The constructor is private because MailerManager is process-local singleton
	 * state. Custom drivers are added later through extend().
	 */
	private function __construct() {
		$this->providerFactories=[
			'log'=>static fn(array $config): MailProvider => new LogProvider($config),
			'cloudflare'=>static fn(array $config): MailProvider => new CloudflareProvider($config),
			'sendgrid'=>static fn(array $config): MailProvider => new SendGridProvider($config),
			'smtp'=>static fn(array $config): MailProvider => new SmtpProvider($config),
			'mailgun'=>static fn(array $config): MailProvider => new MailgunProvider($config),
			'postmark'=>static fn(array $config): MailProvider => new PostmarkProvider($config),
			'resend'=>static fn(array $config): MailProvider => new ResendProvider($config),
			'brevo'=>static fn(array $config): MailProvider => new BrevoProvider($config),
			'sendinblue'=>static fn(array $config): MailProvider => new BrevoProvider($config),
			'aws'=>static fn(array $config): MailProvider => new AwsSesProvider($config),
			'aws_ses'=>static fn(array $config): MailProvider => new AwsSesProvider($config),
			'ses'=>static fn(array $config): MailProvider => new AwsSesProvider($config),
		];
	}

	/**
	 * Returns the shared mailer manager instance.
	 *
	 * @return self Process-wide manager with cached provider instances.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Drops the shared manager and cached provider instances.
	 *
	 * This is useful after configuration changes or tests that register custom provider factories.
	 *
	 * @return void
	 */
	public static function flushInstance(): void {
		self::$instance=null;
	}

	/**
	 * Registers or replaces a mail provider factory for a normalized driver name.
	 *
	 * Factories receive the provider configuration, provider name, and manager instance; they must
	 * return an implementation of MailProvider when provider() resolves that driver.
	 *
	 * @param string $driver Driver identifier such as `smtp`, `sendgrid`, or a custom provider key.
	 * @param callable $factory Factory that creates a MailProvider for the driver.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the normalized driver name is empty.
	 */
	public function extend(string $driver, callable $factory): void {
		$driver=$this->normalizeName($driver);
		if($driver===''){
			throw new \InvalidArgumentException('Mailer provider driver cannot be empty.');
		}
		$this->providerFactories[$driver]=$factory;
	}

	/**
	 * Resolves and caches a configured mail provider.
	 *
	 * Provider names are normalized from explicit input or the configured default provider. The
	 * provider's `driver` setting selects the factory, falling back to the provider name itself.
	 *
	 * @param ?string $name Provider name, or null for the configured default provider.
	 * @return MailProvider Cached provider instance.
	 *
	 * @throws \RuntimeException When no factory exists for the resolved driver or the factory returns a wrong type.
	 */
	public function provider(?string $name=null): MailProvider {
		$name=$this->providerName($name);
		if(isset($this->providers[$name])){
			return $this->providers[$name];
		}
		$config=$this->providerConfig($name);
		$driver=$this->normalizeName((string)($config['driver'] ?? $name));
		if(!isset($this->providerFactories[$driver])){
			throw new \RuntimeException("Mailer provider driver '{$driver}' is not registered.");
		}
		$provider=($this->providerFactories[$driver])($config, $name, $this);
		if(!$provider instanceof MailProvider){
			throw new \RuntimeException("Mailer provider '{$name}' factory did not return a MailProvider.");
		}
		return $this->providers[$name]=$provider;
	}

	/**
	 * Sends one message immediately or queues it when requested.
	 *
	 * Array message data is template-rendered before Message hydration. The message then passes through
	 * delivery safety, validation, suppression, idempotency, optional queueing, and provider-chain failover.
	 *
	 * @param array<string,mixed>|Message $message Message instance or array data accepted by Message::make().
	 * @param ?string $provider Preferred provider name, or null for the default provider chain.
	 * @param array<string,mixed> $options Delivery options including queue, providers/provider_chain, safety, idempotency, and provider options.
	 * @return SendResult Provider result, validation failure, suppression result, queue result, or delivery safety failure.
	 */
	public function send(array|Message $message, ?string $provider=null, array $options=[]): SendResult {
		$traceSuppressed=(bool)($options['__dataphyre_trace_suppressed'] ?? false);
		unset($options['__dataphyre_trace_suppressed']);
		$payload=[
			'provider'=>$provider ?? $this->providerName(null),
			'queue'=>(bool)($options['queue'] ?? false),
			'message_type'=>$message instanceof Message ? Message::class : 'array',
			'option_keys'=>array_values(array_map('strval', array_keys($options))),
		];
		$dialback=\dataphyre\core::dialback('CALL_MAILER_FRAMEWORK_SEND_BEFORE', $payload);
		if($dialback instanceof SendResult){
			return $dialback;
		}
		$message=$message instanceof Message ? $message : Message::make($this->renderMessageArray($message, $options));
		$message=$this->applyDeliverySafety($message, $provider, $options);
		if($message instanceof SendResult){
			$result=$message;
		}
		elseif(false!==$invalid=$this->validateMessage($message, $provider)){
			$result=$invalid;
		}
		elseif(false!==$suppressed=$this->suppressionResult($message, $provider, $options)){
			$result=$suppressed;
		}
		else
		{
			$options=$this->withIdempotency($message, $options);
			$result=(($options['queue'] ?? false)===true)
				? $this->queue($message, $provider, $options)
				: $this->sendThroughProviders($message, $this->providerChain($provider, $options), $options);
		}
		if(!$traceSuppressed){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Mailer send '.($result->ok() ? 'succeeded' : 'failed').'; provider='.$result->provider().'; status='.$result->status().'; queued='.(($options['queue'] ?? false)===true ? 'yes' : 'no'), $S=$result->ok() ? 'info' : 'warning');
		}
		$dialback=\dataphyre\core::dialback('CALL_MAILER_FRAMEWORK_SEND_AFTER', $payload+[
			'ok'=>$result->ok(),
			'result_provider'=>$result->provider(),
			'status'=>$result->status(),
			'has_message_id'=>$result->messageId()!==null,
		]);
		return $dialback instanceof SendResult ? $dialback : $result;
	}

	/**
	 * Sends or queues multiple messages while preserving per-message result order.
	 *
	 * Messages are normalized through the same safety, validation, and suppression gates as send().
	 * When a single batch-capable provider is selected, sendBatch() uses the provider batch API.
	 *
	 * @param list<array<string,mixed>|Message> $messages List of Message instances or array message data.
	 * @param ?string $provider Preferred provider name, or null for the default provider chain.
	 * @param array<string,mixed> $options Batch delivery options shared by every message.
	 * @return array<int,SendResult> Result for each input message in input order.
	 */
	public function sendBatch(array $messages, ?string $provider=null, array $options=[]): array {
		$payload=[
			'provider'=>$provider ?? $this->providerName(null),
			'queue'=>(bool)($options['queue'] ?? false),
			'message_count'=>count($messages),
			'option_keys'=>array_values(array_map('strval', array_keys($options))),
		];
		$dialback=\dataphyre\core::dialback('CALL_MAILER_FRAMEWORK_SEND_BATCH_BEFORE', $payload);
		if(is_array($dialback) && $dialback!==[]){
			return $dialback;
		}
		$normalized=[];
		foreach($messages as $message){
			$message=$message instanceof Message ? $message : Message::make($this->renderMessageArray(is_array($message) ? $message : [], $options));
			$message=$this->applyDeliverySafety($message, $provider, $options);
			if($message instanceof SendResult){
				$normalized[]=$message;
				continue;
			}
			if(false!==$invalid=$this->validateMessage($message, $provider)){
				$normalized[]=$invalid;
				continue;
			}
			if(false!==$suppressed=$this->suppressionResult($message, $provider, $options)){
				$normalized[]=$suppressed;
				continue;
			}
			$normalized[]=$message;
		}
		$sendable=array_values(array_filter($normalized, static fn(mixed $item): bool => $item instanceof Message));
		if($sendable===[]){
			$results=$normalized;
		}
		elseif(($options['queue'] ?? false)===true){
			$results=array_map(fn(Message|SendResult $item): SendResult => $item instanceof SendResult ? $item : $this->queue($item, $provider, $options), $normalized);
		}
		else
		{
			$chain=$this->providerChain($provider, $options);
			$primary=$this->provider($chain[0] ?? null);
			if($primary instanceof BatchMailProvider && count($chain)===1){
				$batchResults=$primary->sendBatch(array_map(fn(Message $message): Message => $message, $sendable), $options);
				$index=0;
				$results=array_map(static function(Message|SendResult $item) use (&$index, $batchResults): SendResult {
					if($item instanceof SendResult){
						return $item;
					}
					return $batchResults[$index++] ?? SendResult::failure('batch', 'Batch provider did not return a result for this message.', 500);
				}, $normalized);
			}
			else
			{
				$sendOptions=$options+['__dataphyre_trace_suppressed'=>true];
				$results=array_map(fn(Message|SendResult $item): SendResult => $item instanceof SendResult ? $item : $this->send($item, $provider, $sendOptions), $normalized);
			}
		}
		$failed=0;
		foreach($results as $result){
			if($result instanceof SendResult && !$result->ok()){
				$failed++;
			}
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Mailer batch send completed; requested='.count($messages).'; results='.count($results).'; failed='.$failed.'; queued='.(($options['queue'] ?? false)===true ? 'yes' : 'no'), $S=$failed===0 ? 'info' : 'warning');
		$dialback=\dataphyre\core::dialback('CALL_MAILER_FRAMEWORK_SEND_BATCH_AFTER', $payload+[
			'result_count'=>count($results),
			'failed'=>$failed,
		]);
		return is_array($dialback) && $dialback!==[] ? $dialback : $results;
	}

	/**
	 * Dispatches a message through the async framework when available, otherwise queues it.
	 *
	 * Async jobs return the send result array from the worker. The fallback queue path returns the
	 * queue SendResult so callers still receive a deterministic delivery outcome.
	 *
	 * @param array<string,mixed>|Message $message Message instance or array message data.
	 * @param ?string $provider Preferred provider name, or null for the default provider.
	 * @param array<string,mixed> $options Async driver and delivery options.
	 * @return mixed Async dispatch handle/result, or SendResult when queue fallback is used.
	 */
	public function sendAsync(array|Message $message, ?string $provider=null, array $options=[]): mixed {
		$payload=[
			'provider'=>$provider ?? $this->providerName(null),
			'message_type'=>$message instanceof Message ? Message::class : 'array',
			'async_driver'=>is_string($options['async_driver'] ?? null) ? (string)$options['async_driver'] : (string)$this->config('async.driver'),
			'option_keys'=>array_values(array_map('strval', array_keys($options))),
		];
		$dialback=\dataphyre\core::dialback('CALL_MAILER_FRAMEWORK_SEND_ASYNC_BEFORE', $payload);
		if($dialback!==null){
			return $dialback;
		}
		if(\dataphyre\core::load_framework_module('async')===true && class_exists('\Dataphyre\Async\Async')){
			$result=\Dataphyre\Async\Async::dispatch(fn(): array => $this->send($message, $provider, $options)->toArray(), [], $options['async_driver'] ?? $this->config('async.driver'));
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Mailer async send dispatched; provider='.$payload['provider'].'; async_driver='.$payload['async_driver']);
			$dialback=\dataphyre\core::dialback('CALL_MAILER_FRAMEWORK_SEND_ASYNC_AFTER', $payload+['queued_fallback'=>false, 'result_type'=>is_object($result) ? $result::class : gettype($result)]);
			return $dialback!==null ? $dialback : $result;
		}
		$result=$this->queue($message, $provider, $options);
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Mailer async send queued fallback; provider='.$result->provider().'; status='.$result->status(), $S=$result->ok() ? 'info' : 'warning');
		$dialback=\dataphyre\core::dialback('CALL_MAILER_FRAMEWORK_SEND_ASYNC_AFTER', $payload+['queued_fallback'=>true, 'ok'=>$result->ok(), 'status'=>$result->status()]);
		return $dialback!==null ? $dialback : $result;
	}

	/**
	 * Persists one validated message into the mailer outbox.
	 *
	 * Queueing requires SQL helpers and an enabled outbox. The queued row stores provider, status,
	 * priority, attempt limits, optional not-before scheduling, encoded message data, and an
	 * initial queued event.
	 *
	 * @param array<string,mixed>|Message $message Message instance or array message data.
	 * @param ?string $provider Provider name stored with the outbox row.
	 * @param array<string,mixed> $options Queue options such as not_before, priority, max_attempts, and idempotency.
	 * @return SendResult Success with the generated outbox id, or failure when queueing is unavailable or invalid.
	 */
	public function queue(array|Message $message, ?string $provider=null, array $options=[]): SendResult {
		if(function_exists('sql_insert')===false || !$this->outboxEnabled()){
			return SendResult::failure($provider ?? $this->providerName(null), 'Mailer outbox is not available.', 500);
		}
		$message=$message instanceof Message ? $message : Message::make($this->renderMessageArray($message, $options));
		$name=$this->providerName($provider);
		$message=$this->applyDeliverySafety($message, $name, $options);
		if($message instanceof SendResult){
			return $message;
		}
		if(false!==$invalid=$this->validateMessage($message, $name)){
			return $invalid;
		}
		if(false!==$suppressed=$this->suppressionResult($message, $name, $options)){
			return $suppressed;
		}
		$options=$this->withIdempotency($message, $options);
		$id='mail_'.bin2hex(random_bytes(16));
		$notBefore=$options['not_before'] ?? null;
		if($notBefore instanceof \DateTimeInterface){
			$notBefore=$notBefore->format('Y-m-d H:i:s');
		}
		elseif(is_int($notBefore)){
			$notBefore=date('Y-m-d H:i:s', $notBefore);
		}
		elseif(is_string($notBefore) && trim($notBefore)!==''){
			$notBefore=date('Y-m-d H:i:s', strtotime($notBefore) ?: time());
		}
		else{
			$notBefore=null;
		}
		$ok=sql_insert($this->outboxTable(), [
			'id'=>$id,
			'provider'=>$name,
			'status'=>'queued',
			'priority'=>$this->messagePriority($message, $options),
			'attempts'=>0,
			'max_attempts'=>(int)($options['max_attempts'] ?? $this->config('outbox.default_max_attempts', 3)),
			'not_before'=>$notBefore,
			'message_json'=>json_encode($message->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
			'result_json'=>null,
			'last_error'=>null,
		], null, true)!==false;
		if(!$ok){
			return SendResult::failure($name, 'Unable to queue mail message.', 500);
		}
		$this->recordEvent($id, $name, 'queued', 'info', [
			'message_id'=>$id,
			'not_before'=>$notBefore,
			'priority'=>$this->messagePriority($message, $options),
			'provider_chain'=>$this->providerChain($name, $options),
		]);
		return SendResult::success($name, 202, 'Message queued.', $id, ['queued'=>true]);
	}

	/**
	 * Processes queued outbox messages up to a bounded limit.
	 *
	 * Flush recovers stale sending rows, skips provider buckets that hit configured per-flush rate
	 * limits, increments attempts, re-applies validation/suppression, sends through the provider
	 * chain, persists the resulting status, and records telemetry events.
	 *
	 * @param int $limit Maximum queued rows to process, clamped to 1..250.
	 * @return array{ok:bool,processed:int,sent:int,failed:int,suppressed?:int,rate_limited?:int,recovered?:int,message?:string} Flush summary.
	 */
	public function flush(int $limit=25): array {
		if(function_exists('sql_select')===false || function_exists('sql_update')===false || !$this->outboxEnabled()){
			return ['ok'=>false, 'processed'=>0, 'sent'=>0, 'failed'=>0, 'message'=>'Mailer outbox is not available.'];
		}
		$limit=max(1, min(250, $limit));
		$recovered=$this->recoverStaleSending();
		$rows=sql_select(
			'*',
			$this->outboxTable(),
			'WHERE status=? AND (not_before IS NULL OR not_before<=?) ORDER BY priority DESC, created_at ASC LIMIT '.$limit,
			['queued', date('Y-m-d H:i:s')],
			true,
			false
		);
		if(!is_array($rows)){
			return ['ok'=>false, 'processed'=>0, 'sent'=>0, 'failed'=>0, 'message'=>'Unable to read mailer outbox.'];
		}
		$processed=0;
		$sent=0;
		$failed=0;
		$suppressed=0;
		$rateLimited=0;
		$providerCounts=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$processed++;
			$id=(string)($row['id'] ?? '');
			$provider=(string)($row['provider'] ?? null);
			if($this->providerRateLimitReached($provider, $providerCounts)){
				$rateLimited++;
				$notBefore=$this->rateLimitRetryAt();
				sql_update($this->outboxTable(), [
					'not_before'=>$notBefore,
					'updated_at'=>date('Y-m-d H:i:s'),
				], 'WHERE id=?', [$id], true);
				$this->recordEvent($id, $provider, 'rate_limited', 'warning', [
					'next_attempt_at'=>$notBefore,
					'limit'=>$this->providerRateLimit($provider),
				]);
				continue;
			}
			$providerCounts[$provider]=($providerCounts[$provider] ?? 0)+1;
			$attempts=((int)($row['attempts'] ?? 0))+1;
			sql_update($this->outboxTable(), [
				'status'=>'sending',
				'attempts'=>$attempts,
				'updated_at'=>date('Y-m-d H:i:s'),
			], 'WHERE id=?', [$id], true);
			$messagePayload=json_decode((string)($row['message_json'] ?? '{}'), true);
			$message=Message::make(is_array($messagePayload) ? $messagePayload : []);
			$message=$this->applyDeliverySafety($message, $provider, ['outbox_id'=>$id, 'outbox_attempt'=>$attempts]);
			if($message instanceof SendResult){
				$result=$message;
			}
			elseif(($message->metadata()['delivery_safety_applied'] ?? false)===true){
				sql_update($this->outboxTable(), [
					'message_json'=>json_encode($message->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
					'updated_at'=>date('Y-m-d H:i:s'),
				], 'WHERE id=?', [$id], true);
				if(false!==$invalid=$this->validateMessage($message, $provider)){
					$result=$invalid;
				}
				elseif(false!==$suppressionResult=$this->suppressionResult($message, $provider, ['outbox_id'=>$id, 'outbox_attempt'=>$attempts])){
					$result=$suppressionResult;
				}
				else{
					$result=$this->sendThroughProviders(
						$message,
						$this->providerChain($provider, []),
						$this->withIdempotency($message, ['outbox_id'=>$id, 'outbox_attempt'=>$attempts], $id)
					);
				}
			}
			elseif(false!==$invalid=$this->validateMessage($message, $provider)){
				$result=$invalid;
			}
			elseif(false!==$suppressionResult=$this->suppressionResult($message, $provider, ['outbox_id'=>$id, 'outbox_attempt'=>$attempts])){
				$result=$suppressionResult;
			}
			else{
				$result=$this->sendThroughProviders(
					$message,
					$this->providerChain($provider, []),
					$this->withIdempotency($message, ['outbox_id'=>$id, 'outbox_attempt'=>$attempts], $id)
				);
			}
			$status=$result->ok()
				? 'sent'
				: ((bool)($result->meta()['suppressed'] ?? false) ? 'suppressed' : ($attempts>=(int)($row['max_attempts'] ?? 3) ? 'failed' : 'queued'));
			$notBefore=$status==='queued' ? $this->nextRetryAt($attempts) : null;
			if($result->ok()){
				$sent++;
			}
			elseif($status==='suppressed'){
				$suppressed++;
			}
			else{
				$failed++;
			}
			sql_update($this->outboxTable(), [
				'status'=>$status,
				'not_before'=>$notBefore,
				'result_json'=>json_encode($result->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
				'last_error'=>$result->ok() ? null : $result->message(),
				'updated_at'=>date('Y-m-d H:i:s'),
			], 'WHERE id=?', [$id], true);
			$this->recordEvent($id, $provider, $status==='queued' ? 'retry_scheduled' : $status, $result->ok() ? 'info' : 'error', [
				'attempt'=>$attempts,
				'next_attempt_at'=>$notBefore,
				'result'=>$result->toArray(),
			]);
		}
		return [
			'ok'=>true,
			'processed'=>$processed,
			'sent'=>$sent,
			'failed'=>$failed,
			'suppressed'=>$suppressed,
			'rate_limited'=>$rateLimited,
			'recovered'=>$recovered,
		];
	}

	/**
	 * Counts outbox rows by status.
	 *
	 * @return array{ok:bool,statuses:array<string,int>,message?:string} Outbox availability and status counts.
	 */
	public function outboxSummary(): array {
		if(function_exists('sql_select')===false || !$this->outboxEnabled()){
			return ['ok'=>false, 'message'=>'Mailer outbox is not available.', 'statuses'=>[]];
		}
		$rows=sql_select(
			'status, COUNT(*) AS total',
			$this->outboxTable(),
			'GROUP BY status',
			[],
			true,
			false
		);
		if(!is_array($rows)){
			return ['ok'=>false, 'message'=>'Unable to read mailer outbox.', 'statuses'=>[]];
		}
		$statuses=[];
		foreach($rows as $row){
			if(is_array($row) && isset($row['status'])){
				$statuses[(string)$row['status']]=(int)($row['total'] ?? 0);
			}
		}
		return ['ok'=>true, 'statuses'=>$statuses];
	}

	/**
	 * Prunes retained outbox, event, webhook dedupe, and expired suppression records.
	 *
	 * Retention windows are merged from configuration and explicit options. Each section reports
	 * its cutoff and deleted count so scheduled maintenance can log partial failures.
	 *
	 * @param array<string,mixed> $options Retention overrides such as outbox_sent_days, events_days, and expired_suppressions.
	 * @return array{ok:bool,sections:array<string,array<string,mixed>>} Per-section pruning results.
	 */
	public function prune(array $options=[]): array {
		if(function_exists('sql_delete')===false){
			return ['ok'=>false, 'message'=>'SQL delete is not available for mailer pruning.', 'sections'=>[]];
		}
		$retention=$this->retentionPolicy($options);
		$sections=[];
		if($this->outboxEnabled()){
			foreach([
				'sent'=>'outbox_sent_days',
				'failed'=>'outbox_failed_days',
				'suppressed'=>'outbox_suppressed_days',
			] as $status=>$key){
				$days=(int)($retention[$key] ?? 0);
				if($days>0){
					$cutoff=$this->retentionCutoff($days);
					$sections['outbox_'.$status]=$this->pruneDelete(
						$this->outboxTable(),
						'WHERE status=? AND updated_at<?',
						[$status, $cutoff],
						$cutoff
					);
				}
			}
			$eventsDays=(int)($retention['events_days'] ?? 0);
			if($eventsDays>0){
				$cutoff=$this->retentionCutoff($eventsDays);
				$sections['events']=$this->pruneDelete($this->eventsTable(), 'WHERE created_at<?', [$cutoff], $cutoff);
			}
		}
		if((bool)$this->config('webhooks.dedupe_enabled', true)===true){
			$webhookDays=(int)($retention['webhook_events_days'] ?? 0);
			if($webhookDays>0){
				$cutoff=$this->retentionCutoff($webhookDays);
				$sections['webhook_events']=$this->pruneDelete($this->webhookEventsTable(), 'WHERE created_at<?', [$cutoff], $cutoff);
			}
		}
		if($this->suppressionEnabled() && (bool)($retention['expired_suppressions'] ?? true)===true){
			$cutoff=date('Y-m-d H:i:s');
			$sections['expired_suppressions']=$this->pruneDelete($this->suppressionTable(), 'WHERE expires_at IS NOT NULL AND expires_at<=?', [$cutoff], $cutoff);
		}
		$ok=true;
		foreach($sections as $section){
			$ok=$ok && ($section['ok'] ?? false)===true;
		}
		return [
			'ok'=>$ok,
			'sections'=>$sections,
		];
	}

	/**
	 * Summarizes recent outbox activity for campaign, tag, template, or metadata filters.
	 *
	 * The scan is bounded by `limit` and `since`/`since_days`, then matched against decoded
	 * message metadata and tags. The response includes matching status/provider counts, related
	 * event counts, and a capped sample of matching messages.
	 *
	 * @param array<string,mixed> $filters Filter options including campaign, tag, template, metadata_key, metadata_value, since, since_days, limit, and sample.
	 * @return array<string,mixed> Campaign summary with ok flag, scanned count, matches, statuses, providers, events, and sample rows.
	 */
	public function campaignSummary(array $filters=[]): array {
		if(function_exists('sql_select')===false || !$this->outboxEnabled()){
			return ['ok'=>false, 'message'=>'Mailer outbox is not available.', 'matches'=>0, 'statuses'=>[], 'events'=>[]];
		}
		$limit=max(1, min(5000, (int)($filters['limit'] ?? 1000)));
		$sinceDays=max(1, min(3660, (int)($filters['since_days'] ?? 90)));
		$since=$this->normalizeDate($filters['since'] ?? null) ?? date('Y-m-d H:i:s', time()-($sinceDays*86400));
		$rows=sql_select(
			'id, provider, status, message_json, created_at, updated_at',
			$this->outboxTable(),
			'WHERE created_at>=? ORDER BY created_at DESC LIMIT '.$limit,
			[$since],
			true,
			false
		);
		if(!is_array($rows)){
			return ['ok'=>false, 'message'=>'Unable to read mailer outbox.', 'matches'=>0, 'statuses'=>[], 'events'=>[]];
		}
		$matches=[];
		$statuses=[];
		$providers=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			$message=json_decode((string)($row['message_json'] ?? '{}'), true);
			$message=is_array($message) ? $message : [];
			if(!$this->campaignSummaryMatch($message, $filters)){
				continue;
			}
			$id=(string)($row['id'] ?? '');
			$status=(string)($row['status'] ?? 'unknown');
			$provider=(string)($row['provider'] ?? 'unknown');
			$statuses[$status]=($statuses[$status] ?? 0)+1;
			$providers[$provider]=($providers[$provider] ?? 0)+1;
			$matches[]=[
				'id'=>$id,
				'provider'=>$provider,
				'status'=>$status,
				'subject'=>(string)($message['subject'] ?? ''),
				'tags'=>is_array($message['tags'] ?? null) ? $message['tags'] : [],
				'metadata'=>is_array($message['metadata'] ?? null) ? $message['metadata'] : [],
				'created_at'=>$row['created_at'] ?? null,
				'updated_at'=>$row['updated_at'] ?? null,
			];
		}
		return [
			'ok'=>true,
			'since'=>$since,
			'scanned'=>count($rows),
			'matches'=>count($matches),
			'statuses'=>$statuses,
			'providers'=>$providers,
			'events'=>$this->campaignEventSummary(array_column($matches, 'id')),
			'sample'=>array_slice($matches, 0, max(0, min(50, (int)($filters['sample'] ?? 10)))),
		];
	}

	/**
	 * Adds or updates an email suppression record.
	 *
	 * Suppressions are stored by salted email hash when SQL and suppression storage are enabled.
	 * The raw email is stored only when explicitly requested or configured.
	 *
	 * @param string $email Email address to suppress.
	 * @param string $reason Normalized reason such as manual, bounce, complaint, or unsubscribe.
	 * @param array<string,mixed> $options Suppression options including expires_at/expires, metadata, source, and store_email.
	 * @return bool True when the suppression row was inserted or updated.
	 */
	public function suppress(string $email, string $reason='manual', array $options=[]): bool {
		$email=$this->normalizeEmail($email);
		if($email===''){
			return false;
		}
		if(function_exists('sql_insert')===false || function_exists('sql_select')===false || !$this->suppressionEnabled()){
			return false;
		}
		$hash=$this->emailHash($email);
		$expiresAt=$this->normalizeDate($options['expires_at'] ?? $options['expires'] ?? null);
		$metadata=is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
		$source=$this->normalizeName((string)($options['source'] ?? 'dataphyre'));
		$reason=$this->normalizeName($reason);
		$row=sql_select('*', $this->suppressionTable(), 'WHERE email_hash=? LIMIT 1', [$hash], false, true);
		$fields=[
			'email_hash'=>$hash,
			'email'=>((bool)($options['store_email'] ?? $this->config('suppression.store_email', false))) ? $email : null,
			'reason'=>$reason!=='' ? $reason : 'manual',
			'source'=>$source!=='' ? $source : 'dataphyre',
			'metadata_json'=>json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
			'expires_at'=>$expiresAt,
			'updated_at'=>date('Y-m-d H:i:s'),
		];
		if(is_array($row) && $row!==[]){
			return function_exists('sql_update') && sql_update($this->suppressionTable(), $fields, 'WHERE email_hash=?', [$hash], true)!==false;
		}
		$fields['id']='msup_'.bin2hex(random_bytes(16));
		unset($fields['updated_at']);
		return sql_insert($this->suppressionTable(), $fields, null, true)!==false;
	}

	/**
	 * Removes an email address from persistent suppressions.
	 *
	 * @param string $email Email address to unsuppress.
	 * @return bool True when the delete operation was available and did not fail.
	 */
	public function unsuppress(string $email): bool {
		$email=$this->normalizeEmail($email);
		if($email==='' || function_exists('sql_delete')===false || !$this->suppressionEnabled()){
			return false;
		}
		return sql_delete($this->suppressionTable(), 'WHERE email_hash=?', [$this->emailHash($email)], true)!==false;
	}

	/**
	 * Reports whether an email is currently suppressed by configuration or storage.
	 *
	 * Expired suppressions are ignored by the lookup path.
	 *
	 * @param string $email Email address to inspect.
	 * @return bool True when an active suppression record exists.
	 */
	public function isSuppressed(string $email): bool {
		return $this->suppressionRecord($email)!==null;
	}

	/**
	 * Normalizes and records one provider delivery event.
	 *
	 * Provider-specific payloads are normalized, deduplicated by event hash, recorded into telemetry,
	 * and may create suppressions for bounce, complaint, unsubscribe, or equivalent hard-failure events.
	 *
	 * @param string $provider Provider that emitted the event.
	 * @param array<string,mixed> $payload Raw provider event payload.
	 * @param ?string $event Optional event name override when the provider payload does not carry one.
	 * @return array{ok:bool,provider:string,event:string,message_id:?string,recipients:list<string>,suppressed:int,duplicate:bool} Normalized ingestion result.
	 */
	public function ingestDeliveryEvent(string $provider, array $payload, ?string $event=null): array {
		$provider=$this->providerName($provider);
		$normalized=$this->normalizeProviderDeliveryPayload($provider, $payload, $event);
		$payload=$normalized['payload'];
		$event=$this->normalizeDeliveryEvent((string)($normalized['event'] ?? ''));
		$messageId=$this->payloadString($payload, ['message_id', 'messageId', 'sg_message_id', 'MessageId']);
		if($messageId==='' && is_array($payload['mail'] ?? null)){
			$messageId=(string)($payload['mail']['messageId'] ?? $payload['mail']['message_id'] ?? '');
		}
		$recipients=$this->deliveryEventRecipients($payload);
		$eventHash=$this->deliveryEventHash($provider, $event, $messageId, $recipients, $payload);
		if($this->webhookEventSeen($eventHash)){
			return [
				'ok'=>true,
				'provider'=>$provider,
				'event'=>$event,
				'message_id'=>$messageId!=='' ? $messageId : null,
				'recipients'=>$recipients,
				'suppressed'=>0,
				'duplicate'=>true,
			];
		}
		$suppressionReason=$this->suppressionReasonForDeliveryEvent($event);
		$suppressed=0;
		if($suppressionReason!==null){
			foreach($recipients as $email){
				if($this->suppress($email, $suppressionReason, [
					'source'=>$provider,
					'metadata'=>[
						'event'=>$event,
						'message_id'=>$messageId,
						'provider'=>$provider,
					],
				])){
					$suppressed++;
				}
			}
		}
		$this->recordEvent(
			$messageId!=='' ? $messageId : null,
			$provider,
			$event!=='' ? $event : 'delivery_event',
			$this->deliveryEventSeverity($event),
			[
				'event'=>$event,
				'recipients'=>$recipients,
				'suppressed'=>$suppressed,
				'payload'=>$payload,
			]
		);
		$this->recordWebhookEvent($eventHash, $provider, $event, $messageId, $payload);
		return [
			'ok'=>true,
			'provider'=>$provider,
			'event'=>$event,
			'message_id'=>$messageId!=='' ? $messageId : null,
			'recipients'=>$recipients,
			'suppressed'=>$suppressed,
			'duplicate'=>false,
		];
	}

	/**
	 * Normalizes and records a batch of provider delivery events.
	 *
	 * Webhook payload wrappers are flattened before each event is passed through ingestDeliveryEvent().
	 *
	 * @param string $provider Provider that emitted the events.
	 * @param array<string,mixed>|list<array<string,mixed>> $payloads Raw webhook payload array or list of event payloads.
	 * @param ?string $event Optional event name override for payloads that do not carry one.
	 * @return array{ok:bool,provider:string,processed:int,suppressed:int,events:list<array<string,mixed>>} Batch ingestion summary.
	 */
	public function ingestDeliveryEvents(string $provider, array $payloads, ?string $event=null): array {
		$events=$this->webhookEventPayloads($payloads);
		$results=[];
		$suppressed=0;
		foreach($events as $payload){
			$result=$this->ingestDeliveryEvent($provider, $payload, $event);
			$results[]=$result;
			$suppressed+=(int)($result['suppressed'] ?? 0);
		}
		return [
			'ok'=>true,
			'provider'=>$this->providerName($provider),
			'processed'=>count($results),
			'suppressed'=>$suppressed,
			'events'=>$results,
		];
	}

	/**
	 * Verifies, decodes, and ingests a provider delivery webhook body.
	 *
	 * Empty bodies, invalid signatures, and invalid JSON return structured failures. Valid JSON is
	 * forwarded to ingestDeliveryEvents() for normalization, dedupe, suppression, and telemetry.
	 *
	 * @param string $provider Provider that sent the webhook.
	 * @param string $body Raw request body.
	 * @param array<string,string|list<string>> $headers Request headers used for signature verification.
	 * @param ?string $event Optional event override.
	 * @return array<string,mixed> Webhook ingestion result with ok flag, processed count, suppressed count, and event records.
	 */
	public function ingestDeliveryWebhook(string $provider, string $body, array $headers=[], ?string $event=null): array {
		$body=trim($body);
		if($body===''){
			return ['ok'=>false, 'message'=>'Mailer webhook body is empty.', 'processed'=>0, 'suppressed'=>0, 'events'=>[]];
		}
		if(!$this->verifyWebhookSignature($provider, $body, $headers)){
			return ['ok'=>false, 'message'=>'Mailer webhook signature is invalid.', 'processed'=>0, 'suppressed'=>0, 'events'=>[]];
		}
		$decoded=json_decode($body, true);
		if(!is_array($decoded)){
			return ['ok'=>false, 'message'=>'Mailer webhook body is not valid JSON.', 'processed'=>0, 'suppressed'=>0, 'events'=>[]];
		}
		return $this->ingestDeliveryEvents($provider, $decoded, $event);
	}

	/**
	 * Builds an operational health snapshot for the mailer subsystem.
	 *
	 * The snapshot combines provider readiness, default-provider readiness, outbox status counts,
	 * recent delivery events, suppression totals, and webhook dedupe counts.
	 *
	 * @param int $windowHours Lookback window for event and webhook summaries, clamped to 1 hour through 31 days.
	 * @return array Health payload with ok flag, provider readiness, outbox, events, suppressions, and webhooks.
	 */
	public function health(int $windowHours=24): array {
		$windowHours=max(1, min(24*31, $windowHours));
		$since=date('Y-m-d H:i:s', time()-($windowHours*3600));
		$outbox=$this->outboxSummary();
		$providers=$this->providerHealth();
		$ok=($outbox['ok'] ?? false)===true;
		foreach($providers as $provider){
			if(($provider['name'] ?? '')===$this->providerName(null) && ($provider['ready'] ?? false)!==true){
				$ok=false;
			}
		}
		return [
			'ok'=>$ok,
			'window_hours'=>$windowHours,
			'default_provider'=>$this->providerName(null),
			'providers'=>$providers,
			'outbox'=>$outbox,
			'events'=>$this->eventSummary($since),
			'suppressions'=>$this->suppressionSummary(),
			'webhooks'=>$this->webhookSummary($since),
		];
	}

	/**
	 * Retrieves outbox, event, and webhook history for one message id.
	 *
	 * JSON columns are decoded before returning so diagnostics can inspect the original message,
	 * send result, telemetry payloads, and webhook payloads without repeating decode logic.
	 *
	 * @param string $messageId Outbox or provider message id to trace.
	 * @return array Trace payload with ok flag, message id, optional outbox row, events, and webhooks.
	 */
	public function trace(string $messageId): array {
		$messageId=trim($messageId);
		if($messageId===''){
			return ['ok'=>false, 'message'=>'Mailer trace message id cannot be empty.', 'message_id'=>''];
		}
		if(function_exists('sql_select')===false){
			return ['ok'=>false, 'message'=>'SQL is not available for mailer trace.', 'message_id'=>$messageId];
		}
		$outbox=null;
		if($this->outboxEnabled()){
			$row=sql_select('*', $this->outboxTable(), 'WHERE id=? LIMIT 1', [$messageId], false, true);
			$outbox=is_array($row) && $row!==[] ? $this->decodeTraceRow($row, ['message_json', 'result_json']) : null;
		}
		$events=sql_select(
			'*',
			$this->eventsTable(),
			'WHERE message_id=? ORDER BY created_at ASC',
			[$messageId],
			true,
			false
		);
		$webhooks=sql_select(
			'*',
			$this->webhookEventsTable(),
			'WHERE message_id=? ORDER BY created_at ASC',
			[$messageId],
			true,
			false
		);
		$events=is_array($events) ? array_map(fn(array $row): array => $this->decodeTraceRow($row, ['payload_json']), array_filter($events, 'is_array')) : [];
		$webhooks=is_array($webhooks) ? array_map(fn(array $row): array => $this->decodeTraceRow($row, ['payload_json']), array_filter($webhooks, 'is_array')) : [];
		return [
			'ok'=>$outbox!==null || $events!==[] || $webhooks!==[],
			'message_id'=>$messageId,
			'outbox'=>$outbox,
			'events'=>$events,
			'webhooks'=>$webhooks,
		];
	}

	/**
	 * Renders a mail template into subject, HTML, and text parts.
	 *
	 * Template resolution supports bundled subject/html/text files, single template files, and inline
	 * template strings. The templating framework is used when available; otherwise simple token
	 * replacement is used. Missing text is derived from rendered HTML.
	 *
	 * @param string $template Template name, file path, bundle stem, or inline template string.
	 * @param array<string,mixed> $data Template variables.
	 * @param array<string,mixed> $options Render options including subject, subject_key, subject_fallback, language, page, and theme.
	 * @return array{subject:string,html:string,text:string} Rendered message parts.
	 */
	public function render(string $template, array $data=[], array $options=[]): array {
		$subject='';
		if(isset($options['subject'])){
			$subject=(string)$options['subject'];
		}
		if(isset($options['subject_key'])){
			$subject=$this->translate((string)$options['subject_key'], $subject !== '' ? $subject : ($options['subject_fallback'] ?? null), $data, $options);
		}
		$html='';
		$text='';
		$resolved=$this->resolveTemplate($template);
		if(is_array($resolved)){
			$subject=$subject!=='' ? $subject : $this->renderTemplateFile((string)($resolved['subject'] ?? ''), $data);
			$html=$this->renderTemplateFile((string)($resolved['html'] ?? ''), $data);
			$text=$this->renderTemplateFile((string)($resolved['text'] ?? ''), $data);
		}
		elseif(is_string($resolved) && $resolved!==''){
			$html=$this->renderTemplateFile($resolved, $data);
		}
		else{
			$html=$this->renderTemplateString($template, $data, 'mailer-inline.html.tpl');
		}
		if($text==='' && $html!==''){
			$text=trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
		}
		return [
			'subject'=>trim($subject),
			'html'=>$html,
			'text'=>$text,
		];
	}

	/**
	 * Renders template-backed message arrays before Message hydration.
	 *
	 * Existing subject, HTML, or text fields are preserved; missing fields are
	 * filled from the rendered template so callers can override individual parts
	 * without bypassing bundle rendering.
	 *
	 * @param array<string,mixed> $message Raw message data.
	 * @param array<string,mixed> $options Delivery/render options.
	 * @return array<string,mixed> Message data with rendered parts applied.
	 */
	private function renderMessageArray(array $message, array $options=[]): array {
		if(isset($message['template']) && is_string($message['template'])){
			$rendered=$this->render($message['template'], is_array($message['data'] ?? null) ? $message['data'] : [], array_replace($options, is_array($message['template_options'] ?? null) ? $message['template_options'] : []));
			foreach(['subject', 'html', 'text'] as $key){
				if(!isset($message[$key]) || trim((string)$message[$key])===''){
					$message[$key]=$rendered[$key] ?? '';
				}
			}
		}
		return $message;
	}

	/**
	 * Resolves the normalized provider name for a delivery attempt.
	 *
	 * Null uses the configured default provider, and empty normalized values fall
	 * back to the log provider so development delivery remains deterministic.
	 *
	 * @param ?string $provider Requested provider name.
	 * @return string Normalized provider name.
	 */
	private function providerName(?string $provider): string {
		$provider=$this->normalizeName((string)($provider ?? $this->config('default_provider', 'log')));
		return $provider!=='' ? $provider : 'log';
	}

	/**
	 * Resolves the ordered provider chain for one delivery.
	 *
	 * Explicit providers/provider_chain options win. Otherwise the primary provider
	 * is followed by configured failover providers, with duplicates and empty names
	 * removed while preserving order.
	 *
	 * @param ?string $provider Preferred provider name.
	 * @param array<string,mixed> $options Delivery options.
	 * @return array<int, string> Provider names in attempt order.
	 */
	private function providerChain(?string $provider, array $options): array {
		$configured=$options['providers'] ?? $options['provider_chain'] ?? null;
		$chain=[];
		if(is_array($configured)){
			$chain=$configured;
		}
		elseif(is_string($configured) && trim($configured)!==''){
			$chain=preg_split('/\s*,\s*/', $configured) ?: [];
		}
		else{
			$chain[]=$this->providerName($provider);
			$failovers=$options['failover_providers'] ?? $this->config('failover_providers', []);
			if(is_string($failovers)){
				$failovers=preg_split('/\s*,\s*/', $failovers) ?: [];
			}
			if(is_array($failovers)){
				$chain=array_merge($chain, $failovers);
			}
		}
		$normalized=[];
		foreach($chain as $name){
			$name=$this->normalizeName((string)$name);
			if($name!=='' && !in_array($name, $normalized, true)){
				$normalized[]=$name;
			}
		}
		return $normalized!==[] ? $normalized : ['log'];
	}

	/**
	 * Sends a message through an ordered provider chain with failover telemetry.
	 *
	 * Provider exceptions are converted to failure SendResult objects. Every
	 * attempt is recorded as an event, and successful failover annotates metadata
	 * with attempts and failover_used.
	 *
	 * @param Message $message Message to send.
	 * @param list<string> $providers Provider names in attempt order.
	 * @param array<string,mixed> $options Provider delivery options.
	 * @return SendResult First success, or final failure with exhausted attempts.
	 */
	private function sendThroughProviders(Message $message, array $providers, array $options=[]): SendResult {
		$attempts=[];
		$lastResult=null;
		foreach($providers as $name){
			try{
				$result=$this->provider($name)->send($message, $options);
			}
			catch(\Throwable $exception){
				$result=SendResult::failure($name, $exception->getMessage(), 500, [
					'exception'=>get_class($exception),
				]);
			}
			$attempts[]=[
				'provider'=>$name,
				'ok'=>$result->ok(),
				'status'=>$result->status(),
				'message'=>$result->message(),
				'message_id'=>$result->messageId(),
			];
			$this->recordEvent(
				(string)($options['outbox_id'] ?? '') !== '' ? (string)$options['outbox_id'] : $result->messageId(),
				$name,
				$result->ok() ? 'sent' : 'failed',
				$result->ok() ? 'info' : 'error',
				[
					'attempts'=>$attempts,
					'result'=>$result->toArray(),
				]
			);
			if($result->ok()){
				return SendResult::success(
					$name,
					$result->status(),
					$result->message(),
					$result->messageId(),
					$result->response(),
					array_replace($result->meta(), ['attempts'=>$attempts, 'failover_used'=>count($attempts)>1])
				);
			}
			$lastResult=$result;
		}
		$name=(string)($providers[0] ?? $this->providerName(null));
		return SendResult::failure(
			$name,
			$lastResult instanceof SendResult ? $lastResult->message() : 'All mail providers failed.',
			$lastResult instanceof SendResult ? $lastResult->status() : 500,
			$lastResult instanceof SendResult ? $lastResult->response() : [],
			['attempts'=>$attempts, 'failover_exhausted'=>true]
		);
	}

	/**
	 * Validates local message structure before provider or queue delivery.
	 *
	 * Validation protects provider APIs from structurally incomplete messages:
	 * at least one recipient, a non-empty subject, and at least one body part.
	 *
	 * @param Message $message Message to validate.
	 * @param ?string $provider Provider used for failure attribution.
	 * @return SendResult|false Failure result, or false when valid.
	 */
	private function validateMessage(Message $message, ?string $provider=null): SendResult|false {
		$name=$provider!==null ? $this->providerName($provider) : $this->providerName(null);
		if($message->to()===[]){
			return SendResult::failure($name, 'Message has no recipients.', 422);
		}
		if($message->subject()===''){
			return SendResult::failure($name, 'Message subject cannot be empty.', 422);
		}
		if($message->html()==='' && $message->text()===''){
			return SendResult::failure($name, 'Message body cannot be empty.', 422);
		}
		return false;
	}

	/**
	 * Resolves a queue priority from options, metadata, or defaults.
	 *
	 * Numeric priorities are clamped to -1000..1000. Named priorities map common
	 * delivery urgency labels onto stable integer buckets.
	 *
	 * @param Message $message Message whose metadata may define priority.
	 * @param array<string,mixed> $options Queue options.
	 * @return int Queue priority.
	 */
	private function messagePriority(Message $message, array $options=[]): int {
		$value=$options['priority'] ?? $message->metadata()['priority'] ?? $message->metadata()['queue_priority'] ?? $this->config('outbox.default_priority', 0);
		if(is_numeric($value)){
			return max(-1000, min(1000, (int)$value));
		}
		$value=$this->normalizeName((string)$value);
		return match($value){
			'critical', 'urgent'=>100,
			'high'=>50,
			'normal', 'default'=>0,
			'low'=>-50,
			'bulk', 'background'=>-100,
			default=>0,
		};
	}

	/**
	 * Applies development/test delivery safety rules to a message.
	 *
	 * Blocked recipients can be rewritten to a safe recipient list with original
	 * recipients preserved in diagnostic headers, or rejected with a SendResult
	 * failure when block_unmatched is enabled.
	 *
	 * @param Message $message Message to inspect.
	 * @param ?string $provider Provider used for failure attribution.
	 * @param array<string,mixed> $options Delivery options.
	 * @return Message|SendResult Safe message or blocking failure.
	 */
	private function applyDeliverySafety(Message $message, ?string $provider=null, array $options=[]): Message|SendResult {
		$policy=$this->deliverySafetyPolicy($options);
		if(($policy['enabled'] ?? false)!==true || ($options['ignore_delivery_safety'] ?? false)===true){
			return $message;
		}
		if(($message->metadata()['delivery_safety_applied'] ?? false)===true && ($policy['reapply'] ?? false)!==true){
			return $message;
		}
		$recipients=[
			'to'=>$message->to(),
			'cc'=>$message->cc(),
			'bcc'=>$message->bcc(),
		];
		$blocked=[];
		foreach($recipients as $kind=>$addresses){
			foreach($addresses as $address){
				if(!$this->deliverySafetyRecipientAllowed((string)($address['email'] ?? ''), $policy)){
					$blocked[$kind][]=$address;
				}
			}
		}
		if($blocked===[]){
			return $message;
		}
		$rewriteTo=Message::make([
			'to'=>$policy['rewrite_to'] ?? [],
			'subject'=>'Delivery safety validation',
			'text'=>'Delivery safety validation',
		])->to();
		if($rewriteTo!==[]){
			$headers=$message->headers();
			if(($policy['preserve_original_recipients_header'] ?? true)===true){
				foreach($recipients as $kind=>$addresses){
					$emails=array_values(array_filter(array_map(static fn(array $address): string => (string)($address['email'] ?? ''), $addresses)));
					if($emails!==[]){
						$headers['X-Dataphyre-Original-'.strtoupper($kind)]=implode(', ', $emails);
					}
				}
			}
			return $message->with([
				'to'=>$rewriteTo,
				'cc'=>[],
				'bcc'=>[],
				'headers'=>$headers,
				'metadata'=>[
					...$message->metadata(),
					'delivery_safety_applied'=>true,
					'delivery_safety_action'=>'rewritten',
					'delivery_safety_blocked_recipients'=>$this->flattenRecipients($blocked),
				],
			]);
		}
		if(($policy['block_unmatched'] ?? true)===true){
			return SendResult::failure($this->providerName($provider), 'Message recipient is blocked by delivery safety policy.', 451, [
				'blocked_recipients'=>$this->flattenRecipients($blocked),
			], [
				'delivery_safety'=>true,
			]);
		}
		return $message;
	}

	/**
	 * Builds the effective delivery safety policy.
	 *
	 * Configured policy is merged with per-send overrides. Allowed emails are
	 * normalized, domains are string-listed, and booleans get explicit defaults.
	 *
	 * @param array<string,mixed> $options Delivery options.
	 * @return array Normalized delivery safety policy.
	 */
	private function deliverySafetyPolicy(array $options=[]): array {
		$config=$this->config('delivery_safety', []);
		$config=is_array($config) ? $config : [];
		if(is_array($options['delivery_safety'] ?? null)){
			$config=array_replace_recursive($config, $options['delivery_safety']);
		}
		return [
			'enabled'=>(bool)($config['enabled'] ?? false),
			'allowed_domains'=>$this->stringList($config['allowed_domains'] ?? []),
			'allowed_emails'=>array_map(fn(string $email): string => $this->normalizeEmail($email), $this->stringList($config['allowed_emails'] ?? [])),
			'rewrite_to'=>$config['rewrite_to'] ?? null,
			'block_unmatched'=>(bool)($config['block_unmatched'] ?? true),
			'preserve_original_recipients_header'=>(bool)($config['preserve_original_recipients_header'] ?? true),
			'reapply'=>(bool)($config['reapply'] ?? false),
		];
	}

	/**
	 * Checks whether a recipient is allowed by delivery safety policy.
	 *
	 * Empty policies deny all recipients, forcing either rewrite_to or blocking
	 * behavior when safety is enabled.
	 *
	 * @param string $email Recipient email address.
	 * @param array<string,mixed> $policy Normalized delivery safety policy.
	 * @return bool Recipient allowance decision.
	 */
	private function deliverySafetyRecipientAllowed(string $email, array $policy): bool {
		$email=$this->normalizeEmail($email);
		if($email===''){
			return false;
		}
		$emails=array_filter($policy['allowed_emails'] ?? []);
		$domains=array_map(static fn(string $domain): string => ltrim(strtolower(trim($domain)), '@'), $policy['allowed_domains'] ?? []);
		if($emails===[] && $domains===[]){
			return false;
		}
		if(in_array($email, $emails, true)){
			return true;
		}
		$domain=substr(strrchr($email, '@') ?: '', 1);
		return $domain!=='' && in_array($domain, $domains, true);
	}

	/**
	 * Flattens grouped recipients into diagnostic kind/email pairs.
	 *
	 * @param array<string,list<array<string,mixed>>> $recipients Recipient groups keyed by to, cc, or bcc.
	 * @return list<array{kind:string,email:string}> Flattened recipients.
	 */
	private function flattenRecipients(array $recipients): array {
		$flattened=[];
		foreach($recipients as $kind=>$addresses){
			foreach($addresses as $address){
				$email=$this->normalizeEmail((string)($address['email'] ?? ''));
				if($email!==''){
					$flattened[]=['kind'=>(string)$kind, 'email'=>$email];
				}
			}
		}
		return $flattened;
	}

	/**
	 * Adds a deterministic idempotency key to provider options when absent.
	 *
	 * Metadata keys win, then a caller fallback such as outbox id, and finally a
	 * hash of the message payload with idempotency metadata removed.
	 *
	 * @param Message $message Message being delivered.
	 * @param array<string,mixed> $options Provider options.
	 * @param ?string $fallbackKey Optional fallback idempotency key.
	 * @return array<string,mixed> Options with idempotency_key populated when enabled.
	 */
	private function withIdempotency(Message $message, array $options, ?string $fallbackKey=null): array {
		if((bool)$this->config('idempotency.enabled', true)===false || array_key_exists('idempotency_key', $options)){
			return $options;
		}
		$metadata=$message->metadata();
		$metadataKey=(string)$this->config('idempotency.metadata_key', 'idempotency_key');
		$key=trim((string)($metadata[$metadataKey] ?? $metadata['idempotency_key'] ?? $fallbackKey ?? ''));
		if($key===''){
			$payload=$message->toArray();
			unset($payload['metadata'][$metadataKey], $payload['metadata']['idempotency_key']);
			$key=hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: serialize($payload));
		}
		$options['idempotency_key']=substr($key, 0, 256);
		return $options;
	}

	/**
	 * Converts active recipient suppressions into a send failure.
	 *
	 * Suppression enforcement can be bypassed per send. Active records are included
	 * in the response payload and a warning event is recorded for traceability.
	 *
	 * @param Message $message Message to inspect.
	 * @param ?string $provider Provider used for failure attribution.
	 * @param array<string,mixed> $options Delivery options.
	 * @return SendResult|false Suppression failure, or false when clear.
	 */
	private function suppressionResult(Message $message, ?string $provider, array $options=[]): SendResult|false {
		if(($options['ignore_suppression'] ?? false)===true || !$this->suppressionEnabled() || (bool)$this->config('suppression.enforce', true)===false){
			return false;
		}
		$suppressed=[];
		foreach($this->recipientEmails($message) as $email){
			$record=$this->suppressionRecord($email);
			if($record!==null){
				$suppressed[]=[
					'email'=>$email,
					'reason'=>(string)($record['reason'] ?? 'suppressed'),
					'source'=>(string)($record['source'] ?? ''),
				];
			}
		}
		if($suppressed===[]){
			return false;
		}
		$name=$provider!==null ? $this->providerName($provider) : $this->providerName(null);
		$this->recordEvent((string)($options['outbox_id'] ?? '') ?: null, $name, 'suppressed', 'warning', [
			'recipients'=>$suppressed,
		]);
		return SendResult::failure($name, 'Message recipient is suppressed.', 423, [
			'suppressed_recipients'=>$suppressed,
		], [
			'suppressed'=>true,
		]);
	}

	/**
	 * Normalizes provider-specific webhook payload shapes into common keys.
	 *
	 * Provider aliases for recipient, message id, event, and nested payload data
	 * are copied into a stable payload while preserving the original fields for
	 * diagnostics and dedupe hashing.
	 *
	 * @param string $provider Normalized provider name.
	 * @param array<string,mixed> $payload Raw provider webhook payload.
	 * @param ?string $event Optional event override.
	 * @return array{payload:array<string,mixed>,event:string} Normalized payload and event candidate.
	 */
	private function normalizeProviderDeliveryPayload(string $provider, array $payload, ?string $event=null): array {
		$normalized=$payload;
		$eventCandidate=$event ?? $this->payloadString($payload, ['event', 'type', 'notificationType', 'EventType', 'RecordType', 'Type']);
		$setScalar=function(string $key, mixed $value) use (&$normalized): void {
			if(!isset($normalized[$key]) && is_scalar($value) && trim((string)$value)!==''){
				$normalized[$key]=trim((string)$value);
			}
		};
		$setMixed=function(string $key, mixed $value) use (&$normalized): void {
			if(!isset($normalized[$key]) && $value!==null && $value!==[]){
				$normalized[$key]=$value;
			}
		};
		switch($provider){
			case 'mailgun':
				$data=is_array($payload['event-data'] ?? null) ? $payload['event-data'] : $payload;
				$eventCandidate=$eventCandidate ?: $this->payloadString($data, ['event']);
				if(strcasecmp($eventCandidate, 'failed')===0 && strcasecmp((string)($data['severity'] ?? ''), 'permanent')===0){
					$eventCandidate='permanent_bounce';
				}
				$setScalar('email', $data['recipient'] ?? null);
				$setScalar('message_id', $data['id'] ?? null);
				if(is_array($data['message']['headers'] ?? null)){
					$setScalar('message_id', $data['message']['headers']['message-id'] ?? $data['message']['headers']['Message-Id'] ?? null);
				}
				break;
			case 'postmark':
				$recordType=$this->payloadString($payload, ['RecordType']);
				$type=$this->payloadString($payload, ['Type', 'Name']);
				$eventCandidate=$eventCandidate ?: $recordType;
				if(strcasecmp($recordType, 'Bounce')===0 && $type!==''){
					$eventCandidate=$type;
				}
				$setScalar('email', $payload['Email'] ?? $payload['Recipient'] ?? null);
				$setScalar('message_id', $payload['MessageID'] ?? $payload['MessageId'] ?? null);
				break;
			case 'resend':
				$data=is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
				$eventCandidate=$eventCandidate ?: $this->payloadString($payload, ['type']);
				$setMixed('to', $data['to'] ?? $data['recipients'] ?? null);
				$setScalar('email', $data['email'] ?? null);
				$setScalar('message_id', $data['email_id'] ?? $data['id'] ?? null);
				break;
			case 'brevo':
			case 'sendinblue':
				$eventCandidate=$eventCandidate ?: $this->payloadString($payload, ['event']);
				$setScalar('email', $payload['email'] ?? null);
				$setScalar('message_id', $payload['message-id'] ?? $payload['messageId'] ?? $payload['message_id'] ?? null);
				break;
		}
		return [
			'payload'=>$normalized,
			'event'=>$eventCandidate ?: $this->payloadString($normalized, ['event', 'type', 'notificationType', 'EventType', 'RecordType', 'Type']),
		];
	}

	/**
	 * Maps provider delivery event names onto Dataphyre event categories.
	 *
	 * @param string $event Raw event name.
	 * @return string Normalized delivery event.
	 */
	private function normalizeDeliveryEvent(string $event): string {
		$event=$this->normalizeName($event);
		return match($event){
			'bounced', 'email_bounced', 'hard_bounce', 'hardbounce', 'permanent_bounce', 'bounce_permanent'=>'bounce',
			'complained', 'spamreport', 'spam_report', 'spam_complaint', 'spamcomplaint', 'abuse'=>'complaint',
			'unsubscribed', 'unsubscription', 'subscriptionchange', 'group_unsubscribe', 'list_unsubscribe'=>'unsubscribe',
			default=>$event,
		};
	}

	/**
	 * Resolves the suppression reason implied by a normalized delivery event.
	 *
	 * @param string $event Normalized delivery event.
	 * @return ?string Suppression reason, or null when the event should not suppress.
	 */
	private function suppressionReasonForDeliveryEvent(string $event): ?string {
		return match($event){
			'bounce'=>'bounce',
			'complaint'=>'complaint',
			'unsubscribe'=>'unsubscribe',
			default=>null,
		};
	}

	/**
	 * Resolves telemetry severity for a normalized delivery event.
	 *
	 * @param string $event Normalized delivery event.
	 * @return string Event severity.
	 */
	private function deliveryEventSeverity(string $event): string {
		return match($event){
			'bounce', 'complaint'=>'warning',
			'dropped', 'deferred', 'failed'=>'error',
			default=>'info',
		};
	}

	/**
	 * Extracts unique recipient emails from provider delivery payloads.
	 *
	 * Supports direct recipient fields plus common nested structures used by SES
	 * bounce/complaint records and provider-specific recipient arrays.
	 *
	 * @param array<string,mixed> $payload Normalized or raw delivery payload.
	 * @return list<string> Normalized recipient emails.
	 */
	private function deliveryEventRecipients(array $payload): array {
		$emails=[];
		$add=function(mixed $value) use (&$emails, &$add): void {
			if(is_string($value)){
				$email=$this->normalizeEmail($value);
				if($email!=='' && !in_array($email, $emails, true)){
					$emails[]=$email;
				}
				return;
			}
			if(!is_array($value)){
				return;
			}
			if(array_keys($value)===range(0, count($value)-1)){
				foreach($value as $nested){
					$add($nested);
				}
				return;
			}
			foreach(['email', 'emailAddress', 'address', 'recipient'] as $key){
				if(isset($value[$key]) && is_string($value[$key])){
					$add($value[$key]);
				}
			}
			foreach($value as $nested){
				if(is_array($nested)){
					$add($nested);
				}
			}
		};
		foreach(['email', 'recipient', 'to', 'recipients', 'destination'] as $key){
			if(array_key_exists($key, $payload)){
				$add($payload[$key]);
			}
		}
		if(is_array($payload['bounce']['bouncedRecipients'] ?? null)){
			$add($payload['bounce']['bouncedRecipients']);
		}
		if(is_array($payload['complaint']['complainedRecipients'] ?? null)){
			$add($payload['complaint']['complainedRecipients']);
		}
		if(is_array($payload['mail']['destination'] ?? null)){
			$add($payload['mail']['destination']);
		}
		return $emails;
	}

	/**
	 * Reads the first non-empty scalar string from a payload key list.
	 *
	 * @param array<string,mixed> $payload Payload to inspect.
	 * @param list<string> $keys Candidate keys.
	 * @return string First non-empty scalar value.
	 */
	private function payloadString(array $payload, array $keys): string {
		foreach($keys as $key){
			if(isset($payload[$key]) && is_scalar($payload[$key])){
				$value=trim((string)$payload[$key]);
				if($value!==''){
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Flattens webhook bodies into a list of event payloads.
	 *
	 * Wrapped `events` arrays are unpacked recursively. Associative payloads become
	 * one event, while list payloads are filtered to array items.
	 *
	 * @param array<string,mixed>|list<array<string,mixed>> $payloads Raw webhook body after JSON decoding.
	 * @return list<array<string,mixed>> Event payload list.
	 */
	private function webhookEventPayloads(array $payloads): array {
		if(isset($payloads['events']) && is_array($payloads['events'])){
			return $this->webhookEventPayloads($payloads['events']);
		}
		$isList=array_keys($payloads)===range(0, count($payloads)-1);
		if(!$isList){
			return [$payloads];
		}
		$events=[];
		foreach($payloads as $payload){
			if(is_array($payload)){
				$events[]=$payload;
			}
		}
		return $events;
	}

	/**
	 * Builds a stable dedupe hash for a normalized delivery event.
	 *
	 * Recipients are sorted before hashing so provider order differences do not
	 * create duplicate webhook records for the same event.
	 *
	 * @param string $provider Provider name.
	 * @param string $event Normalized event.
	 * @param string $messageId Provider or outbox message id.
	 * @param list<string> $recipients Recipient emails.
	 * @param array<string,mixed> $payload Normalized payload.
	 * @return string SHA-256 event hash.
	 */
	private function deliveryEventHash(string $provider, string $event, string $messageId, array $recipients, array $payload): string {
		sort($recipients);
		$stable=[
			'provider'=>$provider,
			'event'=>$event,
			'message_id'=>$messageId,
			'recipients'=>$recipients,
			'payload'=>$payload,
		];
		return hash('sha256', json_encode($stable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: serialize($stable));
	}

	/**
	 * Checks whether a webhook event hash has already been recorded.
	 *
	 * @param string $eventHash Dedupe event hash.
	 * @return bool Duplicate event decision.
	 */
	private function webhookEventSeen(string $eventHash): bool {
		if((bool)$this->config('webhooks.dedupe_enabled', true)===false || function_exists('sql_select')===false){
			return false;
		}
		$row=sql_select('*', $this->webhookEventsTable(), 'WHERE event_hash=? LIMIT 1', [$eventHash], false, true);
		return is_array($row) && $row!==[];
	}

	/**
	 * Records a webhook event for dedupe and later trace inspection.
	 *
	 * Write failures are intentionally ignored because webhook ingestion should not
	 * crash solely because telemetry persistence is unavailable.
	 *
	 * @param string $eventHash Dedupe event hash.
	 * @param string $provider Provider name.
	 * @param string $event Normalized event.
	 * @param string $messageId Provider or outbox message id.
	 * @param array<string,mixed> $payload Normalized payload.
	 */
	private function recordWebhookEvent(string $eventHash, string $provider, string $event, string $messageId, array $payload): void {
		if((bool)$this->config('webhooks.dedupe_enabled', true)===false || function_exists('sql_insert')===false){
			return;
		}
		@sql_insert($this->webhookEventsTable(), [
			'event_hash'=>$eventHash,
			'provider'=>$provider,
			'event'=>$event!=='' ? $event : 'delivery_event',
			'message_id'=>$messageId!=='' ? $messageId : null,
			'payload_json'=>json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
		], null, true);
	}

	/**
	 * Returns the webhook dedupe table name.
	 *
	 * @return string Webhook event table.
	 */
	private function webhookEventsTable(): string {
		return (string)$this->config('webhooks.events_table', 'dataphyre.mailer_webhook_events');
	}

	/**
	 * Summarizes webhook events since a timestamp.
	 *
	 * @param string $since SQL timestamp lower bound.
	 * @return array<string,mixed> Webhook summary grouped by provider and event.
	 */
	private function webhookSummary(string $since): array {
		if((bool)$this->config('webhooks.dedupe_enabled', true)===false){
			return ['ok'=>true, 'dedupe_enabled'=>false, 'counts'=>[]];
		}
		if(function_exists('sql_select')===false){
			return ['ok'=>false, 'dedupe_enabled'=>true, 'counts'=>[]];
		}
		$rows=sql_select(
			'provider, event, COUNT(*) AS total',
			$this->webhookEventsTable(),
			'WHERE created_at>=? GROUP BY provider, event',
			[$since],
			true,
			false
		);
		if(!is_array($rows)){
			return ['ok'=>false, 'dedupe_enabled'=>true, 'counts'=>[]];
		}
		$counts=[];
		foreach($rows as $row){
			if(is_array($row)){
				$provider=(string)($row['provider'] ?? 'unknown');
				$event=(string)($row['event'] ?? 'unknown');
				$counts[$provider][$event]=(int)($row['total'] ?? 0);
			}
		}
		return ['ok'=>true, 'dedupe_enabled'=>true, 'since'=>$since, 'counts'=>$counts];
	}

	/**
	 * Verifies an HMAC-SHA256 webhook signature when configured.
	 *
	 * Providers without a configured secret are accepted unless signatures are
	 * explicitly required.
	 *
	 * @param string $provider Provider name.
	 * @param string $body Raw webhook body.
	 * @param array<string,string|list<string>> $headers Request headers.
	 * @return bool Signature verification decision.
	 */
	private function verifyWebhookSignature(string $provider, string $body, array $headers): bool {
		$provider=$this->providerName($provider);
		$config=$this->webhookProviderConfig($provider);
		$secret=trim((string)($config['hmac_secret'] ?? $this->config('webhooks.default_hmac_secret', '')));
		$required=(bool)($config['require_signature'] ?? $this->config('webhooks.require_signature', false));
		if($secret===''){
			return !$required;
		}
		$headerName=strtolower((string)($config['signature_header'] ?? $this->config('webhooks.signature_header', 'x-dataphyre-mailer-signature')));
		$signature=$this->headerValue($headers, $headerName);
		if($signature===''){
			return false;
		}
		$signature=preg_replace('/^sha256=/i', '', trim($signature)) ?? '';
		$expected=hash_hmac('sha256', $body, $secret);
		return hash_equals($expected, $signature);
	}

	/**
	 * Returns webhook verification config for a provider.
	 *
	 * @param string $provider Provider name.
	 * @return array<string,mixed> Provider webhook config.
	 */
	private function webhookProviderConfig(string $provider): array {
		$config=$this->config('webhooks.providers.'.$provider, []);
		return is_array($config) ? $config : [];
	}

	/**
	 * Reads a request header value case-insensitively.
	 *
	 * @param array<string,string|list<string>> $headers Header map.
	 * @param string $name Lowercase header name to read.
	 * @return string Header value.
	 */
	private function headerValue(array $headers, string $name): string {
		foreach($headers as $key=>$value){
			if(strtolower((string)$key)===$name){
				if(is_array($value)){
					$value=reset($value);
				}
				return trim((string)$value);
			}
		}
		return '';
	}

	/**
	 * Extracts unique recipient emails from a Message.
	 *
	 * @param Message $message Message to inspect.
	 * @return list<string> Normalized recipient emails.
	 */
	private function recipientEmails(Message $message): array {
		$emails=[];
		foreach([$message->to(), $message->cc(), $message->bcc()] as $addresses){
			foreach($addresses as $address){
				$email=$this->normalizeEmail((string)($address['email'] ?? ''));
				if($email!=='' && !in_array($email, $emails, true)){
					$emails[]=$email;
				}
			}
		}
		return $emails;
	}

	/**
	 * Returns provider configuration by name.
	 *
	 * @param string $name Provider name.
	 * @return array<string,mixed> Provider config.
	 */
	private function providerConfig(string $name): array {
		$config=$this->config('providers.'.$name, []);
		return is_array($config) ? $config : [];
	}

	/**
	 * Decodes selected JSON columns in a trace row.
	 *
	 * Invalid JSON is preserved as the original string so diagnostics do not lose
	 * malformed payload evidence.
	 *
	 * @param array<string,mixed> $row Trace row.
	 * @param list<string> $jsonColumns JSON column names.
	 * @return array<string,mixed> Row with decoded JSON columns where possible.
	 */
	private function decodeTraceRow(array $row, array $jsonColumns): array {
		foreach($jsonColumns as $column){
			if(!array_key_exists($column, $row)){
				continue;
			}
			$decoded=json_decode((string)$row[$column], true);
			$row[$column]=$decoded===null && json_last_error()!==JSON_ERROR_NONE ? $row[$column] : $decoded;
		}
		return $row;
	}

	/**
	 * Builds readiness records for configured providers and the default provider.
	 *
	 * @return list<array<string,mixed>> Provider health records.
	 */
	private function providerHealth(): array {
		$providers=[];
		$configured=$this->config('providers', []);
		if(is_array($configured)){
			foreach($configured as $name=>$config){
				$name=$this->providerName((string)$name);
				$providers[$name]=$this->providerReadiness($name, is_array($config) ? $config : []);
			}
		}
		$default=$this->providerName(null);
		if(!isset($providers[$default])){
			$providers[$default]=$this->providerReadiness($default, $this->providerConfig($default));
		}
		return array_values($providers);
	}

	/**
	 * Evaluates whether provider configuration contains required credentials.
	 *
	 * The readiness check is intentionally structural and does not make network
	 * calls, keeping health snapshots cheap and safe for dashboards.
	 *
	 * @param string $name Provider name.
	 * @param array<string,mixed> $config Provider config.
	 * @return array{name:string,driver:string,ready:bool,missing:list<string>,capabilities:array<string,bool>} Provider readiness and capability record.
	 */
	private function providerReadiness(string $name, array $config): array {
		$driver=$this->normalizeName((string)($config['driver'] ?? $name));
		$missing=[];
		if($driver==='sendgrid' && trim((string)($config['api_key'] ?? ''))===''){
			$missing[]='api_key';
		}
		if(in_array($driver, ['mailgun'], true)){
			if(trim((string)($config['api_key'] ?? ''))===''){
				$missing[]='api_key';
			}
			if(trim((string)($config['domain'] ?? ''))===''){
				$missing[]='domain';
			}
		}
		if(in_array($driver, ['postmark'], true) && trim((string)($config['server_token'] ?? $config['api_key'] ?? ''))===''){
			$missing[]='server_token';
		}
		if(in_array($driver, ['resend', 'brevo', 'sendinblue'], true) && trim((string)($config['api_key'] ?? ''))===''){
			$missing[]='api_key';
		}
		if($driver==='smtp' && trim((string)($config['host'] ?? ''))===''){
			$missing[]='host';
		}
		if(in_array($driver, ['aws', 'aws_ses', 'ses'], true)){
			if(trim((string)($config['access_key'] ?? ''))===''){
				$missing[]='access_key';
			}
			if(trim((string)($config['secret_key'] ?? ''))===''){
				$missing[]='secret_key';
			}
		}
		if($driver==='cloudflare' && trim((string)($config['endpoint'] ?? ''))===''){
			$missing[]='endpoint';
		}
		return [
			'name'=>$name,
			'driver'=>$driver,
			'ready'=>$missing===[],
			'missing'=>$missing,
			'capabilities'=>$this->providerCapabilities($driver),
		];
	}

	/**
	 * Describes feature capabilities for a provider driver.
	 *
	 * @param string $driver Provider driver name.
	 * @return array<string, bool> Capability flags.
	 */
	private function providerCapabilities(string $driver): array {
		$driver=$this->normalizeName($driver);
		$http=in_array($driver, ['cloudflare', 'sendgrid', 'mailgun', 'postmark', 'resend', 'brevo', 'sendinblue', 'aws', 'aws_ses', 'ses'], true);
		return [
			'smtp'=>$driver==='smtp',
			'http_api'=>$http,
			'batch'=>in_array($driver, ['postmark', 'resend'], true),
			'attachments'=>in_array($driver, ['smtp', 'sendgrid', 'mailgun', 'postmark', 'resend', 'brevo', 'sendinblue', 'log', 'cloudflare'], true),
			'message_headers'=>in_array($driver, ['smtp', 'sendgrid', 'mailgun', 'postmark', 'resend', 'brevo', 'sendinblue', 'log', 'cloudflare'], true),
			'tags'=>in_array($driver, ['sendgrid', 'mailgun', 'postmark', 'resend', 'brevo', 'sendinblue', 'aws', 'aws_ses', 'ses', 'log', 'cloudflare'], true),
			'metadata'=>in_array($driver, ['sendgrid', 'mailgun', 'postmark', 'brevo', 'sendinblue', 'log', 'cloudflare'], true),
			'native_templates'=>in_array($driver, ['mailgun', 'postmark', 'brevo', 'sendinblue'], true),
			'idempotency_header'=>in_array($driver, ['sendgrid', 'mailgun', 'postmark', 'resend', 'brevo', 'sendinblue', 'cloudflare'], true),
			'webhook_normalization'=>in_array($driver, ['sendgrid', 'mailgun', 'postmark', 'resend', 'brevo', 'sendinblue', 'aws', 'aws_ses', 'ses'], true),
			'sandbox'=>in_array($driver, ['brevo', 'sendinblue'], true),
		];
	}

	/**
	 * Checks whether persistent outbox storage is enabled.
	 *
	 * @return bool Outbox enabled flag.
	 */
	private function outboxEnabled(): bool {
		return (bool)$this->config('outbox.enabled', true);
	}

	/**
	 * Resolves retention policy defaults, config, and call overrides.
	 *
	 * @param array<string,mixed> $options Retention override options.
	 * @return array<string,mixed> Retention policy.
	 */
	private function retentionPolicy(array $options=[]): array {
		$config=$this->config('retention', []);
		$config=is_array($config) ? $config : [];
		return array_replace([
			'outbox_sent_days'=>30,
			'outbox_failed_days'=>180,
			'outbox_suppressed_days'=>90,
			'events_days'=>180,
			'webhook_events_days'=>180,
			'expired_suppressions'=>true,
		], $config, $options);
	}

	/**
	 * Tests whether a decoded message payload matches campaign summary filters.
	 *
	 * Supported filters include campaign, tag, template, metadata_key, and
	 * metadata_value.
	 *
	 * @param array<string,mixed> $message Decoded message payload.
	 * @param array<string,mixed> $filters Summary filters.
	 * @return bool Match decision.
	 */
	private function campaignSummaryMatch(array $message, array $filters): bool {
		$metadata=is_array($message['metadata'] ?? null) ? $message['metadata'] : [];
		$tags=array_map('strval', is_array($message['tags'] ?? null) ? $message['tags'] : []);
		if(isset($filters['campaign']) && trim((string)$filters['campaign'])!==''){
			$campaign=(string)$filters['campaign'];
			if((string)($metadata['campaign'] ?? $metadata['campaign_id'] ?? '')!==$campaign && !in_array($campaign, $tags, true)){
				return false;
			}
		}
		if(isset($filters['tag']) && trim((string)$filters['tag'])!==''){
			if(!in_array((string)$filters['tag'], $tags, true)){
				return false;
			}
		}
		if(isset($filters['template']) && trim((string)$filters['template'])!==''){
			$template=(string)$filters['template'];
			$templateValues=[
				$metadata['template'] ?? null,
				$metadata['template_id'] ?? null,
				$metadata['template_alias'] ?? null,
				$metadata['template_name'] ?? null,
				$metadata['postmark_template_id'] ?? null,
				$metadata['postmark_template_alias'] ?? null,
				$metadata['brevo_template_id'] ?? null,
				$metadata['mailgun_template'] ?? null,
			];
			if(!in_array($template, array_map('strval', array_filter($templateValues, static fn(mixed $value): bool => $value!==null && $value!=='')), true)){
				return false;
			}
		}
		if(isset($filters['metadata_key']) && trim((string)$filters['metadata_key'])!==''){
			$key=(string)$filters['metadata_key'];
			if(!array_key_exists($key, $metadata)){
				return false;
			}
			if(array_key_exists('metadata_value', $filters) && (string)$metadata[$key] !== (string)$filters['metadata_value']){
				return false;
			}
		}
		return true;
	}

	/**
	 * Summarizes telemetry events for a bounded set of message ids.
	 *
	 * @param list<string> $messageIds Message ids.
	 * @return array<string,array<string,int>> Event counts grouped by event and severity.
	 */
	private function campaignEventSummary(array $messageIds): array {
		if(function_exists('sql_select')===false || $messageIds===[]){
			return [];
		}
		$counts=[];
		foreach(array_slice(array_values(array_filter(array_map('strval', $messageIds))), 0, 250) as $id){
			$rows=sql_select(
				'event, severity, COUNT(*) AS total',
				$this->eventsTable(),
				'WHERE message_id=? GROUP BY event, severity',
				[$id],
				true,
				false
			);
			if(!is_array($rows)){
				continue;
			}
			foreach($rows as $row){
				if(!is_array($row)){
					continue;
				}
				$event=(string)($row['event'] ?? 'unknown');
				$severity=(string)($row['severity'] ?? 'info');
				$counts[$event][$severity]=($counts[$event][$severity] ?? 0)+(int)($row['total'] ?? 0);
			}
		}
		return $counts;
	}

	/**
	 * Builds a SQL timestamp cutoff from a retention window.
	 *
	 * @param int $days Retention days.
	 * @return string SQL timestamp cutoff.
	 */
	private function retentionCutoff(int $days): string {
		return date('Y-m-d H:i:s', time()-(max(1, $days)*86400));
	}

	/**
	 * Deletes retained rows from a table and shapes the pruning result.
	 *
	 * @param string $table Table name.
	 * @param string $where SQL where clause.
	 * @param list<mixed> $params Bound parameters.
	 * @param string $cutoff Cutoff timestamp.
	 * @return array{ok:bool,cutoff:string,deleted:?int} Prune result.
	 */
	private function pruneDelete(string $table, string $where, array $params, string $cutoff): array {
		$result=sql_delete($table, $where, $params, true);
		return [
			'ok'=>$result!==false,
			'cutoff'=>$cutoff,
			'deleted'=>is_int($result) ? $result : null,
		];
	}

	/**
	 * Recovers outbox rows stuck in the sending state beyond a timeout.
	 *
	 * Recovered rows are returned to queued status and a warning event is recorded
	 * so operators can distinguish retry recovery from fresh queueing.
	 *
	 * @return int Number of recovered rows.
	 */
	private function recoverStaleSending(): int {
		if((bool)$this->config('outbox.recover_stale_sending.enabled', true)===false || function_exists('sql_select')===false || function_exists('sql_update')===false){
			return 0;
		}
		$timeout=max(60, (int)$this->config('outbox.recover_stale_sending.timeout_seconds', 900));
		$limit=max(1, min(250, (int)$this->config('outbox.recover_stale_sending.batch_size', 50)));
		$cutoff=date('Y-m-d H:i:s', time()-$timeout);
		$rows=sql_select(
			'id, provider, attempts',
			$this->outboxTable(),
			'WHERE status=? AND updated_at<=? ORDER BY updated_at ASC LIMIT '.$limit,
			['sending', $cutoff],
			true,
			false
		);
		if(!is_array($rows)){
			return 0;
		}
		$recovered=0;
		foreach($rows as $row){
			if(!is_array($row) || trim((string)($row['id'] ?? ''))===''){
				continue;
			}
			$id=(string)$row['id'];
			if(sql_update($this->outboxTable(), [
				'status'=>'queued',
				'not_before'=>null,
				'last_error'=>null,
				'updated_at'=>date('Y-m-d H:i:s'),
			], 'WHERE id=? AND status=?', [$id, 'sending'], true)===false){
				continue;
			}
			$recovered++;
			$this->recordEvent($id, (string)($row['provider'] ?? $this->providerName(null)), 'stale_sending_recovered', 'warning', [
				'attempts'=>(int)($row['attempts'] ?? 0),
				'cutoff'=>$cutoff,
				'timeout_seconds'=>$timeout,
			]);
		}
		return $recovered;
	}

	/**
	 * Returns the mailer outbox table name.
	 *
	 * @return string Outbox table.
	 */
	private function outboxTable(): string {
		return (string)$this->config('outbox.table', 'dataphyre.mailer_outbox');
	}

	/**
	 * Returns the mailer event table name.
	 *
	 * @return string Event table.
	 */
	private function eventsTable(): string {
		return (string)$this->config('outbox.events_table', 'dataphyre.mailer_events');
	}

	/**
	 * Summarizes outbox telemetry events since a timestamp.
	 *
	 * @param string $since SQL timestamp lower bound.
	 * @return array<string,mixed> Event summary grouped by event and severity.
	 */
	private function eventSummary(string $since): array {
		if(function_exists('sql_select')===false || !$this->outboxEnabled()){
			return ['ok'=>false, 'message'=>'Mailer events are not available.', 'counts'=>[]];
		}
		$rows=sql_select(
			'event, severity, COUNT(*) AS total',
			$this->eventsTable(),
			'WHERE created_at>=? GROUP BY event, severity',
			[$since],
			true,
			false
		);
		if(!is_array($rows)){
			return ['ok'=>false, 'message'=>'Unable to read mailer events.', 'counts'=>[]];
		}
		$counts=[];
		foreach($rows as $row){
			if(is_array($row)){
				$event=(string)($row['event'] ?? 'unknown');
				$severity=(string)($row['severity'] ?? 'info');
				$counts[$event][$severity]=(int)($row['total'] ?? 0);
			}
		}
		return ['ok'=>true, 'since'=>$since, 'counts'=>$counts];
	}

	/**
	 * Checks whether persistent suppression storage is enabled.
	 *
	 * @return bool Suppression enabled flag.
	 */
	private function suppressionEnabled(): bool {
		return (bool)$this->config('suppression.enabled', true);
	}

	/**
	 * Returns the mailer suppression table name.
	 *
	 * @return string Suppression table.
	 */
	private function suppressionTable(): string {
		return (string)$this->config('suppression.table', 'dataphyre.mailer_suppressions');
	}

	/**
	 * Summarizes configured and stored suppression records.
	 *
	 * @return array Suppression summary.
	 */
	private function suppressionSummary(): array {
		$configured=count((array)$this->config('suppression.list', []));
		if(function_exists('sql_select')===false || !$this->suppressionEnabled()){
			return ['ok'=>false, 'configured'=>$configured, 'stored'=>0, 'by_reason'=>[]];
		}
		$rows=sql_select('reason, COUNT(*) AS total', $this->suppressionTable(), 'GROUP BY reason', [], true, false);
		if(!is_array($rows)){
			return ['ok'=>false, 'configured'=>$configured, 'stored'=>0, 'by_reason'=>[]];
		}
		$stored=0;
		$byReason=[];
		foreach($rows as $row){
			if(is_array($row)){
				$total=(int)($row['total'] ?? 0);
				$stored+=$total;
				$byReason[(string)($row['reason'] ?? 'unknown')]=$total;
			}
		}
		return ['ok'=>true, 'configured'=>$configured, 'stored'=>$stored, 'by_reason'=>$byReason];
	}

	/**
	 * Resolves an active suppression record for an email address.
	 *
	 * Configured suppressions are checked before SQL storage. Expired entries are
	 * ignored in both paths.
	 *
	 * @param string $email Email address.
	 * @return ?array Suppression record, or null when not suppressed.
	 */
	private function suppressionRecord(string $email): ?array {
		$email=$this->normalizeEmail($email);
		if($email==='' || !$this->suppressionEnabled()){
			return null;
		}
		$hash=$this->emailHash($email);
		foreach((array)$this->config('suppression.list', []) as $entry){
			$entry=is_array($entry) ? $entry : ['email'=>(string)$entry];
			$entryEmail=$this->normalizeEmail((string)($entry['email'] ?? ''));
			$entryHash=(string)($entry['email_hash'] ?? '');
			if($entryEmail===$email || ($entryHash!=='' && hash_equals($entryHash, $hash))){
				if(isset($entry['expires_at']) && null!==$expires=$this->normalizeDate($entry['expires_at'])){
					if(strtotime($expires)!==false && strtotime($expires)<=time()){
						continue;
					}
				}
				return [
					'email'=>$email,
					'reason'=>$entry['reason'] ?? 'configured',
					'source'=>$entry['source'] ?? 'config',
				];
			}
		}
		if(function_exists('sql_select')===false){
			return null;
		}
		$row=sql_select(
			'*',
			$this->suppressionTable(),
			'WHERE email_hash=? AND (expires_at IS NULL OR expires_at>?) LIMIT 1',
			[$hash, date('Y-m-d H:i:s')],
			false,
			true
		);
		return is_array($row) && $row!==[] ? $row : null;
	}

	/**
	 * Hashes an email address for suppression storage.
	 *
	 * @param string $email Email address.
	 * @return string SHA-256 or HMAC-SHA-256 email hash.
	 */
	private function emailHash(string $email): string {
		$salt=(string)$this->config('suppression.hash_salt', '');
		return $salt!=='' ? hash_hmac('sha256', $this->normalizeEmail($email), $salt) : hash('sha256', $this->normalizeEmail($email));
	}

	/**
	 * Normalizes and validates an email address.
	 *
	 * @param string $email Raw email address.
	 * @return string Lowercase email, or empty string when invalid.
	 */
	private function normalizeEmail(string $email): string {
		$email=strtolower(trim($email));
		return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
	}

	/**
	 * Computes the next retry timestamp for an outbox attempt.
	 *
	 * @param int $attempt One-based attempt number.
	 * @return string SQL timestamp for next retry.
	 */
	private function nextRetryAt(int $attempt): string {
		$backoff=$this->config('outbox.retry_backoff_seconds', [60, 300, 900, 1800, 3600]);
		if(is_string($backoff)){
			$backoff=array_map('trim', explode(',', $backoff));
		}
		if(!is_array($backoff) || $backoff===[]){
			$backoff=[60, 300, 900, 1800, 3600];
		}
		$seconds=(int)($backoff[max(0, min(count($backoff)-1, $attempt-1))] ?? 60);
		return date('Y-m-d H:i:s', time()+max(1, $seconds));
	}

	/**
	 * Checks whether a provider has reached its per-flush outbox rate limit.
	 *
	 * @param string $provider Provider name.
	 * @param array<string,int> $providerCounts Counts already sent this flush.
	 * @return bool Rate-limit decision.
	 */
	private function providerRateLimitReached(string $provider, array $providerCounts): bool {
		$limit=$this->providerRateLimit($provider);
		if($limit===null){
			return false;
		}
		return (int)($providerCounts[$provider] ?? 0)>=$limit;
	}

	/**
	 * Resolves the per-flush rate limit for a provider.
	 *
	 * @param string $provider Provider name.
	 * @return ?int Limit, or null when disabled.
	 */
	private function providerRateLimit(string $provider): ?int {
		if((bool)$this->config('outbox.rate_limits.enabled', false)===false){
			return null;
		}
		$providers=$this->config('outbox.rate_limits.providers', []);
		$limit=is_array($providers) && array_key_exists($provider, $providers)
			? $providers[$provider]
			: $this->config('outbox.rate_limits.default_per_flush', null);
		if($limit===null || $limit===''){
			return null;
		}
		return max(0, (int)$limit);
	}

	/**
	 * Returns the defer timestamp used after rate limiting.
	 *
	 * @return string SQL timestamp.
	 */
	private function rateLimitRetryAt(): string {
		$seconds=(int)$this->config('outbox.rate_limits.defer_seconds', 60);
		return date('Y-m-d H:i:s', time()+max(1, $seconds));
	}

	/**
	 * Normalizes supported date inputs to SQL timestamp format.
	 *
	 * @param mixed $date DateTimeInterface, Unix timestamp, or parseable string.
	 * @return ?string SQL timestamp, or null when absent/invalid.
	 */
	private function normalizeDate(mixed $date): ?string {
		if($date instanceof \DateTimeInterface){
			return $date->format('Y-m-d H:i:s');
		}
		if(is_int($date)){
			return date('Y-m-d H:i:s', $date);
		}
		if(is_string($date) && trim($date)!==''){
			$timestamp=strtotime($date);
			return $timestamp!==false ? date('Y-m-d H:i:s', $timestamp) : null;
		}
		return null;
	}

	/**
	 * Reads mailer configuration from the kernel module or DP_MAILER_CFG.
	 *
	 * Dot notation is supported for nested config reads.
	 *
	 * @param string $key Config key, or empty string for all config.
	 * @param mixed $default Default value when missing.
	 * @return mixed Mailer configuration value from the kernel module, DP_MAILER_CFG, or the caller default.
	 */
	private function config(string $key='', mixed $default=null): mixed {
		if(class_exists('\dataphyre\mailer', false)){
			return \dataphyre\mailer::config($key, $default);
		}
		$config=\defined('DP_MAILER_CFG') && \is_array(\DP_MAILER_CFG) ? \DP_MAILER_CFG : [];
		if($key===''){
			return $config;
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
	 * Resolves a mail template path or template bundle.
	 *
	 * Resolution checks the raw template value, the configured templates_path, and
	 * subject/html/text bundle stems before falling back to inline rendering.
	 *
	 * @param string $template Template identifier, file, bundle stem, or inline content.
	 * @return string|array|null File path, bundle map, or null when unresolved.
	 */
	private function resolveTemplate(string $template): string|array|null {
		$template=trim($template);
		$base=$this->config('templates_path');
		$candidates=[$template];
		if(is_string($base) && trim($base)!==''){
			$candidates[]=rtrim($base, '/\\').'/'.ltrim($template, '/\\');
		}
		foreach($candidates as $candidate){
			if(is_file($candidate)){
				return $candidate;
			}
			$stem=preg_replace('/\.(html|text|subject)?\.?tpl$/', '', $candidate);
			$bundle=[
				'subject'=>$stem.'.subject.tpl',
				'html'=>$stem.'.html.tpl',
				'text'=>$stem.'.text.tpl',
			];
			if(is_file($bundle['html']) || is_file($bundle['text'])){
				return $bundle;
			}
		}
		return null;
	}

	/**
	 * Renders a template file with the templating framework when available.
	 *
	 * Missing files render as an empty string; the fallback renderer performs
	 * simple escaped token replacement.
	 *
	 * @param string $file Template file path.
	 * @param array<string,mixed> $data Template data.
	 * @return string Rendered content.
	 */
	private function renderTemplateFile(string $file, array $data): string {
		if($file==='' || !is_file($file)){
			return '';
		}
		if(\dataphyre\core::load_framework_module('templating')===true && class_exists('\Dataphyre\Templating\Templating')){
			return \Dataphyre\Templating\Templating::render($file, $data)->content();
		}
		return self::replaceTokens((string)file_get_contents($file), $data);
	}

	/**
	 * Renders an inline template string.
	 *
	 * @param string $template Template source.
	 * @param array<string,mixed> $data Template data.
	 * @param string $name Synthetic template name for diagnostics.
	 * @return string Rendered content.
	 */
	private function renderTemplateString(string $template, array $data, string $name): string {
		if(\dataphyre\core::load_framework_module('templating')===true && class_exists('\Dataphyre\Templating\Templating')){
			return \Dataphyre\Templating\Templating::renderString($template, $data, [], [], $name)->content();
		}
		return self::replaceTokens($template, $data);
	}

	/**
	 * Translates a localization key for mailer subject rendering.
	 *
	 * Framework localization is preferred, legacy localization is used as a
	 * fallback, and finally escaped token replacement is applied to fallback text.
	 *
	 * @param string $key Localization key.
	 * @param ?string $fallback Fallback text.
	 * @param array<string,mixed> $parameters Translation parameters.
	 * @param array<string,mixed> $options Language/page/theme options.
	 * @return string Translated text.
	 */
	private function translate(string $key, ?string $fallback, array $parameters, array $options): string {
		if(\dataphyre\core::load_framework_module('localization')===true && class_exists('\Dataphyre\Localization\Localization')){
			return \Dataphyre\Localization\Localization::translate(
				$key,
				$fallback,
				$parameters,
				isset($options['language']) ? (string)$options['language'] : null,
				isset($options['page']) ? (string)$options['page'] : null,
				isset($options['theme']) ? (string)$options['theme'] : null
			);
		}
		if(class_exists('\dataphyre\localization', false)){
			return \dataphyre\localization::locale($key, $fallback, $parameters, isset($options['language']) ? (string)$options['language'] : null, isset($options['page']) ? (string)$options['page'] : null);
		}
		return self::replaceTokens((string)($fallback ?? $key), $parameters);
	}

	/**
	 * Records a mailer telemetry event when outbox event tracking is available.
	 *
	 * Event writes are best-effort so delivery and webhook paths are not failed by
	 * optional telemetry persistence.
	 *
	 * @param ?string $messageId Related outbox or provider message id.
	 * @param string $provider Provider name.
	 * @param string $event Event name.
	 * @param string $severity Event severity.
	 * @param array<string,mixed> $payload Event metadata written to the outbox event log.
	 */
	private function recordEvent(?string $messageId, string $provider, string $event, string $severity, array $payload=[]): void {
		if(function_exists('sql_insert')===false || !$this->outboxEnabled() || (bool)$this->config('outbox.track_events', true)===false){
			return;
		}
		@sql_insert($this->eventsTable(), [
			'id'=>'mevt_'.bin2hex(random_bytes(16)),
			'message_id'=>$messageId,
			'provider'=>$provider,
			'event'=>$event,
			'severity'=>$severity,
			'payload_json'=>json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
		], null, true);
	}

	/**
	 * Normalizes a config or provider name to a safe lowercase token.
	 *
	 * @param string $name Raw name.
	 * @return string Normalized token.
	 */
	private function normalizeName(string $name): string {
		return strtolower(trim((string)preg_replace('/[^A-Za-z0-9_]+/', '_', $name), '_'));
	}

	/**
	 * Normalizes strings or arrays into a unique trimmed string list.
	 *
	 * @param mixed $value Comma string, list, or unsupported value.
	 * @return list<string> Unique non-empty strings.
	 */
	private function stringList(mixed $value): array {
		if(is_string($value)){
			$value=explode(',', $value);
		}
		if(!is_array($value)){
			return [];
		}
		$list=[];
		foreach($value as $item){
			$item=trim((string)$item);
			if($item!=='' && !in_array($item, $list, true)){
				$list[]=$item;
			}
		}
		return $list;
	}

	/**
	 * Performs escaped {{ token }} replacement for fallback template rendering.
	 *
	 * Dot notation can read nested array values. Missing or non-scalar values
	 * render as empty strings or JSON text before HTML escaping.
	 *
	 * @param string $template Template source.
	 * @param array<string,mixed> $data Replacement data.
	 * @return string Rendered string.
	 */
	private static function replaceTokens(string $template, array $data): string {
		return (string)preg_replace_callback('/{{\s*([A-Za-z0-9_.]+)\s*}}/', static function(array $match) use ($data): string {
			$value=$data;
			foreach(explode('.', $match[1]) as $segment){
				if(is_array($value) && array_key_exists($segment, $value)){
					$value=$value[$segment];
					continue;
				}
				return '';
			}
			return htmlspecialchars((string)(is_scalar($value) || $value===null ? $value : json_encode($value)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}, $template);
	}
}
