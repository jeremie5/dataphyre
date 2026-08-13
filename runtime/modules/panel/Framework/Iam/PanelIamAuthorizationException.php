<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Fail-closed denial of a privileged IAM control-plane mutation. */
final class PanelIamAuthorizationException extends \DomainException {}
