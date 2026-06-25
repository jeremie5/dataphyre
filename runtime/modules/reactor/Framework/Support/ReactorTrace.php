<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Captures bounded in-memory diagnostics for Reactor dispatch.
 *
 * ReactorTrace records event names, sanitized context, timing, and memory data
 * for the current PHP process. It is intentionally lightweight and volatile:
 * traces help local debugging, tests, and operator panels explain recent
 * Reactor activity without becoming a durable audit log.
 */
final class ReactorTrace {

	/** Maximum number of completed event records retained in memory. */
	private const LIMIT=160;

	/** @var array<int, array<string, mixed>> Ring-buffer style event history. */
	private static array $events=[];

	/** @var array<string, array<string, mixed>> Active spans keyed by generated span id. */
	private static array $spans=[];

	/**
	 * Starts a timed Reactor span and records its begin event.
	 *
	 * Span identifiers are random process-local handles. The stored context is
	 * sanitized before retention so objects become class names or explicit JSON
	 * payloads rather than retaining live service instances.
	 *
	 * @param string $event Logical span name, normalized through ReactorName.
	 * @param array<string, mixed> $context Diagnostic context associated with the span.
	 * @return string Generated span id to pass to end() or fail().
	 */
	public static function begin(string $event, array $context=[]): string {
		$id=bin2hex(random_bytes(8));
		self::$spans[$id]=[
			'id'=>$id,
			'event'=>ReactorName::normalize($event) ?: 'span',
			'time'=>microtime(true),
			'memory'=>memory_get_usage(true),
			'context'=>self::sanitize($context),
		];
		self::record($event.'.begin', $context+['span_id'=>$id]);
		return $id;
	}

	/**
	 * Finishes an active span and records duration and memory delta.
	 *
	 * Ending an unknown span does not throw; it records a span.end_missing event
	 * so tracing remains safe inside finally blocks and defensive cleanup paths.
	 *
	 * @param string $spanId Span id returned by begin().
	 * @param array<string, mixed> $context Extra context to merge into the end event.
	 * @return void
	 */
	public static function end(string $spanId, array $context=[]): void {
		$span=self::$spans[$spanId] ?? null;
		if(!is_array($span)){
			self::record('span.end_missing', $context+['span_id'=>$spanId]);
			return;
		}
		unset(self::$spans[$spanId]);
		self::record((string)$span['event'].'.end', $context+[
			'span_id'=>$spanId,
			'duration_ms'=>round((microtime(true)-(float)$span['time'])*1000, 3),
			'memory_delta'=>memory_get_usage(true)-(int)$span['memory'],
		]);
	}

	/**
	 * Records a failed span with exception metadata.
	 *
	 * Known spans are removed from the active span table before the failure
	 * event is written. Unknown span ids still produce a generic span.failed
	 * event, preserving diagnostics for error paths that lost their begin state.
	 *
	 * @param string $spanId Span id returned by begin().
	 * @param \Throwable $exception Exception that interrupted the operation.
	 * @param array<string, mixed> $context Extra context to include with the failure.
	 * @return void
	 */
	public static function fail(string $spanId, \Throwable $exception, array $context=[]): void {
		$event='span.failed';
		if(isset(self::$spans[$spanId])){
			$event=(string)self::$spans[$spanId]['event'].'.failed';
			unset(self::$spans[$spanId]);
		}
		self::record($event, $context+[
			'span_id'=>$spanId,
			'exception'=>$exception::class,
			'message'=>$exception->getMessage(),
			'file'=>$exception->getFile(),
			'line'=>$exception->getLine(),
		]);
	}

	/**
	 * Records one Reactor diagnostic event.
	 *
	 * Events are retained in a bounded in-memory list and mirrored to the global
	 * tracelog function when it is available. Mirroring is best-effort and does
	 * not change the returned runtime behavior of Reactor dispatch.
	 *
	 * @param string $event Logical event name, normalized through ReactorName.
	 * @param array<string, mixed> $context Serializable or object-containing diagnostic context.
	 * @return void
	 */
	public static function record(string $event, array $context=[]): void {
		$entry=[
			'id'=>bin2hex(random_bytes(8)),
			'time'=>microtime(true),
			'event'=>ReactorName::normalize($event) ?: 'event',
			'context'=>self::sanitize($context),
			'memory'=>memory_get_usage(true),
		];
		self::$events[]=$entry;
		if(count(self::$events)>self::LIMIT){
			self::$events=array_slice(self::$events, -self::LIMIT);
		}
		if(function_exists('\tracelog')){
			\tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'Reactor '.$entry['event'], 'reactor_event', [$entry['context']]);
		}
	}

	/**
	 * Returns the retained Reactor event history.
	 *
	 * @return array<int, array{id:string,time:float,event:string,context:array,memory:int}> Recent events in recording order.
	 */
	public static function events(): array {
		return self::$events;
	}

	/**
	 * Builds a compact trace summary for diagnostics and manifests.
	 *
	 * @return array{count:int,events:array<string,int>,latest:array<int,array>,active_spans:array<int,array>}
	 */
	public static function summary(): array {
		$counts=[];
		foreach(self::$events as $event){
			$name=(string)($event['event'] ?? 'event');
			$counts[$name]=($counts[$name] ?? 0)+1;
		}
		return [
			'count'=>count(self::$events),
			'events'=>$counts,
			'latest'=>array_slice(self::$events, -10),
			'active_spans'=>array_values(self::$spans),
		];
	}

	/**
	 * Converts trace context into retention-safe scalar/array data.
	 *
	 * JsonSerializable values are expanded so domain payloads remain readable.
	 * Other objects are reduced to class names to avoid retaining service
	 * graphs, resources, or request objects in the static trace buffer.
	 *
	 * @param array<string, mixed> $context Raw trace context.
	 * @return array<string, mixed> Sanitized context suitable for JSON and in-memory retention.
	 */
	private static function sanitize(array $context): array {
		foreach($context as $key=>$value){
			if($value instanceof \JsonSerializable){
				$context[$key]=$value->jsonSerialize();
			}
			elseif(is_object($value)){
				$context[$key]=$value::class;
			}
		}
		return $context;
	}
}
