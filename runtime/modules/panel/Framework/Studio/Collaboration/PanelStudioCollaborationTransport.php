<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Browser-delivery configuration for progressive Studio collaboration. */
final class PanelStudioCollaborationTransport implements \JsonSerializable {
	private const OPTION_NAMES=[
		'visible_poll_milliseconds',
		'hidden_poll_milliseconds',
		'maximum_backoff_milliseconds',
		'request_timeout_milliseconds',
		'presence_heartbeat_milliseconds',
		'typing_idle_milliseconds',
	];
	/** @var array<string,int> */
	private readonly array $settings;

	/** @param array<string,mixed> $options */
	public function __construct(
		private readonly string $endpointUrl,
		private readonly PanelStudioCollaborationIntent $intent,
		array $options=[],
	){
		if(preg_match('~^/(?!/)[A-Za-z0-9._!$&\'()*+,;=:@%/?#-]{0,2047}$~D', $endpointUrl)!==1){
			throw new \InvalidArgumentException('Studio collaboration endpoint URL must be a same-origin absolute path.');
		}
		foreach(array_keys($options) as $name){
			if(!is_string($name)||!in_array($name, self::OPTION_NAMES, true)){
				throw new \InvalidArgumentException('Studio collaboration transport options contain an unsupported name.');
			}
		}
		$this->settings=[
			'visible_poll_milliseconds'=>self::integer($options,'visible_poll_milliseconds',2000,500,60000),
			'hidden_poll_milliseconds'=>self::integer($options,'hidden_poll_milliseconds',10000,1000,120000),
			'maximum_backoff_milliseconds'=>self::integer($options,'maximum_backoff_milliseconds',30000,1000,300000),
			'request_timeout_milliseconds'=>self::integer($options,'request_timeout_milliseconds',10000,1000,60000),
			'presence_heartbeat_milliseconds'=>self::integer($options,'presence_heartbeat_milliseconds',20000,5000,120000),
			'typing_idle_milliseconds'=>self::integer($options,'typing_idle_milliseconds',1500,500,10000),
		];
	}

	public function endpointUrl():string{return $this->endpointUrl;}
	public function intent():PanelStudioCollaborationIntent{return $this->intent;}
	/** @return array<string,int> */ public function settings():array{return $this->settings;}

	/** @return array<string,mixed> */
	public function browserModel():array {
		return [
			'type'=>'panel_studio_collaboration_transport',
			'version'=>1,
			'endpoint_url'=>$this->endpointUrl,
			'intent'=>$this->intent->browserModel(),
			'settings'=>$this->settings,
			'delivery'=>'at_least_once_reads_single_attempt_mutations',
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		return [
			'type'=>'panel_studio_collaboration_transport',
			'version'=>1,
			'endpoint_url'=>$this->endpointUrl,
			'intent'=>$this->intent->jsonSerialize(),
			'settings'=>$this->settings,
			'progressive_enhancement'=>true,
			'visibility_aware'=>true,
			'offline_aware'=>true,
			'adaptive_backoff'=>true,
			'mutation_retries'=>false,
			'raw_intent_serialized'=>false,
		];
	}

	/** @param array<string,mixed> $options */
	private static function integer(array $options,string $name,int $default,int $minimum,int $maximum):int {
		$value=$options[$name]??$default;
		if(!is_int($value)||$value<$minimum||$value>$maximum){
			throw new \InvalidArgumentException("Studio collaboration transport option '{$name}' is outside its supported bound.");
		}
		return $value;
	}
}
