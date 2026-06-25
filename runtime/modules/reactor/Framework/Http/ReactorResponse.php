<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Represents the JSON payload returned by a Reactor action.
 *
 * Reactor responses carry the rendered HTML fragment, client-side state
 * updates, effect instructions, HTTP status, and an optional message in one
 * immutable value. Endpoint emitters serialize this object directly for browser
 * adapters and batch envelopes.
 */
final class ReactorResponse implements \JsonSerializable {

	/**
	 * Stores the response payload.
	 *
	 * The constructor is private so callers use the named factories, keeping
	 * successful and failing response invariants consistent.
	 *
	 * @param string $html Rendered HTML fragment for successful responses.
	 * @param array<string, mixed> $state Client state patch emitted by the action.
	 * @param array<int|string, mixed> $effects Client effect instructions.
	 * @param int $status HTTP status represented by the response.
	 * @param string $message Human-readable error or status message.
	 */
	private function __construct(
		private readonly string $html='',
		private readonly array $state=[],
		private readonly array $effects=[],
		private readonly int $status=200,
		private readonly string $message=''
	){}

	/**
	 * Creates a successful Reactor response.
	 *
	 * Successful responses always use status 200 and leave the message empty.
	 * Actions should place any client instructions in state or effects rather
	 * than overloading the status message.
	 *
	 * @param string $html Rendered fragment returned to the Reactor client.
	 * @param array<string, mixed> $state Client state patch.
	 * @param array<int|string, mixed> $effects Client effect instructions.
	 * @return self Successful Reactor response.
	 */
	public static function ok(string $html, array $state=[], array $effects=[]): self {
		return new self($html, $state, $effects, 200);
	}

	/**
	 * Creates an error Reactor response.
	 *
	 * Error statuses are clamped into the HTTP 4xx/5xx range so failed actions
	 * cannot accidentally serialize as successful responses. Error responses do
	 * not include HTML or state, but may include effects for client-side cleanup.
	 *
	 * @param string $message Human-readable failure message.
	 * @param int $status Desired HTTP error status.
	 * @param array<int|string, mixed> $effects Optional client effect instructions.
	 * @return self Error Reactor response.
	 */
	public static function error(string $message, int $status=422, array $effects=[]): self {
		return new self('', [], $effects, max(400, min(599, $status)), trim($message));
	}

	/**
	 * Returns the rendered HTML fragment.
	 *
	 * @return string HTML fragment, or an empty string for error responses.
	 */
	public function html(): string {
		return $this->html;
	}

	/**
	 * Returns the client state patch.
	 *
	 * @return array<string, mixed> State values for the Reactor client to merge.
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * Returns client effect instructions.
	 *
	 * @return array<int|string, mixed> Effect payloads consumed by the Reactor client adapter.
	 */
	public function effects(): array {
		return $this->effects;
	}

	/**
	 * Returns the HTTP status represented by the response.
	 *
	 * @return int Status code used by ReactorEndpoint emitters.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Returns the response message.
	 *
	 * @return string Error/status message, empty for normal ok responses.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Serializes the response for HTTP output and batch envelopes.
	 *
	 * @return array{status:int,ok:bool,html:string,state:array,effects:array,message:string}
	 */
	public function jsonSerialize(): array {
		return [
			'status'=>$this->status,
			'ok'=>$this->status>=200 && $this->status<300,
			'html'=>$this->html,
			'state'=>$this->state,
			'effects'=>$this->effects,
			'message'=>$this->message,
		];
	}
}
