<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * ICU-compatible message subset with a deterministic dependency-free fallback.
 *
 * Supports nested select, plural, selectordinal, exact-number branches,
 * plural offsets, simple placeholders, and # substitution. Native intl is used
 * when present unless explicitly disabled; the fallback keeps Panel functional
 * on minimal PHP installations.
 */
final class PanelMessageFormatter implements \JsonSerializable {

	private string $locale;
	private bool $useIntl;
	private string $timezone;

	public function __construct(string $locale='en', bool $useIntl=true, string $timezone='UTC') {
		$this->locale=PanelLocaleMetadata::normalize($locale) ?: 'en';
		$this->useIntl=$useIntl;
		try {
			$this->timezone=(new \DateTimeZone($timezone))->getName();
		}
		catch(\Throwable){
			$this->timezone='UTC';
		}
	}

	/** @param array<string,mixed> $parameters */
	public function format(string $message, array $parameters=[], ?string $locale=null): string {
		$locale=PanelLocaleMetadata::normalize($locale ?? $this->locale) ?: $this->locale;
		if($this->useIntl && class_exists(\MessageFormatter::class)){
			try {
				$result=\MessageFormatter::formatMessage($locale, $message, $parameters);
				if(is_string($result)){
					return $result;
				}
			}
			catch(\Throwable){
				// Fall through to the deterministic formatter.
			}
		}
		return $this->render($message, $parameters, $locale);
	}

	public function formatNumber(int|float $number, int $minFraction=0, int $maxFraction=3, ?string $locale=null, bool $grouping=true): string {
		$locale=PanelLocaleMetadata::normalize($locale ?? $this->locale) ?: $this->locale;
		$minFraction=max(0, min(20, $minFraction));
		$maxFraction=max($minFraction, min(20, $maxFraction));
		if($this->useIntl && class_exists(\NumberFormatter::class)){
			$formatter=new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
			$formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $minFraction);
			$formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxFraction);
			$formatter->setAttribute(\NumberFormatter::GROUPING_USED, $grouping ? 1 : 0);
			$result=$formatter->format($number);
			if(is_string($result)){
				return $result;
			}
		}
		$symbols=(new PanelLocaleMetadata($locale))->numberSymbols();
		$formatted=number_format((float)$number, $maxFraction, (string)$symbols['decimal'], $grouping ? (string)$symbols['group'] : '');
		if($maxFraction>$minFraction){
			$decimal=preg_quote((string)$symbols['decimal'], '/');
			$formatted=preg_replace_callback('/'.$decimal.'([0-9]*?[1-9])?0{0,'.($maxFraction-$minFraction).'}$/u', static fn(array $match): string => isset($match[1]) && $match[1]!=='' ? (string)$symbols['decimal'].$match[1] : ($minFraction>0 ? (string)$symbols['decimal'].str_repeat('0', $minFraction) : ''), $formatted) ?? $formatted;
		}
		if(($symbols['digits'] ?? '0123456789')!=='0123456789'){
			$localizedDigits=preg_split('//u', (string)$symbols['digits'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
			if(count($localizedDigits)===10){
				$formatted=strtr($formatted, array_combine(str_split('0123456789'), $localizedDigits));
			}
		}
		return $formatted;
	}

	public function formatCurrency(int|float $amount, string $currency, ?string $locale=null): string {
		$locale=PanelLocaleMetadata::normalize($locale ?? $this->locale) ?: $this->locale;
		$currency=strtoupper(trim($currency));
		if(preg_match('/^[A-Z]{3}$/', $currency)!==1){
			throw new \InvalidArgumentException('Currency must be a three-letter ISO code.');
		}
		if($this->useIntl && class_exists(\NumberFormatter::class)){
			$formatter=new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
			$result=$formatter->formatCurrency((float)$amount, $currency);
			if(is_string($result)){
				return $result;
			}
		}
		$symbols=['USD'=>'$', 'CAD'=>'CA$', 'EUR'=>'€', 'GBP'=>'£', 'JPY'=>'¥', 'CNY'=>'CN¥', 'INR'=>'₹', 'KRW'=>'₩'];
		$symbol=$symbols[$currency] ?? $currency;
		$number=$this->formatNumber($amount, 2, 2, $locale);
		$language=explode('-', $locale)[0];
		return in_array($language, ['fr', 'de', 'es', 'it', 'pt', 'ru', 'uk', 'pl'], true) ? $number."\u{00A0}".$symbol : $symbol.$number;
	}

	public function formatDate(\DateTimeInterface|int|string $value, string $style='medium', ?string $locale=null, ?string $timezone=null): string {
		$locale=PanelLocaleMetadata::normalize($locale ?? $this->locale) ?: $this->locale;
		$timezone=$timezone ?? $this->timezone;
		try {
			$zone=new \DateTimeZone($timezone);
			$date=$value instanceof \DateTimeInterface
				? \DateTimeImmutable::createFromInterface($value)
				: (is_int($value) ? (new \DateTimeImmutable('@'.$value))->setTimezone($zone) : new \DateTimeImmutable($value, $zone));
			$date=$date->setTimezone($zone);
		}
		catch(\Throwable $exception){
			throw new \InvalidArgumentException('Unable to parse date value.', 0, $exception);
		}
		$style=strtolower(trim($style));
		if($this->useIntl && class_exists(\IntlDateFormatter::class)){
			$intlStyle=match($style){
				'full'=>\IntlDateFormatter::FULL,
				'long'=>\IntlDateFormatter::LONG,
				'short'=>\IntlDateFormatter::SHORT,
				default=>\IntlDateFormatter::MEDIUM,
			};
			$formatter=new \IntlDateFormatter($locale, $intlStyle, \IntlDateFormatter::NONE, $zone->getName());
			$result=$formatter->format($date);
			if(is_string($result)){
				return $result;
			}
		}
		$language=explode('-', $locale)[0];
		$pattern=match($style){
			'full'=>'l, F j, Y',
			'long'=>'F j, Y',
			'short'=>in_array($language, ['en'], true) ? 'n/j/y' : 'd/m/y',
			default=>in_array($language, ['en'], true) ? 'M j, Y' : 'j M Y',
		};
		return $date->format($pattern);
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		return [
			'type'=>'panel_message_formatter',
			'locale'=>$this->locale,
			'timezone'=>$this->timezone,
			'intl_available'=>class_exists(\MessageFormatter::class),
			'intl_enabled'=>$this->useIntl,
			'capabilities'=>[
				'plural'=>true,
				'select'=>true,
				'selectordinal'=>true,
				'exact_plural'=>true,
				'plural_offset'=>true,
				'nested_messages'=>true,
				'numbers'=>true,
				'currencies'=>true,
				'dates'=>true,
				'no_intl_fallback'=>true,
			],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	/** @param array<string,mixed> $parameters */
	private function render(string $message, array $parameters, string $locale, int|float|null $pound=null): string {
		$message=preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function(array $match) use ($parameters): string {
			$value=$this->value($parameters, $match[1], $match[0]);
			return $this->scalar($value);
		}, $message) ?? $message;
		$output='';
		$length=strlen($message);
		for($index=0; $index<$length; $index++){
			if($message[$index]!=='{'){
				$output.=$message[$index];
				continue;
			}
			$end=$this->matchingBrace($message, $index);
			if($end===null){
				$output.=substr($message, $index);
				break;
			}
			$expression=substr($message, $index+1, $end-$index-1);
			$output.=$this->expression($expression, $parameters, $locale);
			$index=$end;
		}
		if($pound!==null){
			$output=str_replace('#', $this->formatNumber($pound, 0, 3, $locale), $output);
		}
		$replace=[];
		foreach($parameters as $key=>$value){
			if(is_string($key) && $key!==''){
				$replace[':'.$key]=$this->scalar($value);
			}
		}
		return $replace!==[] ? strtr($output, $replace) : $output;
	}

	/** @param array<string,mixed> $parameters */
	private function expression(string $expression, array $parameters, string $locale): string {
		$parts=$this->splitTopLevel($expression, ',', 3);
		$name=trim($parts[0] ?? '');
		if(count($parts)===1){
			return $this->scalar($this->value($parameters, $name, '{'.$expression.'}'));
		}
		$type=strtolower(trim($parts[1] ?? ''));
		$style=$parts[2] ?? '';
		$value=$this->value($parameters, $name, null);
		if($type==='select'){
			$options=$this->options($style);
			$selected=$options[(string)$value] ?? $options['other'] ?? '';
			return $this->render($selected, $parameters, $locale);
		}
		if($type==='plural' || $type==='selectordinal'){
			if(!is_numeric($value)){
				$value=0;
			}
			$number=(float)$value;
			[$options, $offset]=$this->pluralOptions($style);
			$exact='='.$this->canonicalNumber($number);
			$category=$type==='selectordinal' ? $this->ordinalCategory($number, $locale) : $this->pluralCategory($number, $locale);
			$selected=$options[$exact] ?? $options[$category] ?? $options['other'] ?? '';
			return $this->render($selected, $parameters, $locale, $number-$offset);
		}
		if($type==='number'){
			return $this->formatNumber((float)$value, 0, 3, $locale);
		}
		return $this->scalar($value);
	}

	/** @return array<string,string> */
	private function options(string $style): array {
		$options=[];
		$length=strlen($style);
		$index=0;
		while($index<$length){
			while($index<$length && ctype_space($style[$index])){
				$index++;
			}
			$start=$index;
			while($index<$length && $style[$index]!=='{' && !ctype_space($style[$index])){
				$index++;
			}
			$key=trim(substr($style, $start, $index-$start));
			while($index<$length && ctype_space($style[$index])){
				$index++;
			}
			if($key==='' || $index>=$length || $style[$index]!=='{'){
				$index++;
				continue;
			}
			$end=$this->matchingBrace($style, $index);
			if($end===null){
				break;
			}
			$options[$key]=substr($style, $index+1, $end-$index-1);
			$index=$end+1;
		}
		return $options;
	}

	/** @return array{0:array<string,string>,1:int|float} */
	private function pluralOptions(string $style): array {
		$offset=0;
		if(preg_match('/^\s*offset\s*:\s*(-?(?:\d+(?:\.\d+)?|\.\d+))\s*/i', $style, $match)===1){
			$offset=(float)$match[1];
			$style=substr($style, strlen($match[0]));
		}
		return [$this->options($style), $offset];
	}

	private function pluralCategory(float $number, string $locale): string {
		$language=explode('-', strtolower($locale))[0];
		$integer=(int)$number;
		$isInteger=$number===(float)$integer;
		if($language==='ar'){
			$mod100=$integer%100;
			return match(true){
				$number==0=>'zero', $number==1=>'one', $number==2=>'two',
				$isInteger && $mod100>=3 && $mod100<=10=>'few',
				$isInteger && $mod100>=11 && $mod100<=99=>'many', default=>'other',
			};
		}
		if(in_array($language, ['ru', 'uk', 'be'], true) && $isInteger){
			$mod10=$integer%10; $mod100=$integer%100;
			return $mod10===1 && $mod100!==11 ? 'one' : ($mod10>=2 && $mod10<=4 && !($mod100>=12 && $mod100<=14) ? 'few' : ($mod10===0 || $mod10>=5 || ($mod100>=11 && $mod100<=14) ? 'many' : 'other'));
		}
		if($language==='pl' && $isInteger){
			$mod10=$integer%10; $mod100=$integer%100;
			return $integer===1 ? 'one' : ($mod10>=2 && $mod10<=4 && !($mod100>=12 && $mod100<=14) ? 'few' : 'many');
		}
		if(in_array($language, ['cs', 'sk'], true) && $isInteger){
			return $integer===1 ? 'one' : ($integer>=2 && $integer<=4 ? 'few' : 'other');
		}
		if($language==='fr'){
			return $number>=0 && $number<2 ? 'one' : 'other';
		}
		return $number==1 ? 'one' : 'other';
	}

	private function ordinalCategory(float $number, string $locale): string {
		$language=explode('-', strtolower($locale))[0];
		$integer=(int)$number;
		if($language==='en' && $number===(float)$integer){
			$mod10=$integer%10; $mod100=$integer%100;
			return $mod10===1 && $mod100!==11 ? 'one' : ($mod10===2 && $mod100!==12 ? 'two' : ($mod10===3 && $mod100!==13 ? 'few' : 'other'));
		}
		return 'other';
	}

	/** @param array<string,mixed> $parameters */
	private function value(array $parameters, string $key, mixed $default=null): mixed {
		if(array_key_exists($key, $parameters)){
			return $parameters[$key];
		}
		$current=$parameters;
		foreach(explode('.', $key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return $default;
			}
			$current=$current[$segment];
		}
		return $current;
	}

	private function scalar(mixed $value): string {
		if($value===null){ return ''; }
		if(is_bool($value)){ return $value ? 'true' : 'false'; }
		if(is_scalar($value) || $value instanceof \Stringable){ return (string)$value; }
		try { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
		catch(\Throwable){ return ''; }
	}

	private function matchingBrace(string $message, int $start): ?int {
		$depth=0;
		for($index=$start, $length=strlen($message); $index<$length; $index++){
			if($message[$index]==='{'){ $depth++; }
			elseif($message[$index]==='}' && --$depth===0){ return $index; }
		}
		return null;
	}

	/** @return list<string> */
	private function splitTopLevel(string $value, string $separator, int $limit): array {
		$parts=[]; $start=0; $depth=0;
		for($index=0, $length=strlen($value); $index<$length && count($parts)<$limit-1; $index++){
			if($value[$index]==='{'){ $depth++; }
			elseif($value[$index]==='}'){ $depth--; }
			elseif($value[$index]===$separator && $depth===0){
				$parts[]=substr($value, $start, $index-$start); $start=$index+1;
			}
		}
		$parts[]=substr($value, $start);
		return $parts;
	}

	private function canonicalNumber(float $number): string {
		return $number===(float)(int)$number ? (string)(int)$number : rtrim(rtrim(sprintf('%.12F', $number), '0'), '.');
	}
}
