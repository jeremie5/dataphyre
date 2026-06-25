<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Thin test facade around a Reactor manager.
 *
 * The harness gives tests and examples a stable way to register components,
 * mount rendered HTML, dispatch component actions, inspect response snapshots,
 * and throw assertion failures with deterministic messages. It does not
 * replace the manager's runtime behavior; it packages the same manager calls
 * into payloads with stable shapes for regression tests, examples, and CLI
 * smoke checks.
 */
final class ReactorTestHarness {

	/**
	 * Stores the manager whose component registry and dispatcher are under test.
	 *
	 * @param ReactorManager $manager Reactor manager used for every harness operation.
	 */
	private function __construct(
		private readonly ReactorManager $manager
	){}

	/**
	 * Creates a harness with the supplied manager or an empty manager.
	 *
	 * Passing a manager lets tests exercise an existing registry. Omitting it
	 * starts with a fresh `ReactorManager`, which keeps isolated harness tests
	 * from sharing component state.
	 *
	 * @param ?ReactorManager $manager Existing manager to wrap, or `null` for a new manager.
	 * @return self Harness bound to the selected manager.
	 */
	public static function make(?ReactorManager $manager=null): self {
		return new self($manager ?? new ReactorManager());
	}

	/**
	 * Returns the wrapped Reactor manager.
	 *
	 * @return ReactorManager Manager whose registry, renderer, and dispatcher are exercised by the harness.
	 */
	public function manager(): ReactorManager {
		return $this->manager;
	}

	/**
	 * Registers a component definition with the wrapped manager.
	 *
	 * Array payloads follow the same normalization path as manager registration,
	 * so tests can register lightweight definitions without constructing full
	 * `ReactorComponent` instances.
	 *
	 * @param ReactorComponent|array<string, mixed> $component Component object or component definition payload.
	 * @return self Same harness for fluent test setup.
	 */
	public function register(ReactorComponent|array $component): self {
		$this->manager->register($component);
		return $this;
	}

	/**
	 * Mounts a component and returns a test-friendly render snapshot.
	 *
	 * The payload includes rendered HTML, byte length, serialized initial
	 * snapshot, and the component manifest entry resolved by normalized
	 * component name. This makes mount assertions independent from internal
	 * manager objects while still exposing enough context for example output.
	 *
	 * @param string $component Component name accepted by `ReactorManager::mount()`.
	 * @param array<string, mixed> $state Initial component state.
	 * @param array<string, mixed> $attributes Render attributes passed to the manager.
	 * @return array{component:string, html:string, html_length:int, snapshot:array<string, mixed>, manifest:?array<string, mixed>} Render snapshot for assertions and documentation.
	 */
	public function mount(string $component, array $state=[], array $attributes=[]): array {
		$html=$this->manager->mount($component, $state, $attributes);
		$snapshot=$this->manager->snapshot($component, $state);
		return [
			'component'=>$component,
			'html'=>$html,
			'html_length'=>strlen($html),
			'snapshot'=>$snapshot->jsonSerialize(),
			'manifest'=>$this->manager->manifest()['components'][ReactorName::normalize($component)] ?? null,
		];
	}

	/**
	 * Dispatches a component action through the wrapped manager.
	 *
	 * If no snapshot is provided the harness creates one from the component and
	 * state before dispatching. The request payload mirrors the runtime request
	 * contract: component name, optional action, state, params, and serialized
	 * snapshot.
	 *
	 * @param string $component Component name accepted by the manager.
	 * @param ?string $action Optional action method or event target to dispatch.
	 * @param array<string, mixed> $state State submitted with the dispatch request.
	 * @param array<string, mixed> $params Action parameters submitted with the request.
	 * @param ?ReactorSnapshot $snapshot Existing snapshot, or `null` to derive one from `$state`.
	 * @return ReactorResponse Response returned by the manager dispatcher.
	 */
	public function dispatch(string $component, ?string $action=null, array $state=[], array $params=[], ?ReactorSnapshot $snapshot=null): ReactorResponse {
		$snapshot ??= $this->manager->snapshot($component, $state);
		return $this->manager->dispatch([
			'component'=>$component,
			'action'=>$action,
			'state'=>$state,
			'params'=>$params,
			'snapshot'=>$snapshot->jsonSerialize(),
		]);
	}

	/**
	 * Converts a Reactor response into a compact assertion payload.
	 *
	 * The snapshot intentionally includes lengths and keys alongside full
	 * effects so tests can assert behavior without embedding whole HTML strings
	 * unless they need to.
	 *
	 * @param ReactorResponse $response Response produced by a Reactor dispatch.
	 * @return array{status:int, ok:bool, message:string, html_length:int, state_keys:array<int, string>, effect_keys:array<int, string>, effects:array<string, mixed>} Assertion-oriented response summary.
	 */
	public static function responseSnapshot(ReactorResponse $response): array {
		return [
			'status'=>$response->status(),
			'ok'=>$response->status()>=200 && $response->status()<300,
			'message'=>$response->message(),
			'html_length'=>strlen($response->html()),
			'state_keys'=>array_keys($response->state()),
			'effect_keys'=>array_keys($response->effects()),
			'effects'=>$response->effects(),
		];
	}

	/**
	 * Asserts that a Reactor response has an HTTP-style success status.
	 *
	 * Status codes in the inclusive 200 and exclusive 300 range pass. All other
	 * responses throw with both status and message so failing tests point at the
	 * dispatcher outcome.
	 *
	 * @param ReactorResponse $response Response to inspect.
	 * @return void Throws when the response is outside the success range.
	 * @throws \RuntimeException When the response status is not 2xx.
	 */
	public static function assertOk(ReactorResponse $response): void {
		if($response->status()<200 || $response->status()>=300){
			throw new \RuntimeException('Expected Reactor response to be OK, got '.$response->status().': '.$response->message());
		}
	}

	/**
	 * Asserts that rendered Reactor HTML contains a substring.
	 *
	 * The result may be a raw HTML string, a `ReactorResponse`, or the array
	 * returned by `mount()`. Empty needles fail deliberately because they do not
	 * prove anything about the render output.
	 *
	 * @param ReactorResponse|array{html?:string}|string $result Render result to inspect.
	 * @param string $needle Non-empty substring expected in the HTML.
	 * @return void Throws when the substring is absent.
	 * @throws \RuntimeException When the HTML does not contain `$needle`.
	 */
	public static function assertHtmlContains(ReactorResponse|array|string $result, string $needle): void {
		$html=is_string($result) ? $result : ($result instanceof ReactorResponse ? $result->html() : (string)($result['html'] ?? ''));
		if($needle==='' || !str_contains($html, $needle)){
			throw new \RuntimeException('Expected Reactor HTML to contain: '.$needle);
		}
	}

	/**
	 * Asserts that a nested response state value matches exactly.
	 *
	 * Dot-separated paths traverse nested arrays. Missing segments resolve to
	 * `null`, so callers can assert both explicit null values and absence by
	 * choosing the expected value intentionally.
	 *
	 * @param ReactorResponse $response Response whose state should be inspected.
	 * @param string $path Dot-separated state path.
	 * @param mixed $expected Expected value compared with strict identity.
	 * @return void Throws when the state value differs.
	 * @throws \RuntimeException When the resolved state value is not identical to `$expected`.
	 */
	public static function assertState(ReactorResponse $response, string $path, mixed $expected): void {
		$actual=self::pathValue($response->state(), $path);
		if($actual!==$expected){
			throw new \RuntimeException('Expected Reactor state '.$path.' to equal '.self::debugValue($expected).', got '.self::debugValue($actual).'.');
		}
	}

	/**
	 * Asserts that a named effect exists on the response.
	 *
	 *
	 * @param ReactorResponse $response Response whose effects map should be inspected.
	 * @param string $effect Effect key expected in the response.
	 * @return void Throws when the effect key is absent.
	 * @throws \RuntimeException When the response does not contain `$effect`.
	 */
	public static function assertEffect(ReactorResponse $response, string $effect): void {
		if(!array_key_exists($effect, $response->effects())){
			throw new \RuntimeException('Expected Reactor effect to exist: '.$effect);
		}
	}

	/**
	 * Resolves a dot-separated path inside a state array.
	 *
	 * @param array<string, mixed> $state State tree returned by a Reactor response.
	 * @param string $path Dot-separated path such as `user.name`.
	 * @return mixed Resolved value, or `null` when any segment is missing or non-array.
	 */
	private static function pathValue(array $state, string $path): mixed {
		$value=$state;
		foreach(explode('.', $path) as $segment){
			if(!is_array($value) || !array_key_exists($segment, $value)){
				return null;
			}
			$value=$value[$segment];
		}
		return $value;
	}

	/**
	 * Formats assertion values for deterministic failure messages.
	 *
	 * JSON encoding is preferred because arrays and scalars become compact,
	 * readable strings. Values that cannot be encoded fall back to their PHP
	 * debug type.
	 *
	 * @param mixed $value Value that will appear in an assertion failure.
	 * @return string JSON representation or PHP debug type.
	 */
	private static function debugValue(mixed $value): string {
		$encoded=json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($encoded) ? $encoded : get_debug_type($value);
	}
}
