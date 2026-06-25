<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Exceptions;

/**
 * Base exception for OAuth provider and callback failures.
 *
 * OAuth-specific exceptions use this type to separate external identity-provider
 * exchange errors from local session, guard, and policy failures.
 */
class OAuthException extends \RuntimeException {
}
