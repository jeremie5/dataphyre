<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access\Exceptions;

/**
 * Reports a failed OAuth state-token validation.
 *
 * This exception marks a security boundary failure during OAuth callback
 * handling, usually caused by a missing, expired, replayed, or mismatched state
 * value. Callers should abort the OAuth exchange rather than retry silently.
 */
final class InvalidOAuthStateException extends OAuthException {
}
