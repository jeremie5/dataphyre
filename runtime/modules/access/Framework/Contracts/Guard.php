<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Contracts;

use Dataphyre\Access\AuthContext;

interface Guard {

	public function name(): string;

	public function authType(): string;

	public function check(): bool;

	public function guest(): bool;

	public function id(): int|string|null;

	public function user(): mixed;

	public function context(): AuthContext;

	public function validate(bool $cache=true): bool;

	public function recover(): bool;

	public function login(mixed $user, bool $remember=false): bool;

	public function loginUsingId(int|string $identifier, bool $remember=false): bool;

	public function attempt(array $credentials, bool $remember=false): bool;

	public function logout(): bool;
}
