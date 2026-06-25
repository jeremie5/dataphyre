<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Carries the result of resolving a template binding.
 *
 * Bindings sometimes intentionally skip rendering while still preserving a diagnostic or
 * fallback value. This immutable value object keeps that distinction explicit for template
 * renderers and extension points.
 */
final class BindingResolution {

	/**
	 * Stores the immutable binding result state.
	 *
	 * @param mixed $value Resolved value or fallback value associated with a skipped binding.
	 * @param bool $skipped Whether the binding chose not to render normally.
	 */
	private function __construct(
		private readonly mixed $value,
		private readonly bool $skipped=false
	){}

	/**
	 * Creates a successful binding resolution.
	 *
	 * @param mixed $value Resolved binding value.
	 * @return self Resolution marked as renderable.
	 */
	public static function value(mixed $value): self {
		return new self($value, false);
	}

	/**
	 * Creates a binding resolution that should be skipped by the renderer.
	 *
	 * @param mixed $value Optional fallback or diagnostic value associated with the skipped binding.
	 * @return self Resolution marked as skipped.
	 */
	public static function skipped(mixed $value=null): self {
		return new self($value, true);
	}

	/**
	 * Returns the stored binding value.
	 *
	 * @return mixed renderable binding value, or fallback/diagnostic value attached to a skipped binding.
	 */
	public function result(): mixed {
		return $this->value;
	}

	/**
	 * Reports whether the renderer should skip this binding.
	 *
	 * @return bool `true` when the binding intentionally skipped normal rendering.
	 */
	public function isSkipped(): bool {
		return $this->skipped;
	}
}
