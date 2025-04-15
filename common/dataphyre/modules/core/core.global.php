<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */

function encrypt_data($a=null,$b=null){return dataphyre\core::encrypt_data($a,$b);}
function decrypt_data($a=null,$b=null){return dataphyre\core::decrypt_data($a,$b);}
function convert_storage_unit($a=null){return dataphyre\core::convert_storage_unit($a);}
function config($a=null){ return dataphyre\core::get_config($a); }
function get_env($a=null){ return dataphyre\core::get_env($a); }
function set_env($a=null, $b=null){ return dataphyre\core::set_env($a, $b); }