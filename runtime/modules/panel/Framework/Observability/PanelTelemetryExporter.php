<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Vendor-neutral sink for immutable, already-sanitized Panel telemetry signals. */
interface PanelTelemetryExporter {
	public function export(PanelTelemetrySignal $signal):void;
	public function flush():void;
	/** @return array<string,mixed> Secret-free exporter capabilities and health counters. */
	public function manifest():array;
}
