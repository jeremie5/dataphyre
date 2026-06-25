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
 * Sends mail through the Postmark HTTP API.
 *
 * The provider converts Dataphyre mail messages into Postmark payloads for
 * single-message and batch endpoints, supports template id or alias metadata,
 * maps Postmark response rows back into SendResult values, and never logs or
 * returns the configured server token.
 * Caller-provided endpoints and headers are treated as trusted provider
 * configuration and are forwarded to the shared HTTP client.
 */
final class PostmarkProvider implements BatchMailProvider {

	/**
	 * Creates a Postmark mail provider.
	 *
	 * @param array<string, mixed> $config Provider configuration such as server token, endpoint, timeout, and headers.
	 */
	public function __construct(private array $config=[]){}

	/**
	 * Returns the provider identifier.
	 *
	 * @return string Provider name used in SendResult payloads.
	 */
	public function name(): string {
		return 'postmark';
	}

	/**
	 * Sends a single message through Postmark.
	 *
	 * The server token may come from options or provider configuration. Missing
	 * tokens fail locally. API failures preserve the HTTP client response in the
	 * SendResult diagnostics; successful responses expose the Postmark message id
	 * when one is returned.
	 * Additional headers are merged after required Postmark headers so trusted
	 * callers can override transport-level defaults.
	 *
	 * @param Message $message Message to send.
	 * @param array<string, mixed> $options Provider overrides such as server token, endpoint, timeout, headers, or idempotency key.
	 * @return SendResult Provider result for the message.
	 */
	public function send(Message $message, array $options=[]): SendResult {
		$token=trim((string)($options['server_token'] ?? $options['api_key'] ?? $this->config['server_token'] ?? $this->config['api_key'] ?? ''));
		if($token===''){
			return SendResult::failure($this->name(), 'Postmark server token is not configured.', 500);
		}
		$endpoint=trim((string)($options['endpoint'] ?? $this->config['endpoint'] ?? 'https://api.postmarkapp.com/email'));
		$response=HttpJsonClient::request('POST', $endpoint, $this->payload($message), [
			'Accept'=>'application/json',
			'Content-Type'=>'application/json',
			'X-Postmark-Server-Token'=>$token,
			...$this->headers($options),
		], (int)($options['timeout'] ?? $this->config['timeout'] ?? 15));
		if(($response['ok'] ?? false)!==true){
			return SendResult::failure($this->name(), self::errorMessage($response), (int)($response['status'] ?? 0), $response);
		}
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		$messageId=(string)($json['MessageID'] ?? $json['MessageId'] ?? '');
		return SendResult::success($this->name(), (int)$response['status'], 'Postmark accepted the message.', $messageId!=='' ? $messageId : null, $response);
	}

	/**
	 * Sends multiple messages through Postmark's batch endpoint.
	 *
	 * Transport-level failures are mapped to a failure result for every message.
	 * When Postmark returns per-message rows, each row's ErrorCode determines the
	 * matching SendResult for the message at the same index.
	 * If the response row is missing for an input index, the result is treated as
	 * a Postmark rejection for that message rather than a transport failure.
	 *
	 * @param array<int, Message> $messages Messages to send.
	 * @param array<string, mixed> $options Provider overrides such as server token, batch endpoint, timeout, headers, or idempotency key.
	 * @return array<int, SendResult> Send results in message order.
	 */
	public function sendBatch(array $messages, array $options=[]): array {
		$token=trim((string)($options['server_token'] ?? $options['api_key'] ?? $this->config['server_token'] ?? $this->config['api_key'] ?? ''));
		if($token===''){
			return array_map(fn(): SendResult => SendResult::failure($this->name(), 'Postmark server token is not configured.', 500), $messages);
		}
		$endpoint=trim((string)($options['batch_endpoint'] ?? $this->config['batch_endpoint'] ?? 'https://api.postmarkapp.com/email/batch'));
		$response=HttpJsonClient::request('POST', $endpoint, array_map(fn(Message $message): array => $this->payload($message), $messages), [
			'Accept'=>'application/json',
			'Content-Type'=>'application/json',
			'X-Postmark-Server-Token'=>$token,
			...$this->headers($options),
		], (int)($options['timeout'] ?? $this->config['timeout'] ?? 15));
		if(($response['ok'] ?? false)!==true){
			return array_map(fn(): SendResult => SendResult::failure($this->name(), self::errorMessage($response), (int)($response['status'] ?? 0), $response), $messages);
		}
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		return array_map(function(Message $message, int $index) use ($json, $response): SendResult {
			$row=is_array($json[$index] ?? null) ? $json[$index] : [];
			$error=(int)($row['ErrorCode'] ?? 0);
			$messageText=(string)($row['Message'] ?? ($error===0 ? 'Postmark accepted the message.' : 'Postmark rejected the message.'));
			$messageId=(string)($row['MessageID'] ?? $row['MessageId'] ?? '');
			return $error===0
				? SendResult::success($this->name(), (int)$response['status'], $messageText, $messageId!=='' ? $messageId : null, $row)
				: SendResult::failure($this->name(), $messageText, (int)$response['status'], $row);
		}, $messages, array_keys($messages));
	}

	/**
	 * Builds the Postmark payload for a message.
	 *
	 * Template messages use TemplateId or TemplateAlias plus TemplateModel. Normal
	 * messages use Subject, HtmlBody, and TextBody. Empty optional fields are
	 * removed before the request is sent.
	 *
	 * Message metadata is copied into Postmark Metadata after scalar string
	 * conversion. Template metadata is also used as TemplateModel unless a
	 * template_model or template_data field is supplied.
	 *
	 * @param Message $message Message to serialize.
	 * @return array<string, mixed> Postmark request payload.
	 */
	private function payload(Message $message): array {
		$templateId=$this->templateId($message);
		if($templateId!==null){
			return array_filter([
				is_numeric($templateId) ? 'TemplateId' : 'TemplateAlias'=>$templateId,
				'From'=>$this->formatAddress($message->from()),
				'To'=>$this->addressList($message->to()),
				'Cc'=>$message->cc()!==[] ? $this->addressList($message->cc()) : null,
				'Bcc'=>$message->bcc()!==[] ? $this->addressList($message->bcc()) : null,
				'ReplyTo'=>$message->replyTo()!==null ? $this->formatAddress($message->replyTo()) : null,
				'TemplateModel'=>$message->metadata()['template_model'] ?? $message->metadata()['template_data'] ?? $message->metadata(),
				'Headers'=>$message->headers()!==[] ? $this->messageHeaders($message->headers()) : null,
				'Attachments'=>$message->attachments()!==[] ? $this->attachments($message->attachments()) : null,
				'Tag'=>$message->tags()[0] ?? null,
				'Metadata'=>$this->stringMetadata($message->metadata()),
			], static fn(mixed $value): bool => $value!==null && $value!==[]);
		}
		$payload=[
			'From'=>$this->formatAddress($message->from()),
			'To'=>$this->addressList($message->to()),
			'Subject'=>$message->subject(),
			'HtmlBody'=>$message->html()!=='' ? $message->html() : null,
			'TextBody'=>$message->text()!=='' ? $message->text() : null,
			'Cc'=>$message->cc()!==[] ? $this->addressList($message->cc()) : null,
			'Bcc'=>$message->bcc()!==[] ? $this->addressList($message->bcc()) : null,
			'ReplyTo'=>$message->replyTo()!==null ? $this->formatAddress($message->replyTo()) : null,
			'Headers'=>$message->headers()!==[] ? $this->messageHeaders($message->headers()) : null,
			'Attachments'=>$message->attachments()!==[] ? $this->attachments($message->attachments()) : null,
			'Tag'=>$message->tags()[0] ?? null,
			'Metadata'=>$this->stringMetadata($message->metadata()),
		];
		return array_filter($payload, static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Resolves Postmark template metadata from a message.
	 *
	 * @param Message $message Message whose metadata may contain template id or alias fields.
	 * @return string|int|null Numeric template id, template alias, or null for non-template mail.
	 */
	private function templateId(Message $message): string|int|null {
		$metadata=$message->metadata();
		$value=$metadata['postmark_template_id'] ?? $metadata['postmark_template_alias'] ?? $metadata['template_id'] ?? $metadata['template_alias'] ?? null;
		if($value===null || $value===''){
			return null;
		}
		return is_numeric($value) ? (int)$value : (string)$value;
	}

	/**
	 * Formats a Postmark comma-separated address list.
	 *
	 * @param array<int, array<string, mixed>> $addresses Message address rows.
	 * @return string Postmark address list.
	 */
	private function addressList(array $addresses): string {
		return implode(', ', array_map(fn(array $address): string => $this->formatAddress($address), $addresses));
	}

	/**
	 * Formats one address for Postmark.
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
	 * Converts message headers into Postmark header rows.
	 *
	 * @param array<string, mixed> $headers Header map.
	 * @return array<int, array{Name: string, Value: string}> Postmark header rows.
	 */
	private function messageHeaders(array $headers): array {
		$out=[];
		foreach($headers as $name=>$value){
			$out[]=['Name'=>(string)$name, 'Value'=>(string)$value];
		}
		return $out;
	}

	/**
	 * Converts message attachments into Postmark attachment rows.
	 *
	 * Attachment content is base64 encoded because Postmark expects encoded
	 * content in JSON request bodies. Attachment bytes are already resident in
	 * the Message object, so request assembly keeps the encoded copy in memory.
	 *
	 * @param array<int, array<string, mixed>> $attachments Message attachment rows.
	 * @return array<int, array<string, mixed>> Postmark attachment rows.
	 */
	private function attachments(array $attachments): array {
		return array_map(static fn(array $attachment): array => array_filter([
			'Name'=>$attachment['filename'] ?? 'attachment',
			'Content'=>base64_encode((string)($attachment['content'] ?? '')),
			'ContentType'=>$attachment['type'] ?? 'application/octet-stream',
			'ContentID'=>($attachment['content_id'] ?? '')!=='' ? $attachment['content_id'] : null,
		], static fn(mixed $value): bool => $value!==null && $value!==''), $attachments);
	}

	/**
	 * Filters metadata to Postmark's string-only metadata map.
	 *
	 * @param array<string, mixed> $metadata Message metadata.
	 * @return array<string, string> Scalar metadata converted to strings.
	 */
	private function stringMetadata(array $metadata): array {
		$out=[];
		foreach($metadata as $key=>$value){
			if(is_scalar($value) || $value===null){
				$out[(string)$key]=(string)$value;
			}
		}
		return $out;
	}

	/**
	 * Extracts a useful failure message from an HTTP client response.
	 *
	 * @param array<string, mixed> $response HttpJsonClient response payload.
	 * @return string Human-readable Postmark or transport error message.
	 */
	private static function errorMessage(array $response): string {
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		return (string)($json['Message'] ?? $json['message'] ?? $response['error'] ?? 'Postmark request failed.');
	}

	/**
	 * Builds request headers beyond the required Postmark headers.
	 *
	 * Configured headers are merged with option headers, and an idempotency key
	 * option is promoted when the caller did not already set the header.
	 *
	 * @param array<string, mixed> $options Send options.
	 * @return array<string, string> Additional HTTP headers.
	 */
	private function headers(array $options): array {
		$headers=is_array($this->config['headers'] ?? null) ? $this->config['headers'] : [];
		if(is_array($options['headers'] ?? null)){
			$headers=array_replace($headers, $options['headers']);
		}
		if(trim((string)($options['idempotency_key'] ?? ''))!=='' && !isset($headers['Idempotency-Key'])){
			$headers['Idempotency-Key']=substr(trim((string)$options['idempotency_key']), 0, 256);
		}
		return $headers;
	}
}
