<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
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