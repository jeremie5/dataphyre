<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Cdn;

final class BlockPath {

	public static function encode(string $blockpath): string {
		return \dataphyre\cdn::encode_blockpath($blockpath);
	}

	public static function decode(string $blockpath): string {
		return \dataphyre\cdn::decode_blockpath($blockpath);
	}

	public static function toId(string $blockpath): int {
		return \dataphyre\cdn::blockpath_to_blockid($blockpath);
	}

	public static function fromId(int $blockid): string {
		return \dataphyre\cdn::blockid_to_blockpath($blockid);
	}
}
