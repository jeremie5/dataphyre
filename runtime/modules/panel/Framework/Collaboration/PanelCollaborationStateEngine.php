<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Normalization, public-state redaction, and immutable receipt primitives. */
final class PanelCollaborationStateEngine {

	/** @return array<string,mixed> */
	public static function initialState(): array {
		return [
			'threads'=>[], 'comments'=>[], 'thread_comments'=>[], 'assignments'=>[],
			'watchers'=>[], 'subscriptions'=>[], 'presence'=>[], 'typing'=>[],
			'receipts'=>[], 'receipt_order'=>[], 'receipt_sequence'=>0, 'meta'=>[],
		];
	}

	public static function identifier(string|int $value, string $label='identifier', int $max=256): string {
		$value=trim((string)$value);
		if($value==='' || strlen($value)>$max || preg_match('/[\x00-\x1F\x7F]/', $value)===1){
			throw new \InvalidArgumentException('Panel collaboration '.$label.' is invalid.');
		}
		return $value;
	}

	public static function key(string $value, string $fallback='item'): string {
		$value=strtolower(trim($value));
		$value=trim(preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '', '.-_');
		return $value!=='' ? substr($value, 0, 128) : $fallback;
	}

	/** @return array{subject_type:string,subject_id:string,subject_key:string} */
	public static function subject(string $type, string|int $id): array {
		$type=self::key($type, 'record');
		$id=self::identifier($id, 'subject id');
		return ['subject_type'=>$type, 'subject_id'=>$id, 'subject_key'=>$type.':'.$id];
	}

	/** @param array<int,mixed> $mentions @return list<string> */
	public static function mentions(array $mentions, string $body=''): array {
		if($body!==''){
			preg_match_all('/(?<![\w@])@([a-zA-Z0-9_.:-]{1,128})/', $body, $matches);
			$mentions=array_merge($mentions, array_map(static fn(mixed $value): string => rtrim((string)$value, '.,;:!?'), $matches[1] ?? []));
		}
		$result=[];
		foreach($mentions as $mention){
			try { $mention=self::identifier((string)$mention, 'mention', 128); }
			catch(\InvalidArgumentException){ continue; }
			if(!in_array($mention, $result, true)){ $result[]=$mention; }
		}
		return $result;
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $subject @param array<string,mixed> $payload */
	public static function receipt(array &$state, string $action, ?string $actor, array $subject=[], array $payload=[]): PanelCollaborationReceipt {
		$action=self::key($action, 'activity');
		$actor=$actor!==null ? self::identifier($actor, 'actor') : null;
		$sequence=(int)($state['receipt_sequence'] ?? 0)+1;
		$previous='';
		$order=is_array($state['receipt_order'] ?? null) ? $state['receipt_order'] : [];
		if($order!==[]){
			$last=$state['receipts'][$order[array_key_last($order)]] ?? null;
			$previous=is_array($last) ? (string)($last['hash'] ?? '') : '';
		}
		$occurredAt=gmdate('c');
		$payload=self::sanitize($payload);
		$canonical=self::canonical($payload);
		$payloadHash=hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$basis=[
			'sequence'=>$sequence, 'action'=>$action, 'actor'=>$actor,
			'subject'=>self::sanitize($subject), 'occurred_at'=>$occurredAt,
			'payload_hash'=>$payloadHash, 'previous_hash'=>$previous,
		];
		$hash=hash('sha256', json_encode(self::canonical($basis), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		$record=$basis+[
			'id'=>sprintf('%012d-', $sequence).substr($hash, 0, 20),
			'hash'=>$hash,
			'payload'=>$payload,
		];
		$state['receipt_sequence']=$sequence;
		$state['receipts'][$record['id']]=$record;
		$state['receipt_order'][]=$record['id'];
		return new PanelCollaborationReceipt($record);
	}

	/** @param array<string,mixed> $state @return array{valid:bool,count:int,first_invalid:?string,head_hash:string} */
	public static function verifyReceipts(array $state): array {
		$previous=''; $count=0; $head='';
		foreach((array)($state['receipt_order'] ?? []) as $id){
			$receipt=$state['receipts'][$id] ?? null;
			if(!is_array($receipt)){ return ['valid'=>false, 'count'=>$count, 'first_invalid'=>(string)$id, 'head_hash'=>$head]; }
			$payloadHash=hash('sha256', json_encode(self::canonical(self::sanitize($receipt['payload'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
			$basis=[
				'sequence'=>(int)($receipt['sequence'] ?? 0), 'action'=>(string)($receipt['action'] ?? ''),
				'actor'=>$receipt['actor'] ?? null, 'subject'=>self::sanitize($receipt['subject'] ?? []),
				'occurred_at'=>(string)($receipt['occurred_at'] ?? ''), 'payload_hash'=>$payloadHash,
				'previous_hash'=>$previous,
			];
			$expected=hash('sha256', json_encode(self::canonical($basis), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
			if(!hash_equals($payloadHash, (string)($receipt['payload_hash'] ?? '')) || !hash_equals($previous, (string)($receipt['previous_hash'] ?? '')) || !hash_equals($expected, (string)($receipt['hash'] ?? ''))){
				return ['valid'=>false, 'count'=>$count, 'first_invalid'=>(string)$id, 'head_hash'=>$head];
			}
			$count++; $previous=$expected; $head=$expected;
		}
		return ['valid'=>true, 'count'=>$count, 'first_invalid'=>null, 'head_hash'=>$head];
	}

	/** @param array<string,mixed> $before @param array<string,mixed> $after */
	public static function assertReceiptAppendOnly(array $before, array $after): void {
		$beforeOrder=array_values((array)($before['receipt_order'] ?? []));
		$afterOrder=array_values((array)($after['receipt_order'] ?? []));
		if(array_slice($afterOrder, 0, count($beforeOrder))!==$beforeOrder || count($afterOrder)<count($beforeOrder)){
			throw new \LogicException('Collaboration receipt order is immutable and append-only.');
		}
		foreach($beforeOrder as $id){
			if(!isset($after['receipts'][$id]) || ($after['receipts'][$id] ?? null)!==($before['receipts'][$id] ?? null)){
				throw new \LogicException('Existing collaboration receipts cannot be changed or removed.');
			}
		}
		if((int)($after['receipt_sequence'] ?? 0)<(int)($before['receipt_sequence'] ?? 0)){
			throw new \LogicException('Collaboration receipt sequence cannot move backwards.');
		}
		$verification=self::verifyReceipts($after);
		if(($verification['valid'] ?? false)!==true){
			throw new \LogicException('Collaboration receipt chain failed integrity validation.');
		}
	}

	public static function sanitize(mixed $value, int $depth=0): mixed {
		if($depth>32){ return null; }
		if(is_array($value)){
			$result=[];
			foreach($value as $key=>$item){
				if(is_string($key) && preg_match('/(?:password|passwd|secret|token|cookie|authorization|private[_-]?key|credential|session[_-]?id|lease_hash)/i', $key)===1){ continue; }
				$result[$key]=self::sanitize($item, $depth+1);
			}
			return $result;
		}
		if(is_object($value)){
			if($value instanceof \JsonSerializable){ return self::sanitize($value->jsonSerialize(), $depth+1); }
			if($value instanceof \Stringable){ return (string)$value; }
			return null;
		}
		return is_scalar($value) || $value===null ? $value : null;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	public static function publicState(array $state): array {
		$state=self::sanitize($state);
		return is_array($state) ? $state : self::initialState();
	}

	private static function canonical(mixed $value): mixed {
		if(!is_array($value)){ return $value; }
		if(!array_is_list($value)){ ksort($value, SORT_STRING); }
		foreach($value as $key=>$item){ $value[$key]=self::canonical($item); }
		return $value;
	}
}
