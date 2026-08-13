<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Requested write guarantee is not implemented by the selected adapter. */
final class PanelDataMutationUnsupported extends PanelDataMutationException {
	/** @param list<string> $features */
	public function __construct(private readonly array $features,string $message='The selected data source does not support this mutation.'){ parent::__construct('mutation_unsupported',$message,422,false); }
	/** @return list<string> */ public function features():array{return$this->features;}
	public function jsonSerialize():array{return parent::jsonSerialize()+['features'=>$this->features];}
}
