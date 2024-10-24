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
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

dataphyre\core::add_config(array(
	'dataphyre'=>array(
		'access'=>array(
			'sanction_on_useragent_change'=>True,
			'sessions_table_name'=>"example_app.sessions",
			'sessions_cookie_name'=>"DPID",
			'must_no_session_redirect'=>dataphyre\core::url_self(),
			'require_session_redirect'=>dataphyre\core::url_self()."login",
			'requires_app_redirect'=>dataphyre\core::url_self()."requires_app",
			'robot_redirect'=>dataphyre\core::url_self()."robot",
			'botlist'=>array(
				'bot','Baiduspider','ia_archiver','R6_FeedFetcher','NetcraftSurveyAgent','Sogou web spider','Yahoo! Slurp','facebookexternalhit',
				'UnwindFetchor','urlresolver','ips-agent','babbar','crawler','spider','wget','Java/1.4.1_04','Ultraseek','InfoSeek','Scooter','Mercator',
				'Ezooms','Mediapartners-Google','Google Web Preview','Domnutch','digincore', 'headless'
			)
		),
	),
));