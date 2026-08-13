<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Deterministic filesystem/package translation catalogue loader. */
final class PanelTranslationCatalogueLoader implements \JsonSerializable {

	/** @var array<int,array<string,mixed>> */
	private array $sources=[];
	private bool $strict;
	/** @var array<int,array<string,mixed>> */
	private array $diagnostics=[];
	private int $order=0;

	public function __construct(bool $strict=true) {
		$this->strict=$strict;
	}

	public static function make(bool $strict=true): self {
		return new self($strict);
	}

	public function addPath(string $path, string $namespace='', int $priority=0, bool $trustedPhp=false): self {
		return $this->source($path, $namespace, $priority, $trustedPhp, null);
	}

	public function addPackage(string $package, string $path, ?string $namespace=null, int $priority=0, bool $trustedPhp=false): self {
		$package=$this->scope($package);
		if($package===''){
			throw new \InvalidArgumentException('Translation package name cannot be empty.');
		}
		return $this->source($path, $namespace ?? $package, $priority, $trustedPhp, $package);
	}

	/** @param array<int,string>|string|null $locales @return array<string,array<string,string>> */
	public function catalogue(array|string|null $locales=null): array {
		$this->diagnostics=[];
		$filter=$locales===null ? null : array_values(array_filter(array_map(static fn(string $locale): string => PanelLocaleMetadata::normalize($locale), is_array($locales) ? $locales : [$locales])));
		$sources=$this->sources;
		usort($sources, static fn(array $left, array $right): int => ((int)$left['priority']<=> (int)$right['priority']) ?: ((int)$left['order']<=> (int)$right['order']));
		$catalogue=[];
		foreach($sources as $source){
			foreach($this->files($source) as $file){
				try {
					$entries=$this->entries($file, $source);
					foreach($entries as $entry){
						$locale=$entry['locale'];
						if($filter!==null && !in_array($locale, $filter, true)){
							continue;
						}
						$temp=PanelLocalization::make();
						$temp->add($locale, $entry['translations'], $entry['scope']);
						$catalogue[$locale]=array_replace($catalogue[$locale] ?? [], $temp->translations($locale));
					}
					$this->diagnostics[]=['file'=>$file, 'status'=>'loaded', 'entries'=>count($entries)];
				}
				catch(\Throwable $exception){
					$this->diagnostics[]=['file'=>$file, 'status'=>'failed', 'error'=>$exception->getMessage()];
					if($this->strict){
						throw new \RuntimeException('Unable to load Panel translation catalogue '.$file.': '.$exception->getMessage(), 0, $exception);
					}
				}
			}
		}
		ksort($catalogue);
		foreach($catalogue as &$translations){
			ksort($translations);
		}
		return $catalogue;
	}

	/** @param array<int,string>|string|null $locales */
	public function load(?string $locale=null, ?string $fallbackLocale=null, array|string|null $locales=null): PanelLocalization {
		$catalogue=$this->catalogue($locales);
		return PanelLocalization::make([
			'locale'=>$locale ?? (array_key_first($catalogue) ?: 'en'),
			'fallback_locale'=>$fallbackLocale ?? 'en',
			'catalogue'=>$catalogue,
			'meta'=>['loader'=>$this->manifest()],
		]);
	}

	/** @return array<int,array<string,mixed>> */
	public function diagnostics(): array {
		return $this->diagnostics;
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		return [
			'type'=>'panel_translation_catalogue_loader',
			'strict'=>$this->strict,
			'sources'=>array_map(static fn(array $source): array => [
				'path'=>$source['path'],
				'namespace'=>$source['namespace'],
				'package'=>$source['package'],
				'priority'=>$source['priority'],
				'trusted_php'=>$source['trusted_php'],
			], $this->sources),
			'diagnostics'=>$this->diagnostics,
			'capabilities'=>[
				'json'=>true,
				'php_opt_in'=>true,
				'packages'=>true,
				'namespaces'=>true,
				'priorities'=>true,
				'locale_filter'=>true,
				'deterministic_merge'=>true,
			],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	private function source(string $path, string $namespace, int $priority, bool $trustedPhp, ?string $package): self {
		$path=rtrim(trim($path), "\\/");
		if($path==='' || (!is_file($path) && !is_dir($path)) || is_link($path)){
			throw new \InvalidArgumentException('Translation source must be an existing non-symlink file or directory.');
		}
		$real=realpath($path);
		if($real===false){
			throw new \InvalidArgumentException('Translation source cannot be resolved.');
		}
		$this->sources[]=[
			'path'=>$real,
			'namespace'=>$this->scope($namespace),
			'package'=>$package,
			'priority'=>$priority,
			'trusted_php'=>$trustedPhp,
			'order'=>$this->order++,
		];
		return $this;
	}

	/** @param array<string,mixed> $source @return array<int,string> */
	private function files(array $source): array {
		$path=$source['path'];
		if(is_file($path)){
			return [$path];
		}
		$files=[];
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $file){
			if(!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()){
				continue;
			}
			$extension=strtolower($file->getExtension());
			if($extension==='json' || ($extension==='php' && $source['trusted_php']===true)){
				$files[]=$file->getPathname();
			}
		}
		sort($files, SORT_STRING);
		return $files;
	}

	/** @param array<string,mixed> $source @return array<int,array{locale:string,scope:string,translations:array<string,mixed>}> */
	private function entries(string $file, array $source): array {
		$extension=strtolower(pathinfo($file, PATHINFO_EXTENSION));
		if($extension==='php'){
			if($source['trusted_php']!==true){
				throw new \RuntimeException('PHP translation catalogues require explicit trust.');
			}
			$data=(static fn(string $__file): mixed => require $__file)($file);
		}
		else {
			$raw=file_get_contents($file);
			if($raw===false){
				throw new \RuntimeException('Translation file cannot be read.');
			}
			$data=json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		}
		if(!is_array($data)){
			throw new \UnexpectedValueException('Translation file must return an object/array.');
		}
		[$inferredLocale, $domain]=$this->infer($file, (string)$source['path']);
		$namespace=(string)$source['namespace'];
		if(isset($data['locale']) && is_string($data['locale'])){
			$inferredLocale=PanelLocaleMetadata::normalize($data['locale']);
			$data=$data['translations'] ?? $data['messages'] ?? $data['catalogue'] ?? array_diff_key($data, ['locale'=>true]);
		}
		if($inferredLocale!==''){
			return [[
				'locale'=>$inferredLocale,
				'scope'=>$this->joinScope($namespace, $domain),
				'translations'=>$data,
			]];
		}
		$entries=[];
		foreach($data as $locale=>$translations){
			$locale=PanelLocaleMetadata::normalize(is_string($locale) ? $locale : '');
			if($locale!=='' && is_array($translations)){
				$entries[]=['locale'=>$locale, 'scope'=>$this->joinScope($namespace, $domain), 'translations'=>$translations];
			}
		}
		if($entries===[]){
			throw new \UnexpectedValueException('Translation file does not identify a locale.');
		}
		return $entries;
	}

	/** @return array{0:string,1:string} */
	private function infer(string $file, string $root): array {
		$relative=is_dir($root) ? substr($file, strlen(rtrim($root, "\\/"))+1) : basename($file);
		$relative=str_replace('\\', '/', $relative);
		$without=preg_replace('/\.(?:json|php)$/i', '', $relative) ?? $relative;
		$segments=explode('/', $without);
		if(count($segments)>1){
			$locale=PanelLocaleMetadata::normalize($segments[0]);
			if($locale!==''){
				return [$locale, $this->scope(implode('.', array_slice($segments, 1)))];
			}
		}
		$name=end($segments) ?: '';
		$parts=explode('.', $name);
		foreach($parts as $index=>$part){
			$locale=PanelLocaleMetadata::normalize($part);
			if($locale!==''){
				$domain=$parts;
				unset($domain[$index]);
				return [$locale, $this->scope(implode('.', $domain))];
			}
		}
		return ['', $this->scope($name)];
	}

	private function scope(string $scope): string {
		$scope=strtolower(trim(str_replace([':', '/', '\\'], '.', $scope)));
		$scope=preg_replace('/[^a-z0-9._-]+/', '.', $scope) ?? '';
		return trim(preg_replace('/\.{2,}/', '.', $scope) ?? '', '.');
	}

	private function joinScope(string ...$scopes): string {
		return $this->scope(implode('.', array_filter($scopes, static fn(string $scope): bool => $scope!=='')));
	}
}
