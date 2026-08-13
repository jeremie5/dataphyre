<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Owns the Context capabilities described by its name. */
trait ReadsStructuredData {

	public function decodeJson(string $json, bool $associative=true): mixed {
		return json_decode($json, $associative, 512, JSON_THROW_ON_ERROR);
	}

	/** @return array<mixed> */
	public function jsonArray(string $json): array {
		$value=$this->decodeJson($json, true);
		if(!is_array($value)){
			throw new \UnexpectedValueException('Expected decoded JSON to be an array or object.');
		}
		return $value;
	}

	/** Decodes optional machine output without mistaking human-readable output for an empty payload. */
	public function tryJsonArray(string $json): ?array {
		try{
			$value=$this->decodeJson($json, true);
		}catch(\JsonException){
			return null;
		}
		return is_array($value) ? $value : null;
	}

	public function readJson(string $path, bool $associative=true): mixed {
		$contents=is_file($path) ? file_get_contents($path) : false;
		if(!is_string($contents)){
			throw new \RuntimeException('Unable to read JSON test artifact: '.$path);
		}
		return $this->decodeJson($contents, $associative);
	}

	/** @return array<mixed> */
	public function readJsonArray(string $path): array {
		$value=$this->readJson($path, true);
		if(!is_array($value)){
			throw new \UnexpectedValueException('Expected JSON test artifact to contain an array or object: '.$path);
		}
		return $value;
	}
}
