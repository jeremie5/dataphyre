<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Reports transaction lifecycle failures from the database layer.
 *
 * The exception is used when beginning, committing, rolling back, or nesting a
 * transaction cannot complete safely. It carries the RuntimeException contract so
 * callers can distinguish transaction failures from validation or query-shape
 * errors.
 */
final class TransactionException extends \RuntimeException {}
