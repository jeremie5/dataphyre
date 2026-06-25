<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission\Laravel;

use Dataphyre\Permission\Permission;

if(class_exists('\Illuminate\Support\ServiceProvider')){
	/**
	 * Bridges Dataphyre Permission into Laravel's service container and Gate.
	 *
	 * The provider is declared only when Laravel's ServiceProvider base class is
	 * available, allowing the same runtime tree to load outside Laravel. It binds
	 * the shared Dataphyre permission engine and installs a Gate::before hook so
	 * Dataphyre grants can allow abilities before Laravel policy resolution.
	 */
	class PermissionServiceProvider extends \Illuminate\Support\ServiceProvider {

		/**
		 * Registers the singleton permission engine used by Laravel integrations.
		 *
		 * The binding name is intentionally string-based so legacy Laravel
		 * consumers and container lookups can resolve the same PermissionEngine.
		 *
		 * @return void
		 */
		public function register(): void {
			$this->app->singleton('dataphyre.permission', static fn(): \Dataphyre\Permission\PermissionEngine => Permission::engine());
		}

		/**
		 * Installs Dataphyre authorization as an allow-only Laravel Gate precheck.
		 *
		 * Returning true grants the ability immediately. Returning null keeps
		 * Laravel's native policies in control, so Dataphyre never turns a miss
		 * into an explicit denial at this integration layer.
		 *
		 * @return void
		 */
		public function boot(): void {
			if(class_exists('\Illuminate\Support\Facades\Gate')){
				\Illuminate\Support\Facades\Gate::before(static function(mixed $user, string $ability, mixed $arguments=[]): ?bool {
					$context=['arguments'=>is_array($arguments) ? $arguments : [$arguments]];
					return Permission::check($ability, $user, $context) ? true : null;
				});
			}
		}
	}
}
