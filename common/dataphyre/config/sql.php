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

dataphyre\core::add_config([
	'dataphyre'=>[
		'sql'=>[
			'default_cluster'=>'Horizon',
			'default_database_location'=>'shopiro',
			'caching'=>[
				'rolling_db_cache_size'=>256,
				'default_policy'=>[
					'type'=>'shared_cache',
					'max_lifespan'=>'30 minute',
					'hash_type'=>'md5'
				]
			],
			'datacenters'=>[
				// OVH Beauharnois, Montréal, Québec, Canada
				'beauharnois'=>[
					'dbms_clusters'=>[
						'Apex'=>[
							'dbms'=>'mysql',
							'dbms_username'=>'',
							'database_name'=>'',
							// password=core::get_password('Apex'];
							'endpoints'=>[
								'',
							]
						],
						'Horizon'=>[
							'dbms'=>'postgresql',
							'dbms_username'=>'',
							'dbms_port'=>5432,
							'database_name'=>'',
							// password=core::get_password('Horizon'];
							'endpoints'=>[
								'', 
							]
						],
					]
				],
			],
			'tables'=>[
				// shopirocj
				'shopirocj.logistic_data'=>[
					'caching'=>[
						'type'=>'shared_cache',
						'max_lifespan'=>'30 minute',
						'hash_type'=>'md5'
					],
					'cluster'=>'Apex'
				],
				'shopirocj.orders'=>[
					'caching'=>[
						'type'=>'shared_cache',
						'max_lifespan'=>'30 minute',
						'hash_type'=>'md5'
					],
					'cluster'=>'Apex'
				],
				'shopirocj.products'=>[
					'caching'=>[
						'type'=>'shared_cache',
						'max_lifespan'=>'30 minute',
						'hash_type'=>'md5'
					],
					'cluster'=>'Apex'
				],
				'shopirocj.synced_products'=>[
					'caching'=>[
						'type'=>'shared_cache',
						'max_lifespan'=>'30 minute',
						'hash_type'=>'md5'
					],
					'cluster'=>'Apex'
				],
				// shopiro
				'shopiro.selling_balance'=>[
					'caching'=>[
						'type'=>'session',
						'max_lifespan'=>'6 hour',
						'hash_type'=>'md5'
					],
					'primary_column'=>'eventid',
					'cluster'=>'Horizon'
				],
				// dataphyre
				'dataphyre.cdn_files'=>[
					'caching'=>[
						'type'=>'session',
						'max_lifespan'=>'5 minute',
						'hash_type'=>'md5'
					],
					'primary_column'=>'blockid',
					'cluster'=>'Horizon'
				],
				'dataphyre.user_changes'=>[
					'caching'=>[
						'type'=>'session',
						'max_lifespan'=>'5 minute',
						'hash_type'=>'md5'
					],
					'primary_column'=>'changeid',
					'cluster'=>'Horizon'
				],
			]
		]
	]
]);