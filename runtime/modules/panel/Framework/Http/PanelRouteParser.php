<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Interprets Panel path segments and separates route identity from view state.
 *
 * Renderers, HTTP adapters, URL builders, and host integrations all consume the
 * same operation vocabulary through this class. Keeping that vocabulary beside
 * the parser prevents a newly rendered operation from silently becoming a record
 * key in another layer.
 */
final class PanelRouteParser {
	/** @var list<string> Operations accepted in the operation-first route position. */
	private const OPERATION_NAMES=[
		'index', 'create', 'store', 'show', 'edit', 'update', 'delete', 'destroy',
		'force_delete', 'restore', 'duplicate', 'import', 'import_template', 'export',
		'board', 'action', 'bulk_action', 'relation', 'transition', 'inline_update',
		'bulk_export', 'bulk_update', 'bulk_transition', 'bulk_duplicate',
		'bulk_restore', 'bulk_delete', 'bulk_force_delete',
		'approval', 'tag', 'task', 'note', 'message', 'attach',
	];

	/** @var list<string> Query keys that describe the current route rather than reusable view state. */
	private const IDENTITY_QUERY_KEYS=[
		'uri', 'resource', 'operation', 'record', 'record_key', 'action', 'relation',
		'panel_resource', 'panel_operation', 'panel_record', 'panel_action',
		'panel_relation', 'panel_segments', 'segments', 'path',
	];

	/**
	 * Returns the complete operation-first path vocabulary.
	 *
	 * @return list<string> Canonical and supported legacy Panel operation names.
	 */
	public static function operationNames(): array {
		return self::OPERATION_NAMES;
	}

	/**
	 * Derives resource, operation, record, action, and relation identity from a path.
	 *
	 * Both canonical record-first paths (`orders/42/edit`) and operation-first
	 * compatibility paths (`orders/edit/42`) are supported. Custom record operations
	 * remain available through `orders/42/custom_operation`.
	 *
	 * @param array<int, mixed>|string $segments Ordered path segments or a slash-delimited path.
	 * @return array<string, string> Inferred route identity.
	 */
	public static function infer(array|string $segments): array {
		if(is_string($segments)){
			$segments=explode('/', trim($segments, '/'));
		}
		$segments=array_values(array_filter(array_map(
			static fn(mixed $segment): string => rawurldecode(trim(trim((string)$segment), '/')),
			$segments
		), static fn(string $segment): bool => $segment!==''));
		$resource=(string)($segments[0] ?? '');
		if($resource===''){
			return [];
		}
		$second=(string)($segments[1] ?? '');
		$third=(string)($segments[2] ?? '');
		$fourth=(string)($segments[3] ?? '');
		$identity=['resource'=>$resource];
		if($second===''){
			return $identity+['operation'=>'index'];
		}

		$secondOperation=self::normalizeOperation($second);
		$thirdOperation=self::normalizeOperation($third);
		if($thirdOperation==='action'){
			return $identity+['record'=>$second, 'operation'=>'action', 'action'=>$fourth];
		}
		if($thirdOperation==='relation'){
			return $identity+['record'=>$second, 'operation'=>'relation', 'relation'=>$fourth];
		}
		if(in_array($secondOperation, self::OPERATION_NAMES, true)){
			$identity['operation']=$secondOperation;
			if($secondOperation==='action'){
				if($third!==''){
					$identity['action']=$third;
				}
				if($fourth!==''){
					$identity['record']=$fourth;
				}
				return $identity;
			}
			if($secondOperation==='relation'){
				if($third!==''){
					$identity['record']=$third;
				}
				if($fourth!==''){
					$identity['relation']=$fourth;
				}
				return $identity;
			}
			if($third!==''){
				$identity['record']=$third;
			}
			return $identity;
		}
		if($third!==''){
			return $identity+['record'=>$second, 'operation'=>$thirdOperation];
		}
		return $identity+['record'=>$second, 'operation'=>'show'];
	}

	/**
	 * Removes route transport/identity fields while preserving reusable filters and view state.
	 *
	 * @param array<string|int, mixed> $query Current request query.
	 * @return array<string|int, mixed> Query data safe to carry to another Panel operation.
	 */
	public static function withoutIdentityQuery(array $query): array {
		foreach(self::IDENTITY_QUERY_KEYS as $key){
			unset($query[$key]);
		}
		return $query;
	}

	/**
	 * Normalizes route aliases into their canonical operation names.
	 */
	private static function normalizeOperation(string $operation): string {
		$operation=Resource::normalizeName($operation) ?: 'index';
		return match($operation){
			'list', 'table'=>'index',
			'new'=>'create',
			'save'=>'store',
			default=>$operation,
		};
	}
}
