<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable adapter-neutral widget lifecycle state. */
final class PanelWidgetInteractionState implements \JsonSerializable {
	private const STATUSES=['ready','loading','offline','error','unavailable','unmounted'];

	/** @param array<string,mixed> $data */
	private function __construct(
		private readonly string $status,
		private readonly int $version,
		private readonly array $data,
		private readonly ?string $errorCode,
		private readonly ?string $message
	){}

	/** @param array<string,mixed> $data */
	public static function ready(array $data, int $version=1, ?string $message=null): self {
		return self::make('ready', $version, $data, null, $message);
	}

	public static function unavailable(string $code='widget_unavailable', string $message='Interactive updates are unavailable.', bool $error=false): self {
		return self::make($error ? 'error' : 'unavailable', 0, [], $code, $message);
	}

	/** @param array<string,mixed> $data */
	public static function make(string $status, int $version=0, array $data=[], ?string $errorCode=null, ?string $message=null): self {
		$status=strtolower(trim($status));
		if(!in_array($status, self::STATUSES, true)){ throw new \InvalidArgumentException('Unsupported widget interaction state.'); }
		if($version<0){ throw new \InvalidArgumentException('Widget interaction state versions cannot be negative.'); }
		$data=PanelWidgetInteractionValue::assertMap($data, 'widget interaction state');
		if($errorCode!==null){ $errorCode=PanelWidgetInteractionValue::safeIdentifier($errorCode, 'Widget error code', 64); }
		if(in_array($status, ['error','unavailable','offline'], true) && $errorCode===null){
			throw new \InvalidArgumentException('Non-ready widget states require a stable public error code.');
		}
		if($message!==null){ $message=PanelWidgetInteractionValue::boundedString($message, 'Widget status message', 240); }
		return new self($status, $version, $data, $errorCode, $message);
	}

	public function status(): string { return $this->status; }
	public function version(): int { return $this->version; }
	/** @return array<string,mixed> */ public function data(): array { return $this->data; }
	public function errorCode(): ?string { return $this->errorCode; }
	public function message(): ?string { return $this->message; }
	public function successful(): bool { return $this->status==='ready'; }

	public function toArray(): array {
		return [
			'status'=>$this->status,
			'version'=>$this->version,
			'data'=>$this->data,
			'error_code'=>$this->errorCode,
			'message'=>$this->message,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }
}
