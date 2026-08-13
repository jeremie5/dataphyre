<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, browser-safe media item exposed by an editor asset provider. */
final class PanelEditorAsset implements \JsonSerializable {
	private const STATUSES=['ready','processing','rejected'];
	private const KINDS=['image','video','audio','document','file'];

	public function __construct(
		private string $assetId,
		private string $assetName,
		private string $assetUrl,
		private string $mime='application/octet-stream',
		private int $bytes=0,
		private string $kind='',
		private ?int $width=null,
		private ?int $height=null,
		private string $alt='',
		private string $status='ready',
		private array $metadata=[],
	) {
		$this->assetId=self::normalizeId($assetId);
		$this->assetName=self::normalizeName($assetName);
		$this->assetUrl=self::normalizeUrl($assetUrl);
		$this->mime=self::normalizeMime($mime);
		$this->bytes=max(0, $bytes);
		$this->kind=self::normalizeKind($kind, $this->mime);
		$this->width=self::dimension($width);
		$this->height=self::dimension($height);
		$this->alt=self::text($alt, 512);
		$this->status=in_array($status, self::STATUSES, true) ? $status : 'ready';
		$this->metadata=PanelEditorManifest::sanitize($metadata);
	}

	public static function fromArray(array $asset): self {
		return new self(
			(string)($asset['id'] ?? ''),
			(string)($asset['name'] ?? $asset['filename'] ?? ''),
			(string)($asset['url'] ?? ''),
			(string)($asset['mime'] ?? 'application/octet-stream'),
			(int)($asset['bytes'] ?? $asset['size'] ?? 0),
			(string)($asset['kind'] ?? ''),
			isset($asset['width']) ? (int)$asset['width'] : null,
			isset($asset['height']) ? (int)$asset['height'] : null,
			(string)($asset['alt'] ?? ''),
			(string)($asset['status'] ?? 'ready'),
			is_array($asset['metadata'] ?? null) ? $asset['metadata'] : [],
		);
	}

	public function id(): string { return $this->assetId; }
	public function name(): string { return $this->assetName; }
	public function url(): string { return $this->assetUrl; }
	public function mime(): string { return $this->mime; }
	public function size(): int { return $this->bytes; }
	public function kind(): string { return $this->kind; }
	public function width(): ?int { return $this->width; }
	public function height(): ?int { return $this->height; }
	public function alt(): string { return $this->alt; }
	public function status(): string { return $this->status; }
	public function metadata(): array { return $this->metadata; }
	public function ready(): bool { return $this->status==='ready'; }

	public function toArray(): array {
		return [
			'type'=>'panel_editor_asset','schema_version'=>1,'id'=>$this->assetId,'name'=>$this->assetName,
			'url'=>$this->assetUrl,'mime'=>$this->mime,'bytes'=>$this->bytes,'kind'=>$this->kind,
			'width'=>$this->width,'height'=>$this->height,'alt'=>$this->alt,'status'=>$this->status,
			'metadata'=>$this->metadata,
		];
	}
	public function jsonSerialize(): array { return $this->toArray(); }

	public static function normalizeId(string $id): string {
		$id=trim($id);
		if($id==='' || strlen($id)>192 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $id)!==1){ throw new \InvalidArgumentException('A safe editor asset id is required.'); }
		return $id;
	}
	public static function normalizeUrl(string $url): string {
		$url=trim($url);
		if($url==='' || strlen($url)>4096 || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)===1){ throw new \InvalidArgumentException('A safe editor asset URL is required.'); }
		$parts=parse_url($url);
		if(!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])){ throw new \InvalidArgumentException('The editor asset URL is unsafe.'); }
		$path=self::decoded((string)($parts['path'] ?? ''));
		if($path===null || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path)===1){ throw new \InvalidArgumentException('The editor asset URL path is unsafe.'); }
		if(isset($parts['query'])){ parse_str((string)$parts['query'], $parameters); if(self::sensitiveQuery($parameters)){ throw new \InvalidArgumentException('The editor asset URL query is unsafe.'); } }
		if(str_starts_with($url, '/') && !str_starts_with($url, '//') && !isset($parts['scheme']) && !isset($parts['host'])){ return $url; }
		if(strtolower((string)($parts['scheme'] ?? ''))!=='https' || trim((string)($parts['host'] ?? ''))===''){ throw new \InvalidArgumentException('Editor asset URLs must be relative or HTTPS.'); }
		return $url;
	}
	public static function normalizeEndpoint(string $endpoint): string {
		$endpoint=rtrim(self::normalizeUrl($endpoint), '/');
		$parts=parse_url($endpoint);
		if(!is_array($parts) || isset($parts['query'])){ throw new \InvalidArgumentException('Editor asset endpoints cannot contain a query string.'); }
		return $endpoint;
	}

	private static function normalizeName(string $name): string {
		$name=trim(str_replace('\\', '/', $name));
		if($name==='' || basename($name)!==$name || preg_match('/[\x00-\x1f\x7f]/', $name)===1){ throw new \InvalidArgumentException('A safe editor asset filename is required.'); }
		return self::text($name, 255);
	}
	private static function normalizeMime(string $mime): string {
		$mime=strtolower(trim($mime));
		return preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/D', $mime)===1 ? $mime : 'application/octet-stream';
	}
	private static function normalizeKind(string $kind, string $mime): string {
		$kind=strtolower(trim($kind));
		if(in_array($kind, self::KINDS, true)){ return $kind; }
		foreach(['image','video','audio'] as $mediaKind){ if(str_starts_with($mime, $mediaKind.'/')){ return $mediaKind; } }
		return str_starts_with($mime, 'text/') || $mime==='application/pdf' ? 'document' : 'file';
	}
	private static function dimension(?int $value): ?int { return $value!==null && $value>0 && $value<=100000 ? $value : null; }
	private static function text(string $value, int $limit): string {
		$value=trim($value);
		if(strlen($value)<=$limit){ return $value; }
		$value=substr($value, 0, $limit);
		while($value!=='' && preg_match('//u', $value)!==1){ $value=substr($value, 0, -1); }
		return $value;
	}
	private static function sensitiveQuery(array $parameters): bool {
		foreach($parameters as $key=>$value){
			$key=self::decoded((string)$key);
			if($key===null || preg_match('/(?:secret|token|password|authorization|credential|api[_-]?key|access[_-]?key)/i', $key)===1){ return true; }
			if(is_array($value) && self::sensitiveQuery($value)){ return true; }
		}
		return false;
	}
	private static function decoded(string $value): ?string {
		for($pass=0;$pass<4;$pass++){ $decoded=rawurldecode($value); if($decoded===$value){ return $value; } $value=$decoded; }
		return rawurldecode($value)===$value ? $value : null;
	}
}
