<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Durable Panel inbox with a first-class Dataphyre Mailer delivery channel.
 *
 * The storage directory, recipient resolver, message factory, transport
 * callback, and provider configuration never enter the public manifest.
 */
final class PanelDataphyreMailerNotificationAdapter implements PanelNotificationAdapter, \JsonSerializable {
	private readonly PanelFilesystemNotificationAdapter $inner;
	private readonly \Closure $send;
	private readonly \Closure $recipient;
	private readonly string $channel;

	/**
	 * @param callable(mixed,PanelInboxNotification,mixed,array<string,mixed>):mixed $send
	 * @param callable(PanelInboxNotification,array<string,mixed>):mixed $recipient
	 * @param array<string,mixed> $options
	 */
	public function __construct(
		string $directory,
		callable $send,
		callable $recipient,
		private readonly array $options=[]
	) {
		if(trim($directory)==='' || str_contains($directory,"\0")){
			throw new \InvalidArgumentException('Mailer notification adapters require a private storage directory.');
		}
		$this->send=\Closure::fromCallable($send);
		$this->recipient=\Closure::fromCallable($recipient);
		$this->channel=Resource::normalizeName((string)($options['channel']??'email')) ?: 'email';
		$channels=$options['channels']??['database',$this->channel];
		if(!is_array($channels)){$channels=[$channels];}
		if(!in_array($this->channel,$channels,true)){$channels[]=$this->channel;}
		$this->inner=new PanelFilesystemNotificationAdapter(
			$directory,
			[],
			$channels,
			[],
			max(2,(int)($options['snapshot_retention']??512)),
			max(50,(int)($options['delivery_retention']??5000))
		);
		$this->inner->handler($this->channel,fn(PanelInboxNotification $notification):array=>$this->dispatch($notification));
	}

	/**
	 * @param callable(PanelInboxNotification,array<string,mixed>):mixed $recipient
	 * @param array<string,mixed> $options
	 */
	public static function fromManager(
		string $directory,
		\Dataphyre\Mailer\MailerManager $manager,
		callable $recipient,
		array $options=[]
	): self {
		$send=static function(mixed $message,PanelInboxNotification $notification,mixed $target,array $adapterOptions) use ($manager):mixed {
			return $manager->send(
				$message,
				isset($adapterOptions['provider'])?(string)$adapterOptions['provider']:null,
				is_array($adapterOptions['send_options']??null)?$adapterOptions['send_options']:[]
			);
		};
		return new self($directory,$send,$recipient,$options);
	}

	public function store(PanelInboxNotification|PanelNotification|array|string $notification, ?string $recipient=null, array $meta=[]): PanelInboxNotification {
		return $this->inner->store($notification,$recipient,$meta);
	}
	/** @return array<int,PanelInboxNotification> */
	public function all(bool $includeDismissed=false): array {return $this->inner->all($includeDismissed);}
	/** @return array<int,PanelInboxNotification> */
	public function unread(bool $includeDismissed=false): array {return $this->inner->unread($includeDismissed);}
	/** @return array<int,PanelInboxNotification> */
	public function read(bool $includeDismissed=false): array {return $this->inner->read($includeDismissed);}
	/** @return array<int,PanelInboxNotification> */
	public function forRecipient(?string $recipient, bool $includeDismissed=false): array {return $this->inner->forRecipient($recipient,$includeDismissed);}
	public function get(string $id): ?PanelInboxNotification {return $this->inner->get($id);}
	public function markRead(string $id, ?string $timestamp=null): bool {return $this->inner->markRead($id,$timestamp);}
	public function markUnread(string $id): bool {return $this->inner->markUnread($id);}
	public function dismiss(string $id, ?string $timestamp=null): bool {return $this->inner->dismiss($id,$timestamp);}
	public function restore(string $id): bool {return $this->inner->restore($id);}
	/** @return array<int,array<string,mixed>> */
	public function deliver(PanelInboxNotification|string $notification, array|string|null $channels=null): array {return $this->inner->deliver($notification,$channels);}
	/** @return array<string,mixed> */
	public function counts(bool $includeDismissed=false, ?string $recipient=null): array {return $this->inner->counts($includeDismissed,$recipient);}
	public function delete(string $id): bool {return $this->inner->delete($id);}
	public function cursor(): int {return $this->inner->cursor();}
	/** @return array<string,mixed> */
	public function changesSince(int $cursor=0,int $limit=100):array{return $this->inner->changesSince($cursor,$limit);}
	/** @return array<int,array<string,mixed>> */
	public function deliveryReceipts(?string $recipient=null,?string $notificationId=null):array{return $this->inner->deliveryReceipts($recipient,$notificationId);}
	public function meta(array|string $key,mixed $value=null):self{$this->inner->meta($key,$value);return $this;}

	/** @return array<string,mixed> */
	public function manifest(array $meta=[]): array {
		$manifest=$this->inner->manifest();
		if(is_array($manifest['store']??null)){unset($manifest['store']['directory']);}
		$manifest['adapter']='dataphyre_mailer_atomic_inbox';
		$manifest['integration']=[
			'channel'=>$this->channel,
			'durable_inbox'=>true,
			'delivery_adapter'=>'dataphyre_mailer',
			'recipient_resolver_supplied'=>true,
			'message_factory_supplied'=>is_callable($this->options['message']??null),
			'callbacks_serialized'=>false,
			'configuration_serialized'=>false,
			'storage_locator_serialized'=>false,
		];
		$manifest['meta']=PanelSensitiveDataSanitizer::sanitize(array_replace(
			is_array($manifest['meta']??null)?$manifest['meta']:[],
			$meta
		));
		return $manifest;
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {return $this->manifest();}

	/** @return array<string,mixed> */
	private function dispatch(PanelInboxNotification $notification): array {
		try{$target=($this->recipient)($notification,$this->options);}
		catch(\Throwable $exception){
			return ['status'=>'failed','data'=>['code'=>'recipient_resolver_failed','exception'=>$exception::class]];
		}
		if($target===null || $target===false || $target==='' || $target===[]){
			return ['status'=>'rejected','data'=>['code'=>'recipient_unavailable']];
		}
		try{
			$message=$this->message($notification,$target);
			$result=($this->send)($message,$notification,$target,$this->options);
			return $this->result($result);
		}
		catch(\Throwable $exception){
			return ['status'=>'failed','data'=>['code'=>'mailer_dispatch_failed','exception'=>$exception::class]];
		}
	}

	private function message(PanelInboxNotification $notification,mixed $target):mixed {
		$factory=$this->options['message']??null;
		if(is_callable($factory)){
			return $factory($notification,$target,$this->options);
		}
		$payload=$notification->toArray();
		$text=$notification->message();
		if(is_string($payload['action_url']??null) && $payload['action_url']!==''){
			$text.="\n\n".((string)($payload['action_label']??'Open')).': '.$payload['action_url'];
		}
		return [
			'to'=>$target,
			'subject'=>$notification->title()??'Panel notification',
			'text'=>$text,
		];
	}

	/** @return array<string,mixed> */
	private function result(mixed $result): array {
		if($result instanceof \Dataphyre\Mailer\SendResult){
			return [
				'status'=>$result->ok()?'delivered':'failed',
				'data'=>[
					'provider'=>Resource::normalizeName($result->provider()),
					'status'=>$result->status(),
					'message_id'=>$result->messageId(),
				],
			];
		}
		if(is_array($result)){
			$ok=($result['ok']??null)===true || in_array(Resource::normalizeName((string)($result['status']??'')),['delivered','sent','accepted','queued'],true);
			return [
				'status'=>$ok?'delivered':'failed',
				'data'=>PanelSensitiveDataSanitizer::sanitize([
					'provider'=>$result['provider']??null,
					'status'=>$result['status']??null,
					'message_id'=>$result['message_id']??$result['id']??null,
				]),
			];
		}
		if($result===true || is_string($result) || is_int($result)){
			return ['status'=>'delivered','data'=>[]];
		}
		return ['status'=>'failed','data'=>['code'=>'unsupported_mailer_result','type'=>get_debug_type($result)]];
	}
}
