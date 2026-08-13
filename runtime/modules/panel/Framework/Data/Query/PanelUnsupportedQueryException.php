<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Fail-closed adapter capability mismatch with a machine-readable manifest. */
final class PanelUnsupportedQueryException extends \DomainException implements \JsonSerializable {
	/** @param list<string> $unsupported @param array<string,mixed> $capabilities */
	public function __construct(private readonly array $unsupported, private readonly array $capabilities, string $message='Panel data source does not support the requested query.') { parent::__construct($message); }
	/** @return list<string> */ public function unsupported(): array { return $this->unsupported; }
	/** @return array<string,mixed> */ public function capabilities(): array { return $this->capabilities; }
	/** @return array{type:string,message:string,unsupported:list<string>,capabilities:array<string,mixed>} */
	public function jsonSerialize(): array { return ['type'=>'panel_unsupported_query', 'message'=>$this->getMessage(), 'unsupported'=>$this->unsupported, 'capabilities'=>$this->capabilities]; }
}
