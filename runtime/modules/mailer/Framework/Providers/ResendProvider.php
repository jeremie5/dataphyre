<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer\Providers;

use Dataphyre\Mailer\Contracts\BatchMailProvider;
use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\SendResult;
use Dataphyre\Mailer\Support\HttpJsonClient;

/**
 * Sends mail through the Resend HTTP API.
 *
 * The provider maps Dataphyre Message values into Resend single-message and
 * batch payloads, merges configured and per-send headers, supports idempotency
 * keys, and converts Resend transport responses into SendResult instances.
 * Caller-provided endpoints and headers are treated as trusted provider
 * configuration and are forwarded to the shared HTTP client.
 */
final class ResendProvider implements BatchMailProvider {

	/**
	 * Creates a Resend mail provider.
	 *
	 * @param array<string, mixed> $config Provider configuration such as api key, endpoint, timeout, and headers.
	 */
	public function __construct(private array $config=[]){}

	/**
	 * Returns the provider identifier.
	 *
	 * @return string Provider name used in SendResult payloads.
	 */
	public function name(): string {
		return 'resend';
	}

	/**
	 * Sends a single message through Resend.
	 *
	 * Missing API keys fail locally. API failures preserve the HTTP client
	 * response in the SendResult diagnostics; successful responses expose the
	 * Resend message id when one is returned.
	 * Configured and per-send headers are merged after Authorization and
	 * Content-Type, so trusted callers can intentionally override request
	 * headers.
	 *
	 * @param Message $message Message to send.
	 * @param array<string, mixed> $options Provider overrides such as api key, endpoint, timeout, headers, or idempotency key.
	 * @return SendResult Provider result for the message.
	 */
	public function send(Message $message, array $options=[]): SendResult {
		$apiKey=trim((string)($options['api_key'] ?? $this->config['api_key'] ?? ''));
		if($apiKey===''){
			return SendResult::failure($this->name(), 'Resend API key is not configured.', 500);
		}
		$endpoint=trim((string)($options['endpoint'] ?? $this->config['endpoint'] ?? 'https://api.resend.com/emails'));
		$headers=[
			'Authorization'=>'Bearer '.$apiKey,
			'Content-Type'=>'application/json',
		];
		$headers=array_replace($headers, is_array($this->config['headers'] ?? null) ? $this->config['headers'] : []);
		if(is_array($options['headers'] ?? null)){
			$headers=array_replace($headers, $options['headers']);
		}
		if(trim((string)($options['idempotency_key'] ?? ''))!==''){
			$headers['Idempotency-Key']=substr(trim((string)$options['idempotency_key']), 0, 256);
		}
		$response=HttpJsonClient::request('POST', $endpoint, $this->payload($message), $headers, (int)($options['timeout'] ?? $this->config['timeout'] ?? 15));
		if(($response['ok'] ?? false)!==true){
			return SendResult::failure($this->name(), self::errorMessage($response), (int)($response['status'] ?? 0), $response);
		}
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		$messageId=(string)($json['id'] ?? '');
		return SendResult::success($this->name(), (int)$response['status'], 'Resend accepted the message.', $messageId!=='' ? $messageId : null, $response);
	}

	/**
	 * Sends multiple messages through Resend's batch endpoint.
	 *
	 * Transport-level failures are mapped to a failure result for every message.
	 * Successful batch responses are matched to the input message order using the
	 * returned `data` array or list-shaped response body.
	 * Missing rows still yield successful SendResult instances without message ids
	 * because Resend accepted the batch request at the transport layer.
	 *
	 * @param array<int, Message> $messages Messages to send.
	 * @param array<string, mixed> $options Provider overrides such as api key, batch endpoint, timeout, and headers.
	 * @return array<int, SendResult> Send results in message order.
	 */
	public function sendBatch(array $messages, array $options=[]): array {
		$apiKey=trim((string)($options['api_key'] ?? $this->config['api_key'] ?? ''));
		if($apiKey===''){
			return array_map(fn(): SendResult => SendResult::failure($this->name(), 'Resend API key is not configured.', 500), $messages);
		}
		$endpoint=trim((string)($options['batch_endpoint'] ?? $this->config['batch_endpoint'] ?? 'https://api.resend.com/emails/batch'));
		$headers=[
			'Authorization'=>'Bearer '.$apiKey,
			'Content-Type'=>'application/json',
		];
		$headers=array_replace($headers, is_array($this->config['headers'] ?? null) ? $this->config['headers'] : []);
		if(is_array($options['headers'] ?? null)){
			$headers=array_replace($headers, $options['headers']);
		}
		$response=HttpJsonClient::request('POST', $endpoint, array_map(fn(Message $message): array => $this->payload($message), $messages), $headers, (int)($options['timeout'] ?? $this->config['timeout'] ?? 15));
		if(($response['ok'] ?? false)!==true){
			return array_map(fn(): SendResult => SendResult::failure($this->name(), self::errorMessage($response), (int)($response['status'] ?? 0), $response), $messages);
		}
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		$data=is_array($json['data'] ?? null) ? $json['data'] : (array_is_list($json) ? $json : []);
		return array_map(function(Message $message, int $index) use ($data, $response): SendResult {
			$row=is_array($data[$index] ?? null) ? $data[$index] : [];
			$id=(string)($row['id'] ?? '');
			return SendResult::success($this->name(), (int)$response['status'], 'Resend accepted the message.', $id!=='' ? $id : null, $response);
		}, $messages, array_keys($messages));
	}

	/**
	 * Builds the Resend payload for a message.
	 *
	 * Optional fields are removed when empty so Resend receives only meaningful
	 * request keys.
	 * Headers and tags are copied from Message without provider-side validation;
	 * upstream message normalization is the trust boundary for custom values.
	 *
	 * @param Message $message Message to serialize.
	 * @return array<string, mixed> Resend request payload.
	 */
	private function payload(Message $message): array {
		return array_filter([
			'from'=>$this->formatAddress($message->from()),
			'to'=>$this->emails($message->to()),
			'cc'=>$message->cc()!==[] ? $this->emails($message->cc()) : null,
			'bcc'=>$message->bcc()!==[] ? $this->emails($message->bcc()) : null,
			'reply_to'=>$message->replyTo()!==null ? [$this->formatAddress($message->replyTo())] : null,
			'subject'=>$message->subject(),
			'html'=>$message->html()!=='' ? $message->html() : null,
			'text'=>$message->text()!=='' ? $message->text() : null,
			'headers'=>$message->headers()!==[] ? $message->headers() : null,
			'attachments'=>$message->attachments()!==[] ? $this->attachments($message->attachments()) : null,
			'tags'=>$message->tags()!==[] ? array_map(static fn(string $tag): array => ['name'=>'tag', 'value'=>$tag], $message->tags()) : null,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Formats message addresses as Resend recipient strings.
	 *
	 * @param array<int, array<string, mixed>> $addresses Message address rows.
	 * @return array<int, string> Formatted recipient strings.
	 */
	private function emails(array $addresses): array {
		return array_map(fn(array $address): string => $this->formatAddress($address), $addresses);
	}

	/**
	 * Formats one address for Resend.
	 *
	 * @param array<string, mixed> $address Address row with email and optional name.
	 * @return string `Name <email>` or bare email.
	 */
	private function formatAddress(array $address): string {
		$email=(string)($address['email'] ?? '');
		$name=trim((string)($address['name'] ?? ''));
		return $name!=='' ? $name.' <'.$email.'>' : $email;
	}

	/**
	 * Converts message attachments into Resend attachment rows.
	 *
	 * Attachment bytes are base64-encoded from already-loaded Message content, so
	 * large attachments remain resident in memory during request assembly.
	 *
	 * @param array<int, array<string, mixed>> $attachments Message attachment rows.
	 * @return array<int, array{filename: mixed, content: string}> Resend attachment rows.
	 */
	private function attachments(array $attachments): array {
		return array_map(static fn(array $attachment): array => [
			'filename'=>$attachment['filename'] ?? 'attachment',
			'content'=>base64_encode((string)($attachment['content'] ?? '')),
		], $attachments);
	}

	/**
	 * Extracts a useful failure message from an HTTP client response.
	 *
	 * @param array<string, mixed> $response HttpJsonClient response payload.
	 * @return string Human-readable Resend or transport error message.
	 */
	private static function errorMessage(array $response): string {
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		return (string)($json['message'] ?? $json['error'] ?? $response['error'] ?? 'Resend request failed.');
	}
}
