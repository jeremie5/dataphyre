<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Async\Contracts;

interface Dispatcher {

	public function dispatch(mixed $task, array $arguments=[]): \dataphyre\async\promise;
}
