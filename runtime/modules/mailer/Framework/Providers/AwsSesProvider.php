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
use Dataphyre\Mailer\Support\AwsSignatureV4;
use Dataphyre\Mailer\Support\HttpJsonClient;

/**
 * Sends Dataphyre mail messages through Amazon SES v2.
 *
 * The provider serializes Message objects into SES Simple content payloads,
 * signs the request with AWS Signature V4, submits it to the regional SES
 * endpoint, and translates SES message identifiers or API errors into SendResult.
 * Attachments are not represented in SES Simple content by this provider path;
 * callers that need attachments must route through another provider or a raw MIME
 * SES integration.
 */
final class AwsSesProvider implements MailProvider {

	/**
	 * Stores default AWS SES connection and credential configuration.
	 *
	 * @param array<string,mixed> $config Provider defaults such as region, access_key, secret_key, session_token, endpoint, and timeout.
	 */
	public function __construct(private array $config=[]){}

	/**
	 * Identifies this provider in mailer results and telemetry.
	 *
	 * @return string Stable provider key.
	 */
	public function name(): string {
		return 'aws';
	}

	/**
	 * Sends one message through the SES v2 outbound-emails endpoint.
	 *
	 * Runtime options override constructor config. The payload is JSON encoded,
	 * signed for the configured region and ses service, then posted through the
	 * shared HTTP client.
	 * Custom endpoints are signed as supplied; endpoint trust, credential sourcing,
	 * and retry policy remain caller/configuration concerns.
	 *
	 * @param Message $message Message containing recipients, subject, text/html body, reply-to, and tags.
	 * @param array<string,mixed> $options Per-send overrides such as region, credentials, session_token, endpoint, and timeout.
	 * @return SendResult Success when SES accepts the message; failure for missing credentials or HTTP/API errors.
	 */
	public function send(Message $message, array $options=[]): SendResult {
		$region=trim((string)($options['region'] ?? $this->config['region'] ?? 'us-east-1'));
		$accessKey=trim((string)($options['access_key'] ?? $this->config['access_key'] ?? ''));
		$secretKey=trim((string)($options['secret_key'] ?? $this->config['secret_key'] ?? ''));
		if($accessKey==='' || $secretKey===''){
			return SendResult::failure($this->name(), 'AWS SES credentials are not configured.', 500);
		}
		$endpoint=trim((string)($options['endpoint'] ?? $this->config['endpoint'] ?? ''));
		if($endpoint===''){
			$endpoint='https://email.'.$region.'.amazonaws.com/v2/email/outbound-emails';
		}
		$payload=json_encode($this->payload($message), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
		$headers=AwsSignatureV4::headers(
			'POST',
			$endpoint,
			$region,
			'ses',
			$accessKey,
			$secretKey,
			$payload,
			$options['session_token'] ?? $this->config['session_token'] ?? null
		);
		$response=HttpJsonClient::request('POST', $endpoint, $payload, $headers, (int)($options['timeout'] ?? $this->config['timeout'] ?? 15));
		if(($response['ok'] ?? false)!==true){
			return SendResult::failure($this->name(), self::errorMessage($response), (int)($response['status'] ?? 0), $response);
		}
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		$messageId=(string)($json['MessageId'] ?? $json['messageId'] ?? '');
		return SendResult::success($this->name(), (int)$response['status'], 'AWS SES accepted the message.', $messageId!=='' ? $messageId : null, $response);
	}

	/**
	 * Builds the SES v2 Simple email payload.
	 *
	 * Tags are normalized to SES-compatible names and assigned a fixed value of
	 * `1`. Message headers and attachments are intentionally not emitted by this
	 * Simple content shape.
	 *
	 * @param Message $message Message to serialize.
	 * @return array<string,mixed> SES request body.
	 */
	private function payload(Message $message): array {
		$payload=[
			'FromEmailAddress'=>$this->formatAddress($message->from()),
			'Destination'=>array_filter([
				'ToAddresses'=>$this->addressList($message->to()),
				'CcAddresses'=>$message->cc()!==[] ? $this->addressList($message->cc()) : null,
				'BccAddresses'=>$message->bcc()!==[] ? $this->addressList($message->bcc()) : null,
			], static fn(mixed $value): bool => $value!==null && $value!==[]),
			'Content'=>[
				'Simple'=>[
					'Subject'=>[
						'Data'=>$message->subject(),
						'Charset'=>'UTF-8',
					],
					'Body'=>$this->body($message),
				],
			],
		];
		if($message->replyTo()!==null){
			$payload['ReplyToAddresses']=[$this->formatAddress($message->replyTo())];
		}
		if($message->tags()!==[]){
			$payload['EmailTags']=array_map(
				static fn(string $tag): array => ['Name'=>substr(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $tag) ?: 'tag', 0, 256), 'Value'=>'1'],
				$message->tags()
			);
		}
		return $payload;
	}

	/**
	 * Builds the SES text and HTML body object.
	 *
	 * @param Message $message Message body source.
	 * @return array<string,array{Data:string,Charset:string}> SES body map with at least Text.
	 */
	private function body(Message $message): array {
		$body=[];
		if($message->text()!==''){
			$body['Text']=['Data'=>$message->text(), 'Charset'=>'UTF-8'];
		}
		if($message->html()!==''){
			$body['Html']=['Data'=>$message->html(), 'Charset'=>'UTF-8'];
		}
		if($body===[]){
			$body['Text']=['Data'=>'', 'Charset'=>'UTF-8'];
		}
		return $body;
	}

	/**
	 * Formats SES recipient address arrays.
	 *
	 * @param array<int,array{email?:string,name?:string}> $addresses Recipient address data.
	 * @return list<string> SES address strings.
	 */
	private function addressList(array $addresses): array {
		return array_map(fn(array $address): string => $this->formatAddress($address), $addresses);
	}

	/**
	 * Formats one address for SES.
	 *
	 * @param array{email?:string,name?:string} $address Address data.
	 * @return string Display-name address or email alone.
	 */
	private function formatAddress(array $address): string {
		$email=(string)($address['email'] ?? '');
		$name=trim((string)($address['name'] ?? ''));
		return $name!=='' ? $name.' <'.$email.'>' : $email;
	}

	/**
	 * Extracts a human-readable SES error message.
	 *
	 * @param array<string,mixed> $response HttpJsonClient response data.
	 * @return string API message, AWS type, transport error, or generic failure text.
	 */
	private static function errorMessage(array $response): string {
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		foreach(['message', 'Message', '__type'] as $key){
			if(isset($json[$key])){
				return (string)$json[$key];
			}
		}
		return (string)($response['error'] ?? 'AWS SES request failed.');
	}
}
