<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Http;

/**
 * Immutable wrapper around one PHP uploaded-file entry.
 *
 * The value object keeps the client-supplied name and MIME type separate from
 * the server temporary path, upload error code, and reported size. Client
 * metadata is informational; `isValid()` and `moveTo()` are the safety gates
 * for interacting with the temporary file.
 */
final class UploadedFile implements \JsonSerializable {

	private ?string $clientExtension=null;

	/**
	 * Captures uploaded-file metadata from PHP request state.
	 *
	 * @param string $name Client-supplied original filename.
	 * @param string $type Client-supplied MIME type.
	 * @param string $tmpName Server temporary file path.
	 * @param int $error PHP upload error code.
	 * @param int $size Reported uploaded size in bytes.
	 */
	public function __construct(
		private string $name,
		private string $type,
		private string $tmpName,
		private int $error,
		private int $size
	){}

	/**
	 * Creates an upload wrapper from a `$_FILES`-style entry.
	 *
	 * Missing fields fall back to safe empty values and `UPLOAD_ERR_NO_FILE`.
	 *
	 * @param array<string, mixed> $file PHP upload entry with name, type, tmp_name, error, and size keys.
	 * @return self Uploaded file wrapper.
	 */
	public static function fromArray(array $file): self {
		return new self(
			(string)($file['name'] ?? ''),
			(string)($file['type'] ?? ''),
			(string)($file['tmp_name'] ?? ''),
			(int)($file['error'] ?? UPLOAD_ERR_NO_FILE),
			(int)($file['size'] ?? 0)
		);
	}

	/**
	 * Returns the client-supplied original filename.
	 *
	 * @return string untrusted filename exactly as reported by the client upload metadata.
	 */
	public function clientOriginalName(): string {
		return $this->name;
	}

	/**
	 * Returns the lowercase extension from the client filename.
	 *
	 * @return string Client filename extension, or an empty string.
	 */
	public function clientExtension(): string {
		if($this->clientExtension!==null){
			return $this->clientExtension;
		}
		$extension=pathinfo($this->name, PATHINFO_EXTENSION);
		return $this->clientExtension=is_string($extension) ? strtolower($extension) : '';
	}

	/**
	 * Returns the client-supplied MIME type.
	 *
	 * @return string untrusted MIME type exactly as reported by the client upload metadata.
	 */
	public function mimeType(): string {
		return $this->type;
	}

	/**
	 * Returns the server temporary upload path.
	 *
	 * @return string Temporary path assigned by PHP.
	 */
	public function path(): string {
		return $this->tmpName;
	}

	/**
	 * Returns the reported upload size.
	 *
	 * @return int byte count reported by PHP for the uploaded temporary file.
	 */
	public function size(): int {
		return $this->size;
	}

	/**
	 * Returns the PHP upload error code.
	 *
	 * @return int One of the `UPLOAD_ERR_*` constants.
	 */
	public function error(): int {
		return $this->error;
	}

	/**
	 * Checks whether the upload can be moved or read from disk.
	 *
	 * @return bool `true` when PHP reported success, a temp path exists, and the temp path is a file.
	 */
	public function isValid(): bool {
		return $this->error===UPLOAD_ERR_OK && $this->tmpName!=='' && is_file($this->tmpName);
	}

	/**
	 * Moves the uploaded temporary file to a caller-selected target path.
	 *
	 * The target directory is created when needed. Real HTTP uploads use
	 * `move_uploaded_file()` so PHP's upload checks remain enforced; non-SAPI/test
	 * files fall back to `rename()` only after passing `isValid()`. Callers remain
	 * responsible for choosing a safe destination outside web-executable paths when
	 * accepting untrusted filenames or content types.
	 *
	 * @param string $target Destination file path.
	 * @return bool `true` when the file is moved successfully.
	 */
	public function moveTo(string $target): bool {
		if(!$this->isValid()){
			return false;
		}
		$directory=dirname($target);
		if($directory!=='' && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)){
			return false;
		}
		if(is_uploaded_file($this->tmpName)){
			return move_uploaded_file($this->tmpName, $target);
		}
		return @rename($this->tmpName, $target);
	}

	/**
	 * Returns a human-readable explanation of the upload error code.
	 *
	 * @return string Message describing the current upload status.
	 */
	public function errorMessage(): string {
		return match($this->error){
			UPLOAD_ERR_OK=>'The file uploaded successfully.',
			UPLOAD_ERR_INI_SIZE=>'The uploaded file exceeds the server upload limit.',
			UPLOAD_ERR_FORM_SIZE=>'The uploaded file exceeds the form upload limit.',
			UPLOAD_ERR_PARTIAL=>'The uploaded file was only partially uploaded.',
			UPLOAD_ERR_NO_FILE=>'No file was uploaded.',
			UPLOAD_ERR_NO_TMP_DIR=>'The temporary upload directory is missing.',
			UPLOAD_ERR_CANT_WRITE=>'The uploaded file could not be written to disk.',
			UPLOAD_ERR_EXTENSION=>'A PHP extension stopped the upload.',
			default=>'Unknown upload error.',
		};
	}

	/**
	 * Serializes upload metadata for diagnostics and APIs.
	 *
	 * @return array{name:string, type:string, tmp_name:string, error:int, size:int, valid:bool} Upload metadata for diagnostics or API responses.
	 */
	public function jsonSerialize(): array {
		return [
			'name'=>$this->name,
			'type'=>$this->type,
			'tmp_name'=>$this->tmpName,
			'error'=>$this->error,
			'size'=>$this->size,
			'valid'=>$this->isValid(),
		];
	}
}
