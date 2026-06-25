<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Permission\Exceptions;

/**
 * Carries denied permission requirements and authorization context.
 *
 * Permission callers throw this exception when a guard or policy check needs to
 * preserve the missing permission set for HTTP, logging, or Panel rendering
 * layers. The default status code is 403 to match authorization failures.
 */
final class AuthorizationException extends \RuntimeException {

	/**
	 * Creates an authorization failure with structured denial metadata.
	 *
	 * @param string $message Human-readable denial message.
	 * @param array<int|string,mixed> $permissions Permission names, groups, or requirement descriptors that failed.
	 * @param array<string,mixed> $context Actor, resource, guard, or policy context useful to callers.
	 * @param int $code Exception code, normally the HTTP status code 403.
	 * @param ?\Throwable $previous Previous exception for chained failures.
	 */
	public function __construct(
		string $message='Permission denied.',
		private readonly array $permissions=[],
		private readonly array $context=[],
		int $code=403,
		?\Throwable $previous=null
	){
		parent::__construct($message, $code, $previous);
	}

	/**
	 * Returns the permission requirements that caused the denial.
	 *
	 * @return array<int|string,mixed> Permission names, groups, or requirement descriptors supplied at construction.
	 */
	public function permissions(): array {
		return $this->permissions;
	}

	/**
	 * Returns contextual authorization metadata captured with the denial.
	 *
	 * @return array<string,mixed> Actor, resource, guard, or policy context supplied at construction.
	 */
	public function context(): array {
		return $this->context;
	}
}
