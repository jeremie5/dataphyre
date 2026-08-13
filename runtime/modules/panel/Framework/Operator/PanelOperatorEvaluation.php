<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** One named deterministic or host-supplied proposal evaluation. */
final class PanelOperatorEvaluation implements \JsonSerializable {
	/** @param array<string,mixed> $evidence */ public function __construct(private readonly string $name,private readonly bool $passed,private readonly string $message,private readonly array $evidence=[]){PanelOperationsGuard::name($name,'operator evaluator name');PanelOperationsGuard::label($message,'operator evaluation message',2048);PanelOperationsGuard::safeMetadata($evidence,256);}
	public static function pass(string $name,string $message='Evaluation passed.',array $evidence=[]):self{return new self($name,true,$message,$evidence);}public static function fail(string $name,string $message='Evaluation failed.',array $evidence=[]):self{return new self($name,false,$message,$evidence);}
	public function name():string{return$this->name;}public function passed():bool{return$this->passed;}public function message():string{return$this->message;}/** @return array<string,mixed> */public function evidence():array{return$this->evidence;}
	public function jsonSerialize():array{return['name'=>$this->name,'passed'=>$this->passed,'message'=>$this->message,'evidence'=>$this->evidence];}
}
