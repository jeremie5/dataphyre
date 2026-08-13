<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Public audit DTO describing every nested-resource authorization and tenant scope. */
final class PanelQueryScopeManifest implements \JsonSerializable {
	/** @param list<array<string,mixed>> $checks */
	public function __construct(private readonly string $resource, private readonly array $checks=[]){ }
	public function resource(): string { return $this->resource; }
	/** @return list<array<string,mixed>> */ public function checks(): array { return $this->checks; }
	/** @return list<string> */ public function paths(): array { return array_values(array_unique(array_map(static fn(array $check): string=>(string)($check['path'] ?? ''), $this->checks))); }
	/** @return array{type:string,resource:string,allowed:bool,paths:list<string>,checks:list<array<string,mixed>>} */
	public function jsonSerialize(): array { return ['type'=>'panel_query_scope_manifest', 'resource'=>$this->resource, 'allowed'=>true, 'paths'=>$this->paths(), 'checks'=>$this->checks]; }
}
