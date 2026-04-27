<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class RecordNotFoundException extends \RuntimeException {

	public function __construct(
		string $message,
		private readonly array $context=[]
	){
		parent::__construct($message);
	}

	public function context(): array {
		return $this->context;
	}
}
