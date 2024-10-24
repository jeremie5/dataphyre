<?php
/*************************************************************************
*  2020-2022 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the
* property of Shopiro Ltd. and its suppliers, if any. The
* intellectual and technical concepts contained herein are
* proprietary to Shopiro Ltd. and its suppliers and may be
* covered by Canadian and Foreign Patents, patents in process, and
* are protected by trade secret or copyright law. Dissemination of
* this information or reproduction of this material is strictly
* forbidden unless prior written permission is obtained from Shopiro Ltd.
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