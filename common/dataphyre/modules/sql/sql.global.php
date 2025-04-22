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

new \dataphyre\sql();

function sql_count($a=null,$b=null,$c=null, $d=null, $e=null, $f=null){ return dataphyre\sql::count($a,$b,$c,$d,$e,$f); }
function sql_select($a=null,$b=null,$c=null,$d=null,$e=null,$f=null,$g=null,$h=null){ return dataphyre\sql::select($a,$b,$c,$d,$e,$f,$g,$h); }
function sql_delete($a=null,$b=null,$c=null,$d=null,$e=null,$f=null){ return dataphyre\sql::delete($a,$b,$c,$d,$e,$f); }
function sql_update($a=null,$b=null,$c=null,$d=null,$e=null,$f=null,$g=null){ return dataphyre\sql::update($a,$b,$c,$d,$e,$f,$g); }
function sql_insert($a=null,$b=null,$c=null,$d=null,$e=null,$f=null){ return dataphyre\sql::insert($a,$b,$c,$d,$e,$f); }
function sql_query($a=null,$b=null,$c=null,$d=null,$e=null,$f=null, $g=null, $h=null){ return dataphyre\sql::query($a,$b,$c,$d,$e,$f,$g,$h); }
function sql_upsert($a=null,$b=null,$c=null,$d=null,$e=null,$f=null, $g=null){ return dataphyre\sql::upsert($a,$b,$c,$d,$e,$f,$g); }
function sql_transaction($a=null,$b=null){ return dataphyre\sql::transaction($a,$b); }
function sql_begin($a=null){ return dataphyre\sql::begin($a); }
function sql_commit($a=null){ return dataphyre\sql::commit($a); }
function sql_rollback($a=null){ return dataphyre\sql::rollback($a); }
function sql_table($a=null, $b=null){ return dataphyre\sql::table($a, $b); }