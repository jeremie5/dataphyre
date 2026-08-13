<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Result of checking policy obligations against trusted execution evidence. */
final class PanelCommandObligationResult implements \JsonSerializable {
	/** @param list<string> $reasons @param array<string,mixed> $evidence */
	public function __construct(
		private readonly bool $satisfied,
		private readonly array $reasons=[],
		private readonly array $evidence=[],
	){
		foreach($reasons as $reason){PanelOperationsGuard::label((string)$reason,'command obligation reason',512);}
		PanelOperationsGuard::safeMetadata($evidence,128);
	}

	public function satisfied():bool{return $this->satisfied;}
	/** @return list<string> */public function reasons():array{return array_values($this->reasons);}
	/** @return array<string,mixed> */public function evidence():array{return PanelOperationsGuard::safeMetadata($this->evidence,128);}
	public function jsonSerialize():array{return ['type'=>'panel_command_obligation_result','satisfied'=>$this->satisfied,'reasons'=>$this->reasons(),'evidence'=>$this->evidence()];}
}
