<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Logarithmic proof that one signed tree head is an append-only prefix of another. */
final class PanelPackageTransparencyConsistencyProof implements \JsonSerializable {
	/** @param list<string> $hashes */ public function __construct(private readonly int $oldSize,private readonly int $newSize,private readonly string $oldRoot,private readonly string $newRoot,private readonly array $hashes){if($oldSize<1||$newSize<$oldSize||$newSize>1000000||preg_match('/^[a-f0-9]{64}$/D',$oldRoot)!==1||preg_match('/^[a-f0-9]{64}$/D',$newRoot)!==1||count($hashes)>64){throw new \InvalidArgumentException('Transparency consistency proof is invalid.');}foreach($hashes as$hash){if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1){throw new \InvalidArgumentException('Transparency consistency proof hash is invalid.');}}}
	/** @param list<string> $leaves */ public static function build(array $leaves,int $oldSize,int $newSize=0):self {$newSize=$newSize===0?count($leaves):$newSize;$oldRoot=PanelPackageTransparencyMerkle::root(array_slice($leaves,0,$oldSize));$newRoot=PanelPackageTransparencyMerkle::root(array_slice($leaves,0,$newSize));return new self($oldSize,$newSize,$oldRoot,$newRoot,PanelPackageTransparencyMerkle::consistencyProof($leaves,$oldSize,$newSize));}
	/** @param array<string,mixed> $manifest */ public static function fromArray(array $manifest):self{return new self((int)($manifest['old_size']??0),(int)($manifest['new_size']??0),(string)($manifest['old_root']??''),(string)($manifest['new_root']??''),is_array($manifest['hashes']??null)?array_values($manifest['hashes']):[]);}
	public function verify():bool{return PanelPackageTransparencyMerkle::verifyConsistency($this->oldSize,$this->newSize,$this->oldRoot,$this->newRoot,$this->hashes);}public function oldSize():int{return$this->oldSize;}public function newSize():int{return$this->newSize;}public function oldRoot():string{return$this->oldRoot;}public function newRoot():string{return$this->newRoot;}
	/** @return array<string,mixed> */public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_package_transparency_consistency_proof','version'=>1,'old_size'=>$this->oldSize,'new_size'=>$this->newSize,'old_root'=>$this->oldRoot,'new_root'=>$this->newRoot,'hashes'=>$this->hashes,'verified'=>$this->verify()]);}
}
