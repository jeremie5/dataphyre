<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Contracts;

interface UserProvider {

	public function retrieveById(int|string $identifier): mixed;

	public function retrieveByCredentials(array $credentials): mixed;

	public function validateCredentials(mixed $user, array $credentials): bool;

	public function authIdentifier(mixed $user): int|string|null;
}
