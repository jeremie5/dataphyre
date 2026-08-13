<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Framework-neutral transport/lifecycle adapter for interactive widgets.
 *
 * Implementations own component resolution, authorization, snapshots, and
 * persistence. Panel never receives or selects an implementation class from a
 * browser payload; the instance registry resolves a server-registered alias.
 */
interface PanelWidgetRuntimeAdapter {
	public function name(): string;
	public function contractVersion(): int;
	public function handle(PanelWidgetInteractionDefinition $definition, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult;
	/** @return array<string,mixed> Public capability/health description without retained state or secrets. */
	public function manifest(): array;
	/** Clears ephemeral lifecycle state while preserving registered component handlers. */
	public function reset(): void;
}
