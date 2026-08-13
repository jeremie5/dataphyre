<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Fail-closed mutation authorization failure. */
final class PanelDataMutationAccessDenied extends PanelDataMutationException {
	public function __construct(string $code='mutation_denied',string $message='The data mutation is not authorized.',?\Throwable $previous=null){ parent::__construct($code,$message,403,false,$previous); }
}
