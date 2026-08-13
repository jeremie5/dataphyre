<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Durable Panel notification adapter with atomic JSON commits and cursor replay.
 */
final class PanelFilesystemNotificationAdapter implements PanelNotificationAdapter, \JsonSerializable {

	private PanelAtomicSnapshotStore $store;
	/** @var list<string> */
	private array $channels;
	/** @var array<string,callable> */
	private array $handlers=[];
	private int $deliveryRetention;

	/**
	 * @param array<int,PanelInboxNotification|PanelNotification|array|string> $notifications
	 * @param array<int,string> $channels
	 * @param array<string,callable> $handlers
	 */
	public function __construct(
		string $directory,
		array $notifications=[],
		array $channels=['database'],
		array $handlers=[],
		int $snapshotRetention=512,
		int $deliveryRetention=5000
	) {
		$this->channels=$this->normalizeChannels($channels);
		$this->deliveryRetention=max(50, $deliveryRetention);
		$this->store=new PanelAtomicSnapshotStore($directory, 'dataphyre.panel.notifications.v1', [
			'notifications'=>[],
			'deliveries'=>[],
			'meta'=>[],
		], $snapshotRetention);
		foreach($handlers as $channel=>$handler){
			if(is_callable($handler)){
				$this->handler((string)$channel, $handler);
			}
		}
		foreach($notifications as $notification){
			$this->store($notification);
		}
	}

	/** @param array<int,PanelInboxNotification|PanelNotification|array|string> $notifications @param array<int,string> $channels @param array<string,callable> $handlers */
	public static function make(string $directory, array $notifications=[], array $channels=['database'], array $handlers=[]): self {
		return new self($directory, $notifications, $channels, $handlers);
	}

	public function handler(string $channel, callable $handler): self {
		$channel=Resource::normalizeName($channel);
		if($channel===''){
			throw new \InvalidArgumentException('Notification delivery channel cannot be empty.');
		}
		$this->handlers[$channel]=$handler;
		return $this;
	}

	public function withoutHandler(string $channel): self {
		unset($this->handlers[Resource::normalizeName($channel)]);
		return $this;
	}

	public function store(PanelInboxNotification|PanelNotification|array|string $notification, ?string $recipient=null, array $meta=[]): PanelInboxNotification {
		$item=$notification instanceof PanelInboxNotification ? $notification : PanelInboxNotification::from($notification, $recipient, $meta);
		$serialized=$item->toArray();
		$this->store->transaction(function(array &$state) use ($item, $serialized): PanelInboxNotification {
			$state['notifications']=is_array($state['notifications'] ?? null) ? $state['notifications'] : [];
			$state['notifications'][$item->id()]=$serialized;
			return $item;
		}, 'notification.stored', [
			'notification_id'=>$item->id(),
			'recipient'=>$item->recipient(),
			'notification'=>$serialized,
		]);
		return $item;
	}

	/** @return array<int,PanelInboxNotification> */
	public function all(bool $includeDismissed=false): array {
		$items=$this->hydrate($this->store->payload()['notifications'] ?? []);
		if(!$includeDismissed){
			$items=array_values(array_filter($items, static fn(PanelInboxNotification $item): bool => !$item->isDismissed()));
		}
		usort($items, static function(PanelInboxNotification $left, PanelInboxNotification $right): int {
			return strcmp($right->createdAt(), $left->createdAt()) ?: strcmp($right->id(), $left->id());
		});
		return $items;
	}

	/** @return array<int,PanelInboxNotification> */
	public function unread(bool $includeDismissed=false): array {
		return array_values(array_filter($this->all($includeDismissed), static fn(PanelInboxNotification $item): bool => $item->isUnread()));
	}

	/** @return array<int,PanelInboxNotification> */
	public function read(bool $includeDismissed=false): array {
		return array_values(array_filter($this->all($includeDismissed), static fn(PanelInboxNotification $item): bool => $item->isRead()));
	}

	/** @return array<int,PanelInboxNotification> */
	public function forRecipient(?string $recipient, bool $includeDismissed=false): array {
		$recipient=$recipient!==null ? trim($recipient) : null;
		return array_values(array_filter($this->all($includeDismissed), static function(PanelInboxNotification $item) use ($recipient): bool {
			return $recipient===null ? $item->recipient()===null : $item->recipient()===$recipient;
		}));
	}

	public function get(string $id): ?PanelInboxNotification {
		$id=trim($id);
		if($id===''){
			return null;
		}
		$payload=$this->store->payload();
		$item=$payload['notifications'][$id] ?? null;
		return is_array($item) ? new PanelInboxNotification($item) : null;
	}

	public function markRead(string $id, ?string $timestamp=null): bool {
		return $this->transition($id, 'notification.read', static function(PanelInboxNotification $item) use ($timestamp): void {
			$item->markRead($timestamp);
		});
	}

	public function markUnread(string $id): bool {
		return $this->transition($id, 'notification.unread', static function(PanelInboxNotification $item): void {
			$item->markUnread();
		});
	}

	public function dismiss(string $id, ?string $timestamp=null): bool {
		return $this->transition($id, 'notification.dismissed', static function(PanelInboxNotification $item) use ($timestamp): void {
			$item->dismiss($timestamp);
		});
	}

	public function restore(string $id): bool {
		return $this->transition($id, 'notification.restored', static function(PanelInboxNotification $item): void {
			$item->restore();
		});
	}

	public function delete(string $id): bool {
		$id=trim($id);
		$result=$this->store->transaction(function(array &$state) use ($id): bool {
			if($id==='' || !isset($state['notifications'][$id])){
				return false;
			}
			unset($state['notifications'][$id]);
			return true;
		}, 'notification.deleted', ['notification_id'=>$id]);
		return $result['result']===true;
	}

	/** @return array<int,array<string,mixed>> */
	public function deliver(PanelInboxNotification|string $notification, array|string|null $channels=null): array {
		$item=is_string($notification) ? $this->get($notification) : $notification;
		if(!$item instanceof PanelInboxNotification){
			return [];
		}
		$selected=$this->normalizeChannels($channels ?? $item->channels());
		if($selected===[]){
			$selected=$this->channels;
		}
		$receipts=[];
		foreach($selected as $channel){
			$receipt=[
				'id'=>bin2hex(random_bytes(12)),
				'notification_id'=>$item->id(),
				'recipient'=>$item->recipient(),
				'channel'=>$channel,
				'status'=>'queued',
				'delivered_at'=>gmdate('c'),
				'data'=>[],
			];
			if(isset($this->handlers[$channel])){
				try {
					$response=($this->handlers[$channel])($item, $channel, $receipt);
					if(is_array($response)){
						$receipt['status']=Resource::normalizeName((string)($response['status'] ?? 'delivered')) ?: 'delivered';
						$receipt['data']=$this->jsonSafe($response['data'] ?? array_diff_key($response, ['status'=>true]));
					}
					elseif($response===false){
						$receipt['status']='rejected';
					}
					else {
						$receipt['status']='delivered';
						if(is_scalar($response) && $response!==true && $response!==null){
							$receipt['data']=['result'=>(string)$response];
						}
					}
				}
				catch(\Throwable $exception){
					$receipt['status']='failed';
					$receipt['data']=['error'=>$exception->getMessage(), 'exception'=>$exception::class];
				}
			}
			$receipts[]=$receipt;
		}
		$this->store->transaction(function(array &$state) use ($receipts): array {
			$state['deliveries']=is_array($state['deliveries'] ?? null) ? $state['deliveries'] : [];
			array_push($state['deliveries'], ...$receipts);
			if(count($state['deliveries'])>$this->deliveryRetention){
				$state['deliveries']=array_slice($state['deliveries'], -$this->deliveryRetention);
			}
			return $receipts;
		}, 'notification.delivered', [
			'notification_id'=>$item->id(),
			'recipient'=>$item->recipient(),
			'receipts'=>$receipts,
		]);
		return $receipts;
	}

	/** @return array<string,mixed> */
	public function counts(bool $includeDismissed=false, ?string $recipient=null): array {
		$items=$recipient===null ? $this->all($includeDismissed) : $this->forRecipient($recipient, $includeDismissed);
		$all=$recipient===null ? $this->all(true) : $this->forRecipient($recipient, true);
		$byType=[];
		$byChannel=[];
		foreach($items as $item){
			$byType[$item->type()]=($byType[$item->type()] ?? 0)+1;
			foreach($item->channels() as $channel){
				$byChannel[$channel]=($byChannel[$channel] ?? 0)+1;
			}
		}
		ksort($byType);
		ksort($byChannel);
		$deliveries=$this->deliveryReceipts($recipient);
		return [
			'total'=>count($items),
			'unread'=>count(array_filter($items, static fn(PanelInboxNotification $item): bool => $item->isUnread())),
			'read'=>count(array_filter($items, static fn(PanelInboxNotification $item): bool => $item->isRead())),
			'dismissed'=>count(array_filter($all, static fn(PanelInboxNotification $item): bool => $item->isDismissed())),
			'by_type'=>$byType,
			'by_channel'=>$byChannel,
			'deliveries'=>count($deliveries),
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function deliveryReceipts(?string $recipient=null, ?string $notificationId=null): array {
		$deliveries=$this->store->payload()['deliveries'] ?? [];
		if(!is_array($deliveries)){
			return [];
		}
		return array_values(array_filter($deliveries, static function(mixed $receipt) use ($recipient, $notificationId): bool {
			return is_array($receipt)
				&& ($recipient===null || ($receipt['recipient'] ?? null)===$recipient)
				&& ($notificationId===null || ($receipt['notification_id'] ?? null)===$notificationId);
		}));
	}

	public function meta(array|string $key, mixed $value=null): self {
		$values=is_array($key) ? $key : [$key=>$value];
		$this->store->transaction(function(array &$state) use ($values): void {
			$state['meta']=array_replace(is_array($state['meta'] ?? null) ? $state['meta'] : [], $this->jsonSafe($values));
		}, 'adapter.meta.updated', ['keys'=>array_map('strval', array_keys($values))]);
		return $this;
	}

	public function cursor(): int {
		return $this->store->cursor();
	}

	/** @return array<string,mixed> */
	public function changesSince(int $cursor=0, int $limit=100): array {
		return $this->store->changesSince($cursor, $limit);
	}

	/** @return array<string,mixed> */
	public function manifest(array $meta=[]): array {
		$payload=$this->store->payload();
		return [
			'type'=>'panel_notification_adapter_manifest',
			'adapter'=>'filesystem_atomic_json',
			'channels'=>$this->channels,
			'handlers'=>array_keys($this->handlers),
			'cursor'=>$this->cursor(),
			'counts'=>$this->counts(true),
			'capabilities'=>[
				'durable'=>true,
				'atomic_snapshots'=>true,
				'cross_process_locking'=>true,
				'change_cursor'=>true,
				'realtime_feed'=>true,
				'read_state'=>true,
				'dismissal_state'=>true,
				'channel_handlers'=>true,
				'delivery_receipts'=>true,
			],
			'store'=>$this->store->manifest(),
			'meta'=>array_replace(is_array($payload['meta'] ?? null) ? $payload['meta'] : [], $this->jsonSafe($meta)),
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	private function transition(string $id, string $event, callable $transition): bool {
		$id=trim($id);
		$result=$this->store->transaction(function(array &$state) use ($id, $transition): array|false {
			$serialized=$state['notifications'][$id] ?? null;
			if($id==='' || !is_array($serialized)){
				return false;
			}
			$item=new PanelInboxNotification($serialized);
			$transition($item);
			$state['notifications'][$id]=$item->toArray();
			return $item->toArray();
		}, $event, ['notification_id'=>$id]);
		return $result['result']!==false;
	}

	/** @param mixed $items @return array<int,PanelInboxNotification> */
	private function hydrate(mixed $items): array {
		if(!is_array($items)){
			return [];
		}
		$hydrated=[];
		foreach($items as $item){
			if(is_array($item)){
				$hydrated[]=new PanelInboxNotification($item);
			}
		}
		return $hydrated;
	}

	/** @param array|string|null $channels @return list<string> */
	private function normalizeChannels(array|string|null $channels): array {
		$channels=is_array($channels) ? $channels : [$channels];
		$normalized=[];
		foreach($channels as $channel){
			$channel=Resource::normalizeName((string)$channel);
			if($channel!=='' && !in_array($channel, $normalized, true)){
				$normalized[]=$channel;
			}
		}
		return $normalized;
	}

	private function jsonSafe(mixed $value): mixed {
		try {
			return json_decode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		}
		catch(\Throwable){
			return is_scalar($value) || $value===null ? $value : (string)(is_object($value) ? $value::class : gettype($value));
		}
	}
}
