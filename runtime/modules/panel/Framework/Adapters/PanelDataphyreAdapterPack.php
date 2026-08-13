<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** First-party Access, Fulltext, Mailer, and Storage integration pack for Panel. */
final class PanelDataphyreAdapterPack {
	public static function make(): PanelAdapterPack {
		return PanelAdapterPack::make('dataphyre_framework_adapters','1.1.0',[
			'label'=>'Dataphyre Framework Adapters',
			'description'=>'Transactional Panel bridges for Dataphyre Access, Fulltext, Mailer, and Storage.',
			'publisher'=>'Shopiro Ltd.',
		])
		->binding(PanelAdapterPackBinding::make(
			'access',
			'plugin:dataphyre_access',
			PanelPlugin::class,
			static function(PanelAdapterPackContext $context,array $config):PanelPlugin {
				$options=$config['options']??[];
				if(!is_array($options)){throw new \InvalidArgumentException('Access adapter options must be a map.');}
				return new PanelDataphyreAccessPlugin($options);
			},
			null,
			[
				'required_classes'=>[\Dataphyre\Access\PanelAuth::class],
				'config_keys'=>['options'],
				'capabilities'=>['authentication_pages','panel_authorization','session_auth'],
				'optional'=>true,
			]
		))
		->binding(PanelAdapterPackBinding::make(
			'fulltext',
			'search:dataphyre_fulltext',
			PanelSearchProvider::class,
			static function(PanelAdapterPackContext $context,array $config):PanelSearchProvider {
				if(($config['provider']??null) instanceof PanelSearchProvider){return $config['provider'];}
				$index=is_string($config['index']??null)?trim($config['index']):'';
				if($index===''){throw new \InvalidArgumentException('Fulltext adapters require index or provider configuration.');}
				$mapper=$config['map']??null;
				if($mapper!==null&&!is_callable($mapper)){throw new \InvalidArgumentException('Fulltext adapter map must be callable.');}
				$options=$config['options']??[];
				if(!is_array($options)){throw new \InvalidArgumentException('Fulltext adapter options must be a map.');}
				if(is_callable($config['search']??null)){
					return (new PanelDataphyreFulltextSearchAdapter(
						'dataphyre_fulltext',$index,$config['search'],$mapper,$options
					))->provider();
				}
				$manager=$config['manager']??\Dataphyre\FulltextEngine\SearchManager::instance();
				if(!$manager instanceof \Dataphyre\FulltextEngine\SearchManager){
					throw new \InvalidArgumentException('Fulltext adapter manager must be a Dataphyre SearchManager.');
				}
				return PanelDataphyreFulltextSearchAdapter::fromManager(
					'dataphyre_fulltext',$index,$manager,$mapper,$options
				)->provider();
			},
			PanelAdapterConformanceCatalog::searchProvider(),
			[
				'required_classes'=>[\Dataphyre\FulltextEngine\SearchManager::class],
				'config_keys'=>['index','manager','map','options','provider','search'],
				'capabilities'=>['bounded_results','fulltext_search','global_search','typed_results'],
				'optional'=>true,
			]
		))
		->binding(PanelAdapterPackBinding::make(
			'mailer',
			'platform:notifications.adapter',
			PanelNotificationAdapter::class,
			static function(PanelAdapterPackContext $context,array $config):PanelNotificationAdapter {
				if(($config['adapter']??null) instanceof PanelNotificationAdapter){return $config['adapter'];}
				$directory=is_string($config['directory']??null)?trim($config['directory']):'';
				$recipient=$config['recipient']??null;
				if($directory===''||!is_callable($recipient)){
					throw new \InvalidArgumentException('Mailer adapters require directory and recipient configuration.');
				}
				$options=$config['options']??[];
				if(!is_array($options)){throw new \InvalidArgumentException('Mailer adapter options must be a map.');}
				if(is_callable($config['send']??null)){
					return new PanelDataphyreMailerNotificationAdapter($directory,$config['send'],$recipient,$options);
				}
				$manager=$config['manager']??\Dataphyre\Mailer\MailerManager::instance();
				if(!$manager instanceof \Dataphyre\Mailer\MailerManager){
					throw new \InvalidArgumentException('Mailer adapter manager must be a Dataphyre MailerManager.');
				}
				return PanelDataphyreMailerNotificationAdapter::fromManager($directory,$manager,$recipient,$options);
			},
			PanelAdapterConformanceCatalog::notificationAdapter(),
			[
				'required_classes'=>[\Dataphyre\Mailer\MailerManager::class],
				'config_keys'=>['adapter','directory','manager','options','recipient','send'],
				'capabilities'=>['delivery_receipts','durable_inbox','mailer_delivery','realtime_feed'],
				'optional'=>true,
			]
		))
		->binding(PanelAdapterPackBinding::make(
			'storage_media',
			'platform:media.manager',
			PanelMediaManager::class,
			static function(PanelAdapterPackContext $context,array $config):PanelMediaManager {
				if(($config['media_manager']??null) instanceof PanelMediaManager){return $config['media_manager'];}
				$manager=$config['storage_manager']??\Dataphyre\Storage\StorageManager::instance();
				if(!$manager instanceof \Dataphyre\Storage\StorageManager){
					throw new \InvalidArgumentException('Storage media adapters require a Dataphyre StorageManager.');
				}
				$disk=is_string($config['disk']??null)?trim($config['disk']):'';
				if($disk===''){throw new \InvalidArgumentException('Storage media adapters require a named disk.');}
				$prefix=$config['prefix']??'panel-media';
				if(!is_string($prefix)){throw new \InvalidArgumentException('Storage media adapter prefix must be a string.');}
				$options=$config['options']??[];
				if(!is_array($options)){throw new \InvalidArgumentException('Storage media adapter options must be a map.');}
				$catalog=$config['catalog']??null;
				if(!$catalog instanceof PanelSnapshotStore){
					$directory=is_string($config['catalog_directory']??null)?trim($config['catalog_directory']):'';
					if($directory===''){throw new \InvalidArgumentException('Storage media adapters require catalog or catalog_directory configuration.');}
					$catalog=new PanelAtomicSnapshotStore(
						$directory,
						'panel.media.catalog',
						['items'=>[],'uploads'=>[]],
						(int)($options['retention']??512)
					);
				}
				$signingKey=$config['signing_key']??null;
				if($signingKey!==null&&!is_string($signingKey)){
					throw new \InvalidArgumentException('Storage media adapter signing_key must be a string or null.');
				}
				$diskOptions=is_array($options['disk']??null)?$options['disk']:[];
				$managerOptions=is_array($options['manager']??null)?$options['manager']:$options;
				unset($managerOptions['disk'],$managerOptions['manager'],$managerOptions['retention']);
				return PanelMediaManager::forDisk(
					new PanelDataphyreStorageMediaDisk($manager,$disk,$prefix,$diskOptions),
					$catalog,
					is_string($signingKey)&&$signingKey!==''?$signingKey:null,
					$managerOptions
				);
			},
			PanelAdapterConformanceCatalog::mediaManager(),
			[
				'required_classes'=>[\Dataphyre\Storage\StorageManager::class],
				'config_keys'=>['catalog','catalog_directory','disk','media_manager','options','prefix','signing_key','storage_manager'],
				'capabilities'=>['change_feed','dataphyre_storage','media_cleanup','media_processing','private_delivery','resumable_uploads'],
				'optional'=>true,
				'replace'=>true,
			]
		));
	}
}
