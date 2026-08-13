<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Explainable authorization result suitable for users, tests, logs, and agents. */
final class PanelSecurityDecision implements \JsonSerializable {
	public function __construct(
		private readonly bool $allowed,
		private readonly string $ability,
		private readonly array $reasons=[],
		private readonly array $requirements=[],
		private readonly array $evidence=[]
	){}
	public function allowed(): bool { return $this->allowed; }
	public function denied(): bool { return !$this->allowed; }
	public function reasons(): array { return $this->reasons; }
	public function requirements(): array { return $this->requirements; }
	public function jsonSerialize(): array { return ['allowed'=>$this->allowed, 'ability'=>$this->ability, 'reasons'=>$this->reasons, 'requirements'=>$this->requirements, 'evidence'=>$this->evidence]; }
}
