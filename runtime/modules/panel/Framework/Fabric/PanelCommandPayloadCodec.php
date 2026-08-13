<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Encryption boundary for durable command inputs and idempotency material. */
interface PanelCommandPayloadCodec {/** @param array<string,mixed> $payload @return array<string,mixed> */public function seal(array $payload,string $context):array;/** @param array<string,mixed> $sealed @return array<string,mixed> */public function open(array $sealed,string $context):array;}
