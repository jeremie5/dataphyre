<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Immutable discovery result stored behind one content fingerprint. */
final class CaseDiscoveryCacheEntry {
	/** @var list<array<string,mixed>> */
	private array $cases;

	/** @param list<array<string,mixed>> $cases */
	public function __construct(private string $fingerprint, array $cases) {
		if(trim($fingerprint)===''){
			throw new \InvalidArgumentException('Case discovery cache fingerprints cannot be blank.');
		}
		foreach($cases as $case){
			if(!is_array($case)){
				throw new \InvalidArgumentException('Case discovery cache entries must contain case maps.');
			}
		}
		$this->cases=array_values($cases);
	}

	public function fingerprint(): string { return $this->fingerprint; }

	/** @return list<array<string,mixed>> */
	public function cases(): array { return $this->cases; }
}
