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
		if(str_contains($path, '..')){
			return self::error('Stored upload path is invalid.');
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
		$total=max(1, min(10000, (int)($post['chunks'] ?? 1)));
		$index=max(0, min($total-1, (int)($post['chunk_index'] ?? 0)));
		$tmp=(string)($file['tmp_name'] ?? '');
		if($tmp==='' || !is_file($tmp)){
			return self::error('Temporary upload chunk is unavailable.');
		}
		$directory=self::chunkDirectory($uploadId);
		if(!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)){
			return self::error('Could not prepare upload workspace.');
		}
		$chunkPath=$directory.'/part-'.str_pad((string)$index, 6, '0', STR_PAD_LEFT);
		if(!@move_uploaded_file($tmp, $chunkPath) && !@rename($tmp, $chunkPath)){
			if(!@copy($tmp, $chunkPath)){
				return self::error('Could not persist upload chunk.');
			}
		}
		$manifest=[
			'upload_id'=>$uploadId,
			'filename'=>$filename,
			'size'=>max(0, (int)($post['size'] ?? $file['size'] ?? 0)),
			'mime'=>trim((string)($post['type'] ?? $file['type'] ?? 'application/octet-stream')) ?: 'application/octet-stream',
			'chunks'=>$total,
			'updated_at'=>time(),
		];
		@file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
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
		$disk=self::storageName((string)($post['storage_disk'] ?? 'local')) ?: 'local';
		$template=trim((string)($post['storage_path'] ?? 'panel_uploads/{date}/{filename}'), "\\/") ?: 'panel_uploads/{date}/{filename}';
		$path=self::storagePath($template, $filename, $uploadId, (string)($post['field'] ?? 'file'), (string)($post['storage_collection'] ?? 'default'));
		$options=array_filter([
			'content_type'=>$manifest['mime'],
			'original_name'=>$filename,
			'visibility'=>trim((string)($post['storage_visibility'] ?? '')) ?: null,
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
			'mime'=>$manifest['mime'],
			'size'=>$metadata ? $metadata->size() : (int)@filesize($assembled),
			'url'=>Storage::temporaryUrl($path, time()+3600, $disk) ?: null,
		];
		self::cleanup($directory);
		return [
			'ok'=>true,
			'complete'=>true,
			'file'=>$item,
		];
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
		return trim(preg_replace('#/+#', '/', str_replace('\\', '/', $path)) ?? $stored, '/');
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
			stream_copy_to_stream($in, $out);
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
		@rmdir($directory);
	}

	/**
	 * Sanitizes upload tokens to characters safe for temporary path segments.
	 *
	 * @param string $value Raw token value.
	 * @return string Sanitized token.
	 */
	private static function token(string $value): string {
		return preg_replace('/[^A-Za-z0-9_.-]+/', '', trim($value)) ?? '';
	}

	/**
	 * Sanitizes storage disk, field, and collection names.
	 *
	 * @param string $value Raw storage-related name.
	 * @return string Name containing only storage-safe characters.
	 */
	private static function storageName(string $value): string {
		return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($value)) ?? '';
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
