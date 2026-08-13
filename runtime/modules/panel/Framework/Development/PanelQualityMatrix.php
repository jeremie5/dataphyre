<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Generates browser plans across layout, input, locale, contrast, and network axes. */
final class PanelQualityMatrix implements \JsonSerializable {
	private const MAX_AXES=16;
	private const MAX_VALUES_PER_AXIS=64;
	private const MAX_VALUE_DEPTH=8;
	private const MAX_VALUE_ITEMS=128;
	private array $axes;
	private int $maximumCases=5000;
	private readonly string $name;
	private readonly string $url;
	public function __construct(string $name, string $url, array $axes=[]) {
		$this->name=Resource::normalizeName($name) ?: 'quality_matrix';
		$this->url=PanelBrowserRegressionManifest::normalizeUrl($url);
		$axes=array_replace(self::defaults(), $axes);
		if(count($axes)>self::MAX_AXES){ throw new \LengthException('Panel quality matrices support at most sixteen axes.'); }
		$this->axes=[];
		foreach($axes as $axis=>$values){
			$name=Resource::normalizeName((string)$axis);
			if($name==='' || isset($this->axes[$name])){ throw new \InvalidArgumentException('Panel quality matrix axis names must be unique and stable.'); }
			$values=is_array($values) && array_is_list($values) ? array_values($values) : [$values];
			if($values===[]){ throw new \InvalidArgumentException("Panel quality matrix axis '{$name}' cannot be empty."); }
			if(count($values)>self::MAX_VALUES_PER_AXIS){ throw new \LengthException("Panel quality matrix axis '{$name}' exceeds its value budget."); }
			foreach($values as $value){ self::assertValue($value); }
			$this->axes[$name]=$values;
		}
	}
	public static function make(string $name, string $url, array $axes=[]): self { return new self($name, $url, $axes); }
	/** Builds the bounded locale, input-method, and assistive-technology matrix. */
	public static function inclusive(string $name,string $url,array $options=[]): PanelInclusiveQualityMatrix { return PanelInclusiveQualityMatrix::make($name,$url,$options); }
	public function maximumCases(int $maximum): self { $clone=clone $this; $clone->maximumCases=max(1, min(25000, $maximum)); return $clone; }
	/** @return list<PanelBrowserRegressionManifest> */
	public function manifests(): array {
		$cases=[[]];
		foreach($this->axes as $axis=>$values){ $next=[]; foreach($cases as $case){ foreach($values as $value){ $next[]=array_replace($case, [$axis=>$value]); if(count($next)>$this->maximumCases){ throw new \OverflowException('Panel quality matrix exceeds its maximum case budget.'); } } } $cases=$next; }
		$manifests=[];
		foreach($cases as $index=>$case){
			$viewport=is_array($case['viewport'] ?? null) ? $case['viewport'] : ['width'=>1280, 'height'=>720];
			$id=$this->name.'-'.($index+1).'-'.substr(hash('sha256', json_encode($case, JSON_THROW_ON_ERROR)), 0, 10);
			$manifest=PanelBrowserRegressionManifest::make($id, $this->url)
				->viewport((int)($viewport['width'] ?? 1280), (int)($viewport['height'] ?? 720), ['is_mobile'=>($viewport['mobile'] ?? false)===true, 'device_scale_factor'=>(float)($viewport['scale'] ?? 1)])
				->accessibility(['enabled'=>true, 'fail_on'=>['critical','serious'], 'rules'=>['keyboard'=>true, 'focus_trap'=>true, 'contrast'=>true, 'zoom'=>(int)($case['zoom'] ?? 100)]])
				->consolePolicy(['fail_on'=>['error','assert'], 'allow'=>[], 'ignore'=>[]])
				->meta(['quality_case'=>$case, 'theme'=>$case['theme'] ?? 'default', 'locale'=>$case['locale'] ?? 'en', 'direction'=>$case['direction'] ?? 'ltr', 'color_mode'=>$case['color_mode'] ?? 'light', 'motion'=>$case['motion'] ?? 'normal', 'network'=>$case['network'] ?? 'online']);
			$manifests[]=$manifest;
		}
		return $manifests;
	}
	public function jsonSerialize(): array { $manifests=array_map(static fn(PanelBrowserRegressionManifest $manifest): array => $manifest->toArray(), $this->manifests()); return ['type'=>'panel_quality_matrix', 'version'=>1, 'name'=>$this->name, 'url'=>$this->url, 'axes'=>$this->axes, 'cases'=>count($manifests), 'manifests'=>$manifests]; }
	private static function defaults(): array { return ['viewport'=>[['width'=>1440,'height'=>900],['width'=>1024,'height'=>768],['width'=>390,'height'=>844,'mobile'=>true]], 'color_mode'=>['light','dark'], 'direction'=>['ltr','rtl'], 'zoom'=>[100,200], 'motion'=>['normal','reduced'], 'network'=>['online','slow','offline']]; }
	private static function assertValue(mixed $value, int $depth=0): void {
		if($depth>self::MAX_VALUE_DEPTH){ throw new \LengthException('Panel quality matrix values exceed the nesting budget.'); }
		if(is_array($value)){
			if(count($value)>self::MAX_VALUE_ITEMS){ throw new \LengthException('Panel quality matrix values exceed the item budget.'); }
			foreach($value as $item){ self::assertValue($item, $depth+1); }
			return;
		}
		if(!is_scalar($value) && $value!==null){ throw new \InvalidArgumentException('Panel quality matrix values must be JSON scalar or array data.'); }
		if(is_float($value) && !is_finite($value)){ throw new \InvalidArgumentException('Panel quality matrix numeric values must be finite.'); }
		if(is_string($value) && preg_match('//u', $value)!==1){ throw new \InvalidArgumentException('Panel quality matrix string values must be valid UTF-8.'); }
		if(is_string($value) && strlen($value)>4096){ throw new \LengthException('Panel quality matrix string values exceed the byte budget.'); }
	}
}
