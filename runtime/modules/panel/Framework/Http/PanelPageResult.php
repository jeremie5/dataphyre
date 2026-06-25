<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable panel controller result that can become an HTTP response.
 *
 * PanelPageResult carries the rendered body, HTTP status, headers, auxiliary panel data, normalized notifications, and an
 * optional redirect target. It is used as a lightweight boundary object between panel actions/controllers and whatever HTTP
 * response implementation is available in the host application.
 *
 * Factory methods set the content type and attachment headers for common panel result modes. The object does not send
 * headers itself; toResponse() adapts it to Dataphyre\Http\Response when that module is present.
 */
final class PanelPageResult implements \Stringable, \JsonSerializable {

	/**
	 * Creates a result with already-normalized response fields.
	 *
	 * @param string $content Response body.
	 * @param int $status HTTP status code.
	 * @param array<string, string> $headers Headers to attach when converted to an HTTP response.
	 * @param array<string, mixed> $data Extra panel state for JSON serialization and diagnostics.
	 * @param array<int, array<string, mixed>> $notifications Normalized notification payloads.
	 * @param ?string $redirectTo Redirect target, or null for non-redirect results.
	 */
	public function __construct(
		private readonly string $content,
		private readonly int $status=200,
		private readonly array $headers=['Content-Type'=>'text/html; charset=utf-8'],
		private readonly array $data=[],
		private readonly array $notifications=[],
		private readonly ?string $redirectTo=null
	){}

	/**
	 * Creates an HTML panel result.
	 *
	 * @param string $content Rendered HTML body.
	 * @param int $status HTTP status code.
	 * @param array<string, mixed> $data Extra panel state.
	 * @param array<int, PanelNotification|array<string, mixed>|string> $notifications Notifications to normalize.
	 * @return self HTML result.
	 */
	public static function html(string $content, int $status=200, array $data=[], array $notifications=[]): self {
		return new self($content, $status, ['Content-Type'=>'text/html; charset=utf-8'], $data, self::normalizeNotifications($notifications));
	}

	/**
	 * Creates a CSV download result.
	 *
	 * Filenames are reduced to simple ASCII-safe attachment names so caller-supplied export names cannot inject header
	 * separators or path fragments.
	 *
	 * @param string $content CSV body.
	 * @param string $filename Download filename.
	 * @param array<string, mixed> $data Extra panel state.
	 * @return self CSV attachment result.
	 */
	public static function csv(string $content, string $filename='panel-export.csv', array $data=[]): self {
		$filename=trim($filename) ?: 'panel-export.csv';
		$filename=preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $filename) ?: 'panel-export.csv';
		return new self($content, 200, [
			'Content-Type'=>'text/csv; charset=utf-8',
			'Content-Disposition'=>'attachment; filename="'.$filename.'"',
		], $data);
	}

	/**
	 * Creates a pretty-printed JSON download result.
	 *
	 * @param mixed $payload Payload encoded as JSON.
	 * @param string $filename Download filename.
	 * @param array<string, mixed> $data Extra panel state.
	 * @return self JSON attachment result.
	 */
	public static function jsonDownload(mixed $payload, string $filename='panel-export.json', array $data=[]): self {
		$filename=trim($filename) ?: 'panel-export.json';
		$filename=preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $filename) ?: 'panel-export.json';
		$content=json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
		return new self($content, 200, [
			'Content-Type'=>'application/json; charset=utf-8',
			'Content-Disposition'=>'attachment; filename="'.$filename.'"',
		], $data);
	}

	/**
	 * Creates an inline JSON response result.
	 *
	 * Caller-supplied headers override defaults with array_replace().
	 *
	 * @param mixed $payload Payload encoded as compact JSON.
	 * @param int $status HTTP status code.
	 * @param array<string, string> $headers Additional or replacement response headers.
	 * @return self JSON response result.
	 */
	public static function json(mixed $payload, int $status=200, array $headers=[]): self {
		$content=json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
		return new self($content, $status, array_replace([
			'Content-Type'=>'application/json; charset=utf-8',
		], $headers));
	}

	/**
	 * Creates a redirect result with an HTML fallback body.
	 *
	 * The Location header is the primary redirect signal. The body also includes a meta refresh fallback with the target
	 * escaped for HTML contexts.
	 *
	 * @param string $to Redirect target.
	 * @param array<string, mixed> $data Extra panel state.
	 * @param array<int, PanelNotification|array<string, mixed>|string> $notifications Notifications to normalize.
	 * @param int $status Redirect status, usually 303 after panel actions.
	 * @return self Redirect result.
	 */
	public static function redirect(string $to, array $data=[], array $notifications=[], int $status=303): self {
		$headers=[
			'Location'=>$to,
			'Content-Type'=>'text/html; charset=utf-8',
		];
		$content='<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url='.self::e($to).'"></head><body><p>Redirecting...</p></body></html>';
		return new self($content, $status, $headers, $data, self::normalizeNotifications($notifications), $to);
	}

	/**
	 * Returns the response body.
	 *
	 * @return string Rendered content.
	 */
	public function content(): string {
		return $this->content;
	}

	/**
	 * Returns the HTTP status code.
	 *
	 * @return int Status code.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Returns response headers.
	 *
	 * @return array<string, string> Header map.
	 */
	public function headers(): array {
		return $this->headers;
	}

	/**
	 * Returns auxiliary panel state attached to the result.
	 *
	 * @return array<string, mixed> Extra data for diagnostics or client-side consumers.
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Returns normalized panel notifications.
	 *
	 * @return array<int, array<string, mixed>> Notification payloads.
	 */
	public function notifications(): array {
		return $this->notifications;
	}

	/**
	 * Returns the redirect target when this result is a redirect.
	 *
	 * @return ?string Redirect target, or null for non-redirect results.
	 */
	public function redirectTo(): ?string {
		return $this->redirectTo;
	}

	/**
	 * Reports whether the result represents a redirect.
	 *
	 * @return bool Whether redirectTo() is non-null.
	 */
	public function isRedirect(): bool {
		return $this->redirectTo!==null;
	}

	/**
	 * Converts the result to the Dataphyre HTTP response object when available.
	 *
	 * @return mixed Dataphyre\Http\Response when installed, otherwise this result object.
	 */
	public function toResponse(): mixed {
		if(class_exists('\Dataphyre\Http\Response')){
			return new \Dataphyre\Http\Response($this->content, $this->status, $this->headers);
		}
		return $this;
	}

	/**
	 * Serializes response metadata without including the body content.
	 *
	 * @return array{status:int, headers:array<string, string>, data:array<string, mixed>, notifications:array<int, array<string, mixed>>, redirect_to:?string} Response metadata payload.
	 */
	public function jsonSerialize(): array {
		return [
			'status'=>$this->status,
			'headers'=>$this->headers,
			'data'=>$this->data,
			'notifications'=>$this->notifications,
			'redirect_to'=>$this->redirectTo,
		];
	}

	/**
	 * Returns the response body as the string representation.
	 *
	 * @return string Response body.
	 */
	public function __toString(): string {
		return $this->content;
	}

	/**
	 * Converts mixed notification inputs into PanelNotification arrays.
	 *
	 * @param array<int, PanelNotification|array<string, mixed>|string> $notifications Notification inputs.
	 * @return array<int, array<string, mixed>> Normalized notification payloads.
	 */
	private static function normalizeNotifications(array $notifications): array {
		$normalized=[];
		foreach($notifications as $notification){
			if($notification instanceof PanelNotification){
				$normalized[]=$notification->jsonSerialize();
				continue;
			}
			if(is_array($notification)){
				$normalized[]=PanelNotification::fromArray($notification)->jsonSerialize();
				continue;
			}
			if(is_string($notification) && trim($notification)!==''){
				$normalized[]=PanelNotification::info($notification)->jsonSerialize();
			}
		}
		return $normalized;
	}

	/**
	 * Escapes text for redirect fallback HTML.
	 *
	 * @param string $value Raw value.
	 * @return string HTML-safe value.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
