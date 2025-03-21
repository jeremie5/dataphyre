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
 
namespace dataphyre\access;

class diagnostic{

    public static function tests(array &$verbose=[]): bool {
		foreach(glob(__DIR__."/unit_tests/*.json") as $file){
			if(false===\dataphyre\dpanel::unit_test($file, $verbose)){
				$all_passed=false;
			}
		}
        return $all_passed;
    }

}