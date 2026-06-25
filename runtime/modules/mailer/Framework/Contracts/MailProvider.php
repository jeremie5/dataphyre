<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mailer\Contracts;

use Dataphyre\Mailer\Message;
use Dataphyre\Mailer\SendResult;

/**
 * Contract for a transport capable of sending Dataphyre mail messages.
 *
 * Providers own service-specific serialization, authentication, transport
 * errors, and response mapping while presenting a stable SendResult surface to
 * the mailer manager. Implementations should not mutate Message objects and
 * should keep secrets out of SendResult response/meta arrays.
 */
interface MailProvider {
	/**
	 * Returns the stable provider key used in send results and telemetry.
	 *
	 * @return string Provider identifier such as smtp, sendgrid, mailgun, or aws.
	 */
	public function name(): string;
	/**
	 * Sends one message through the provider transport.
	 *
	 * Options are provider-specific, but common keys include endpoint, headers,
	 * credentials, timeout, and idempotency_key. Providers should convert local
	 * validation, transport, and remote API failures into SendResult objects
	 * instead of leaking implementation exceptions for normal delivery failures.
	 *
	 * @param Message $message Message envelope, headers, body, attachments, tags, and metadata.
	 * @param array<string,mixed> $options Per-send provider overrides such as credentials, endpoint, headers, or timeout.
	 * @return SendResult Normalized success or failure result.
	 */
	public function send(Message $message, array $options=[]): SendResult;
}

/**
 * Optional contract for providers that can send multiple messages efficiently.
 *
 * Batch providers may use a remote bulk API or optimized transport reuse, but
 * must preserve result order so each SendResult corresponds to the input
 * message at the same index. Partial remote failures should become failed
 * SendResult entries for the affected input rows.
 */
interface BatchMailProvider extends MailProvider {
	/**
	 * Sends multiple messages and returns one result per input message.
	 *
	 * @param array<int, Message> $messages Ordered message list.
	 * @param array<string,mixed> $options Per-send or provider-level overrides shared by the batch.
	 * @return array<int, SendResult> Ordered result list aligned with $messages.
	 */
	public function sendBatch(array $messages, array $options=[]): array;
}
