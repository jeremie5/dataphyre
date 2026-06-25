<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable result returned by panel lifecycle hooks to steer request flow.
 *
 * Lifecycle hooks can halt an operation, request a redirect, attach operator
 * notifications, and carry a response payload without throwing framework-control
 * exceptions. Status codes are clamped to the family implied by the result type.
 */
final class PanelLifecycleResult implements \JsonSerializable {

	/**
	 * Stores the normalized lifecycle outcome.
	 *
	 * @param bool $halted Whether the panel operation should stop before its normal continuation.
	 * @param string $message Operator-facing lifecycle message.
	 * @param array<int, PanelNotification|array|string> $notifications Notification payloads to surface in the UI.
	 * @param ?string $redirectTo Optional redirect target for post-lifecycle navigation.
	 * @param int $status HTTP status selected for the lifecycle response.
	 * @param mixed $payload Optional structured payload for callers that need extra context.
	 */
	private function __construct(
		private readonly bool $halted=false,
		private readonly string $message='',
		private readonly array $notifications=[],
		private readonly ?string $redirectTo=null,
		private readonly int $status=422,
		private readonly mixed $payload=null
	){}

	/**
	 * Creates a lifecycle result that stops the active panel operation.
	*
	 * Halt statuses are constrained to the client/server error range so consumers
	 * can distinguish intentional lifecycle cancellation from successful redirects
	 * or informational notifications.
	 *
	 * @param string $message Operator-facing reason for the halt.
	 * @param array<int, PanelNotification|array|string> $notifications Notifications to display with the halt.
	 * @param int $status Requested HTTP status; clamped to `400..599`.
	 * @param mixed $payload Optional diagnostic or domain payload.
	 * @return self Halted lifecycle result.
	 */
	public static function halt(string $message, array $notifications=[], int $status=422, mixed $payload=null): self {
		return new self(true, trim($message), $notifications, null, max(400, min(599, $status)), $payload);
	}

	/**
	 * Creates a lifecycle result that asks the panel response layer to redirect.
	*
	 * Redirect results are not marked halted, but they carry a `redirect_to` target
	 * and a 3xx status so the caller can short-circuit normal rendering.
	 *
	 * @param string $to Redirect target URL or panel path.
	 * @param string $message Optional operator-facing message.
	 * @param array<int, PanelNotification|array|string> $notifications Notifications to flash before redirecting.
	 * @param int $status Requested redirect status; clamped to `300..399`.
	 * @param mixed $payload Optional structured context.
	 * @return self Redirect lifecycle result.
	 */
	public static function redirect(string $to, string $message='', array $notifications=[], int $status=303, mixed $payload=null): self {
		return new self(false, trim($message), $notifications, trim($to) ?: null, max(300, min(399, $status)), $payload);
	}

	/**
	 * Creates a lifecycle result whose primary effect is surfacing one notification.
	*
	 * String notifications double as the default message. `PanelNotification`
	 * instances contribute their own message. When `$halt` is true the result uses
	 * status `422`; otherwise it is a successful `200` notification result.
	 *
	 * @param PanelNotification|array|string $notification Notification object, raw notification array, or message string.
	 * @param bool $halt Whether this notification should also stop the operation.
	 * @param ?string $message Optional explicit message override.
	 * @return self Notification lifecycle result.
	 */
	public static function notify(PanelNotification|array|string $notification, bool $halt=false, ?string $message=null): self {
		$notifications=[$notification];
		$message ??= $notification instanceof PanelNotification ? $notification->message() : (is_string($notification) ? $notification : '');
		return new self($halt, trim((string)$message), $notifications, null, $halt ? 422 : 200, null);
	}

	/**
	 * Indicates whether the lifecycle result stops the active operation.
	 *
	 * @return bool `true` when normal panel processing should halt.
	 */
	public function halted(): bool {
		return $this->halted;
	}

	/**
	 * Returns the operator-facing lifecycle message.
	 *
	 * @return string Trimmed message, possibly empty.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Returns notifications carried by the lifecycle result.
	 *
	 * @return array<int, PanelNotification|array|string> Notification payloads in display order.
	 */
	public function notifications(): array {
		return $this->notifications;
	}

	/**
	 * Returns the requested redirect target, when this result redirects.
	 *
	 * @return ?string Redirect target or `null` for non-redirect lifecycle outcomes.
	 */
	public function redirectTo(): ?string {
		return $this->redirectTo;
	}

	/**
	 * Returns the normalized HTTP status for the lifecycle response.
	 *
	 * @return int Status code clamped by the factory that created the result.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Returns optional structured data attached by the lifecycle hook.
	 *
	 * @return mixed caller-defined lifecycle context for response handling or diagnostics.
	 */
	public function payload(): mixed {
		return $this->payload;
	}

	/**
	 * Serializes the lifecycle outcome for JSON responses and shape discovery.
	 *
	 * @return array{halted:bool,message:string,notifications:array,redirect_to:?string,status:int,payload:mixed}
	 */
	public function jsonSerialize(): array {
		return [
			'halted'=>$this->halted,
			'message'=>$this->message,
			'notifications'=>$this->notifications,
			'redirect_to'=>$this->redirectTo,
			'status'=>$this->status,
			'payload'=>$this->payload,
		];
	}
}
