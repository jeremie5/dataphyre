<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Module initialization');

$default_storage_root=defined('ROOTPATH') && isset(ROOTPATH['dataphyre'])
	? rtrim((string)ROOTPATH['dataphyre'], '/\\').'/storage'
	: sys_get_temp_dir().'/dataphyre-storage';
$default_vestra_manifest=defined('ROOTPATH') && isset(ROOTPATH['dataphyre'])
	? rtrim((string)ROOTPATH['dataphyre'], '/\\').'/cache/storage-vestra-manifest.json'
	: sys_get_temp_dir().'/dataphyre-storage-vestra-manifest.json';

dp_define_module_config('storage', 'DP_STORAGE_CFG', [
	'default_disk'=>'local',
	'disks'=>[
		'local'=>[
			'driver'=>'local',
			'root'=>$default_storage_root,
			'url'=>null,
			'signing_key'=>null,
			'max_bytes'=>0,
			'allowed_extensions'=>[],
			'allowed_mime_types'=>[],
			'encryption'=>[
				'enabled'=>false,
				'key'=>null,
				'key_file'=>null,
			],
		],
		'vestra'=>[
			'driver'=>'vestra',
			'manifest'=>$default_vestra_manifest,
			'encryption'=>[
				'enabled'=>false,
				'key'=>null,
				'key_file'=>null,
			],
		],
		's3'=>[
			'driver'=>'s3',
			'endpoint'=>'https://s3.amazonaws.com',
			'bucket'=>null,
			'region'=>'us-east-1',
			'access_key'=>null,
			'secret_key'=>null,
			'session_token'=>null,
			'style'=>'path',
			'public_url'=>null,
			'max_bytes'=>0,
			'allowed_extensions'=>[],
			'allowed_mime_types'=>[],
			'encryption'=>[
				'enabled'=>false,
				'key'=>null,
				'key_file'=>null,
			],
		],
	],
]);

/**
 * Snake_case kernel bridge for Dataphyre storage operations.
 *
 * Each helper loads the framework storage facade, selects a configured disk, and delegates file I/O, signed URLs, multipart uploads, retention, tagging, reports, manifests, and fake-driver test state without adding compatibility shims.
 */
class storage {

	/**
	 * Reads storage configuration from DP_STORAGE_CFG, including dot-path lookup with a caller default.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $key Global value key or dot-path lookup key.
	 * @param mixed $default Value returned when the config path is absent.
	 * @return mixed Full storage config, configured value, or supplied default.
	 */
	public static function config(string $key='', mixed $default=null): mixed {
		$config=defined('DP_STORAGE_CFG') && is_array(DP_STORAGE_CFG) ? DP_STORAGE_CFG : [];
		if($key===''){
			return $config;
		}
		if(array_key_exists($key, $config)){
			return $config[$key];
		}
		$current=$config;
		foreach(explode('.', $key) as $segment){
			if(!is_array($current) || !array_key_exists($segment, $current)){
				return $default;
			}
			$current=$current[$segment];
		}
		return $current;
	}

	/**
	 * Resolves a framework storage disk through the kernel bridge.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param ?string $name Configured storage disk name, or null for the default disk.
	 * @return \Dataphyre\Storage\Contracts\StorageDriver|false Resolved storage driver, or false when the framework module is unavailable.
	 */
	public static function disk(?string $name=null): mixed {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::disk($name);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function exists(string $path, ?string $disk=null): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::exists($path, $disk);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return string|false String payload such as object contents, checksum, or signed URL; false when the framework is unavailable or the driver rejects the operation.
	 */
	public static function get(string $path, ?string $disk=null, array $options=[]): string|false {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::get($path, $disk, $options);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return resource|false Readable stream resource, or false when loading or driver execution fails.
	 */
	public static function read_stream(string $path, ?string $disk=null, array $options=[]): mixed {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::readStream($path, $disk, $options);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param mixed $contents String, stream, or driver-supported payload to write.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function put(string $path, mixed $contents, ?string $disk=null, array $options=[]): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::put($path, $contents, $disk, $options);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param string $local_file Filesystem path to the local source file.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function put_file(string $path, string $local_file, ?string $disk=null, array $options=[]): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::putFile($path, $local_file, $disk, $options);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param array{name?:string,type?:string,tmp_name:string,error?:int,size?:int}|array<string,mixed> $file Uploaded file array compatible with PHP upload metadata.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function put_uploaded_file(string $path, array $file, ?string $disk=null, array $options=[]): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::putUploadedFile($path, $file, $disk, $options);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function delete(string $path, ?string $disk=null): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::delete($path, $disk);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $from Source or destination object path.
	 * @param string $to Source or destination object path.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param ?string $to_disk Storage disk name used as transfer endpoint.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function copy(string $from, string $to, ?string $disk=null, ?string $to_disk=null, array $options=[]): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::copy($from, $to, $disk, $to_disk, $options);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $from Source or destination object path.
	 * @param string $to Source or destination object path.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param ?string $to_disk Storage disk name used as transfer endpoint.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function move(string $from, string $to, ?string $disk=null, ?string $to_disk=null, array $options=[]): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::move($from, $to, $disk, $to_disk, $options);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param string $algorithm PHP hash algorithm name used by the framework storage manager.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return string|false String payload such as object contents, checksum, or signed URL; false when the framework is unavailable or the driver rejects the operation.
	 */
	public static function checksum(string $path, ?string $disk=null, string $algorithm='sha256', array $options=[]): string|false {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::checksum($path, $disk, $algorithm, $options);
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @return array|false Driver-normalized multipart or metadata payload, or false when the framework is unavailable, unsupported, or rejected by the driver.
	 */
	public static function metadata(string $path, ?string $disk=null): array|false {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		$metadata=\Dataphyre\Storage\Storage::metadata($path, $disk);
		return $metadata ? $metadata->toArray() : false;
	}

	/**
	 * Delegates the core file operation to the selected storage disk after loading the framework module.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function list(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return [];
		}
		return array_map(static fn($item): array => $item->toArray(), \Dataphyre\Storage\Storage::list($prefix, $disk, $options));
	}

	/**
	 * Delegates signed URL and multipart upload workflows to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param DateTimeInterface $expires Expiration timestamp or interval accepted by the storage driver.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return string|false String payload such as object contents, checksum, or signed URL; false when the framework is unavailable or the driver rejects the operation.
	 */
	public static function temporary_url(string $path, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): string|false {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::temporaryUrl($path, $expires, $disk, $options);
	}

	/**
	 * Delegates signed URL and multipart upload workflows to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param DateTimeInterface $expires Expiration timestamp or interval accepted by the storage driver.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return string|false String payload such as object contents, checksum, or signed URL; false when the framework is unavailable or the driver rejects the operation.
	 */
	public static function temporary_upload_url(string $path, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): string|false {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::temporaryUploadUrl($path, $expires, $disk, $options);
	}

	/**
	 * Delegates signed URL and multipart upload workflows to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array|false Driver-normalized multipart or metadata payload, or false when the framework is unavailable, unsupported, or rejected by the driver.
	 */
	public static function initiate_multipart_upload(string $path, ?string $disk=null, array $options=[]): array|false {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::initiateMultipartUpload($path, $disk, $options);
	}

	/**
	 * Delegates signed URL and multipart upload workflows to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param string $upload_id Driver-issued multipart upload identifier.
	 * @param int $parts Completed multipart part descriptors passed back to the driver.
	 * @param DateTimeInterface $expires Expiration timestamp or interval accepted by the storage driver.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array|false Driver-normalized multipart or metadata payload, or false when the framework is unavailable, unsupported, or rejected by the driver.
	 */
	public static function temporary_multipart_upload_urls(string $path, string $upload_id, int $parts, int|\DateTimeInterface $expires, ?string $disk=null, array $options=[]): array|false {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::temporaryMultipartUploadUrls($path, $upload_id, $parts, $expires, $disk, $options);
	}

	/**
	 * Delegates signed URL and multipart upload workflows to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param string $upload_id Driver-issued multipart upload identifier.
	 * @param list<array{part_number?:int,etag?:string,checksum?:string,size?:int}>|array<int,array<string,mixed>> $parts Completed multipart part descriptors passed back to the driver.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function complete_multipart_upload(string $path, string $upload_id, array $parts, ?string $disk=null): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::completeMultipartUpload($path, $upload_id, $parts, $disk);
	}

	/**
	 * Delegates signed URL and multipart upload workflows to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param string $upload_id Driver-issued multipart upload identifier.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function abort_multipart_upload(string $path, string $upload_id, ?string $disk=null): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::abortMultipartUpload($path, $upload_id, $disk);
	}

	/**
	 * Delegates object version listing, restore, purge, or pruning to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function versions(string $path, ?string $disk=null): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return [];
		}
		return \Dataphyre\Storage\Storage::versions($path, $disk);
	}

	/**
	 * Delegates object version listing, restore, purge, or pruning to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param string $version_id Driver version identifier to restore or remove.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function restore_version(string $path, string $version_id, ?string $disk=null, array $options=[]): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::restoreVersion($path, $version_id, $disk, $options);
	}

	/**
	 * Delegates object version listing, restore, purge, or pruning to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param string $version_id Driver version identifier to restore or remove.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function purge_version(string $path, string $version_id, ?string $disk=null): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::purgeVersion($path, $version_id, $disk);
	}

	/**
	 * Delegates object version listing, restore, purge, or pruning to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param ?string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function prune_versions(?string $path=null, ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'pruned'=>0, 'deleted_ids'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::pruneVersions($path, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function verify_integrity(string $path, ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'path'=>$path, 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::verifyIntegrity($path, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function integrity_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'checked'=>0, 'passed'=>0, 'failed'=>0, 'failures'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::integrityReport($prefix, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param ?string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function audit_trail(?string $path=null, ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return [];
		}
		return \Dataphyre\Storage\Storage::auditTrail($path, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function deduplication_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'logical_objects'=>0, 'unique_blobs'=>0, 'referenced_bytes'=>0, 'stored_bytes'=>0, 'saved_bytes'=>0, 'missing'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::deduplicationReport($prefix, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function quota_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'scope'=>$prefix, 'bytes'=>0, 'objects'=>0, 'max_bytes'=>0, 'max_objects'=>0, 'bytes_remaining'=>null, 'objects_remaining'=>null, 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::quotaReport($prefix, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function cache_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'objects'=>0, 'bytes'=>0, 'fresh'=>0, 'stale'=>0, 'missing'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::cacheReport($prefix, $disk, $options);
	}

	/**
	 * Delegates a snake_case kernel helper to the camelCase framework storage facade.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function purge_cache(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'purged'=>0, 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::purgeCache($prefix, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function compression_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'objects'=>0, 'compressed_objects'=>0, 'raw_objects'=>0, 'original_bytes'=>0, 'stored_bytes'=>0, 'saved_bytes'=>0, 'ratio'=>1, 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::compressionReport($prefix, $disk, $options);
	}

	/**
	 * Delegates retention, lifecycle, and quarantine maintenance operations to the selected disk.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function set_retention(string $path, ?string $disk=null, array $options=[]): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::setRetention($path, $disk, $options);
	}

	/**
	 * Delegates retention, lifecycle, and quarantine maintenance operations to the selected disk.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function release_retention(string $path, ?string $disk=null, array $options=[]): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::releaseRetention($path, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function retention_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'objects'=>0, 'locked'=>0, 'unlocked'=>0, 'legal_holds'=>0, 'expired'=>0, 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::retentionReport($prefix, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function lifecycle_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'dry_run'=>true, 'eligible'=>0, 'deleted'=>0, 'paths'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::lifecycleReport($prefix, $disk, $options);
	}

	/**
	 * Delegates retention, lifecycle, and quarantine maintenance operations to the selected disk.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function apply_lifecycle(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'dry_run'=>false, 'eligible'=>0, 'deleted'=>0, 'paths'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::applyLifecycle($prefix, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function scan_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'objects'=>0, 'clean'=>0, 'blocked'=>0, 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::scanReport($prefix, $disk, $options);
	}

	/**
	 * Delegates retention, lifecycle, and quarantine maintenance operations to the selected disk.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function purge_quarantine(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'purged'=>0, 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::purgeQuarantine($prefix, $disk, $options);
	}

	/**
	 * Delegates object tagging reads, writes, searches, and reports to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function tag_object(string $path, ?string $disk=null, array $options=[]): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		return \Dataphyre\Storage\Storage::tagObject($path, $disk, $options);
	}

	/**
	 * Delegates object tagging reads, writes, searches, and reports to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function tags_for(string $path, ?string $disk=null): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['tags'=>[], 'metadata'=>[]];
		}
		return \Dataphyre\Storage\Storage::tagsFor($path, $disk);
	}

	/**
	 * Delegates object tagging reads, writes, searches, and reports to the selected storage driver.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param array<string,scalar|null> $tags Tag key/value criteria used by object tag lookups.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function find_by_tags(array $tags, ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return [];
		}
		return array_map(static fn($item): array => $item->toArray(), \Dataphyre\Storage\Storage::findByTags($tags, $disk, $options));
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function tag_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'objects'=>0, 'tags'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::tagReport($prefix, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function policy_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'rules'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::policyReport($prefix, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function rate_limit_report(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'limits'=>[], 'buckets'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::rateLimitReport($prefix, $disk, $options);
	}

	/**
	 * Delegates a snake_case kernel helper to the camelCase framework storage facade.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function reset_rate_limits(string $prefix='', ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'reset'=>false, 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::resetRateLimits($prefix, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param ?string $path Storage object path or prefix within the selected disk.
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function event_trail(?string $path=null, ?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return [];
		}
		return \Dataphyre\Storage\Storage::eventTrail($path, $disk, $options);
	}

	/**
	 * Returns driver diagnostics for integrity, policy, quota, scan, lifecycle, cache, or audit state.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function manifest_report(?string $disk=null): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'manifests'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::manifestReport($disk);
	}

	/**
	 * Imports, exports, or reports storage manifests for driver synchronization.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function export_manifests(?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['format'=>'dataphyre-storage-manifests', 'version'=>1, 'exported_at'=>time(), 'manifests'=>[]];
		}
		return \Dataphyre\Storage\Storage::exportManifests($disk, $options);
	}

	/**
	 * Imports, exports, or reports storage manifests for driver synchronization.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param array|string $bundle Manifest bundle array or encoded manifest bundle string.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function import_manifests(array|string $bundle, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'imported'=>0, 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::importManifests($bundle, $options);
	}

	/**
	 * Delegates a snake_case kernel helper to the camelCase framework storage facade.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param ?string $disk Configured storage disk name, or null for the default disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function diagnostics(?string $disk=null, array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'checked'=>0, 'disks'=>[], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::diagnostics($disk, $options);
	}

	/**
	 * Synchronizes objects between two configured disks using a common prefix and driver options.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @param string $from_disk Storage disk name used as transfer endpoint.
	 * @param string $to_disk Storage disk name used as transfer endpoint.
	 * @param string $prefix Storage object path or prefix within the selected disk.
	 * @param array<string,mixed> $options Runtime options forwarded to the selected storage driver.
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function sync(string $from_disk, string $to_disk, string $prefix='', array $options=[]): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return ['ok'=>false, 'dry_run'=>true, 'from'=>$from_disk, 'to'=>$to_disk, 'prefix'=>$prefix, 'copied'=>[], 'updated'=>[], 'skipped'=>[], 'deleted'=>[], 'failed'=>[], 'counts'=>['copied'=>0, 'updated'=>0, 'skipped'=>0, 'deleted'=>0, 'failed'=>0], 'message'=>'Storage framework is unavailable.'];
		}
		return \Dataphyre\Storage\Storage::sync($from_disk, $to_disk, $prefix, $options);
	}

	/**
	 * Exposes fake-driver state for tests without touching production disks.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @return bool True when the framework loads and the selected storage operation succeeds; false on load failure, unsupported driver capability, guard rejection, or driver failure.
	 */
	public static function fake_flush(): bool {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return false;
		}
		\Dataphyre\Storage\Storage::fakeFlush();
		return true;
	}

	/**
	 * Exposes fake-driver state for tests without touching production disks.
	 *
	 * Kernel callers receive false or empty reports when the framework module is unavailable; otherwise the selected driver owns I/O, signing, metadata, and side effects.
	 *
	 * @return array Driver-normalized report, descriptor, or empty fallback array when the framework module is unavailable.
	 */
	public static function fake_snapshot(): array {
		if(core::load_framework_module('storage')!==true || class_exists('\Dataphyre\Storage\Storage')!==true){
			return [];
		}
		return \Dataphyre\Storage\Storage::fakeSnapshot();
	}
}
