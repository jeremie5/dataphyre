<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Secret-free operational failure emitted by infrastructure-backed media disks. */
final class PanelMediaStorageException extends \RuntimeException implements \JsonSerializable {
	public function __construct(
		private readonly string $operation,
		private readonly string $reason='backend_failure',
		private readonly bool $retryable=true,
		?\Throwable $previous=null
	) {
		if(Resource::normalizeName($operation)!==trim($operation) || trim($operation)===''){
			throw new \InvalidArgumentException('Media storage operations must be canonical names.');
		}
		if(Resource::normalizeName($reason)!==trim($reason) || trim($reason)===''){
			throw new \InvalidArgumentException('Media storage failure reasons must be canonical names.');
		}
		parent::__construct("Panel media storage {$operation} failed ({$reason}).", 0, $previous);
	}

	public function operation(): string {return $this->operation;}
	public function reason(): string {return $this->reason;}
	public function retryable(): bool {return $this->retryable;}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_media_storage_exception',
			'operation'=>$this->operation,
			'reason'=>$this->reason,
			'retryable'=>$this->retryable,
			'provider_message_serialized'=>false,
			'path_serialized'=>false,
			'credentials_serialized'=>false,
		];
	}
}
