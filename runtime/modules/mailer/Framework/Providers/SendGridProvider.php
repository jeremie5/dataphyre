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
 * Sends Dataphyre mail messages through SendGrid's v3 Mail Send API.
 *
 * The provider translates the framework `Message` value into SendGrid personalizations,
 * content, category, attachment, header, and custom argument request data, then posts JSON with a
 * bearer API key. HTTP responses are preserved in `SendResult` for diagnostics; API keys are only
 * placed in request headers and are not copied into provider result metadata.
 */
final class SendGridProvider implements MailProvider {

	/**
	 * Stores SendGrid provider configuration.
	 *
	 * @param array{api_key?:string, endpoint?:string, timeout?:int, headers?:array<string, string>} $config Provider configuration.
	 */
	public function __construct(private array $config=[]){}

	/**
	 * Returns the provider identifier used in send results.
	 *
	 * @return string Provider name.
	 */
	public function name(): string {
		return 'sendgrid';
	}

	/**
	 * Sends a message to SendGrid.
	 *
	 * The API key is required. A successful response extracts `x-message-id` from response
	 * headers when present, while failed responses are normalized from SendGrid's `errors` array.
	 * Caller/configured headers are merged after the required Authorization and Content-Type
	 * headers, so trusted configuration can intentionally override transport headers.
	 *
	 * @param Message $message Message to send.
	 * @param array{api_key?:string, endpoint?:string, timeout?:int, headers?:array<string, string>, idempotency_key?:string} $options Per-send overrides.
	 * @return SendResult Provider result with status, optional message id, and raw HTTP response.
	 */
	public function send(Message $message, array $options=[]): SendResult {
		$apiKey=trim((string)($options['api_key'] ?? $this->config['api_key'] ?? ''));
		if($apiKey===''){
			return SendResult::failure($this->name(), 'SendGrid API key is not configured.', 500);
		}
		$endpoint=trim((string)($options['endpoint'] ?? $this->config['endpoint'] ?? 'https://api.sendgrid.com/v3/mail/send'));
		$response=HttpJsonClient::request('POST', $endpoint, $this->payload($message), [
			'Authorization'=>'Bearer '.$apiKey,
			'Content-Type'=>'application/json',
			...$this->headers($options),
		], (int)($options['timeout'] ?? $this->config['timeout'] ?? 15));
		if(($response['ok'] ?? false)!==true){
			return SendResult::failure($this->name(), self::errorMessage($response), (int)($response['status'] ?? 0), $response);
		}
		$messageId='';
		foreach((array)($response['headers'] ?? []) as $line){
			if(stripos((string)$line, 'x-message-id:')===0){
				$messageId=trim(substr((string)$line, 13));
			}
		}
		return SendResult::success($this->name(), (int)$response['status'], 'SendGrid accepted the message.', $messageId!=='' ? $messageId : null, $response);
	}

	/**
	 * Converts a Dataphyre message into SendGrid's mail/send request data.
	 *
	 * Message metadata is exposed as SendGrid custom arguments after scalar string conversion.
	 * Attachments are base64-encoded from already-loaded Message content, so large attachments
	 * remain in memory during request assembly.
	 *
	 * @param Message $message Message to serialize.
	 * @return array<string, mixed> SendGrid API request data.
	 */
	private function payload(Message $message): array {
		$payload=[
			'personalizations'=>[array_filter([
				'to'=>$this->addresses($message->to()),
				'cc'=>$message->cc()!==[] ? $this->addresses($message->cc()) : null,
				'bcc'=>$message->bcc()!==[] ? $this->addresses($message->bcc()) : null,
				'subject'=>$message->subject(),
				'headers'=>$message->headers()!==[] ? $message->headers() : null,
				'custom_args'=>$message->metadata()!==[] ? $this->stringMetadata($message->metadata()) : null,
			], static fn(mixed $value): bool => $value!==null && $value!==[])],
			'from'=>$this->address($message->from()),
			'subject'=>$message->subject(),
			'content'=>$this->content($message),
		];
		if($message->replyTo()!==null){
			$payload['reply_to']=$this->address($message->replyTo());
		}
		if($message->tags()!==[]){
			$payload['categories']=$message->tags();
		}
		if($message->attachments()!==[]){
			$payload['attachments']=$this->attachments($message->attachments());
		}
		return $payload;
	}

	/**
	 * Builds SendGrid content blocks from text and HTML bodies.
	 *
	 * @param Message $message Message to serialize.
	 * @return array<int, array{type:string, value:string}> SendGrid content blocks.
	 */
	private function content(Message $message): array {
		$content=[];
		if($message->text()!==''){
			$content[]=['type'=>'text/plain', 'value'=>$message->text()];
		}
		if($message->html()!==''){
			$content[]=['type'=>'text/html', 'value'=>$message->html()];
		}
		return $content!==[] ? $content : [['type'=>'text/plain', 'value'=>'']];
	}

	/**
	 * Converts address rows into SendGrid address objects.
	 *
	 * @param array<int, array<string, string>> $addresses Message address rows.
	 * @return array<int, array<string, string>> SendGrid address objects.
	 */
	private function addresses(array $addresses): array {
		return array_map(fn(array $address): array => $this->address($address), $addresses);
	}

	/**
	 * Converts one address row into SendGrid shape.
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
	 * Converts message attachments into SendGrid attachment rows.
	 *
	 * @param array<int, array<string, mixed>> $attachments Message attachment rows.
	 * @return array<int, array<string, string>> SendGrid attachment rows.
	 */
	private function attachments(array $attachments): array {
		return array_map(static fn(array $attachment): array => array_filter([
			'content'=>base64_encode((string)$attachment['content']),
			'type'=>$attachment['type'] ?? 'application/octet-stream',
			'filename'=>$attachment['filename'] ?? 'attachment',
			'disposition'=>$attachment['disposition'] ?? 'attachment',
			'content_id'=>($attachment['content_id'] ?? '')!=='' ? $attachment['content_id'] : null,
		], static fn(mixed $value): bool => $value!==null && $value!==''), $attachments);
	}

	/**
	 * Converts scalar metadata into SendGrid custom arguments.
	 *
	 * @param array<string, mixed> $metadata Message metadata.
	 * @return array<string, string> String-only custom argument map.
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
	 * Extracts a readable SendGrid error message from an HTTP response.
	 *
	 * @param array<string, mixed> $response Response returned by `HttpJsonClient`.
	 * @return string Failure message.
	 */
	private static function errorMessage(array $response): string {
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		if(isset($json['errors']) && is_array($json['errors'])){
			return json_encode($json['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'SendGrid request failed.';
		}
		return (string)($response['error'] ?? 'SendGrid request failed.');
	}

	/**
	 * Merges configured and per-send headers for SendGrid requests.
	 *
	 * The idempotency key is truncated to SendGrid's accepted header size before
	 * it is promoted into request headers.
	 *
	 * @param array<string, mixed> $options Per-send options.
	 * @return array<string, string> Headers applied after required auth/content-type headers.
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
