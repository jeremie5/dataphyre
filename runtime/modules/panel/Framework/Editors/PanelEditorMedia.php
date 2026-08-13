<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Explicit media-reference allow-list used by rich editor sanitization. */
final class PanelEditorMedia implements PanelEditorMediaAdapter, \JsonSerializable {
	private const DANGEROUS_SCHEMES=['javascript','vbscript','data','file','filesystem','blob','about'];
	/** @var list<string> */
	private array $prefixes=[];
	/** @var list<string> */
	private array $hosts=[];
	/** @var list<string> */
	private array $schemes=['https'];
	private ?\Closure $resolver=null;
	private bool $enabled=true;

	private function __construct(private string $adapterName) { $this->adapterName=PanelEditorManifest::name($adapterName, 'media'); }
	public static function make(string $name='media'): self { return new self($name); }
	public static function fromArray(array $definition): self {
		$media=self::make((string)($definition['name'] ?? 'media'))
			->allowPrefixes(is_array($definition['prefixes'] ?? null) ? $definition['prefixes'] : [])
			->allowHosts(is_array($definition['hosts'] ?? null) ? $definition['hosts'] : [])
			->allowSchemes(is_array($definition['schemes'] ?? null) ? $definition['schemes'] : ['https'])
			->enabled((bool)($definition['enabled'] ?? true));
		return ($definition['runtime'] ?? '')==='resolver' ? $media->enabled(false) : $media;
	}
	public function allowPrefixes(array|string $prefixes): self { $clone=clone $this; foreach((array)$prefixes as $prefix){ $prefix=trim((string)$prefix); if(str_starts_with($prefix, '/') && !str_starts_with($prefix, '//') && !str_contains($prefix, '?') && !str_contains($prefix, '#') && !self::unsafePath($prefix) && !in_array($prefix, $clone->prefixes, true)){ $clone->prefixes[]=$prefix; } } return $clone; }
	public function allowHosts(array|string $hosts): self { $clone=clone $this; foreach((array)$hosts as $host){ $host=strtolower(trim((string)$host, " \t\n\r\0\x0B.")); if(preg_match('/^[a-z0-9.-]+$/', $host)===1 && !in_array($host, $clone->hosts, true)){ $clone->hosts[]=$host; } } return $clone; }
	public function allowSchemes(array|string $schemes): self { $clone=clone $this; $clone->schemes=[]; foreach((array)$schemes as $scheme){ $scheme=strtolower(trim((string)$scheme)); if(preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme)===1 && !in_array($scheme, self::DANGEROUS_SCHEMES, true) && !in_array($scheme, $clone->schemes, true)){ $clone->schemes[]=$scheme; } } return $clone; }
	public function resolveUsing(callable $resolver): self { $clone=clone $this; $clone->resolver=\Closure::fromCallable($resolver); return $clone; }
	public function enabled(bool $enabled=true): self { $clone=clone $this; $clone->enabled=$enabled; return $clone; }
	public function name(): string { return $this->adapterName; }
	public function ready(): bool { return $this->enabled && $this->adapterName!=='' && ($this->resolver!==null || $this->prefixes!==[] || $this->hosts!==[]); }
	public function normalizeReference(string $url, PanelEditorContext $context): ?string {
		$url=trim($url);
		if(!$this->ready() || $url==='' || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)===1 || self::unsafePath($url)){ return null; }
		if($this->resolver!==null){
			$value=PanelUtilityResolver::evaluate($this->resolver, ['url'=>$url, 'context'=>$context], ['url','context']);
			if(!is_string($value) || trim($value)===''){ return null; }
			$url=trim($value);
			if(str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)===1 || self::unsafePath($url)){ return null; }
		}
		foreach($this->prefixes as $prefix){ if(str_starts_with($url, $prefix)){ return $url; } }
		$parts=parse_url($url);
		if(!is_array($parts)){ return null; }
		$scheme=strtolower((string)($parts['scheme'] ?? ''));
		$host=strtolower((string)($parts['host'] ?? ''));
		if($scheme==='' && str_starts_with($url, '/')){ return null; }
		if(!in_array($scheme, $this->schemes, true)){ return null; }
		foreach($this->hosts as $allowed){ if($host===$allowed || str_ends_with($host, '.'.$allowed)){ return $url; } }
		return null;
	}
	public function manifest(): array { return ['name'=>$this->name(), 'prefixes'=>$this->prefixes, 'hosts'=>$this->hosts, 'schemes'=>$this->schemes, 'enabled'=>$this->enabled, 'ready'=>$this->ready(), 'runtime'=>$this->resolver!==null ? 'resolver' : 'allow_list']; }
	public function jsonSerialize(): array { return $this->manifest(); }
	private static function unsafePath(string $url): bool {
		$probe=$url;
		$stable=false;
		for($pass=0;$pass<4;$pass++){ $decoded=rawurldecode($probe); if($decoded===$probe){ $stable=true; break; } $probe=$decoded; }
		if(!$stable && rawurldecode($probe)!==$probe){ return true; }
		$path=(string)(parse_url($probe, PHP_URL_PATH) ?? '');
		return preg_match('~(?:^|/)\.\.?(?:/|$)~', $path)===1 || str_contains($probe, '\\') || preg_match('/[\x00-\x1f\x7f]/', $probe)===1;
	}
}
