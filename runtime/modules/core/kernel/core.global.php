<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
/**
 * Proxies legacy global encryption calls to `dataphyre\core::encrypt_data()`.
 *
 * @param mixed $a Plain value, serialized data, or binary-safe string accepted by the core encryption service.
 * @param mixed $b Optional key/context override passed through to the encryption backend.
 * @return mixed ciphertext representation emitted by the configured core encryption backend.
 */
function encrypt_data($a=null,$b=null){return dataphyre\core::encrypt_data($a,$b);}
/**
 * Proxies legacy global decryption calls to `dataphyre\core::decrypt_data()`.
 *
 * @param mixed $a Ciphertext representation previously emitted by the core encryption backend.
 * @param mixed $b Optional key/context override passed through to the decryption backend.
 * @return mixed Plain value restored by the configured core decryption backend, or its failure sentinel.
 */
function decrypt_data($a=null,$b=null){return dataphyre\core::decrypt_data($a,$b);}
/**
 * Converts a storage-size string or value through the core unit parser.
 *
 * @param mixed $a Numeric byte count or unit-suffixed storage size such as `10MB`.
 * @return mixed Normalized byte/unit value produced by the core parser, preserving its invalid-input sentinel.
 */
function convert_storage_unit($a=null){return dataphyre\core::convert_storage_unit($a);}
/**
 * Reads configuration through the core config service.
 *
 * @param mixed $a Config key/path, namespace request, or null for the core service default.
 * @return mixed Configuration value, subtree, default value, or missing-key sentinel from the core service.
 */
function config($a=null){ return dataphyre\core::get_config($a); }
