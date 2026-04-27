<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
function encrypt_data($a=null,$b=null){return dataphyre\core::encrypt_data($a,$b);}
function decrypt_data($a=null,$b=null){return dataphyre\core::decrypt_data($a,$b);}
function convert_storage_unit($a=null){return dataphyre\core::convert_storage_unit($a);}
function config($a=null){ return dataphyre\core::get_config($a); }
function get_env($a=null){ return dataphyre\core::get_env($a); }
function set_env($a=null, $b=null){ return dataphyre\core::set_env($a, $b); }