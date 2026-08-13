<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Fail-closed scanner and transformation pipeline for stored Panel media. */
final class PanelMediaProcessingPipeline implements \JsonSerializable {

	private PanelMediaDisk $disk;
	/** @var array<string,PanelMediaScanner|callable> */
	private array $scanners=[];
	/** @var array<string,PanelMediaTransformer|callable> */
	private array $transformers=[];
	private bool $failClosed;
	private ?string $quarantinePrefix;

	public function __construct(PanelMediaDisk $disk, bool $failClosed=true, ?string $quarantinePrefix='.panel-quarantine') {
		$this->disk=$disk;
		$this->failClosed=$failClosed;
		$this->quarantinePrefix=$quarantinePrefix!==null ? trim($quarantinePrefix, "\\/") : null;
	}

	public static function make(PanelMediaDisk $disk): self {
		return new self($disk);
	}

	public function scanner(PanelMediaScanner|callable $scanner, ?string $name=null): self {
		$name=$this->pluginName($scanner, $name, 'scanner');
		$this->scanners[$name]=$scanner;
		return $this;
	}

	public function transformer(PanelMediaTransformer|callable $transformer, ?string $name=null): self {
		$name=$this->pluginName($transformer, $name, 'transformer');
		$this->transformers[$name]=$transformer;
		return $this;
	}

	/**
	 * @param array<string,array<string,mixed>> $variantDefinitions
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function process(string $path, array $variantDefinitions=[], array $context=[]): array {
		$path=$this->disk->normalizePath($path);
		$source=$this->disk->descriptor($path);
		$metadata=$this->sourceMetadata($source, $context);
		$started=gmdate('c');
		$scans=[];
		$clean=true;
		foreach($this->scanners as $name=>$scanner){
			$before=microtime(true);
			try {
				$result=$scanner instanceof PanelMediaScanner
					? $scanner->scan($this->disk, $path, $context)
					: $scanner($this->disk, $path, $context);
				if(!is_array($result)){
					throw new \UnexpectedValueException('Media scanner must return an array.');
				}
				$result=array_replace(['clean'=>false, 'status'=>'unknown'], $this->jsonSafe($result));
			}
			catch(\Throwable $exception){
				$result=[
					'clean'=>!$this->failClosed,
					'status'=>'error',
					'error'=>$exception->getMessage(),
					'exception'=>$exception::class,
				];
			}
			$result['scanner']=$name;
			$result['duration_ms']=(int)round((microtime(true)-$before)*1000);
			$scans[$name]=$result;
			if(($result['clean'] ?? false)!==true){
				$clean=false;
			}
		}
		$quarantine=null;
		if(!$clean){
			if($this->quarantinePrefix!==null){
				$quarantine=$this->quarantine($path, $source);
			}
			return [
				'type'=>'panel_media_processing_result',
				'ok'=>false,
				'status'=>'rejected',
				'source'=>$source,
				'scans'=>$scans,
				'variants'=>[],
				'metadata'=>$metadata,
				'quarantine'=>$quarantine,
				'started_at'=>$started,
				'completed_at'=>gmdate('c'),
			];
		}

		$variants=[];
		$transformations=[];
		foreach($this->transformers as $name=>$transformer){
			$before=microtime(true);
			try {
				$result=$transformer instanceof PanelMediaTransformer
					? $transformer->transform($this->disk, $path, $variantDefinitions, $context)
					: $transformer($this->disk, $path, $variantDefinitions, $context);
				if(!is_array($result)){
					throw new \UnexpectedValueException('Media transformer must return an array.');
				}
				$rawVariants=is_array($result['variants'] ?? null) ? $result['variants'] : $result;
				$pluginVariants=[];
				foreach($rawVariants as $variantName=>$variant){
					if(is_string($variant)){
						$variant=['path'=>$variant];
					}
					if(!is_array($variant) || !isset($variant['path'])){
						continue;
					}
					$variantPath=$this->disk->normalizePath((string)$variant['path']);
					if(!$this->disk->exists($variantPath)){
						throw new \RuntimeException('Transformer returned a missing variant: '.$variantPath);
					}
					$descriptor=array_replace($this->disk->descriptor($variantPath), $this->jsonSafe($variant));
					$descriptor['path']=$variantPath;
					$key=$this->key(is_string($variantName) ? $variantName : basename($variantPath));
					if($key!==''){
						$pluginVariants[$key]=$descriptor;
						$variants[$key]=$descriptor;
					}
				}
				$transformations[$name]=[
					'ok'=>true,
					'variants'=>$pluginVariants,
					'metadata'=>$this->jsonSafe($result['metadata'] ?? []),
					'duration_ms'=>(int)round((microtime(true)-$before)*1000),
				];
			}
			catch(\Throwable $exception){
				$transformations[$name]=[
					'ok'=>false,
					'error'=>$exception->getMessage(),
					'exception'=>$exception::class,
					'duration_ms'=>(int)round((microtime(true)-$before)*1000),
				];
				if($this->failClosed){
					return [
						'type'=>'panel_media_processing_result',
						'ok'=>false,
						'status'=>'transformation_failed',
						'source'=>$source,
						'scans'=>$scans,
						'transformations'=>$transformations,
						'variants'=>$variants,
						'metadata'=>$metadata,
						'quarantine'=>null,
						'started_at'=>$started,
						'completed_at'=>gmdate('c'),
					];
				}
			}
		}
		return [
			'type'=>'panel_media_processing_result',
			'ok'=>true,
			'status'=>'processed',
			'source'=>$source,
			'scans'=>$scans,
			'transformations'=>$transformations,
			'variants'=>$variants,
			'metadata'=>$metadata,
			'quarantine'=>null,
			'started_at'=>$started,
			'completed_at'=>gmdate('c'),
		];
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		return [
			'type'=>'panel_media_processing_pipeline',
			'disk'=>$this->disk->name(),
			'scanners'=>array_keys($this->scanners),
			'transformers'=>array_keys($this->transformers),
			'fail_closed'=>$this->failClosed,
			'quarantine_prefix'=>$this->quarantinePrefix,
			'capabilities'=>[
				'pluggable_scanners'=>true,
				'pluggable_transformers'=>true,
				'variant_validation'=>true,
				'metadata_extraction'=>true,
				'quarantine'=>true,
			],
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	/** @param array<string,mixed> $source @return array<string,mixed> */
	private function quarantine(string $path, array $source): array {
		$target=$this->quarantinePrefix.'/'.gmdate('Y/m/d').'/'.substr((string)$source['checksum'], 0, 16).'-'.basename($path);
		if($this->disk->exists($target)){
			$target=$this->quarantinePrefix.'/'.gmdate('Y/m/d').'/'.substr((string)$source['checksum'], 0, 16).'-'.bin2hex(random_bytes(4)).'-'.basename($path);
		}
		return $this->disk->move($path, $target, false);
	}

	/** @param array<string,mixed> $source @param array<string,mixed> $context @return array<string,mixed> */
	private function sourceMetadata(array $source, array $context): array {
		$metadata=[
			'mime'=>$source['mime'] ?? 'application/octet-stream',
			'size'=>$source['size'] ?? 0,
			'checksum'=>$source['checksum'] ?? null,
			'extension'=>strtolower(pathinfo((string)($source['filename'] ?? ''), PATHINFO_EXTENSION)),
		];
		if(str_starts_with((string)$metadata['mime'], 'image/') && function_exists('getimagesize')){
			$stream=$this->disk->readStream((string)$source['path']);
			try {
				$bytes=stream_get_contents($stream, 33554432);
				if(is_string($bytes)){
					$details=@getimagesizefromstring($bytes);
					if(is_array($details)){
						$metadata['width']=$details[0] ?? null;
						$metadata['height']=$details[1] ?? null;
					}
				}
			}
			finally {
				fclose($stream);
			}
		}
		$metadata['context']=$this->jsonSafe($context);
		return $metadata;
	}

	private function pluginName(object|callable $plugin, ?string $name, string $fallback): string {
		$name=$this->key((string)$name);
		if($name!==''){
			return $name;
		}
		if(is_object($plugin) && !$plugin instanceof \Closure){
			$name=$this->key((new \ReflectionClass($plugin))->getShortName());
		}
		return $name!=='' ? $name : $fallback.'-'.(count($fallback==='scanner' ? $this->scanners : $this->transformers)+1);
	}

	private function key(string $value): string {
		$value=strtolower(trim($value));
		return trim(preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '', '-_.');
	}

	private function jsonSafe(mixed $value): mixed {
		try {
			return json_decode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		}
		catch(\Throwable){
			return is_scalar($value) || $value===null ? $value : [];
		}
	}
}
