<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer;

/**
 * Immutable outcome record for a mail transport send attempt.
 *
 * Send results separate transport success from provider diagnostics. They keep
 * the provider name, transport/status code, human-readable message, optional
 * provider message id, raw provider response, and local metadata together for
 * logs, queues, retries, and operator-facing delivery reports.
 */
final class SendResult implements \JsonSerializable {

	/** @var ?array<string, mixed> Cached send outcome serialization payload. */
	private ?array $arrayPayload=null;

	/**
	 * Captures a complete send outcome.
	 *
	 * The response array is intended for provider data that is already safe
	 * to persist or render. Sensitive tokens, credentials, and full message body
	 * content should be removed before constructing the result.
	 *
	 * @param bool $ok True when the provider accepted the send operation.
	 * @param string $provider Mail provider or transport name.
	 * @param int $status Provider or transport status code.
	 * @param string $message Human-readable send outcome.
	 * @param ?string $messageId Provider message id for accepted mail.
	 * @param array<string, mixed> $response Redacted provider response data.
	 * @param array<string, mixed> $meta Local diagnostic metadata.
	 */
	public function __construct(
		private bool $ok,
		private string $provider,
		private int $status=0,
		private string $message='',
		private ?string $messageId=null,
		private array $response=[],
		private array $meta=[]
	){}

	/**
	 * Builds a successful send result.
	 *
	 * Success means the configured provider accepted the message for delivery;
	 * it does not guarantee recipient inbox placement.
	 *
	 * @param string $provider Mail provider or transport name.
	 * @param int $status Provider or transport status code.
	 * @param string $message Human-readable success message.
	 * @param ?string $messageId Provider message id, when returned.
	 * @param array<string, mixed> $response Redacted provider response data.
	 * @param array<string, mixed> $meta Local diagnostic metadata.
	 * @return self Successful send outcome.
	 */
	public static function success(string $provider, int $status=200, string $message='Sent', ?string $messageId=null, array $response=[], array $meta=[]): self {
		return new self(true, $provider, $status, $message, $messageId, $response, $meta);
	}

	/**
	 * Builds a failed send result.
	 *
	 * Failed results intentionally omit message id because the provider did not
	 * accept the message in a way the mailer can track as delivered.
	 *
	 * @param string $provider Mail provider or transport name.
	 * @param string $message Human-readable failure message.
	 * @param int $status Provider or transport status code, or zero when unavailable.
	 * @param array<string, mixed> $response Redacted provider failure data.
	 * @param array<string, mixed> $meta Local diagnostic metadata.
	 * @return self Failed send outcome.
	 */
	public static function failure(string $provider, string $message, int $status=0, array $response=[], array $meta=[]): self {
		return new self(false, $provider, $status, $message, null, $response, $meta);
	}

	/**
	 * Indicates whether the provider accepted the send operation.
	 *
	 * @return bool True when the transport/provider reported acceptance.
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * Returns the provider or transport name that produced the result.
	 *
	 * @return string Mail provider identifier.
	 */
	public function provider(): string {
		return $this->provider;
	}

	/**
	 * Returns the provider or transport status code.
	 *
	 * @return int Status code, or zero when no provider status was available.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Returns the human-readable send outcome message.
	 *
	 * @return string Success or failure message for logs and operator UI.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Returns the external provider message id.
	 *
	 * @return ?string Provider message id, or null when unavailable or failed.
	 */
	public function messageId(): ?string {
		return $this->messageId;
	}

	/**
	 * Returns the redacted provider response data.
	 *
	 * The response shape depends on the configured provider and is preserved for
	 * diagnostics after provider-level redaction has already happened.
	 *
	 * @return array<string, mixed> Provider response data.
	 */
	public function response(): array {
		return $this->response;
	}

	/**
	 * Returns local mailer metadata attached to the result.
	 *
	 * Metadata can include queue ids, template names, recipient counts, retry
	 * attempts, or trace identifiers produced by Dataphyre rather than the
	 * provider.
	 *
	 * @return array<string, mixed> Local diagnostic metadata.
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Serializes the send result for queues, logs, and diagnostics.
	 *
	 * @return array{ok: bool, provider: string, status: int, message: string, message_id: ?string, response: array<string, mixed>, meta: array<string, mixed>} Send outcome for queues, logs, and diagnostics.
	 */
	public function toArray(): array {
		return $this->arrayPayload ??= [
			'ok'=>$this->ok,
			'provider'=>$this->provider,
			'status'=>$this->status,
			'message'=>$this->message,
			'message_id'=>$this->messageId,
			'response'=>$this->response,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Serializes the send result for JSON logs and queue diagnostics.
	 *
	 * @return array{ok: bool, provider: string, status: int, message: string, message_id: ?string, response: array<string, mixed>, meta: array<string, mixed>} Send outcome for queues, logs, and diagnostics.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
