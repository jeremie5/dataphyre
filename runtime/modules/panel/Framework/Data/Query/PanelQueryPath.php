<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Validated dotted field or relation path used by public query DTOs. */
final class PanelQueryPath implements \JsonSerializable, \Stringable {
	/** @param non-empty-list<string> $segments */
	private function __construct(private readonly string $value, private readonly array $segments){}

	public static function make(string $path): self {
		$path=trim($path);
		if($path==='' || strlen($path)>191){ throw new \InvalidArgumentException('Panel query paths must contain between 1 and 191 bytes.'); }
		$segments=explode('.', $path);
		if(count($segments)>12){ throw new \LengthException('Panel query paths support at most 12 segments.'); }
		foreach($segments as $segment){
			if(preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $segment)!==1){ throw new \InvalidArgumentException("Invalid Panel query path '{$path}'."); }
		}
		/** @var non-empty-list<string> $segments */
		return new self(implode('.', $segments), $segments);
	}

	public function value(): string { return $this->value; }
	/** @return non-empty-list<string> */
	public function segments(): array { return $this->segments; }
	public function head(): string { return $this->segments[0]; }
	public function tail(): ?self { return count($this->segments)>1 ? self::make(implode('.', array_slice($this->segments, 1))) : null; }
	public function prefixed(string|self $prefix): self { return self::make((string)$prefix.'.'.$this->value); }
	public function __toString(): string { return $this->value; }
	/** @return array{type:string,value:string,segments:list<string>} */
	public function jsonSerialize(): array { return ['type'=>'panel_query_path', 'value'=>$this->value, 'segments'=>$this->segments]; }
}
