<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Permission/tenant failure while scoping an explicit nested-resource query. */
final class PanelQueryScopeException extends \DomainException implements \JsonSerializable {
	/** @param array<string,mixed> $context */
	public function __construct(private readonly string $codeName, string $message, private readonly array $context=[]) { parent::__construct($message); }
	public function codeName(): string { return $this->codeName; }
	/** @return array<string,mixed> */ public function context(): array { return $this->context; }
	/** @return array{type:string,code:string,message:string,context:array<string,mixed>} */
	public function jsonSerialize(): array { return ['type'=>'panel_query_scope_error', 'code'=>$this->codeName, 'message'=>$this->getMessage(), 'context'=>$this->context]; }
}
