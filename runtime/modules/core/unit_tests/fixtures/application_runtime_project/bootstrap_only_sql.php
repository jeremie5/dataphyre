<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace dataphyre;

if(!\class_exists(__NAMESPACE__.'\\sql',false)){
	final class sql {
		public static function registered_table_definitions(): array {
			if(\function_exists('dp_sql_run_deferred_table_definitions')){
				\dp_sql_run_deferred_table_definitions();
			}elseif(\is_array($GLOBALS['dataphyre_deferred_sql_table_definitions'] ?? null)){ // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial bootstrap fixture must consume the legacy deferred SQL registry published by tenant bootstrap."
				foreach($GLOBALS['dataphyre_deferred_sql_table_definitions'] as $key=>$callback){ // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial bootstrap fixture must execute the exact deferred SQL callbacks under test."
					if(\is_callable($callback)) $callback();
					unset($GLOBALS['dataphyre_deferred_sql_table_definitions'][$key]); // dataphyre-test-architecture: exempt[raw-global-variable] reason="Adversarial bootstrap fixture removes each consumed legacy callback to preserve one-shot semantics."
				}
			}
			return ['fixture.bootstrap_alpha','fixture.bootstrap_beta'];
		}
		public static function materializable_table_definitions(): array {
			return self::registered_table_definitions();
		}
		public static function hydrate_table_definition(string $table): bool {
			$context=\Dataphyre\InternalApplicationBootstrapOnly::context();
			return ($context['purpose'] ?? null)===\Dataphyre\InternalApplicationBootstrapOnly::MATERIALIZER
				&& \in_array($table,self::registered_table_definitions(),true);
		}
	}
}
