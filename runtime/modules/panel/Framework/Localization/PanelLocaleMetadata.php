<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Locale normalization, fallback-chain, and bidirectional layout metadata. */
final class PanelLocaleMetadata implements \JsonSerializable {

	private string $locale;
	/** @var list<string> */
	private array $fallbacks;

	/** @param array<int,string> $fallbacks */
	public function __construct(string $locale='en', array $fallbacks=[]) {
		$this->locale=self::normalize($locale) ?: 'en';
		$this->fallbacks=$this->buildFallbacks($fallbacks);
	}

	/** @param array<int,string> $fallbacks */
	public static function make(string $locale='en', array $fallbacks=[]): self {
		return new self($locale, $fallbacks);
	}

	public static function normalize(?string $locale): string {
		$locale=trim((string)$locale);
		if($locale===''){
			return '';
		}
		$locale=str_replace('_', '-', $locale);
		$parts=array_values(array_filter(preg_split('/[^a-zA-Z0-9]+/', $locale) ?: []));
		if($parts===[] || preg_match('/^[a-zA-Z]{2,3}$/', $parts[0])!==1){
			return '';
		}
		$parts[0]=strtolower($parts[0]);
		for($index=1; $index<count($parts); $index++){
			$part=$parts[$index];
			if(strlen($part)===4 && ctype_alpha($part)){
				$parts[$index]=ucfirst(strtolower($part));
			}
			elseif((strlen($part)===2 && ctype_alpha($part)) || (strlen($part)===3 && ctype_digit($part))){
				$parts[$index]=strtoupper($part);
			}
			else {
				$parts[$index]=strtolower($part);
			}
		}
		return implode('-', $parts);
	}

	public function locale(): string {
		return $this->locale;
	}

	public function language(): string {
		return strtolower(explode('-', $this->locale)[0]);
	}

	public function script(): ?string {
		foreach(array_slice(explode('-', $this->locale), 1) as $part){
			if(strlen($part)===4 && ctype_alpha($part)){
				return ucfirst(strtolower($part));
			}
		}
		return null;
	}

	public function region(): ?string {
		foreach(array_slice(explode('-', $this->locale), 1) as $part){
			if((strlen($part)===2 && ctype_alpha($part)) || (strlen($part)===3 && ctype_digit($part))){
				return strtoupper($part);
			}
		}
		return null;
	}

	public function isRtl(): bool {
		return in_array($this->language(), ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ku', 'nqo', 'ps', 'sd', 'syr', 'ug', 'ur', 'yi'], true)
			|| in_array($this->script(), ['Adlm', 'Arab', 'Hebr', 'Nkoo', 'Rohg', 'Syrc', 'Thaa'], true);
	}

	public function direction(): string {
		return $this->isRtl() ? 'rtl' : 'ltr';
	}

	/** @return list<string> */
	public function fallbackChain(): array {
		return $this->fallbacks;
	}

	/** @return array<string,string> */
	public function htmlAttributes(): array {
		return [
			'lang'=>$this->locale,
			'dir'=>$this->direction(),
			'data-dp-panel-locale'=>$this->locale,
			'data-dp-panel-direction'=>$this->direction(),
		];
	}

	/** @return array<string,mixed> */
	public function numberSymbols(): array {
		$language=$this->language();
		if(in_array($language, ['ar', 'fa', 'ur'], true)){
			return ['decimal'=>'٫', 'group'=>'٬', 'minus'=>'−', 'digits'=>'٠١٢٣٤٥٦٧٨٩'];
		}
		if(in_array($language, ['fr', 'ru', 'uk', 'pl', 'cs', 'sk', 'sv', 'fi', 'nb', 'da'], true)){
			return ['decimal'=>',', 'group'=>"\u{00A0}", 'minus'=>'−', 'digits'=>'0123456789'];
		}
		if(in_array($language, ['de', 'es', 'it', 'pt', 'nl', 'tr'], true)){
			return ['decimal'=>',', 'group'=>'.', 'minus'=>'−', 'digits'=>'0123456789'];
		}
		return ['decimal'=>'.', 'group'=>',', 'minus'=>'−', 'digits'=>'0123456789'];
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		return [
			'type'=>'panel_locale_metadata',
			'locale'=>$this->locale,
			'language'=>$this->language(),
			'script'=>$this->script(),
			'region'=>$this->region(),
			'direction'=>$this->direction(),
			'rtl'=>$this->isRtl(),
			'writing_mode'=>'horizontal-tb',
			'fallback_chain'=>$this->fallbacks,
			'number_symbols'=>$this->numberSymbols(),
			'html_attributes'=>$this->htmlAttributes(),
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	/** @param array<int,string> $fallbacks @return list<string> */
	private function buildFallbacks(array $fallbacks): array {
		$candidates=[$this->locale];
		$base=explode('-', $this->locale)[0];
		if($base!==$this->locale){
			$candidates[]=$base;
		}
		foreach($fallbacks as $fallback){
			$fallback=self::normalize($fallback);
			if($fallback===''){
				continue;
			}
			$candidates[]=$fallback;
			$fallbackBase=explode('-', $fallback)[0];
			if($fallbackBase!==$fallback){
				$candidates[]=$fallbackBase;
			}
		}
		return array_values(array_unique($candidates));
	}
}
