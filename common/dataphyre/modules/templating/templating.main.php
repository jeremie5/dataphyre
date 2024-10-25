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


namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");

class templating {

	public static function adapt(array $values, $spacing=false){
		if(null!==$early_return=core::dialback("CALL_TEMPLATING_ADAPT",...func_get_args())) return $early_return;
		global $user_theme_mode;
		if(!empty($values[$user_theme_mode])){
			if($spacing){
				return " ".$values[$user_theme_mode]." ";
			}
			else
			{
				return $values[$user_theme_mode];
			}
		}
	}

}