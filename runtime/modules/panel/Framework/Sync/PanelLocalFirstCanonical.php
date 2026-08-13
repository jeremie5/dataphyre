<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Cross-language canonical digest model using exact IEEE-754 bytes for browser numbers. */
final class PanelLocalFirstCanonical {
	private function __construct(){}
	public static function digest(mixed $value):string{return hash('sha256',PanelOperationsGuard::json(self::value($value)));}
	public static function value(mixed $value,int $depth=0):mixed{if($depth>32){throw new \LengthException('Local-first canonical value exceeds the maximum depth.');}if(is_int($value)){return['@panel_number_f64'=>bin2hex(pack('E',(float)$value))];}if(is_float($value)){if(!is_finite($value)||is_nan($value)){throw new \InvalidArgumentException('Local-first numbers must be finite.');}$value=$value==0.0?0.0:$value;return['@panel_number_f64'=>bin2hex(pack('E',$value))];}if(is_array($value)){if(count($value)>4096){throw new \LengthException('Local-first canonical value exceeds the maximum item count.');}$result=[];foreach($value as$key=>$item){if(!is_int($key)&&!is_string($key)){throw new \InvalidArgumentException('Local-first canonical keys are invalid.');}$result[$key]=self::value($item,$depth+1);}if(!array_is_list($result)){ksort($result,SORT_STRING);}return$result;}if(is_string($value)){if(strlen($value)>262144){throw new \LengthException('Local-first canonical string exceeds its byte limit.');}return$value;}if(is_bool($value)||$value===null){return$value;}throw new \InvalidArgumentException('Local-first values must be JSON-compatible.');}
}
