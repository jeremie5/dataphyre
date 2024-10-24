<?php
/*************************************************************************
*  2021 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

dataphyre\core::add_config(array(
	'dataphyre'=>array(
		'currency'=>array(
			// exchangerate.host, europa.eu
			'exchange_rate_sources'=>array("exchangerate.host", "europa.eu")
		)
	)
));