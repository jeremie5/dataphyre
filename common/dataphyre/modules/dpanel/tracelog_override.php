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

function tracelog($a=null, $b=null, $c=null, $d=null, $e=null, $f=null, $g=null, $h=null): void {
	if(is_string($f)){
		if($g==='warning' || $g==='fatal'){
			dataphyre\dpanel::$catched_tracelog['errors'].=$f.PHP_EOL;
		}
		else
		{
			dataphyre\dpanel::$catched_tracelog['info'].=$f.PHP_EOL;
		}
	}
}