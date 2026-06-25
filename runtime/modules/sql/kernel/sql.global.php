<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
new \dataphyre\sql();

/**
 * Replays a SQL operation once after auto-hydrating a missing table or column definition.
 *
 * Global SQL helpers delegate through this guard so legacy call sites can create schema lazily from registered table definitions. The first failure is left untouched unless the SQL kernel recognizes it as a missing structure error, in which case Dataphyre hydrates the relevant definition, invalidates that table cache, clears the diagnostic state, and invokes the operation a final time. Non-structure failures and failed hydration return the original result without masking the underlying driver state.
 *
 * @param callable $operation Zero-argument SQL operation that returns the normal kernel result or false on driver failure.
 * @param ?string $location Logical table location used to match the failure and clear table-scoped cache after hydration.
 * @return mixed Original operation result, hydrated retry result, or the original false value when recovery is not available.
 */
function dp_sql_retry_missing_table(callable $operation, ?string $location=null): mixed {
	dataphyre\sql::clear_last_query_error();
	$result=$operation();
	if($result!==false){
		return $result;
	}
	if(dataphyre\sql::hydrate_missing_structure_from_definition($location)===false){
		return $result;
	}
	if($location!==null){
		dataphyre\sql::invalidate_cache($location);
	}
	dataphyre\sql::clear_last_query_error();
	return $operation();
}

/**
 * Counts rows through the legacy global SQL facade with missing-schema recovery.
 *
 * This wrapper preserves the snake_case kernel API while forwarding to dataphyre\sql::count(). Prepared values remain separate from the where expression, cache policy is interpreted by the SQL kernel, and the operation is retried once when the target table or column can be hydrated from a registered definition.
 *
 * @param mixed $a Table location resolved by dataphyre\sql::table().
 * @param mixed $b Optional where clause, condition array, or null for an unfiltered count.
 * @param mixed $c Bound values for placeholders contained in the where clause.
 * @param mixed $d Cache policy override, cache key selector, or false to bypass cached reads.
 * @param mixed $e Queue name; "end" defers work to shutdown while null executes immediately.
 * @param mixed $f Optional callback invoked with the count result.
 * @return int|bool|null Row count, queue status, null, or false on query failure.
 */
function sql_count($a=null,$b=null,$c=null, $d=null, $e=null, $f=null){
	return dp_sql_retry_missing_table(fn()=>dataphyre\sql::count($a,$b,$c,$d,$e,$f), $a);
}
/**
 * Selects records through the global SQL facade with cache-aware missing-schema recovery.
 *
 * The helper accepts the historical positional argument list and forwards it unchanged to dataphyre\sql::select(). Column selection, table location, filtering, binding, associativity, caching, queueing, and callback handling are all owned by the SQL kernel so legacy callers can treat this function as a stable compatibility entrypoint.
 *
 * @param mixed $a Column list, SQL projection string, or DBMS-specific query map.
 * @param mixed $b Table location resolved by dataphyre\sql::table().
 * @param mixed $c Optional where clause, condition array, or null for no filter.
 * @param mixed $d Bound values for placeholders contained in the where clause.
 * @param mixed $e True for associative rows, false for numeric rows, or null for kernel default.
 * @param mixed $f Cache policy override, cache key selector, or false to bypass cached reads.
 * @param mixed $g Queue name; "end" defers work to shutdown while null executes immediately.
 * @param mixed $h Optional callback invoked with the selected result set.
 * @return mixed Selected rows, cached payload, callback return value, null queue status, or false on failure.
 */
function sql_select($a=null,$b=null,$c=null,$d=null,$e=null,$f=null,$g=null,$h=null){
	return dp_sql_retry_missing_table(fn()=>dataphyre\sql::select($a,$b,$c,$d,$e,$f,$g,$h), $b);
}
/**
 * Deletes rows through the global SQL facade and clears affected cache namespaces.
 *
 * Deletion semantics, placeholder binding, queueing, cache invalidation, and observer traces remain centralized in dataphyre\sql::delete(). This wrapper adds the same definition-hydration retry path used by reads and writes so deferred schema declarations can satisfy early application calls.
 *
 * @param mixed $a Table location resolved by dataphyre\sql::table().
 * @param mixed $b Optional where clause, condition array, or null for the kernel-defined deletion scope.
 * @param mixed $c Bound values for placeholders contained in the where clause.
 * @param mixed $d Cache invalidation flag, namespace list, or null for table policy.
 * @param mixed $e Queue name; "end" defers work to shutdown while null executes immediately.
 * @param mixed $f Optional callback invoked with the delete result.
 * @return int|bool|null Affected row count, queue status, null, or false on failure.
 */
function sql_delete($a=null,$b=null,$c=null,$d=null,$e=null,$f=null){
	return dp_sql_retry_missing_table(fn()=>dataphyre\sql::delete($a,$b,$c,$d,$e,$f), $a);
}
/**
 * Updates rows through the global SQL facade with write-side cache invalidation.
 *
 * The field set and predicate are passed to dataphyre\sql::update() without reinterpretation. The SQL kernel owns escaping, binding, queue semantics, mutation tracing, and cache clearing; this wrapper only supplies legacy function access and one missing-structure retry.
 *
 * @param mixed $a Table location resolved by dataphyre\sql::table().
 * @param mixed $b Field assignment string or associative field/value array.
 * @param mixed $c Optional where clause, condition array, or null for the kernel-defined update scope.
 * @param mixed $d Bound values for placeholders contained in assignments or predicates.
 * @param mixed $e Cache invalidation flag, namespace list, or null for table policy.
 * @param mixed $f Queue name; "end" defers work to shutdown while null executes immediately.
 * @param mixed $g Optional callback invoked with the update result.
 * @return int|bool|null Affected row count, queue status, null, or false on failure.
 */
function sql_update($a=null,$b=null,$c=null,$d=null,$e=null,$f=null,$g=null){
	return dp_sql_retry_missing_table(fn()=>dataphyre\sql::update($a,$b,$c,$d,$e,$f,$g), $a);
}
/**
 * Inserts rows through the global SQL facade and applies table cache policy.
 *
 * Field payloads, placeholder bindings, write queueing, callbacks, and generated key handling are delegated to dataphyre\sql::insert(). Missing table or column diagnostics can trigger definition hydration before the operation is replayed once.
 *
 * @param mixed $a Table location resolved by dataphyre\sql::table().
 * @param mixed $b Field assignment string, associative field/value array, or bulk row payload accepted by the kernel.
 * @param mixed $c Bound values for placeholders contained in the insert payload.
 * @param mixed $d Cache invalidation flag, namespace list, or null for table policy.
 * @param mixed $e Queue name; "end" defers work to shutdown while null executes immediately.
 * @param mixed $f Optional callback invoked with the insert result.
 * @return mixed Insert id, driver result, callback return value, null queue status, or false on failure.
 */
function sql_insert($a=null,$b=null,$c=null,$d=null,$e=null,$f=null){
	return dp_sql_retry_missing_table(fn()=>dataphyre\sql::insert($a,$b,$c,$d,$e,$f), $a);
}
/**
 * Runs raw SQL or DBMS-mapped SQL through the global SQL facade.
 *
 * This entrypoint is the escape hatch for callers that already own the SQL text. It still uses the kernel contract for bound values, multipoint execution, cache reads, cache invalidation, queueing, callbacks, and tracing. Because raw SQL may not expose a table location, automatic schema hydration can only use the kernel's recorded last-query diagnostics.
 *
 * @param mixed $a SQL string or DBMS-specific query map.
 * @param mixed $b Bound values for placeholders contained in the SQL text.
 * @param mixed $c True for associative rows, false for numeric rows, or null for kernel default.
 * @param mixed $d True when the SQL payload contains multiple statements or execution points.
 * @param mixed $e Cache policy override, cache key selector, or false to bypass cached reads.
 * @param mixed $f Cache invalidation flag, namespace list, or null for query policy.
 * @param mixed $g Queue name; "end" defers work to shutdown while null executes immediately.
 * @param mixed $h Optional callback invoked with the query result.
 * @return mixed Query rows, driver result, cached payload, callback return value, null queue status, or false on failure.
 */
function sql_query($a=null,$b=null,$c=null,$d=null,$e=null,$f=null, $g=null, $h=null){
	return dp_sql_retry_missing_table(fn()=>dataphyre\sql::query($a,$b,$c,$d,$e,$f,$g,$h), null);
}
/**
 * Performs insert-or-update mutations through the global SQL facade.
 *
 * Upsert payloads are delegated to dataphyre\sql::upsert(), which owns the DBMS-specific mutation strategy, update predicate, binding, cache invalidation, queueing, callback invocation, and observer traces. Missing table or column failures can hydrate a registered definition before one retry.
 *
 * @param mixed $a Table location resolved by dataphyre\sql::table().
 * @param mixed $b Associative insert field payload.
 * @param mixed $c Optional update predicate, conflict selector, or condition array accepted by the kernel.
 * @param mixed $d Bound values for placeholders contained in the update predicate.
 * @param mixed $e Cache invalidation flag, namespace list, or null for table policy.
 * @param mixed $f Queue name; "end" defers work to shutdown while null executes immediately.
 * @param mixed $g Optional callback invoked with the upsert result.
 * @return int|bool|null Affected row count, queue status, null, or false on failure.
 */
function sql_upsert($a=null,$b=null,$c=null,$d=null,$e=null,$f=null, $g=null){
	return dp_sql_retry_missing_table(fn()=>dataphyre\sql::upsert($a,$b,$c,$d,$e,$f,$g), $a);
}
/**
 * Runs a callback inside a SQL transaction on the selected cluster.
 *
 * The callback is executed between begin and commit using dataphyre\sql::transaction(); thrown exceptions cause rollback and a false return from the kernel. The helper does not expose nested transaction state, so callers should treat the callback as the unit of work and keep side effects idempotent until commit.
 *
 * @param mixed $a Transactional callback.
 * @param mixed $b Optional SQL cluster name used to choose the configured DBMS connection.
 * @return bool True when the callback commits, false when begin, callback, or commit handling fails.
 */
function sql_transaction($a=null,$b=null){ return dataphyre\sql::transaction($a,$b); }
/**
 * Begins a SQL transaction on the selected cluster.
 *
 * The underlying kernel maps the transaction start command to the configured DBMS and records the operation through normal SQL tracing. Callers must pass the same cluster to commit or rollback when they override the default connection.
 *
 * @param mixed $a Optional SQL cluster name used to choose the configured DBMS connection.
 * @return bool True when the driver accepts the transaction start command.
 */
function sql_begin($a=null){ return dataphyre\sql::begin($a); }
/**
 * Commits the active SQL transaction on the selected cluster.
 *
 * Commit is delegated to the SQL kernel so DBMS-specific SQL, tracing, and failure recording stay consistent with query execution. The helper returns a boolean status and does not inspect application-level mutation results.
 *
 * @param mixed $a Optional SQL cluster name used to choose the configured DBMS connection.
 * @return bool True when the driver accepts the commit command.
 */
function sql_commit($a=null){ return dataphyre\sql::commit($a); }
/**
 * Rolls back the active SQL transaction on the selected cluster.
 *
 * Rollback is routed through dataphyre\sql::rollback() so DBMS-specific command selection, tracing, and failure recording match the rest of the SQL kernel. The helper does not undo non-SQL side effects performed by the caller.
 *
 * @param mixed $a Optional SQL cluster name used to choose the configured DBMS connection.
 * @return bool True when the driver accepts the rollback command.
 */
function sql_rollback($a=null){ return dataphyre\sql::rollback($a); }
/**
 * Resolves a logical table location into the SQL kernel's physical table name.
 *
 * Locations may include a DBMS or cluster prefix before a colon; the SQL kernel separates that prefix through the output parameter and applies the configured default database location when the table name has no database qualifier. This helper is read-only and performs no schema lookup.
 *
 * @param mixed $a Logical or physical table location.
 * @param mixed $b Output parameter receiving the DBMS or cluster prefix when one is present.
 * @return string Physical table location used in generated SQL.
 */
function sql_table($a=null, $b=null){ return dataphyre\sql::table($a, $b); }
/**
 * Registers a table definition file for lazy schema hydration.
 *
 * The registry links a logical table location to a PHP definition file and optional definition id. Later read or write helpers can use that registry to create a missing table or column after the SQL kernel records a compatible driver failure.
 *
 * @param mixed $a Logical or physical table location.
 * @param mixed $b PHP file containing Dataphyre table definition declarations.
 * @param mixed $c Optional definition id used when a file contains multiple table definitions.
 * @return bool True when the definition registration is accepted.
 */
function sql_define_table($a=null, $b=null, $c=null){ return dataphyre\sql::define_table($a, $b, $c); }
/**
 * Loads the registered table definition object for a table location.
 *
 * Definitions describe table creation, column hydration, and schema metadata used by the SQL kernel's recovery path. A null return means no definition is registered, the definition file cannot produce the requested id, or the kernel cannot resolve the location.
 *
 * @param mixed $a Logical or physical table location.
 * @return ?\Dataphyre\Database\TableDefinition Registered table definition, or null when unavailable.
 */
function sql_table_definition($a=null){ return dataphyre\sql::table_definition($a); }
/**
 * Builds the schema descriptor for a registered SQL table location.
 *
 * The returned schema is derived from the table definition and can be consumed by higher-level repository or introspection code without executing ad hoc SQL. A null return indicates that no usable definition exists for the location.
 *
 * @param mixed $a Logical or physical table location.
 * @return ?\Dataphyre\Database\TableSchema Schema descriptor for the table, or null when no definition can provide one.
 */
function sql_table_schema($a=null){ return dataphyre\sql::table_schema($a); }

/**
 * Drains deferred SQL table definition callbacks registered during bootstrap.
 *
 * Modules may defer definition registration until the global SQL facade exists. This bootstrap hook invokes each callable exactly once, removes it from the global queue, and ignores non-callable entries so a malformed registration cannot block later definitions in the same queue.
 *
 * @return void The global deferred-definition queue is mutated in place.
 */
function dp_sql_run_deferred_table_definitions(): void {
	if(empty($GLOBALS['dataphyre_deferred_sql_table_definitions']) || is_array($GLOBALS['dataphyre_deferred_sql_table_definitions'])===false){
		return;
	}
	foreach($GLOBALS['dataphyre_deferred_sql_table_definitions'] as $key=>$callback){
		if(is_callable($callback)){
			$callback();
		}
		unset($GLOBALS['dataphyre_deferred_sql_table_definitions'][$key]);
	}
}

dp_sql_run_deferred_table_definitions();
