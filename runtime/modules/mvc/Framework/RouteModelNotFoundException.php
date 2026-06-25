<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Captures a failed MVC route-model binding lookup.
 *
 * The exception preserves the model class, route parameter name, submitted value,
 * and lookup key so dispatchers, exception renderers, and tests can explain why
 * implicit model binding produced a 404-style failure.
 */
final class RouteModelNotFoundException extends \RuntimeException {

	/**
	 * Creates a route-model binding failure.
	 *
	 * @param string $modelClass Fully qualified model class that could not be resolved.
	 * @param string $parameter Route parameter name that supplied the value.
	 * @param mixed $value Submitted route parameter value.
	 * @param string $key Model lookup key, usually `id`.
	 */
	public function __construct(
		private string $modelClass,
		private string $parameter,
		private mixed $value,
		private string $key='id'
	){
		parent::__construct("MVC route model binding failed for {$this->modelClass} using parameter '{$this->parameter}'.");
	}

	/**
	 * Returns the model class that failed to resolve.
	 *
	 * @return string Fully qualified model class name.
	 */
	public function modelClass(): string {
		return $this->modelClass;
	}

	/**
	 * Returns the route parameter name used for binding.
	 *
	 * @return string Route parameter name.
	 */
	public function parameter(): string {
		return $this->parameter;
	}

	/**
	 * Returns the raw route value that failed lookup.
	 *
	 * @return mixed raw route value that could not be resolved to the requested model.
	 */
	public function value(): mixed {
		return $this->value;
	}

	/**
	 * Returns the model lookup key used during binding.
	 *
	 * @return string Lookup key, usually `id`.
	 */
	public function key(): string {
		return $this->key;
	}
}
