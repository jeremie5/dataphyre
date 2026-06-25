<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

/**
 * Immutable view over one registered dialback event and its callable stack.
 *
 * A dialback event is identified by the exact event name used by the core
 * registry. The object snapshots callable references at construction time for
 * runtime diagnostics and registry reports, while mutating operations continue to flow
 * through {@see Dialback} so the global runtime registry remains authoritative.
 */
final class DialbackEvent implements \JsonSerializable {

	/**
	 * Callable stack captured from the registry after invalid entries are removed.
	 *
	 * The array is re-indexed to integer offsets so serialized diagnostics can be
	 * compared without preserving user-supplied keys.
	 *
	 * @var array<int, callable>
	 */
	private readonly array $callbacks;

	/**
	 * Captures an event name and the callable stack that should describe it.
	 *
	 * Non-callable values are discarded during construction because they cannot be
	 * fired by the registry. The provided name is stored exactly as received; use
	 * {@see fromCallbacks()} when caller input should be trimmed first.
	 *
	 * @param string $name Registry event name represented by this snapshot.
	 * @param array<int|string, mixed> $callbacks Candidate callback list from the registry or caller input.
	 */
	public function __construct(
		private readonly string $name,
		array $callbacks=[]
	){
		$normalized=[];
		foreach($callbacks as $callback){
			if(is_callable($callback)){
				$normalized[]=$callback;
			}
		}
		$this->callbacks=$normalized;
	}

	/**
	 * Builds a dialback event snapshot from a registry callback list.
	 *
	 * The factory trims event names sourced from free-form callers before the
	 * immutable event value is created. Callable filtering is delegated to the
	 * constructor so this path and direct construction share one normalization
	 * rule.
	 *
	 * @param string $name Event name as provided by the registry consumer.
	 * @param array<int|string, mixed> $callbacks Candidate callbacks associated with the event.
	 * @return self Event snapshot containing the normalized name and callable-only stack.
	 */
	public static function fromCallbacks(string $name, array $callbacks=[]): self {
		return new self(trim($name), $callbacks);
	}

	/**
	 * Returns the registry event name represented by this snapshot.
	 *
	 * @return string Exact event identifier used for registration and firing.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Exposes the callable stack captured for the event.
	 *
	 * The returned callables are the original callable references after
	 * callable-only filtering and integer re-indexing. Mutating this returned
	 * array does not update the global registry or this immutable snapshot.
	 *
	 * @return array<int, callable> Callable stack in execution order.
	 */
	public function callbacks(): array {
		return $this->callbacks;
	}

	/**
	 * Describes every captured callback in a serialization-friendly form.
	 *
	 * Descriptions preserve enough identity for diagnostics without embedding the
	 * callable itself. Closures include source file and line when reflection can
	 * read them; instance, static, and invokable callbacks include class/method
	 * fields where available.
	 *
	 * @return array<int, array<string, int|string|null>> Callback descriptions in stack order.
	 */
	public function callbackDescriptions(): array {
		$descriptions=[];
		foreach($this->callbacks as $callback){
			$descriptions[]=static::describeCallback($callback);
		}
		return $descriptions;
	}

	/**
	 * Counts callable entries captured in this event snapshot.
	 *
	 * @return int Number of callbacks that survived callable filtering.
	 */
	public function callbackCount(): int {
		return count($this->callbacks);
	}

	/**
	 * Indicates whether the snapshot contains at least one callable callback.
	 *
	 * @return bool True when the event can fire at least one captured callback.
	 */
	public function hasCallbacks(): bool {
		return $this->callbacks!==[];
	}

	/**
	 * Indicates whether callable filtering left the event without callbacks.
	 *
	 * @return bool True when no callable callback is present in the snapshot.
	 */
	public function isEmpty(): bool {
		return $this->callbacks===[];
	}

	/**
	 * Tests whether the event name belongs to a prefix-scoped catalog view.
	 *
	 * Null, non-string, and blank prefixes are treated as an unscoped catalog
	 * request and therefore match every event. Non-blank prefixes are trimmed and
	 * compared from the beginning of the stored event name.
	 *
	 * @param ?string $prefix Optional event-name prefix to evaluate.
	 * @return bool True when the prefix is empty or the event name starts with it.
	 */
	public function matchesPrefix(?string $prefix): bool {
		$prefix=static::normalizePrefix($prefix);
		if($prefix===null){
			return true;
		}
		return str_starts_with($this->name, $prefix);
	}

	/**
	 * Registers an additional callback for this event name in the runtime registry.
	 *
	 * Registration mutates the global dialback registry through {@see Dialback}
	 * and does not alter this snapshot's captured callback list. Build a new
	 * {@see DialbackEvent} from the registry when fresh diagnostics are needed.
	 *
	 * @param callable $callback Callback to append to the runtime registry for this event.
	 * @return bool True when the core registry accepts the callback.
	 */
	public function register(callable $callback): bool {
		return Dialback::register($this->name, $callback);
	}

	/**
	 * Fires this event name through the runtime dialback dispatcher.
	 *
	 * Event arguments are forwarded unchanged to every registered callback for
	 * the event. The returned value and exception behavior are owned by the core
	 * dispatcher, allowing dialback callbacks to short-circuit or aggregate
	 * responses according to the registry's active implementation.
	 *
	 * @param mixed ...$data Event argument values forwarded to the registered callbacks.
	 * @return mixed value produced by the runtime dispatcher for this event name.
	 */
	public function fire(mixed ...$data): mixed {
		return Dialback::fire($this->name, ...$data);
	}

	/**
	 * Serializes event identity and callback diagnostics.
	 *
	 * The diagnostic array avoids exposing executable callables directly and instead uses
	 * stable descriptions that can be rendered, logged, or JSON encoded.
	 *
	 * @return array{name: string, callback_count: int, callbacks: array<int, array<string, int|string|null>>} Event diagnostic data.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'callback_count'=>$this->callbackCount(),
			'callbacks'=>$this->callbackDescriptions(),
		];
	}

	/**
	 * Serializes the event diagnostic description for JSON output.
	 *
	 * @return array{name: string, callback_count: int, callbacks: array<int, array<string, int|string|null>>} Event diagnostic data.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Normalizes an optional event prefix before catalog filtering.
	 *
	 * Blank prefixes collapse to null so callers can treat missing, whitespace,
	 * and unscoped prefixes identically.
	 *
	 * @param ?string $prefix Prefix candidate from catalog filters.
	 * @return ?string Trimmed prefix, or null when the filter should match all events.
	 */
	private static function normalizePrefix(?string $prefix): ?string {
		if(!is_string($prefix)){
			return null;
		}
		$prefix=trim($prefix);
		return $prefix!=='' ? $prefix : null;
	}

	/**
	 * Converts a PHP callable into a diagnostic-safe descriptor.
	 *
	 * The descriptor intentionally records identity metadata only; executable
	 * callable values are omitted so the diagnostic data remains safe for JSON surfaces,
	 * logs, and diffable diagnostics.
	 *
	 * @param callable $callback Callable captured from the event snapshot.
	 * @return array<string, int|string|null> Descriptor containing type, label, and optional source identity.
	 */
	private static function describeCallback(callable $callback): array {
		if(is_string($callback)){
			return [
				'type'=>'function',
				'label'=>$callback,
			];
		}
		if($callback instanceof \Closure){
			try{
				$reflection=new \ReflectionFunction($callback);
				return [
					'type'=>'closure',
					'label'=>'closure@'.basename((string)$reflection->getFileName()).':'.$reflection->getStartLine(),
					'file'=>$reflection->getFileName() ?: null,
					'line'=>$reflection->getStartLine(),
				];
			}catch(\ReflectionException){
				return [
					'type'=>'closure',
					'label'=>'closure',
				];
			}
		}
		if(is_array($callback) && count($callback)===2){
			[$target, $method]=$callback;
			$class=is_object($target) ? $target::class : (string)$target;
			return [
				'type'=>is_object($target) ? 'instance_method' : 'static_method',
				'label'=>$class.'::'.$method,
				'class'=>$class,
				'method'=>(string)$method,
			];
		}
		if(is_object($callback) && method_exists($callback, '__invoke')){
			return [
				'type'=>'invokable',
				'label'=>$callback::class,
				'class'=>$callback::class,
			];
		}
		return [
			'type'=>'callable',
			'label'=>'callable',
		];
	}
}
