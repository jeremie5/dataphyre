<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Negotiated semantic capability is unavailable on the selected backend. */
final class PanelSemanticUnsupported extends PanelSemanticException {
	/** @param list<string> $features */public function __construct(private readonly array $features,string $message='The semantic backend does not support this query.'){parent::__construct('semantic_unsupported',$message,422,false);}
	/** @return list<string> */public function features():array{return$this->features;}/** @return array<string,mixed> */public function jsonSerialize():array{return parent::jsonSerialize()+['features'=>$this->features];}
}
