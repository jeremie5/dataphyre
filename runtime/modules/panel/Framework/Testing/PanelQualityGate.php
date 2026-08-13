<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Aggregates server accessibility, contract, asset, and localization gates. */
final class PanelQualityGate implements \JsonSerializable {
	private array $checks=[];
	private function __construct(PanelPageResult|string $result, array $options=[]) {
		$html=$result instanceof PanelPageResult ? $result->content() : $result;
		$accessibility=PanelAccessibilityAudit::from($result,['gate'=>'panel_quality']);
		$this->checks[]=['name'=>'accessibility','passed'=>$accessibility->passed(),'details'=>$accessibility->toArray()];
		$this->checks[]=['name'=>'positive_tabindex','passed'=>preg_match('/tabindex\s*=\s*["\']?[1-9]/i',$html)!==1,'details'=>[]];
		$this->checks[]=['name'=>'inline_fixed_width','passed'=>preg_match('/style\s*=\s*["\'][^"\']*(?:width|min-width)\s*:\s*[0-9]{4,}px/i',$html)!==1,'details'=>[]];
		$this->checks[]=['name'=>'unsafe_scheme','passed'=>preg_match('/(?:href|src|action)\s*=\s*["\']\s*(?:javascript|data):/i',$html)!==1,'details'=>[]];
		$this->checks[]=['name'=>'unbounded_autofocus','passed'=>preg_match('/\sautofocus(?:\s|>|=)/i',$html)!==1,'details'=>[]];
		if(($options['require_language']??false)===true){ $this->checks[]=['name'=>'document_language','passed'=>preg_match('/<html\b[^>]*\blang\s*=/i',$html)===1,'details'=>[]]; }
		if(($options['require_direction']??false)===true){ $this->checks[]=['name'=>'document_direction','passed'=>preg_match('/<(?:html|body)\b[^>]*\bdir\s*=\s*["\'](?:ltr|rtl)["\']/i',$html)===1,'details'=>[]]; }
		foreach(is_array($options['custom']??null)?$options['custom']:[] as $name=>$check){ if(is_callable($check)){ try{$result=$check($html,$result);$this->checks[]=['name'=>(string)$name,'passed'=>$result===true,'details'=>is_array($result)?$result:[]];}catch(\Throwable $exception){$this->checks[]=['name'=>(string)$name,'passed'=>false,'details'=>['error'=>$exception->getMessage()]];} } }
	}
	public static function from(PanelPageResult|string $result,array $options=[]): self { return new self($result,$options); }
	public function passed(): bool { return !array_filter($this->checks,static fn(array $check):bool=>$check['passed']!==true); }
	public function failures(): array { return array_values(array_filter($this->checks,static fn(array $check):bool=>$check['passed']!==true)); }
	public function jsonSerialize(): array { return ['type'=>'panel_quality_gate','passed'=>$this->passed(),'checks'=>$this->checks,'failures'=>$this->failures()]; }
}
