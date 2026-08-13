<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Redacted result of independently verifying a signed release-evidence pack. */
final class PanelReleaseEvidenceVerification implements \JsonSerializable {
	/** @param list<string> $failures */
	public function __construct(
		private readonly string $runId,
		private readonly string $bundleDigest,
		private readonly int $artifactCount,
		private readonly int $verifiedArtifacts,
		private readonly array $failures
	) {}

	public function passed():bool {return $this->failures===[]&&$this->artifactCount===$this->verifiedArtifacts;}
	/** @return list<string> */ public function failures():array {return $this->failures;}
	public function bundleDigest():string {return $this->bundleDigest;}
	public function replayKey():string {return hash('sha256','dataphyre.panel.release-evidence.replay.v1' . "\0" . $this->runId . "\0" . $this->bundleDigest);}
	public function assertPassed():void {if(!$this->passed()){throw new \UnexpectedValueException('Panel release evidence verification failed: '.implode(', ',$this->failures).'.');}}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return ['type'=>'panel_release_evidence_verification','version'=>1,'passed'=>$this->passed(),'bundle_digest'=>$this->bundleDigest,'replay_key'=>$this->replayKey(),'artifact_count'=>$this->artifactCount,'verified_artifacts'=>$this->verifiedArtifacts,'failures'=>$this->failures];
	}
}
