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
 * Filesystem-backed storage driver rooted inside one configured directory.
 *
 * The driver normalizes logical storage paths through the shared `Path` helper,
 * prevents path traversal outside the root, writes file data through a temporary
 * file plus rename, and stores portable metadata in JSON sidecars next to the
 * content file.
 */
final class LocalDriver implements StorageDriver {

	private string $root;
	private ?string $url;
	private ?string $signingKey;

	/**
	 * Creates a local disk driver and ensures its storage root exists.
	 *
	 * Supported config keys are `root`, `url`, and `signing_key`. Missing `root`
	 * values fall back to a temp-directory Dataphyre storage area. The configured
	 * public URL is used only for temporary URL generation; filesystem operations
	 * always resolve through the local root.
	 *
	 * @param array{root?:string,url?:string,signing_key?:string} $config Driver configuration.
	 */
	public function __construct(array $config) {
		$this->root=rtrim(str_replace('\\', '/', (string)($config['root'] ?? sys_get_temp_dir().'/dataphyre-storage')), '/');
		$this->url=isset($config['url']) ? rtrim((string)$config['url'], '/') : null;
		$this->signingKey=isset($config['signing_key']) && trim((string)$config['signing_key'])!=='' ? (string)$config['signing_key'] : null;
		if(!is_dir($this->root)){
			@mkdir($this->root, 0775, true);
		}
	}

	/**
	 * Checks whether a logical storage path currently maps to a regular file.
	 *
	 * @param string $path Logical storage path relative to the configured root.
	 * @return bool `true` when the resolved file exists inside the root.
	 * @throws \InvalidArgumentException When the path escapes the storage root.
	 */
	public function exists(string $path): bool {
		return is_file($this->fullPath($path));
	}

	/**
	 * Reads the full contents of a logical storage file.
	 *
	 * Missing files return `false`, matching the storage driver contract and PHP's
	 * stream-reading style. Options are currently accepted for interface symmetry
	 * with remote drivers and are not interpreted by the local backend.
	 *
	 * @param string $path Logical storage path relative to the configured root.
	 * @param array<string, mixed> $options Reserved read options.
	 * @return string|false File bytes, or `false` when the file is absent or unreadable.
	 * @throws \InvalidArgumentException When the path escapes the storage root.
	 */
	public function read(string $path, array $options=[]): string|false {
		$file=$this->fullPath($path);
		return is_file($file) ? file_get_contents($file) : false;
	}

	/**
	 * Opens a binary read stream for a logical storage file.
	 *
	 * The caller owns the returned stream resource and must close it. Missing
	 * files return `false`; no empty stream is synthesized.
	 *
	 * @param string $path Logical storage path relative to the configured root.
	 * @param array<string, mixed> $options Reserved read options.
	 * @return resource|false Binary read stream, or `false` when unavailable.
	 * @throws \InvalidArgumentException When the path escapes the storage root.
	 */
	public function readStream(string $path, array $options=[]): mixed {
		$file=$this->fullPath($path);
		return is_file($file) ? fopen($file, 'rb') : false;
	}

	/**
	 * Writes bytes or a stream to a logical path with atomic local replacement.
	 *
	 * Parent directories are created as needed. Contents are first written to a
	 * random temporary file in the target directory and then renamed into place so
	 * readers do not observe partial writes. Recognized options include
	 * `visibility`, `content_type`, `cache_control`, `content_disposition`, and
	 * `original_name`.
	 *
	 * @param string $path Logical storage path relative to the configured root.
	 * @param mixed $contents Stringable content or readable stream resource.
	 * @param array<string, mixed> $options File visibility and metadata attributes.
	 * @return bool `true` when the content and sidecar metadata were written.
	 * @throws \InvalidArgumentException When the path escapes the storage root.
	 */
	public function write(string $path, mixed $contents, array $options=[]): bool {
		$file=$this->fullPath($path);
		$dir=dirname($file);
		if(!is_dir($dir)){
			@mkdir($dir, 0775, true);
		}
		$tmp=$file.'.tmp.'.bin2hex(random_bytes(8));
		$out=fopen($tmp, 'w+b');
		if($out===false){
			return false;
		}
		$ok=is_resource($contents) ? Stream::copy($contents, $out) : fwrite($out, (string)$contents)!==false;
		fclose($out);
		if(!$ok){
			@unlink($tmp);
			return false;
		}
		if(!@rename($tmp, $file)){
			return false;
		}
		$visibility=(string)($options['visibility'] ?? '');
		if($visibility==='public'){
			@chmod($file, 0644);
		}
		elseif($visibility==='private'){
			@chmod($file, 0600);
		}
		$this->writeAttributes($file, $options);
		return true;
	}

	/**
	 * Deletes a logical file and its Dataphyre metadata sidecar.
	 *
	 * Deleting an already-missing file is considered successful. Sidecar deletion
	 * is best-effort so a stale metadata file cannot prevent content removal.
	 *
	 * @param string $path Logical storage path relative to the configured root.
	 * @return bool `true` when the file is absent after the delete attempt.
	 * @throws \InvalidArgumentException When the path escapes the storage root.
	 */
	public function delete(string $path): bool {
		$file=$this->fullPath($path);
		@unlink($file.'.dataphyre-storage.json');
		return !is_file($file) || @unlink($file);
	}

	/**
	 * Reads file size, modification time, MIME type, and stored sidecar attributes.
	 *
	 * Sidecar `content_type` overrides MIME probing so uploads can preserve a
	 * caller-supplied type even when the local PHP environment cannot detect it.
	 *
	 * @param string $path Logical storage path relative to the configured root.
	 * @return FileMetadata|false Metadata object, or `false` when the file is missing.
	 * @throws \InvalidArgumentException When the path escapes the storage root.
	 */
	public function metadata(string $path): FileMetadata|false {
		$file=$this->fullPath($path);
		if(!is_file($file)){
			return false;
		}
		$mime=function_exists('mime_content_type') ? (mime_content_type($file) ?: null) : null;
		$attributes=$this->readAttributes($file);
		if(isset($attributes['content_type'])){
			$mime=(string)$attributes['content_type'];
		}
		return new FileMetadata(Path::normalize($path), filesize($file) ?: 0, filemtime($file) ?: null, $mime, $attributes);
	}

	/**
	 * Lists metadata for files under a logical prefix.
	 *
	 * The listing is recursive below the prefix and excludes Dataphyre sidecar
	 * files. Returned metadata paths are relative to the driver root, not absolute
	 * filesystem paths.
	 *
	 * @param string $prefix Logical directory prefix to scan.
	 * @param array<string, mixed> $options Reserved listing options.
	 * @return array<int, FileMetadata> Metadata entries for files below the prefix.
	 * @throws \InvalidArgumentException When the prefix escapes the storage root.
	 */
	public function list(string $prefix='', array $options=[]): array {
		$prefix=Path::normalize($prefix);
		$base=$prefix==='' ? $this->root : $this->fullPath($prefix);
		if(!is_dir($base)){
			return [];
		}
		$results=[];
		$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
		foreach($iterator as $file){
			if(!$file instanceof \SplFileInfo || !$file->isFile()){
				continue;
			}
			if(str_ends_with($file->getFilename(), '.dataphyre-storage.json')){
				continue;
			}
			$relative=ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($this->root))), '/');
			$attributes=$this->readAttributes($file->getPathname());
			$results[]=new FileMetadata($relative, $file->getSize(), $file->getMTime(), $attributes['content_type'] ?? null, $attributes);
		}
		return $results;
	}

	/**
	 * Builds a public URL for a local storage path, optionally signed with expiry.
	 *
	 * The driver does not serve files itself; it emits URLs beneath the configured
	 * `url` base. If a signing key is configured or provided in options, the URL
	 * includes `expires` and `signature` query parameters that can be validated by
	 * `verifyTemporaryUrl()`.
	 *
	 * @param string $path Logical storage path relative to the configured root.
	 * @param int|\DateTimeInterface $expires Unix timestamp or datetime expiry.
	 * @param array{signing_key?:string} $options Optional signing override.
	 * @return string|false Public URL, or `false` when no base URL is configured.
	 */
	public function temporaryUrl(string $path, int|\DateTimeInterface $expires, array $options=[]): string|false {
		if($this->url===null){
			return false;
		}
		$path=Path::normalize($path);
		$url=$this->url.'/'.str_replace('%2F', '/', rawurlencode($path));
		$key=(string)($options['signing_key'] ?? $this->signingKey ?? '');
		if($key===''){
			return $url;
		}
		$expiresAt=$expires instanceof \DateTimeInterface ? $expires->getTimestamp() : (int)$expires;
		$signature=hash_hmac('sha256', $path.'|'.$expiresAt, $key);
		$query=http_build_query(['expires'=>$expiresAt, 'signature'=>$signature]);
		return $url.(str_contains($url, '?') ? '&' : '?').$query;
	}

	/**
	 * Verifies a local-driver temporary URL signature.
	 *
	 * Signatures bind the normalized path and expiry timestamp with HMAC-SHA256.
	 * Expired URLs and empty signing keys are rejected before comparing hashes.
	 *
	 * @param string $path Logical storage path from the URL.
	 * @param int $expires Unix timestamp expiry from the URL.
	 * @param string $signature HMAC signature supplied by the URL.
	 * @param string $signingKey Secret used to generate the signature.
	 * @return bool `true` when the signature is valid and the expiry is still in the future.
	 */
	public static function verifyTemporaryUrl(string $path, int $expires, string $signature, string $signingKey): bool {
		if($expires<time() || $signingKey===''){
			return false;
		}
		$expected=hash_hmac('sha256', Path::normalize($path).'|'.$expires, $signingKey);
		return hash_equals($expected, $signature);
	}

	/**
	 * Resolves a logical storage path to an absolute path beneath the driver root.
	 *
	 * @param string $path Logical path supplied by storage callers.
	 * @return string Absolute filesystem path under the configured root.
	 * @throws \InvalidArgumentException When path normalization would escape the root.
	 */
	private function fullPath(string $path): string {
		$full=Path::join($this->root, $path);
		$root=$this->root.'/';
		if($full!==$this->root && !str_starts_with($full, $root)){
			throw new \InvalidArgumentException('Storage path escapes disk root.');
		}
		return $full;
	}

	/**
	 * Persists portable file attributes in a JSON sidecar next to the content file.
	 *
	 * Empty metadata removes the sidecar. Sidecars are deliberately separate from
	 * filesystem permissions so metadata survives platforms that do not support
	 * extended attributes.
	 *
	 * @param string $file Absolute content file path.
	 * @param array<string, mixed> $options Write options containing supported attribute keys.
	 * @return void
	 */
	private function writeAttributes(string $file, array $options): void {
		$attributes=[];
		foreach(['visibility', 'content_type', 'cache_control', 'content_disposition', 'original_name'] as $key){
			if(isset($options[$key]) && trim((string)$options[$key])!==''){
				$attributes[$key]=(string)$options[$key];
			}
		}
		$sidecar=$file.'.dataphyre-storage.json';
		if($attributes===[]){
			@unlink($sidecar);
			return;
		}
		@file_put_contents($sidecar, json_encode($attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Reads the JSON sidecar attributes stored for a local content file.
	 *
	 * Missing or malformed sidecars degrade to an empty attribute map so metadata
	 * reads remain available even when optional sidecar state is damaged.
	 *
	 * @param string $file Absolute content file path.
	 * @return array<string, mixed> Stored sidecar attributes.
	 */
	private function readAttributes(string $file): array {
		$sidecar=$file.'.dataphyre-storage.json';
		if(!is_file($sidecar)){
			return [];
		}
		$decoded=json_decode((string)file_get_contents($sidecar), true);
		return is_array($decoded) ? $decoded : [];
	}
}
