<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Migration-aware structural difference between two compiled domains. */
final class PanelDomainDiff implements \JsonSerializable {
	/** @param array<string,array{added:list<string>,removed:list<string>,changed:list<string>}> $sections @param list<array<string,mixed>> $migrationSteps */
	public function __construct(
		private readonly string $fromDigest,
		private readonly string $toDigest,
		private readonly array $sections,
		private readonly array $migrationSteps,
		private readonly bool $breaking,
	){foreach([$fromDigest,$toDigest]as$digest){if(preg_match('/^[a-f0-9]{64}$/D',$digest)!==1){throw new \InvalidArgumentException('Domain diff digest is invalid.');}}}

	public function changed():bool{return!hash_equals($this->fromDigest,$this->toDigest);}
	public function breaking():bool{return$this->breaking;}
	/** @return array<string,array{added:list<string>,removed:list<string>,changed:list<string>}> */ public function sections():array{return$this->sections;}
	/** @return list<array<string,mixed>> */ public function migrationSteps():array{return$this->migrationSteps;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_domain_diff_manifest','version'=>1,'from_digest'=>$this->fromDigest,'to_digest'=>$this->toDigest,'changed'=>$this->changed(),'breaking'=>$this->breaking,'sections'=>$this->sections,'migration_steps'=>$this->migrationSteps]);}
}
