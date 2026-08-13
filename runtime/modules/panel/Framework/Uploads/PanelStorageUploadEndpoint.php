<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

use Dataphyre\Storage\Storage;

/**
 * Handles chunked Panel uploads and persists completed files through Storage.
 *
 * The endpoint accepts browser-uploaded chunks, stores them in a temporary
 * workspace keyed by upload id, assembles the final object after every chunk is
 * present, writes it to the configured storage disk, and returns a normalized
 * file descriptor for Panel field state.
 */
final class PanelStorageUploadEndpoint {

	/**
	 * Deletes a previously stored upload from a storage disk.
	 *
	 * Paths are normalized to slash form and rejected when empty or containing
	 * parent traversal segments before Storage::delete is called.
	 *
	 * @param array{disk?:string,path?:string} $post Request payload identifying the storage disk and relative object path to delete.
	 * @return array{ok:bool,deleted?:bool,disk?:string,path?:string,error?:string} Delete result for the Panel client.
	 */
	public static function delete(array $post): array {
		$path=trim(str_replace('\\', '/', (string)($post['path'] ?? '')), '/');
		$disk=self::storageName((string)($post['disk'] ?? 'local')) ?: 'local';
		if($path===''){
			return self::error('Stored upload path is missing.');
		}
		if(!self::pathSegmentsSafe($path)){
			return self::error('Stored upload path is invalid.');
		}
		if(!self::deletePathAllowed($path)){
			return self::error('Stored upload path is outside the allowed upload prefixes.');
		}
		if(!class_exists(Storage::class)){
			return self::error('Dataphyre Storage is unavailable.');
		}
		if(!Storage::delete($path, $disk)){
			return self::error('Dataphyre Storage could not delete the upload.');
		}
		return [
			'ok'=>true,
			'deleted'=>true,
			'disk'=>$disk,
			'path'=>$path,
		];
	}

	/**
	 * Accepts one upload chunk and persists the assembled file when complete.
	 *
	 * The method validates upload identity, clamps chunk counts, writes the
	 * current part into the temporary workspace, updates a manifest, and returns
	 * a pending response until all chunks exist. Completed uploads are assembled,
	 * stored through Dataphyre Storage, described with metadata and a temporary
	 * URL, then the workspace is removed.
	 *
	 * @param array{upload_id?:string,filename?:string,size?:int|string,type?:string,chunks?:int|string,chunk_index?:int|string,storage_disk?:string,storage_path?:string,field?:string,storage_collection?:string,storage_visibility?:string} $post Browser upload metadata and storage routing options for the current chunk.
	 * @param array{file?:array{name?:string,type?:string,tmp_name?:string,error?:int|string,size?:int|string}} $files PHP upload payload containing the current chunk under "file".
	 * @return array{ok:bool,pending?:bool,complete?:bool,upload_id?:string,chunk?:int,chunks?:int,file?:array{upload_id:string,disk:string,path:string,filename:string,original_name:string,mime:string,size:int,url:?string},error?:string} Client upload state.
	 */
	public static function handle(array $post, array $files): array {
		$file=$files['file'] ?? null;
		if(!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
			return self::error('Upload chunk is missing or invalid.');
		}
		$uploadId=self::token((string)($post['upload_id'] ?? ''));
		$filename=self::cleanFilename((string)($post['filename'] ?? $file['name'] ?? 'file'));
		if($uploadId==='' || $filename===''){
			return self::error('Upload identity is missing.');
		}
		$total=self::integer($post['chunks'] ?? 1);
		$index=self::integer($post['chunk_index'] ?? 0);
		if($total===null || $total<1 || $total>10000){
			return self::error('Upload chunk count is invalid.');
		}
		if($index===null || $index<0 || $index>=$total){
			return self::error('Upload chunk index is invalid.');
		}
		$declaredSize=self::integer($post['size'] ?? $file['size'] ?? 0);
		if($declaredSize===null || $declaredSize<0 || ($total>1 && $declaredSize===0)){
			return self::error('Upload size is invalid.');
		}
		$maxUploadBytes=self::configuredByteLimit('upload_max_bytes', 536870912);
		if($maxUploadBytes>0 && $declaredSize>$maxUploadBytes){
			return self::error('Upload exceeds the configured size limit.');
		}
		$disk=self::storageName((string)($post['storage_disk'] ?? 'local')) ?: 'local';
		$template=trim((string)($post['storage_path'] ?? 'panel_uploads/{date}/{filename}'), "\\/") ?: 'panel_uploads/{date}/{filename}';
		$field=self::storageName((string)($post['field'] ?? 'file')) ?: 'file';
		$collection=self::storageName((string)($post['storage_collection'] ?? 'default')) ?: 'default';
		$visibility=trim((string)($post['storage_visibility'] ?? ''));
		$path=self::storagePath($template, $filename, $uploadId, $field, $collection);
		if($path===''){
			return self::error('Upload storage path is invalid.');
		}
		$tmp=(string)($file['tmp_name'] ?? '');
		if($tmp==='' || !is_file($tmp)){
			return self::error('Temporary upload chunk is unavailable.');
		}
		$chunkBytes=@filesize($tmp);
		if(!is_int($chunkBytes) || $chunkBytes<0){
			return self::error('Upload chunk size could not be verified.');
		}
		$maxChunkBytes=self::configuredByteLimit('upload_max_chunk_bytes', 52428800);
		if($maxChunkBytes>0 && $chunkBytes>$maxChunkBytes){
			return self::error('Upload chunk exceeds the configured size limit.');
		}
		if($declaredSize>0 && $chunkBytes>$declaredSize){
			return self::error('Upload chunk exceeds the declared upload size.');
		}
		if($declaredSize===0){
			$declaredSize=$chunkBytes;
		}
		$directory=self::chunkDirectory($uploadId);
		if(!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)){
			return self::error('Could not prepare upload workspace.');
		}
		$lock=@fopen($directory.'/.upload.lock', 'c+b');
		if(!is_resource($lock) || !@flock($lock, LOCK_EX)){
			if(is_resource($lock)){
				fclose($lock);
			}
			return self::error('Could not lock upload workspace.');
		}
		$cleanupAfterUnlock=false;
		try{
			$manifest=[
				'upload_id'=>$uploadId,
				'filename'=>$filename,
				'size'=>$declaredSize,
				'mime'=>self::mimeType((string)($post['type'] ?? $file['type'] ?? 'application/octet-stream')),
				'chunks'=>$total,
				'disk'=>$disk,
				'path'=>$path,
				'visibility'=>$visibility,
				'updated_at'=>time(),
			];
			$manifestPath=$directory.'/manifest.json';
			if(is_file($manifestPath)){
				$existing=json_decode((string)@file_get_contents($manifestPath), true);
				if(!is_array($existing) || !self::manifestMatches($existing, $manifest)){
					return self::error('Upload metadata changed between chunks.');
				}
				$manifest=array_replace($manifest, $existing, ['updated_at'=>time()]);
			}
			$chunkPath=$directory.'/part-'.str_pad((string)$index, 6, '0', STR_PAD_LEFT);
			if(!@move_uploaded_file($tmp, $chunkPath) && !@rename($tmp, $chunkPath)){
				if(!@copy($tmp, $chunkPath)){
					return self::error('Could not persist upload chunk.');
				}
			}
			if(@file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX)===false){
				return self::error('Could not persist upload metadata.');
			}
			if(!self::chunksComplete($directory, $total)){
				return [
					'ok'=>true,
					'pending'=>true,
					'upload_id'=>$uploadId,
					'chunk'=>$index,
					'chunks'=>$total,
				];
			}
			$assembled=$directory.'/assembled.bin';
			if(!self::assemble($directory, $assembled, $total)){
				return self::error('Could not assemble upload chunks.');
			}
			$assembledBytes=@filesize($assembled);
			if(!is_int($assembledBytes) || $assembledBytes!==$declaredSize){
				$cleanupAfterUnlock=true;
				return self::error('Assembled upload size does not match the declared size.');
			}
			$detectedMime=self::detectedMimeType($assembled);
			$storedMime=$detectedMime!=='application/octet-stream' ? $detectedMime : (string)$manifest['mime'];
			$options=array_filter([
				'content_type'=>$storedMime,
				'original_name'=>$filename,
				'visibility'=>$manifest['visibility'] ?: null,
			], static fn(mixed $value): bool => $value!==null && $value!=='');
			if(!class_exists(Storage::class)){
				return self::error('Dataphyre Storage is unavailable.');
			}
			if(!Storage::putFile($path, $assembled, $disk, $options)){
				return self::error('Dataphyre Storage could not persist the upload.');
			}
			$metadata=Storage::metadata($path, $disk);
			$item=[
				'upload_id'=>$uploadId,
				'disk'=>$disk,
				'path'=>$path,
				'filename'=>basename($path),
				'original_name'=>$filename,
				'mime'=>$storedMime,
				'size'=>$metadata ? $metadata->size() : (int)@filesize($assembled),
				'url'=>Storage::temporaryUrl($path, time()+3600, $disk) ?: null,
			];
			$cleanupAfterUnlock=true;
			return ['ok'=>true, 'complete'=>true, 'file'=>$item]; } finally {
			@flock($lock, LOCK_UN);
			fclose($lock);
			if($cleanupAfterUnlock){
				self::cleanup($directory);
			}
		}
	}

	/**
	 * Expands a storage path template for the final uploaded object.
	 *
	 * @param string $template Slash-delimited path template with supported placeholders.
	 * @param string $filename Sanitized original filename.
	 * @param string $uploadId Sanitized upload id.
	 * @param string $field Panel field name.
	 * @param string $collection Panel storage collection name.
	 * @return string Normalized relative storage path.
	 */
	private static function storagePath(string $template, string $filename, string $uploadId, string $field, string $collection): string {
		$extension=strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		$name=pathinfo($filename, PATHINFO_FILENAME);
		$hash=substr(hash('sha256', $uploadId.'|'.$filename), 0, 16);
		$stored=$extension!=='' ? self::cleanFilename($name).'-'.$hash.'.'.$extension : self::cleanFilename($name).'-'.$hash;
		$path=strtr($template, [
			'{date}'=>gmdate('Y/m/d'),
			'{field}'=>self::storageName($field) ?: 'file',
			'{collection}'=>self::storageName($collection) ?: 'default',
			'{filename}'=>$stored,
			'{original}'=>$filename,
			'{name}'=>self::cleanFilename($name),
			'{ext}'=>$extension,
			'{hash}'=>$hash,
			'{id}'=>$uploadId,
		]);
		$path=trim(preg_replace('#/+#', '/', str_replace('\\', '/', $path)) ?? '', '/');
		if($path===''){
			return '';
		}
		return self::pathSegmentsSafe($path) ? $path : '';
	}

	/**
	 * Checks slash-delimited storage paths for empty and traversal segments.
	 *
	 * @param string $path Normalized relative storage path.
	 * @return bool Whether every segment is a usable relative component.
	 */
	private static function pathSegmentsSafe(string $path): bool {
		if($path===''){
			return false;
		}
		foreach(explode('/', str_replace('\\', '/', $path)) as $segment){
			if($segment==='' || $segment==='.' || $segment==='..'){
				return false;
			}
		}
		return true;
	}

	/**
	 * Restricts browser-triggered deletion to configured Panel upload prefixes.
	 *
	 * The secure default covers objects produced by the default storage template.
	 * Applications using custom templates must add their root prefixes through
	 * `upload_delete_prefixes`; `*` is an explicit opt-out of prefix isolation.
	 *
	 * @param string $path Validated relative object path.
	 * @return bool Whether the path belongs to an allowed upload namespace.
	 */
	private static function deletePathAllowed(string $path): bool {
		$configured=PanelConfig::config('upload_delete_prefixes', ['panel_uploads']);
		$prefixes=is_array($configured) ? $configured : [$configured];
		foreach($prefixes as $prefix){
			$prefix=trim(str_replace('\\', '/', (string)$prefix), '/');
			if($prefix==='*'){
				return true;
			}
			if($prefix==='' || !self::pathSegmentsSafe($prefix)){
				continue;
			}
			if($path===$prefix || str_starts_with($path, $prefix.'/')){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether every expected chunk file exists in the workspace.
	 *
	 * @param string $directory Temporary upload workspace.
	 * @param int $total Expected number of chunks.
	 * @return bool True when all chunk files are present.
	 */
	private static function chunksComplete(string $directory, int $total): bool {
		for($index=0;$index<$total;$index++){
			if(!is_file($directory.'/part-'.str_pad((string)$index, 6, '0', STR_PAD_LEFT))){
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks that immutable upload metadata matches the first chunk manifest.
	 *
	 * @param array<string, mixed> $existing Stored first-chunk manifest.
	 * @param array<string, mixed> $incoming Incoming chunk manifest.
	 * @return bool True when identity, size, routing, and chunk count are unchanged.
	 */
	private static function manifestMatches(array $existing, array $incoming): bool {
		foreach(['upload_id', 'filename', 'size', 'mime', 'chunks', 'disk', 'path', 'visibility'] as $key){
			if(!array_key_exists($key, $existing) || (string)$existing[$key] !== (string)($incoming[$key] ?? null)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Concatenates chunk files into one assembled upload file.
	 *
	 * @param string $directory Temporary upload workspace containing part files.
	 * @param string $target Destination path for the assembled binary.
	 * @param int $total Expected number of chunk files to copy.
	 * @return bool True when the assembled target exists after copying.
	 */
	private static function assemble(string $directory, string $target, int $total): bool {
		$out=@fopen($target, 'wb');
		if(!is_resource($out)){
			return false;
		}
		for($index=0;$index<$total;$index++){
			$part=$directory.'/part-'.str_pad((string)$index, 6, '0', STR_PAD_LEFT);
			$in=@fopen($part, 'rb');
			if(!is_resource($in)){
				fclose($out);
				return false;
			}
			if(@stream_copy_to_stream($in, $out)===false){
				fclose($in);
				fclose($out);
				@unlink($target);
				return false;
			}
			fclose($in);
		}
		fclose($out);
		return is_file($target);
	}

	/**
	 * Returns the temporary workspace path for one upload id.
	 *
	 * @param string $uploadId Sanitized upload token.
	 * @return string Absolute directory under the system temp path.
	 */
	private static function chunkDirectory(string $uploadId): string {
		return rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/dataphyre-panel-uploads/'.$uploadId;
	}

	/**
	 * Removes temporary upload files and the empty workspace directory.
	 *
	 * @param string $directory Temporary upload workspace.
	 * @return void
	 */
	private static function cleanup(string $directory): void {
		if(!is_dir($directory)){
			return;
		}
		foreach(glob($directory.'/*') ?: [] as $file){
			if(is_file($file)){
				@unlink($file);
			}
		}
		@unlink($directory.'/.upload.lock');
		@rmdir($directory);
	}

	/**
	 * Sanitizes upload tokens to characters safe for temporary path segments.
	 *
	 * @param string $value Raw token value.
	 * @return string Sanitized token.
	 */
	private static function token(string $value): string {
		$value=preg_replace('/[^A-Za-z0-9_.-]+/', '', trim($value)) ?? '';
		if(!preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9_.-]{0,126}[A-Za-z0-9])?\z/', $value)){
			return '';
		}
		return $value;
	}

	/**
	 * Parses an integer request field without accepting partial numeric strings.
	 *
	 * @param mixed $value Raw request value.
	 * @return int|null Parsed integer, or null when the value is not an integer.
	 */
	private static function integer(mixed $value): ?int {
		if(is_int($value)){
			return $value;
		}
		if(!is_string($value) || !preg_match('/\A-?\d+\z/', trim($value))){
			return null;
		}
		$validated=filter_var(trim($value), FILTER_VALIDATE_INT);
		return $validated===false ? null : $validated;
	}

	/**
	 * Resolves a non-negative byte limit from Panel configuration.
	 *
	 * Invalid values fail back to the supplied secure default. A configured zero
	 * explicitly disables that limit for applications that enforce quotas at a
	 * different trusted boundary.
	 *
	 * @param string $key Panel configuration key.
	 * @param int $default Default byte limit.
	 * @return int Non-negative byte limit.
	 */
	private static function configuredByteLimit(string $key, int $default): int {
		$value=PanelConfig::config($key, $default);
		$parsed=self::integer($value);
		return $parsed!==null && $parsed>=0 ? $parsed : $default;
	}

	/**
	 * Normalizes a caller-provided MIME value to a single safe media type.
	 *
	 * Parameters and control characters are discarded. Invalid values use the
	 * generic binary type until server-side detection can inspect the assembled
	 * object.
	 *
	 * @param string $value Browser-provided MIME value.
	 * @return string Safe MIME type.
	 */
	private static function mimeType(string $value): string {
		$value=strtolower(trim(explode(';', $value, 2)[0]));
		return preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*\z/', $value)===1
			? $value
			: 'application/octet-stream';
	}

	/**
	 * Detects the assembled file MIME type without trusting browser metadata.
	 *
	 * @param string $path Assembled temporary file.
	 * @return string Detected MIME type or the generic binary fallback.
	 */
	private static function detectedMimeType(string $path): string {
		if(!class_exists('finfo')){
			return 'application/octet-stream';
		}
		$finfo=new \finfo(FILEINFO_MIME_TYPE);
		$detected=$finfo->file($path);
		return is_string($detected) ? self::mimeType($detected) : 'application/octet-stream';
	}

	/**
	 * Sanitizes storage disk, field, and collection names.
	 *
	 * @param string $value Raw storage-related name.
	 * @return string Name containing only storage-safe characters.
	 */
	private static function storageName(string $value): string {
		$value=preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($value)) ?? '';
		return trim($value, '.-');
	}

	/**
	 * Converts an uploaded filename into a storage-safe basename.
	 *
	 * @param string $filename Browser-provided filename.
	 * @return string Non-empty filename using safe ASCII separators.
	 */
	private static function cleanFilename(string $filename): string {
		$filename=trim(str_replace(["\0", "\\", "/"], '-', $filename));
		$filename=preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $filename) ?: 'file';
		return trim($filename, '.-') ?: 'file';
	}

	/**
	 * Creates a normalized error payload for Panel upload callers.
	 *
	 * @param string $message Human-readable error message.
	 * @return array{ok:bool,error:string} Error response.
	 */
	private static function error(string $message): array {
		return ['ok'=>false, 'error'=>$message];
	}
}
