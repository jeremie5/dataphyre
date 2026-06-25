<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Drivers;

use Dataphyre\Storage\Contracts\StorageDriver;
use Dataphyre\Storage\FileMetadata;
use Dataphyre\Storage\Support\Path;
use Dataphyre\Storage\Support\Stream;

/**
 * Storage driver backed by Dataphyre Vestra object references and an alias manifest.
 *
 * The driver maps logical storage paths to structured Vestra reference objects
 * stored in a JSON manifest. Writes propagate bytes to Vestra, then record a
 * stable reference locally; reads resolve the full reference into a generated URL.
 * Use the generic S3-compatible driver for Vestra bucket/key storage. This driver
 * exists only to bridge Storage's logical-path API to the Vestra Fabric module.
 */
final class VestraDriver implements StorageDriver {

	/**
	 * Stores Vestra driver configuration.
	 *
	 * @param array<string, mixed> $config Driver configuration, including optional `manifest` path for alias persistence.
	 */
	public function __construct(private array $config) {
	}

	/**
	 * Checks whether a logical path resolves to a Vestra asset URL.
	 *
	 * This consults the alias manifest and Vestra URL builder, not the physical Vestra bytes.
	 *
	 * @return bool `true` when an alias exists in the local reference manifest.
	 */
	public function exists(string $path): bool {
		return $this->lookup($path)!==null;
	}

	/**
	 * Downloads file contents through a generated Vestra URL.
	 *
	 * Reads use a short-lived URL and `file_get_contents()`. Network failures,
	 * missing aliases, or unavailable Vestra framework state return `false`.
	 *
	 * @param string $path Logical storage path.
	 * @param array<string, mixed> $options URL generation options, including optional `query`.
	 * @return string|false Downloaded contents, or `false` on lookup, Vestra, or network failure.
	 */
	public function read(string $path, array $options=[]): string|false {
		$url=$this->temporaryUrl($path, time()+300, $options);
		return $url!==false ? $this->downloadUrl($url) : false;
	}

	/**
	 * Reads Vestra contents and exposes them as an in-memory stream.
	 *
	 * @param string $path Logical storage path.
	 * @param array<string, mixed> $options Read options forwarded to `read()`.
	 * @return mixed Stream resource created from downloaded contents, or `false` when the read fails.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		$contents=$this->read($path, $options);
		return is_string($contents) ? Stream::fromString($contents) : false;
	}

	/**
	 * Propagates contents to the Vestra and records a logical alias.
	 *
	 * Contents are first copied into a temporary local file because the Vestra
	 * client propagates file paths. The temporary file is deleted after
	 * propagation. A successful Vestra object without a successful alias write still
	 * returns `false` because the logical storage path would not be readable.
	 *
	 * @param string $path Logical storage path to associate with the Vestra object.
	 * @param mixed $contents Stringable contents or readable stream.
	 * @param array<string, mixed> $options Write options; `vestra_encrypt` requests encrypted Vestra propagation.
	 * @return bool `true` only when propagation succeeds and the alias manifest is updated.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		if($this->ensureVestra()!==true){
			return false;
		}
		$tmp=$this->temporaryPath($path, $options);
		if($tmp===''){
			return false;
		}
		$out=fopen($tmp, 'w+b');
		$ok=is_resource($contents) ? Stream::copy($contents, $out) : fwrite($out, (string)$contents)!==false;
		fclose($out);
		if(!$ok){
			@unlink($tmp);
			return false;
		}
		$reference=\Dataphyre\Vestra\Client::propagate($tmp, (bool)($options['vestra_encrypt'] ?? false));
		@unlink($tmp);
		if(!is_array($reference)){
			return false;
		}
		return $this->recordAlias($path, $this->reference($reference, $path, $options));
	}

	/**
	 * Removes a logical alias and decrements Vestra object usage when possible.
	 *
	 * Deleting does not directly erase Vestra bytes. When the Vestra framework is
	 * available, the object usage count is decremented, then the alias is removed
	 * from the manifest.
	 *
	 * @param string $path Logical storage path to forget.
	 * @return bool `true` when the alias manifest is saved after removal.
	 */
	public function delete(string $path): bool {
		$reference=$this->lookup($path);
		if($reference!==null && $this->ensureVestra()===true){
			\dataphyre\vestra::update_use_count($reference, -1);
		}
		return $this->forgetAlias($path);
	}

	/**
	 * Returns manifest-backed metadata for a logical Vestra alias.
	 *
	 * Size, modification time, and MIME type are unknown at this layer; the Vestra
	 * reference object is exposed through metadata extras for diagnostics.
	 *
	 * @param string $path Logical storage path.
	 * @return FileMetadata|false Metadata with `vestra` extra, or `false` when no alias exists.
	 */
	public function metadata(string $path): FileMetadata|false {
		$reference=$this->lookup($path);
		if($reference===null){
			return false;
		}
		$metadata=is_array($reference['metadata'] ?? null) ? $reference['metadata'] : [];
		$size=$reference['filesize'] ?? $metadata['filesize'] ?? $metadata['size'] ?? null;
		$mime=$reference['mime_type'] ?? $reference['content_type'] ?? $metadata['mime_type'] ?? $metadata['content_type'] ?? null;
		return new FileMetadata(
			Path::normalize($path),
			is_numeric($size) ? (int)$size : null,
			null,
			is_scalar($mime) && trim((string)$mime)!=='' ? (string)$mime : null,
			['vestra'=>$reference]
		);
	}

	/**
	 * Lists logical aliases from the local manifest.
	 *
	 * Listing does not query the Vestra. It reflects only the alias manifest and
	 * filters normalized paths by prefix.
	 *
	 * @param string $prefix Logical path prefix.
	 * @param array<string, mixed> $options Reserved for interface compatibility.
	 * @return array<int, FileMetadata> Manifest aliases represented as metadata with `vestra` extras.
	 */
	public function list(string $prefix='', array $options=[]): array {
		$aliases=$this->aliases();
		$prefix=Path::normalize($prefix);
		$results=[];
		foreach($aliases as $path=>$reference){
			if($prefix==='' || str_starts_with($path, $prefix)){
				$results[]=new FileMetadata($path, null, null, null, ['vestra'=>$reference]);
			}
		}
		return $results;
	}

	/**
	 * Builds a Vestra asset URL for a logical alias.
	 *
	 * The expiry value is accepted for storage-driver compatibility but this
	 * implementation delegates to the Vestra asset URL builder without signing an
	 * expiry itself.
	 *
	 * @param string $path Logical storage path.
	 * @param int|\DateTimeInterface $expires Expiry requested by callers.
	 * @param array<string, mixed> $options URL options; `query` is forwarded to the Vestra client.
	 * @return string|false Vestra asset URL, or `false` when no alias or Vestra runtime is available.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		$reference=$this->lookup($path);
		if($reference===null){
			return false;
		}
		if($this->ensureVestra()!==true){
			return false;
		}
		return \Dataphyre\Vestra\Client::assetUrl($reference, $this->assetExtension($path, $options), $this->urlParameters($options));
	}

	/**
	 * Ensures the Dataphyre Vestra framework and client class are loaded.
	 *
	 * @return bool `true` when Vestra module and client APIs are available.
	 */
	private function ensureVestra(): bool {
		if(!class_exists('\dataphyre\vestra', false) && function_exists('\dp_module_present')){
			$module=\dp_module_present('vestra');
			if(is_array($module) && !empty($module[0])){
				require_once((string)$module[0]);
			}
		}
		return class_exists('\dataphyre\vestra', false)
			&& \dataphyre\core::load_framework_module('vestra')===true
			&& class_exists('\Dataphyre\Vestra\Client');
	}

	/**
	 * Loads the logical-path to Vestra reference map.
	 *
	 * @return array<string, array<string,mixed>> Alias manifest contents, or an empty map when absent or invalid.
	 */
	private function aliases(): array {
		$file=(string)($this->config['manifest'] ?? '');
		if($file==='' || !is_file($file)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($file), true);
		if(!is_array($decoded)){
			return [];
		}
		$aliases=[];
		foreach($decoded as $path=>$reference){
			if(is_array($reference) && isset($reference['object_id']) && is_numeric($reference['object_id'])){
				$reference['object_id']=(int)$reference['object_id'];
				$aliases[(string)$path]=$reference;
			}
		}
		return $aliases;
	}

	/**
	 * Persists the alias map to the configured manifest file.
	 *
	 * @param array<string, array<string,mixed>> $aliases Logical paths mapped to Vestra references.
	 * @return bool `true` when the manifest is written successfully.
	 */
	private function saveAliases(array $aliases): bool {
		$file=(string)($this->config['manifest'] ?? '');
		if($file===''){
			return false;
		}
		$dir=dirname($file);
		if(!is_dir($dir)){
			@mkdir($dir, 0775, true);
		}
		return file_put_contents($file, json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))!==false;
	}

	/**
	 * Resolves a logical path to its Vestra reference.
	 *
	 * @param string $path Logical storage path.
	 * @return ?array<string,mixed> Vestra reference, or `null` when no alias exists.
	 */
	private function lookup(string $path): ?array {
		$path=Path::normalize($path);
		$aliases=$this->aliases();
		return isset($aliases[$path]) && is_array($aliases[$path]) ? $aliases[$path] : null;
	}

	/**
	 * Builds the framework-level Vestra storage reference object.
	 *
	 * @param array<string,mixed> $reference Vestra Fabric reference.
	 * @param array<string,mixed> $options Write options.
	 * @return array<string,mixed> Stable storage reference.
	 */
	private function reference(array $reference, string $path, array $options=[]): array {
		$reference['driver']='vestra';
		$tenant=(string)($options['tenant'] ?? ($this->config['tenant'] ?? ''));
		if($tenant!=='' && (!isset($reference['tenant']) || (string)$reference['tenant']==='')){
			$reference['tenant']=$tenant;
		}
		$metadata=is_array($reference['metadata'] ?? null) ? $reference['metadata'] : [];
		if(isset($options['metadata']) && is_array($options['metadata'])){
			$metadata=array_replace_recursive($metadata, $options['metadata']);
		}
		$metadata=array_replace($metadata, [
			'storage_path'=>Path::normalize($path),
		]);
		foreach(['original_name', 'content_type'] as $key){
			if(isset($options[$key]) && is_scalar($options[$key]) && trim((string)$options[$key])!==''){
				$metadata[$key]=(string)$options[$key];
			}
		}
		if(isset($options['content_type']) && is_scalar($options['content_type']) && trim((string)$options['content_type'])!=='' && empty($reference['mime_type'])){
			$reference['mime_type']=(string)$options['content_type'];
		}
		$reference['metadata']=$metadata;
		return $reference;
	}

	/**
	 * Creates a temporary local path that keeps the logical asset extension.
	 *
	 * Vestra infers MIME and decorative object names from the propagated file. A
	 * bare `tempnam()` file would lose useful `.png`, `.pdf`, or `.txt` context.
	 *
	 * @param string $path Logical storage path.
	 * @param array<string,mixed> $options Write options, including optional original_name.
	 * @return string Writable temporary path, or an empty string on failure.
	 */
	private function temporaryPath(string $path, array $options=[]): string {
		$extension=$this->assetExtension((string)($options['original_name'] ?? $path), []);
		$tmp=tempnam(sys_get_temp_dir(), 'dpstor_vestra_');
		if($tmp===false){
			return '';
		}
		if($extension===''){
			return $tmp;
		}
		$withExtension=$tmp.'.'.$extension;
		if(@rename($tmp, $withExtension)){
			return $withExtension;
		}
		return $tmp;
	}

	/**
	 * Resolves the asset extension for generated Vestra URLs and temp filenames.
	 *
	 * @param string $path Logical path or original filename.
	 * @param array<string,mixed> $options URL/write options.
	 * @return string Lowercase extension without dot.
	 */
	private function assetExtension(string $path, array $options=[]): string {
		$extension=(string)($options['extension'] ?? pathinfo(Path::normalize($path), PATHINFO_EXTENSION));
		$extension=strtolower(ltrim(trim($extension), '.'));
		return preg_match('/^[a-z0-9]{1,12}$/', $extension)===1 ? $extension : '';
	}

	/**
	 * Merges disk-level Vestra URL context with per-call query parameters.
	 *
	 * @param array<string,mixed> $options Temporary URL options.
	 * @return array<string,mixed> Parameters accepted by the Vestra URL builder.
	 */
	private function urlParameters(array $options=[]): array {
		$parameters=is_array($options['query'] ?? null) ? $options['query'] : [];
		foreach(['tenant', 'rate', 'plan', 'object_url', 'base_url', 'api_url', 'api_token', 'api_auth_mode', 'node_token', 'tenant_read_token', 'allow_unsigned', 'token', 'passkey', 'expires_in_secs', 'grace_secs', 'tenant_grant'] as $key){
			if(array_key_exists($key, $options)){
				$parameters[$key]=$options[$key];
			}
			elseif(array_key_exists($key, $this->config)){
				$parameters[$key]=$this->config[$key];
			}
		}
		return $parameters;
	}

	/**
	 * Downloads a generated Vestra URL with TLS settings from disk/Vestra config.
	 *
	 * PHP installations on local Windows builds often lack a default CA bundle.
	 * The driver first tries stream wrappers with the configured bundle, then
	 * falls back to cURL so storage reads behave like Vestra uploads.
	 *
	 * @param string $url Generated Vestra URL.
	 * @return string|false Downloaded bytes, or false when HTTP/TLS fails.
	 */
	private function downloadUrl(string $url): string|false {
		$context_options=[
			'http'=>[
				'timeout'=>(float)($this->config['read_timeout'] ?? 30),
				'ignore_errors'=>false,
			],
		];
		$ca_bundle=$this->caBundle();
		if($ca_bundle!==''){
			$context_options['ssl']=[
				'cafile'=>$ca_bundle,
				'verify_peer'=>true,
				'verify_peer_name'=>true,
			];
		}
		$contents=@file_get_contents($url, false, stream_context_create($context_options));
		if(is_string($contents)){
			return $contents;
		}
		if(!function_exists('curl_init')){
			return false;
		}
		$curl=curl_init($url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_TIMEOUT, (int)($this->config['read_timeout'] ?? 30));
		if($ca_bundle!==''){
			curl_setopt($curl, CURLOPT_CAINFO, $ca_bundle);
		}
		$result=curl_exec($curl);
		$status=(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);
		return is_string($result) && $status>=200 && $status<300 ? $result : false;
	}

	/**
	 * Resolves the CA bundle for Vestra reads from disk or Vestra tenant config.
	 *
	 * @return string Existing CA bundle path, or empty string.
	 */
	private function caBundle(): string {
		$candidates=[];
		if(isset($this->config['ca_bundle']) && is_scalar($this->config['ca_bundle'])){
			$candidates[]=(string)$this->config['ca_bundle'];
		}
		if(defined('DP_VESTRA_CFG') && is_array(DP_VESTRA_CFG)){
			$tenant=(string)($this->config['tenant'] ?? DP_VESTRA_CFG['tenant'] ?? DP_VESTRA_CFG['default_tenant'] ?? '');
			if($tenant!=='' && isset(DP_VESTRA_CFG['tenants'][$tenant]['ca_bundle']) && is_scalar(DP_VESTRA_CFG['tenants'][$tenant]['ca_bundle'])){
				$candidates[]=(string)DP_VESTRA_CFG['tenants'][$tenant]['ca_bundle'];
			}
			if(isset(DP_VESTRA_CFG['ca_bundle']) && is_scalar(DP_VESTRA_CFG['ca_bundle'])){
				$candidates[]=(string)DP_VESTRA_CFG['ca_bundle'];
			}
		}
		foreach($candidates as $candidate){
			$candidate=trim($candidate);
			if($candidate!=='' && is_file($candidate)){
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Records or replaces a logical path alias.
	 *
	 * @param string $path Logical storage path.
	 * @param array<string,mixed> $reference Vestra reference returned by propagation.
	 * @return bool `true` when the manifest is saved.
	 */
	private function recordAlias(string $path, array $reference): bool {
		$aliases=$this->aliases();
		$aliases[Path::normalize($path)]=$reference;
		return $this->saveAliases($aliases);
	}

	/**
	 * Removes a logical path alias from the manifest.
	 *
	 * @param string $path Logical storage path.
	 * @return bool `true` when the manifest is saved.
	 */
	private function forgetAlias(string $path): bool {
		$aliases=$this->aliases();
		unset($aliases[Path::normalize($path)]);
		return $this->saveAliases($aliases);
	}
}
