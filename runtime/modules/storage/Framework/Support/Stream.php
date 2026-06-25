<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Support;

/**
 * Normalizes string and resource handling for storage adapters.
 *
 * The helpers accept PHP stream resources and use php://temp for in-memory or
 * spill-to-disk buffering, giving adapters a consistent way to move content
 * between strings, uploads, encryption, and remote SDKs.
 */
final class Stream {

	/**
	 * Creates a readable temp stream from string bytes.
	 *
	 * The returned `php://temp` handle is rewound before return so callers can
	 * pass it directly to drivers, encryption, checksums, or uploads.
	 *
	 * @param string $contents Bytes to write into the stream.
	 * @return resource Temp stream positioned at the beginning.
	 */
	public static function fromString(string $contents): mixed {
		$stream=fopen('php://temp', 'w+b');
		fwrite($stream, $contents);
		rewind($stream);
		return $stream;
	}

	/**
	 * Reads the full contents of a stream from the beginning.
	 *
	 * The input stream is rewound before reading. Non-resource inputs return
	 * false without throwing so storage wrappers can surface ordinary read
	 * failures through their own result contracts.
	 *
	 * @param mixed $stream Stream resource to read.
	 * @return string|false Stream bytes, or false when the input is not a resource or reading fails.
	 */
	public static function contents(mixed $stream): string|false {
		if(!is_resource($stream)){
			return false;
		}
		rewind($stream);
		return stream_get_contents($stream);
	}

	/**
	 * Copies one stream into another from the source beginning.
	 *
	 * The source is rewound before copying. The destination position is left at
	 * the end of the copied bytes, matching `stream_copy_to_stream()` behavior.
	 *
	 * @param mixed $source Readable source stream resource.
	 * @param mixed $destination Writable destination stream resource.
	 * @return bool True when both inputs are resources and stream_copy_to_stream succeeds.
	 */
	public static function copy(mixed $source, mixed $destination): bool {
		if(!is_resource($source) || !is_resource($destination)){
			return false;
		}
		rewind($source);
		return stream_copy_to_stream($source, $destination)!==false;
	}
}
