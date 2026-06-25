<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Routing;

/**
 * Compiles PHP route declarations into deterministic manifest arrays and cache files.
 *
 * The compiler is deliberately filesystem-oriented: it expands route source paths,
 * fingerprints source modification times, validates manifest payloads for
 * `var_export()` persistence, and writes PHP cache files that can be required
 * without booting the authoring route DSL.
 */
final class RouteCompiler {

	/** @var array<string, mixed>|null */
	private static ?array $lastManifestPayloadInput=null;
	private static ?string $lastManifestPayloadOutput=null;

	/**
	 * Resolves a single route source path into the PHP files that should be compiled.
	 *
	 * Directory sources are treated as shallow route folders: only direct `*.php`
	 * children are returned, sorted by filename for stable signatures. File
	 * sources are validated eagerly so a stale manifest cannot silently mask a
	 * missing route declaration.
	 *
	 * @param string $path File or directory path supplied by routing or MVC configuration.
	 * @return array<int, string> Absolute or caller-relative PHP route file paths in compile order.
	 * @throws \RuntimeException When a non-empty source path is neither a file nor a directory.
	 */
	public static function routeFiles(string $path): array {
		$path=trim($path);
		if($path===''){
			return [];
		}
		if(is_dir($path)){
			$files=glob(rtrim($path, '/\\').'/*.php') ?: [];
			sort($files, SORT_STRING);
			return $files;
		}
		if(!is_file($path)){
			throw new \RuntimeException("Route file does not exist: {$path}");
		}
		return [$path];
	}

	/**
	 * Builds the source timestamp map that participates in manifest cache invalidation.
	 *
	 * Each configured path is expanded through `routeFiles()`, then recorded with
	 * its current `filemtime()` value. Missing or unreadable timestamps degrade to
	 * `0`, which keeps the signature deterministic while still allowing the caller
	 * to detect that the source set changed.
	 *
	 * @param array<int, string>|string $paths Route file or route directory source paths.
	 * @return array<string, int> Map of route file path to observed modification time.
	 * @throws \RuntimeException When any configured source path is invalid.
	 */
	public static function sourceMtimes(array|string $paths): array {
		$sources=[];
		foreach((array)$paths as $path){
			foreach(self::routeFiles((string)$path) as $file){
				$sources[$file]=@filemtime($file) ?: 0;
			}
		}
		return $sources;
	}

	/**
	 * Produces the stable hash used to decide whether a manifest cache is fresh.
	 *
	 * If the input contains a `sources` map, existing files are re-sampled at the
	 * point of hashing and sorted by path before encoding. That makes the signature
	 * sensitive to route edits but insensitive to associative insertion order.
	*
	 * @param array<string, mixed> $parts Manifest metadata, source timestamps, and option values.
	 * @return non-empty-string SHA-256 digest of the normalized signature payload.
	 */
	public static function manifestSignature(array $parts): string {
		if(isset($parts['sources']) && is_array($parts['sources'])){
			foreach($parts['sources'] as $file=>$mtime){
				$parts['sources'][$file]=is_file((string)$file) ? (@filemtime((string)$file) ?: 0) : $mtime;
			}
			ksort($parts['sources'], SORT_STRING);
		}
		return hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Loads one route declaration file and compiles it into a manifest array.
	*
	 * Route files are part of the trusted application configuration surface and
	 * are executed with `require`. They must return the declarative array accepted
	 * by `RouteManifest::compile()`. The source filename is injected into metadata
	 * so diagnostics and route cache readers can trace generated entries
	 * back to the file that authored them.
	 *
	 * @param string $routesFile PHP route declaration file.
	 * @param array<string, mixed> $metadata Compile metadata merged into the manifest context.
	 * @return array<string, mixed> Compiled route manifest.
	 * @throws \RuntimeException When the route file does not return an array.
	 */
	public static function compileFile(string $routesFile, array $metadata=[]): array {
		$routes=require($routesFile);
		if(!is_array($routes)){
			throw new \RuntimeException("Routes file must return an array: {$routesFile}");
		}
		$metadata['source_file']=$routesFile;
		return RouteManifest::compile($routes, $metadata);
	}

	/**
	 * Requires a generated manifest cache file and validates its minimum shape.
	*
	 * Manifest files are PHP arrays produced by `writeManifestFile()`. This reader
	 * intentionally checks only the envelope needed by dispatch and cache inspection:
	 * existence, array payload, and array-typed `routes` when present.
	 *
	 * @param string $manifestFile Generated PHP manifest file.
	 * @return array<string, mixed> Manifest payload loaded from disk.
	 * @throws \RuntimeException When the file is missing or does not contain a route manifest array.
	 */
	public static function readManifestFile(string $manifestFile): array {
		if(!is_file($manifestFile)){
			throw new \RuntimeException("Route manifest file does not exist: {$manifestFile}");
		}
		$manifest=require($manifestFile);
		if(!is_array($manifest)){
			throw new \RuntimeException("Route manifest file must return an array: {$manifestFile}");
		}
		if(($manifest['routes'] ?? null)!==null && !is_array($manifest['routes'])){
			throw new \RuntimeException("Route manifest routes must be an array: {$manifestFile}");
		}
		return $manifest;
	}

	/**
	 * Determines whether a manifest value can be persisted with `var_export()`.
	 *
	 * Closures are rejected because they cannot be reconstructed in a cache file.
	 * Objects are accepted only when they expose `__set_state()`, matching PHP's
	 * export contract. Arrays are checked recursively so nested route metadata does
	 * not make a manifest unwritable after part of the cache path has already run.
	 *
	 * @param mixed $value Manifest fragment, handler descriptor, or metadata value.
	 * @return bool `true` when the value can round-trip through `var_export()` safely.
	 */
	public static function manifestExportable(mixed $value): bool {
		if($value instanceof \Closure){
			return false;
		}
		if(is_object($value)){
			return method_exists($value, '__set_state');
		}
		if(is_array($value)){
			foreach($value as $entry){
				if(self::manifestExportable($entry)===false){
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Attempts to write a manifest cache file without throwing for exportability failures.
	*
	 * This is the optimistic cache path used when callers can continue with an
	 * in-memory manifest. Non-exportable payloads return `false`; filesystem
	 * failures from the actual write still surface as runtime errors because they
	 * indicate the configured cache location is unhealthy.
	*
	 * @param string $targetFile Destination PHP cache file.
	 * @param array<string, mixed> $manifest Compiled manifest payload.
	 * @return bool `true` when the manifest was exportable and the write completed.
	 */
	public static function tryWriteManifestFile(string $targetFile, array $manifest): bool {
		if(self::manifestExportable($manifest)===false){
			return false;
		}
		self::writeManifestFile($targetFile, $manifest, false);
		return true;
	}

	/**
	 * Persists a compiled manifest as a PHP file that returns the manifest array.
	*
	 * The destination directory is created on demand, the payload is emitted with
	 * `var_export()`, and `LOCK_EX` is used to avoid readers observing partial
	 * writes during route cache refresh. The method is intentionally strict about
	 * exportability so dispatch never requires an invalid generated cache file.
	 *
	 * @param string $targetFile Destination PHP cache file.
	 * @param array<string, mixed> $manifest Compiled manifest payload.
	 * @param bool $validateExportable Whether to validate `var_export()` compatibility before writing.
	 * @return void
	 * @throws \RuntimeException When the manifest cannot be exported or the cache directory cannot be created.
	 */
	public static function writeManifestFile(string $targetFile, array $manifest, bool $validateExportable=true): void {
		if($validateExportable===true && self::manifestExportable($manifest)===false){
			throw new \RuntimeException('Route manifest contains non-exportable values and cannot be written to disk.');
		}
		$directory=dirname($targetFile);
		if(!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)){
			throw new \RuntimeException("Unable to create route manifest directory: {$directory}");
		}
		$payload=self::manifestPayload($manifest);
		if(is_file($targetFile) && @file_get_contents($targetFile)===$payload){
			return;
		}
		file_put_contents($targetFile, $payload, LOCK_EX);
	}

	/**
	 * Builds the generated PHP payload for a manifest, caching the last exact value.
	 *
	 * @param array<string, mixed> $manifest Compiled manifest payload.
	 * @return string PHP cache file contents.
	 */
	private static function manifestPayload(array $manifest): string {
		if(self::$lastManifestPayloadInput===$manifest && self::$lastManifestPayloadOutput!==null){
			return self::$lastManifestPayloadOutput;
		}
		self::$lastManifestPayloadInput=$manifest;
		return self::$lastManifestPayloadOutput="<?php\n\nreturn ".var_export($manifest, true).";\n";
	}
}
