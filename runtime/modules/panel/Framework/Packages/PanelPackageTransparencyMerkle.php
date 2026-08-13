<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** RFC 6962-style SHA-256 Merkle primitives for package transparency. */
final class PanelPackageTransparencyMerkle {
	private function __construct(){}

	public static function leaf(mixed $value):string{return hash('sha256',"\x00".PanelOperationsGuard::json($value));}
	public static function node(string $left,string $right):string{return hash('sha256',"\x01".hex2bin(self::hash($left)).hex2bin(self::hash($right)));}
	/** @param list<string> $leaves */ public static function root(array $leaves):string {
		if(count($leaves)>1000000){throw new \LengthException('Transparency trees support at most 1000000 leaves.');}foreach($leaves as&$leaf){$leaf=self::hash($leaf);}unset($leaf);return self::subtreeRoot($leaves);
	}
	/** @param list<string> $leaves @return list<string> */ public static function inclusionProof(array $leaves,int $index):array {
		$count=count($leaves);if($count<1||$index<0||$index>=$count){throw new \OutOfBoundsException('Transparency inclusion index is outside the tree.');}foreach($leaves as&$leaf){$leaf=self::hash($leaf);}unset($leaf);return self::inclusion($leaves,$index);
	}
	/** @param list<string> $proof */ public static function verifyInclusion(string $leaf,int $index,int $size,array $proof,string $root):bool {
		try{$leaf=self::hash($leaf);$root=self::hash($root);if($size<1||$index<0||$index>=$size||count($proof)>64){return false;}foreach($proof as&$hash){$hash=self::hash($hash);}unset($hash);$cursor=0;$calculated=self::verifySubtree($leaf,$index,$size,$proof,$cursor);return$cursor===count($proof)&&hash_equals($root,$calculated);}catch(\Throwable){return false;}
	}
	/** @param list<string> $leaves @return list<string> */ public static function consistencyProof(array $leaves,int $oldSize,int $newSize=0):array {
		$newSize=$newSize===0?count($leaves):$newSize;if($oldSize<1||$newSize<$oldSize||$newSize>count($leaves)){throw new \OutOfBoundsException('Transparency consistency range is invalid.');}$leaves=array_slice($leaves,0,$newSize);foreach($leaves as&$leaf){$leaf=self::hash($leaf);}unset($leaf);if($oldSize===$newSize){return[];}return self::consistency($oldSize,$leaves,true);
	}
	/** @param list<string> $proof */ public static function verifyConsistency(int $oldSize,int $newSize,string $oldRoot,string $newRoot,array $proof):bool {
		try{$oldRoot=self::hash($oldRoot);$newRoot=self::hash($newRoot);if($oldSize<1||$newSize<$oldSize||count($proof)>64){return false;}foreach($proof as&$hash){$hash=self::hash($hash);}unset($hash);if($oldSize===$newSize){return$proof===[]&&hash_equals($oldRoot,$newRoot);}if($proof===[]){return false;}
			$fn=$oldSize-1;$sn=$newSize-1;while(($fn&1)===1){$fn>>=1;$sn>>=1;}
			$offset=0;if($fn===0){$first=$oldRoot;$second=$oldRoot;}else{$first=$proof[$offset];$second=$proof[$offset];$offset++;}
			for(;$offset<count($proof);$offset++){$hash=$proof[$offset];if($sn===0){return false;}if(($fn&1)===1||$fn===$sn){$first=self::node($hash,$first);$second=self::node($hash,$second);while(($fn&1)===0&&$fn!==0){$fn>>=1;$sn>>=1;}}else{$second=self::node($second,$hash);}$fn>>=1;$sn>>=1;}
			return$sn===0&&hash_equals($oldRoot,$first)&&hash_equals($newRoot,$second);
		}catch(\Throwable){return false;}
	}

	/** @param list<string> $leaves */ private static function subtreeRoot(array $leaves):string {$count=count($leaves);if($count===0){return hash('sha256','');}if($count===1){return$leaves[0];}$split=self::largestPowerBelow($count);return self::node(self::subtreeRoot(array_slice($leaves,0,$split)),self::subtreeRoot(array_slice($leaves,$split)));}
	/** @param list<string> $leaves @return list<string> */ private static function inclusion(array $leaves,int $index):array {$count=count($leaves);if($count===1){return[];}$split=self::largestPowerBelow($count);if($index<$split){return[...self::inclusion(array_slice($leaves,0,$split),$index),self::subtreeRoot(array_slice($leaves,$split))];}return[...self::inclusion(array_slice($leaves,$split),$index-$split),self::subtreeRoot(array_slice($leaves,0,$split))];}
	/** @param list<string> $proof */ private static function verifySubtree(string $leaf,int $index,int $size,array $proof,int &$cursor):string {if($size===1){return$leaf;}$split=self::largestPowerBelow($size);if($index<$split){$left=self::verifySubtree($leaf,$index,$split,$proof,$cursor);if(!isset($proof[$cursor])){throw new \UnexpectedValueException('Transparency inclusion proof is incomplete.');}$right=$proof[$cursor++];return self::node($left,$right);}$right=self::verifySubtree($leaf,$index-$split,$size-$split,$proof,$cursor);if(!isset($proof[$cursor])){throw new \UnexpectedValueException('Transparency inclusion proof is incomplete.');}$left=$proof[$cursor++];return self::node($left,$right);}
	/** @param list<string> $leaves @return list<string> */ private static function consistency(int $oldSize,array $leaves,bool $complete):array {$newSize=count($leaves);if($oldSize===$newSize){return$complete?[]:[self::subtreeRoot($leaves)];}$split=self::largestPowerBelow($newSize);if($oldSize<=$split){return[...self::consistency($oldSize,array_slice($leaves,0,$split),$complete),self::subtreeRoot(array_slice($leaves,$split))];}return[...self::consistency($oldSize-$split,array_slice($leaves,$split),false),self::subtreeRoot(array_slice($leaves,0,$split))];}
	private static function largestPowerBelow(int $value):int {$power=1;while(($power<<1)<$value){$power<<=1;}return$power;}
	private static function hash(string $hash):string {$hash=strtolower(trim($hash));if(preg_match('/^[a-f0-9]{64}$/D',$hash)!==1){throw new \InvalidArgumentException('Transparency hashes must be SHA-256 hex values.');}return$hash;}
}
