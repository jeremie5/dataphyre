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
 * Sends mail through a Cloudflare-backed HTTP endpoint.
 *
 * The provider wraps Dataphyre mail messages into a JSON request accepted by an external
 * Cloudflare Worker or service endpoint. Configuration supplies the endpoint, optional headers,
 * API token, and timeout; per-send options can override endpoint, token, and timeout.
 * Endpoint semantics, downstream authentication, and message persistence are owned by the
 * configured Worker or service; this provider only posts the normalized message array and
 * returns the HTTP response.
 */
final class CloudflareProvider implements MailProvider {

	/**
	 * Stores provider configuration for later sends.
	 *
	 * @param array{endpoint?:string, headers?:array<string, string>, api_token?:string, timeout?:int} $config Provider configuration.
	 */
	public function __construct(private array $config=[]){}

	/**
	 * Returns the provider identifier used in send results.
	 *
	 * @return string Provider name.
	 */
	public function name(): string {
		return 'cloudflare';
	}

	/**
	 * Posts message data to the configured Cloudflare mail endpoint.
	 *
	 * Authorization is added as a bearer token when configured. Successful responses may return
	 * either `id` or `message_id`; failures preserve the raw HTTP client response for diagnostics.
	 * The request body includes the full normalized Message array and a small transport metadata
	 * block, so callers should configure only trusted endpoints.
	 *
	 * @param Message $message Message to send.
	 * @param array{endpoint?:string, api_token?:string, timeout?:int} $options Per-send overrides.
	 * @return SendResult Provider result including status, message id, and raw response metadata.
	 */
	public function send(Message $message, array $options=[]): SendResult {
		$endpoint=trim((string)($options['endpoint'] ?? $this->config['endpoint'] ?? ''));
		if($endpoint===''){
			return SendResult::failure($this->name(), 'Cloudflare mail endpoint is not configured.', 500);
		}
		$headers=is_array($this->config['headers'] ?? null) ? $this->config['headers'] : [];
		$token=trim((string)($options['api_token'] ?? $this->config['api_token'] ?? ''));
		if($token!==''){
			$headers['Authorization']='Bearer '.$token;
		}
		$response=HttpJsonClient::request('POST', $endpoint, [
			'message'=>$message->toArray(),
			'metadata'=>[
				'transport'=>'cloudflare',
				'sent_at'=>date('c'),
			],
		], $headers, (int)($options['timeout'] ?? $this->config['timeout'] ?? 15));
		if(($response['ok'] ?? false)!==true){
			return SendResult::failure($this->name(), self::errorMessage($response), (int)($response['status'] ?? 0), $response);
		}
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		$messageId=(string)($json['id'] ?? $json['message_id'] ?? '');
		return SendResult::success($this->name(), (int)$response['status'], 'Cloudflare mail accepted.', $messageId!=='' ? $messageId : null, $response);
	}

	/**
	 * Extracts a useful failure message from an HTTP JSON response.
	 *
	 * @param array<string, mixed> $response Response returned by `HttpJsonClient`.
	 * @return string Failure message for `SendResult`.
	 */
	private static function errorMessage(array $response): string {
		$json=is_array($response['json'] ?? null) ? $response['json'] : [];
		if(isset($json['message'])){
			return (string)$json['message'];
		}
		if(isset($json['errors']) && is_array($json['errors'])){
			return json_encode($json['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'Cloudflare mail request failed.';
		}
		return (string)($response['error'] ?? 'Cloudflare mail request failed.');
	}
}
