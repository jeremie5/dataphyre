<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Discoverable PDO facade over the executor-neutral production SQL adapter. */
final class PanelPdoDataSource implements PanelDataSource, \JsonSerializable {
	private readonly PanelSqlDataSource $source;

	/** @param array<string,mixed> $options */
	public function __construct(\PDO $pdo, PanelSqlSchema $schema, array $options=[]) {
		$this->source=PanelSqlDataSource::usingPdo($pdo, $schema, $options);
	}

	public function query(PanelDataQuery $query): PanelDataResult { return $this->source->query($query); }
	public function find(string|int $id, ?PanelDataQuery $scope=null): mixed { return $this->source->find($id, $scope); }
	/** @return array<string,mixed> */ public function capabilities(): array { return $this->source->capabilities()+['pdo'=>true]; }
	/** @return array<string,mixed> */ public function manifest(): array { return $this->source->manifest()+['facade'=>'pdo']; }
	/** @return array<string,mixed> */ public function jsonSerialize(): array { return $this->manifest(); }
	public function source(): PanelSqlDataSource { return $this->source; }
}
