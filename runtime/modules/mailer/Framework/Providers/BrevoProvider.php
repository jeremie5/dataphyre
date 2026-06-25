<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer\Providers;

use Dataphyre\Mailer\Contracts\MailProvider;
use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\SendResult;
use Dataphyre\Mailer\Support\HttpJsonClient;

/**
 * Sends Dataphyre mail messages through Brevo's SMTP email API.
 *
 * The provider supports both direct subject/body emails and Brevo template sends selected by
 * message metadata. It preserves request/response details in `SendResult` so mail delivery
 * diagnostics can inspect provider failures. Caller-provided endpoints and headers are trusted
 * provider configuration and are forwarded to the shared HTTP client.
 */
final class BrevoProvider implements MailProvider {

	/**
	 * Stores Brevo provider configuration.
	 *
	 * @param array{api_key?:string, endpoint?:string, timeout?:int, headers?:array<string, string>, sandbox?:bool} $config Provider configuration.
	 */
	public function __construct(private array $config=[]){}

	/**
	 * Returns the provider identifier used in send results.
	 *
	 * @return string Provider name.
	 */
	public function name(): string {
		return 'brevo';
	}

	/**
	 * Sends a message to Brevo.
	 *
	 * The API key is required. Successful responses may include `messageId` or `message_id`;
	 * failures are normalized from Brevo's JSON response or transport error.
	 * Additional headers are merged after required API headers, so trusted callers can
	 * intentionally override request headers.
	 *
	 * @param Message $message Message to send.
	 * @param array{api_key?:string, endpoint?:string, timeout?:int, headers?:array<string, string>, sandbox?:bool, idempotency_key?:string} $options Per-send overrides.
	 * @return SendResult Provider result with status, optional message id, and raw HTTP response.
	 */
	public function send(Message $message, array $options=[]): SendResult {
		$apiKey=trim((string)($options['api_key'] ?? $this->config['api_key'] ?? ''));
		if($apiKey===''){
			return SendResult::failure($this->name(), 'Brevo API key is not configured.', 500);
		}
		$endpoint=trim((string)($options['endpoint'] ?? $this->config['endpoint'] ?? 'https://api.brevo.com/v3/smtp/email'));
		$response=HttpJsonClient::request('POST', $endpoint, $this->payload($message), [
			'Accept'=>'application/json',
			'Content-Type'=>'application/json',
			'api-key'=>$apiKey,
			...$this->headers($options),
		], (int)($options['timeout'] ?? $this->config['timeout'] ?? 15));
		if(($response['ok'] ?? false)!==true){
			return SendResult::failure($this->name(), self::errorMessage($response), (int)($response['status'] ?? 0), $response);
		}
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		$messageId=(string)($json['messageId'] ?? $json['message_id'] ?? '');
		return SendResult::success($this->name(), (int)$response['status'], 'Brevo accepted the message.', $messageId!=='' ? $messageId : null, $response);
	}

	/**
	 * Converts a Dataphyre message into Brevo's email request data.
	 *
	 * Metadata keys `brevo_template_id` or `template_id` switch the payload into template mode;
	 * `template_params` or `template_data` supply template parameters when present.
	 * In template mode, attachments are not included because this provider path mirrors Brevo's
	 * template send shape used by the runtime.
	 *
	 * @param Message $message Message to serialize.
	 * @return array<string, mixed> Brevo API request data.
	 */
	private function payload(Message $message): array {
		$templateId=$message->metadata()['brevo_template_id'] ?? $message->metadata()['template_id'] ?? null;
		if($templateId!==null && $templateId!==''){
			return array_filter([
				'sender'=>$this->address($message->from()),
				'to'=>$this->addresses($message->to()),
				'cc'=>$message->cc()!==[] ? $this->addresses($message->cc()) : null,
				'bcc'=>$message->bcc()!==[] ? $this->addresses($message->bcc()) : null,
				'replyTo'=>$message->replyTo()!==null ? $this->address($message->replyTo()) : null,
				'templateId'=>(int)$templateId,
				'params'=>$message->metadata()['template_params'] ?? $message->metadata()['template_data'] ?? $message->metadata(),
				'headers'=>$message->headers()!==[] ? $message->headers() : null,
				'tags'=>$message->tags()!==[] ? $message->tags() : null,
			], static fn(mixed $value): bool => $value!==null && $value!==[]);
		}
		return array_filter([
			'sender'=>$this->address($message->from()),
			'to'=>$this->addresses($message->to()),
			'cc'=>$message->cc()!==[] ? $this->addresses($message->cc()) : null,
			'bcc'=>$message->bcc()!==[] ? $this->addresses($message->bcc()) : null,
			'replyTo'=>$message->replyTo()!==null ? $this->address($message->replyTo()) : null,
			'subject'=>$message->subject(),
			'htmlContent'=>$message->html()!=='' ? $message->html() : null,
			'textContent'=>$message->text()!=='' ? $message->text() : null,
			'headers'=>$message->headers()!==[] ? $message->headers() : null,
			'attachment'=>$message->attachments()!==[] ? $this->attachments($message->attachments()) : null,
			'tags'=>$message->tags()!==[] ? $message->tags() : null,
			'params'=>$message->metadata()!==[] ? $message->metadata() : null,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Converts address rows into Brevo address objects.
	 *
	 * @param array<int, array<string, string>> $addresses Message address rows.
	 * @return array<int, array<string, string>> Brevo address objects.
	 */
	private function addresses(array $addresses): array {
		return array_map(fn(array $address): array => $this->address($address), $addresses);
	}

	/**
	 * Converts one address row into Brevo shape.
	 *
	 * @param array<string, string> $address Message address row.
	 * @return array<string, string> Address object with empty fields removed.
	 */
	private function address(array $address): array {
		return array_filter([
			'email'=>$address['email'] ?? '',
			'name'=>($address['name'] ?? '')!=='' ? $address['name'] : null,
		], static fn(mixed $value): bool => $value!==null && $value!=='');
	}

	/**
	 * Converts message attachments into Brevo attachment rows.
	 *
	 * Attachment bytes are base64-encoded from already-loaded Message content, so large
	 * attachments remain resident in memory during request assembly.
	 *
	 * @param array<int, array<string, mixed>> $attachments Message attachment rows.
	 * @return array<int, array{name:string, content:string}> Brevo attachment rows.
	 */
	private function attachments(array $attachments): array {
		return array_map(static fn(array $attachment): array => [
			'name'=>$attachment['filename'] ?? 'attachment',
			'content'=>base64_encode((string)($attachment['content'] ?? '')),
		], $attachments);
	}

	/**
	 * Extracts a readable Brevo error message from an HTTP response.
	 *
	 * @param array<string, mixed> $response Response returned by `HttpJsonClient`.
	 * @return string Failure message.
	 */
	private static function errorMessage(array $response): string {
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		return (string)($json['message'] ?? $json['error'] ?? $response['error'] ?? 'Brevo request failed.');
	}

	/**
	 * Merges configured and per-send headers for Brevo requests.
	 *
	 * Sandbox mode adds Brevo's drop header, and idempotency keys are capped to provider-safe
	 * length.
	 *
	 * @param array<string, mixed> $options Per-send options.
	 * @return array<string, string> Headers applied after required API headers.
	 */
	private function headers(array $options): array {
		$headers=is_array($this->config['headers'] ?? null) ? $this->config['headers'] : [];
		if((bool)($options['sandbox'] ?? $this->config['sandbox'] ?? false)){
			$headers['X-Sib-Sandbox']='drop';
		}
		if(is_array($options['headers'] ?? null)){
			$headers=array_replace($headers, $options['headers']);
		}
		if(trim((string)($options['idempotency_key'] ?? ''))!=='' && !isset($headers['Idempotency-Key'])){
			$headers['Idempotency-Key']=substr(trim((string)$options['idempotency_key']), 0, 256);
		}
		return $headers;
	}
}
