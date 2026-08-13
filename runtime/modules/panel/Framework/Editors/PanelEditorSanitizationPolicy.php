<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable allow-list policy for server-side rich editor sanitization. */
final class PanelEditorSanitizationPolicy implements \JsonSerializable {
	private const DANGEROUS_SCHEMES=['javascript','vbscript','data','file','filesystem','blob','about'];
	/** @var array<string,list<string>> */
	private array $elements;
	/** @var list<string> */
	private array $schemes;
	private bool $relativeUrls=true;
	private bool $protocolRelativeUrls=false;
	private bool $rejectUnsafe=true;
	private bool $stripComments=true;
	private int $maxNodes=10000;
	private int $maxDepth=64;
	private int $maxUrlLength=2048;
	private int $maxBytes=2097152;

	private function __construct() {
		$this->elements=[
			'p'=>[], 'br'=>[], 'strong'=>[], 'b'=>[], 'em'=>[], 'i'=>[], 'u'=>[], 's'=>[],
			'a'=>['href','title','target','rel'], 'ul'=>[], 'ol'=>[], 'li'=>[], 'blockquote'=>[],
			'pre'=>[], 'code'=>[], 'h1'=>[], 'h2'=>[], 'h3'=>[], 'h4'=>[], 'h5'=>[], 'h6'=>[], 'hr'=>[],
		];
		$this->schemes=['http','https','mailto','tel'];
	}

	public static function strict(): self { return new self(); }
	public static function make(): self { return self::strict(); }

	public static function fromArray(array $definition): self {
		$policy=self::strict();
		if(isset($definition['elements']) && is_array($definition['elements'])){
			$policy=$policy->elements($definition['elements']);
		}
		if(isset($definition['schemes']) && is_array($definition['schemes'])){
			$policy=$policy->urlSchemes($definition['schemes']);
		}
		if(array_key_exists('relative_urls', $definition)){
			$policy=$policy->relativeUrls((bool)$definition['relative_urls']);
		}
		if(array_key_exists('protocol_relative_urls', $definition)){
			$policy=$policy->protocolRelativeUrls((bool)$definition['protocol_relative_urls']);
		}
		if(array_key_exists('reject_unsafe', $definition)){
			$policy=$policy->rejectUnsafe((bool)$definition['reject_unsafe']);
		}
		if(array_key_exists('strip_comments', $definition)){
			$policy=$policy->stripComments((bool)$definition['strip_comments']);
		}
		if(isset($definition['max_nodes'])){
			$policy=$policy->maxNodes((int)$definition['max_nodes']);
		}
		if(isset($definition['max_depth'])){
			$policy=$policy->maxDepth((int)$definition['max_depth']);
		}
		if(isset($definition['max_url_length'])){
			$policy=$policy->maxUrlLength((int)$definition['max_url_length']);
		}
		if(isset($definition['max_bytes'])){
			$policy=$policy->maxBytes((int)$definition['max_bytes']);
		}
		return $policy;
	}

	/** @param array<string,array|string> $elements */
	public function elements(array $elements): self {
		$clone=clone $this;
		$clone->elements=[];
		foreach($elements as $tag=>$attributes){
			if(is_int($tag)){
				$tag=(string)$attributes;
				$attributes=[];
			}
			$tag=self::tag((string)$tag);
			if($tag===''){
				continue;
			}
			$attributes=is_array($attributes) ? $attributes : preg_split('/\s*,\s*/', (string)$attributes, -1, PREG_SPLIT_NO_EMPTY);
			$clone->elements[$tag]=self::attributes(is_array($attributes) ? $attributes : []);
		}
		return $clone;
	}

	public function allowElement(string $tag, array $attributes=[]): self {
		$tag=self::tag($tag);
		if($tag===''){
			return $this;
		}
		$clone=clone $this;
		$clone->elements[$tag]=self::attributes($attributes);
		return $clone;
	}

	public function disallowElement(string $tag): self {
		$clone=clone $this;
		unset($clone->elements[self::tag($tag)]);
		return $clone;
	}

	public function urlSchemes(array $schemes): self {
		$clone=clone $this;
		$clone->schemes=[];
		foreach($schemes as $scheme){
			$scheme=strtolower(trim((string)$scheme));
			if(preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme)===1 && !in_array($scheme, self::DANGEROUS_SCHEMES, true) && !in_array($scheme, $clone->schemes, true)){
				$clone->schemes[]=$scheme;
			}
		}
		return $clone;
	}

	public function relativeUrls(bool $allowed=true): self { $clone=clone $this; $clone->relativeUrls=$allowed; return $clone; }
	public function protocolRelativeUrls(bool $allowed=true): self { $clone=clone $this; $clone->protocolRelativeUrls=$allowed; return $clone; }
	public function rejectUnsafe(bool $reject=true): self { $clone=clone $this; $clone->rejectUnsafe=$reject; return $clone; }
	public function stripUnsafe(bool $strip=true): self { return $this->rejectUnsafe(!$strip); }
	public function stripComments(bool $strip=true): self { $clone=clone $this; $clone->stripComments=$strip; return $clone; }
	public function maxNodes(int $count): self { $clone=clone $this; $clone->maxNodes=max(1, min(100000, $count)); return $clone; }
	public function maxDepth(int $depth): self { $clone=clone $this; $clone->maxDepth=max(1, min(256, $depth)); return $clone; }
	public function maxUrlLength(int $length): self { $clone=clone $this; $clone->maxUrlLength=max(64, min(16384, $length)); return $clone; }
	public function maxBytes(int $bytes): self { $clone=clone $this; $clone->maxBytes=max(1024, min(67108864, $bytes)); return $clone; }

	/** @return array<string,list<string>> */
	public function allowedElements(): array { return $this->elements; }
	/** @return list<string> */
	public function allowedSchemes(): array { return $this->schemes; }
	public function rejectsUnsafe(): bool { return $this->rejectUnsafe; }
	public function stripsComments(): bool { return $this->stripComments; }
	public function nodeLimit(): int { return $this->maxNodes; }
	public function depthLimit(): int { return $this->maxDepth; }
	public function byteLimit(): int { return $this->maxBytes; }

	/** Returns a normalized URL, or null when the destination is unsafe. */
	public function normalizeUrl(string $url): ?string {
		$url=trim(html_entity_decode($url, ENT_QUOTES|ENT_HTML5, 'UTF-8'));
		if($url==='' || strlen($url)>$this->maxUrlLength || str_contains($url, ' ') || str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url)===1){
			return null;
		}
		$probe=$url;
		$stable=false;
		for($pass=0;$pass<4;$pass++){
			$decoded=rawurldecode($probe);
			if($decoded===$probe){ $stable=true; break; }
			if(str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded)===1){ return null; }
			$probe=$decoded;
		}
		if(!$stable && rawurldecode($probe)!==$probe){ return null; }
		$probe=str_replace(' ', '', $probe);
		if(str_starts_with($probe, '//')){
			return $this->protocolRelativeUrls ? $url : null;
		}
		if(preg_match('/^([a-z][a-z0-9+.-]*):/i', $probe, $matches)===1){
			return in_array(strtolower($matches[1]), $this->schemes, true) ? $url : null;
		}
		return $this->relativeUrls ? $url : null;
	}

	public function toArray(): array {
		return [
			'name'=>'strict_html',
			'elements'=>$this->elements,
			'schemes'=>$this->schemes,
			'relative_urls'=>$this->relativeUrls,
			'protocol_relative_urls'=>$this->protocolRelativeUrls,
			'reject_unsafe'=>$this->rejectUnsafe,
			'strip_comments'=>$this->stripComments,
			'max_nodes'=>$this->maxNodes,
			'max_depth'=>$this->maxDepth,
			'max_url_length'=>$this->maxUrlLength,
			'max_bytes'=>$this->maxBytes,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	private static function tag(string $tag): string {
		$tag=strtolower(trim($tag));
		return preg_match('/^[a-z][a-z0-9-]*$/', $tag)===1 ? $tag : '';
	}

	/** @return list<string> */
	private static function attributes(array $attributes): array {
		$clean=[];
		foreach($attributes as $attribute){
			$attribute=strtolower(trim((string)$attribute));
			if(preg_match('/^[a-z][a-z0-9_:-]*$/', $attribute)===1 && !str_starts_with($attribute, 'on') && !in_array($attribute, ['style','srcdoc'], true) && !in_array($attribute, $clean, true)){
				$clean[]=$attribute;
			}
		}
		return $clean;
	}
}
