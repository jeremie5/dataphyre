<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Completed mutation batch with one ordered receipt per requested command. */
final class PanelDataMutationBatchResult implements \JsonSerializable {
	/** @var list<PanelDataMutationReceipt> */private readonly array $receipts;
	/** @param list<PanelDataMutationReceipt> $receipts */
	public function __construct(private readonly PanelDataMutationBatch $batch,array $receipts,private readonly string $source){
		if(count($receipts)!==$batch->count()||!array_is_list($receipts)){throw new \InvalidArgumentException('Panel data-mutation batch results require one ordered receipt per mutation.');}
		foreach($receipts as$index=>$receipt){if(!$receipt instanceof PanelDataMutationReceipt||$receipt->source()!==$source||!hash_equals($batch->mutations()[$index]->fingerprint(),$receipt->mutationFingerprint())){throw new \InvalidArgumentException('Panel data-mutation batch receipt does not match its request.');}}
		$this->receipts=$receipts;
	}
	public function batch():PanelDataMutationBatch{return$this->batch;}
	/** @return list<PanelDataMutationReceipt> */public function receipts():array{return$this->receipts;}
	public function source():string{return$this->source;}
	public function count():int{return count($this->receipts);}
	public function replayed():bool{foreach($this->receipts as$receipt){if(!$receipt->replayed()){return false;}}return true;}
	public function jsonSerialize():array{return['type'=>'panel_data_mutation_batch_result','version'=>1,'source'=>$this->source,'atomic'=>$this->batch->atomic(),'count'=>$this->count(),'replayed'=>$this->replayed(),'batch_fingerprint'=>$this->batch->fingerprint(),'receipts'=>array_map(static fn(PanelDataMutationReceipt $receipt):array=>$receipt->jsonSerialize(),$this->receipts)];}
}
