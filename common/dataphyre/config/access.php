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