<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Datadoc;

/** Immutable outcome of previewing or applying one static documentation release. */
final class DocumentationPortalWriteResult implements \JsonSerializable {
	public function __construct(
		private readonly string $root,
		private readonly string $target,
		private readonly bool $dryRun,
		private readonly bool $changed,
		private readonly int $fileCount,
	){}

	public function root():string { return $this->root; }
	public function target():string { return $this->target; }
	public function dryRun():bool { return $this->dryRun; }
	public function changed():bool { return $this->changed; }
	public function fileCount():int { return $this->fileCount; }

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return [
			'type'=>'dataphyre_datadoc_portal_write_result',
			'ok'=>true,
			'status'=>$this->dryRun?'preview':($this->changed?'applied':'unchanged'),
			'root'=>$this->root,
			'target'=>$this->target,
			'dry_run'=>$this->dryRun,
			'changed'=>$this->changed,
			'file_count'=>$this->fileCount,
		];
	}
}
