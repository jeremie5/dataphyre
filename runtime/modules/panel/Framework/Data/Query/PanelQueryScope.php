<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Scoped expression plus its authorization/tenant audit manifest. */
final class PanelQueryScope implements \JsonSerializable {
	public function __construct(private readonly ?PanelQueryExpression $expression, private readonly PanelQueryScopeManifest $manifest){}
	public function expression(): ?PanelQueryExpression { return $this->expression; }
	public function manifest(): PanelQueryScopeManifest { return $this->manifest; }
	/** @return array{type:string,expression:?array<string,mixed>,manifest:array<string,mixed>} */
	public function jsonSerialize(): array { return ['type'=>'panel_query_scope', 'expression'=>$this->expression?->jsonSerialize(), 'manifest'=>$this->manifest->jsonSerialize()]; }
}
