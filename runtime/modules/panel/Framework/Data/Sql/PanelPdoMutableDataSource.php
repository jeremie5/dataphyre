<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Discoverable PDO facade over the durable SQL mutation adapter. */
final class PanelPdoMutableDataSource implements PanelMutableDataSource,\JsonSerializable {
	private readonly PanelSqlMutableDataSource $source;
	/** @param array<string,mixed> $options */public function __construct(\PDO $pdo,PanelSqlMutationSchema $schema,array $options=[]){$this->source=PanelSqlMutableDataSource::usingPdo($pdo,$schema,$options);}
	public function query(PanelDataQuery $query):PanelDataResult{return$this->source->query($query);}
	public function find(string|int $id,?PanelDataQuery $scope=null):mixed{return$this->source->find($id,$scope);}
	public function mutate(PanelDataMutation $mutation):PanelDataMutationReceipt{return$this->source->mutate($mutation);}
	public function mutateBatch(PanelDataMutationBatch $batch):PanelDataMutationBatchResult{return$this->source->mutateBatch($batch);}
	/** @return array<string,mixed> */public function capabilities():array{return$this->source->capabilities()+['pdo'=>true];}
	/** @return array<string,mixed> */public function manifest():array{return$this->source->manifest()+['facade'=>'pdo_mutable'];}
	/** @return array<string,mixed> */public function installMutationSchema():array{return$this->source->installMutationSchema();}
	/** @return array<string,mixed> */public function inspectMutationSchema():array{return$this->source->inspectMutationSchema();}
	/** @return array<string,mixed> */public function jsonSerialize():array{return$this->manifest();}
	public function source():PanelSqlMutableDataSource{return$this->source;}
}
