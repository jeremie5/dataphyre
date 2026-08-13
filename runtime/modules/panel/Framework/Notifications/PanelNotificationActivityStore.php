<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Durable collaboration store for notification preferences and record activity.
 *
 * The store centralizes subscriptions, digests, comments, mentions,
 * assignments, and watchers so Panel applications do not need to invent
 * incompatible side tables for every resource. All writes use the same atomic
 * snapshot and cursor semantics as the filesystem notification adapter.
 */
final class PanelNotificationActivityStore implements \JsonSerializable {

	private PanelAtomicSnapshotStore $store;
	/** @var array<string,list<callable>> */
	private array $policies=[];
	private int $activityRetention;

	public function __construct(string $directory, int $snapshotRetention=512, int $activityRetention=25000) {
		$this->activityRetention=max(100, $activityRetention);
		$this->store=new PanelAtomicSnapshotStore($directory, 'dataphyre.panel.activity.v1', [
			'preferences'=>[],
			'subscriptions'=>[],
			'watchers'=>[],
			'assignments'=>[],
			'activities'=>[],
			'meta'=>[],
		], $snapshotRetention);
	}

	public static function make(string $directory): self {
		return new self($directory);
	}

	/** Registers a fail-closed authorization or lifecycle policy. */
	public function policy(string $operation, callable $policy): self {
		$operation=trim($operation)==='*' ? '*' : $this->key($operation);
		if($operation===''){
			throw new \InvalidArgumentException('Activity policy operation cannot be empty.');
		}
		$this->policies[$operation] ??=[];
		$this->policies[$operation][]=$policy;
		return $this;
	}

	/** @param array<string,mixed> $preferences @return array<string,mixed> */
	public function setPreferences(string $recipient, array $preferences): array {
		$recipient=$this->recipient($recipient);
		$this->authorize('preferences.update', ['recipient'=>$recipient, 'preferences'=>$preferences]);
		$normalized=$this->normalizePreferences($preferences);
		$result=$this->store->transaction(function(array &$state) use ($recipient, $normalized): array {
			$current=is_array($state['preferences'][$recipient] ?? null) ? $state['preferences'][$recipient] : [];
			$state['preferences'][$recipient]=array_replace($this->defaultPreferences(), $current, $normalized, ['updated_at'=>gmdate('c')]);
			return $state['preferences'][$recipient];
		}, 'preferences.updated', ['recipient'=>$recipient, 'preferences'=>$normalized]);
		return $result['result'];
	}

	/** @return array<string,mixed> */
	public function preferences(string $recipient): array {
		$recipient=$this->recipient($recipient);
		$stored=$this->store->payload()['preferences'][$recipient] ?? [];
		return array_replace($this->defaultPreferences(), is_array($stored) ? $stored : []);
	}

	/** @param array<int,string>|string|null $channels @param array<string,mixed> $meta @return array<string,mixed> */
	public function subscribe(string $recipient, string $topic, array|string|null $channels=null, string $digest='immediate', array $meta=[]): array {
		$recipient=$this->recipient($recipient);
		$topic=$this->topic($topic);
		$this->authorize('subscription.create', compact('recipient', 'topic', 'channels', 'digest', 'meta'));
		$id=substr(hash('sha256', $recipient."\0".$topic), 0, 32);
		$subscription=[
			'id'=>$id,
			'recipient'=>$recipient,
			'topic'=>$topic,
			'channels'=>$this->channels($channels ?? []),
			'digest'=>$this->digestMode($digest),
			'muted'=>false,
			'created_at'=>gmdate('c'),
			'meta'=>$this->jsonSafe($meta),
		];
		$result=$this->store->transaction(function(array &$state) use ($id, $subscription): array {
			$existing=is_array($state['subscriptions'][$id] ?? null) ? $state['subscriptions'][$id] : [];
			$state['subscriptions'][$id]=array_replace($subscription, ['created_at'=>$existing['created_at'] ?? $subscription['created_at']]);
			return $state['subscriptions'][$id];
		}, 'subscription.saved', ['recipient'=>$recipient, 'topic'=>$topic, 'subscription'=>$subscription]);
		return $result['result'];
	}

	public function unsubscribe(string $recipient, string $topic): bool {
		$recipient=$this->recipient($recipient);
		$topic=$this->topic($topic);
		$this->authorize('subscription.delete', compact('recipient', 'topic'));
		$id=substr(hash('sha256', $recipient."\0".$topic), 0, 32);
		$result=$this->store->transaction(function(array &$state) use ($id): bool {
			if(!isset($state['subscriptions'][$id])){
				return false;
			}
			unset($state['subscriptions'][$id]);
			return true;
		}, 'subscription.deleted', ['recipient'=>$recipient, 'topic'=>$topic]);
		return $result['result']===true;
	}

	public function muteSubscription(string $recipient, string $topic, bool $muted=true): bool {
		$recipient=$this->recipient($recipient);
		$topic=$this->topic($topic);
		$id=substr(hash('sha256', $recipient."\0".$topic), 0, 32);
		$this->authorize('subscription.mute', compact('recipient', 'topic', 'muted'));
		$result=$this->store->transaction(function(array &$state) use ($id, $muted): bool {
			if(!is_array($state['subscriptions'][$id] ?? null)){
				return false;
			}
			$state['subscriptions'][$id]['muted']=$muted;
			$state['subscriptions'][$id]['updated_at']=gmdate('c');
			return true;
		}, 'subscription.muted', ['recipient'=>$recipient, 'topic'=>$topic, 'muted'=>$muted]);
		return $result['result']===true;
	}

	/** @return array<int,array<string,mixed>> */
	public function subscriptions(?string $recipient=null): array {
		if($recipient!==null){
			$recipient=$this->recipient($recipient);
		}
		$items=$this->store->payload()['subscriptions'] ?? [];
		if(!is_array($items)){
			return [];
		}
		$items=array_values(array_filter($items, static fn(mixed $item): bool => is_array($item) && ($recipient===null || ($item['recipient'] ?? null)===$recipient)));
		usort($items, static fn(array $left, array $right): int => strcmp((string)$left['topic'], (string)$right['topic']));
		return $items;
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	public function watch(string $subjectType, string|int $subjectId, string $recipient, array $meta=[]): array {
		$subject=$this->subject($subjectType, $subjectId);
		$recipient=$this->recipient($recipient);
		$this->authorize('watch.create', $subject+compact('recipient', 'meta'));
		$watch=[
			'recipient'=>$recipient,
			'subject_type'=>$subject['subject_type'],
			'subject_id'=>$subject['subject_id'],
			'created_at'=>gmdate('c'),
			'meta'=>$this->jsonSafe($meta),
		];
		$result=$this->store->transaction(function(array &$state) use ($subject, $recipient, $watch): array {
			$key=$subject['subject_key'];
			$state['watchers'][$key]=is_array($state['watchers'][$key] ?? null) ? $state['watchers'][$key] : [];
			$existing=is_array($state['watchers'][$key][$recipient] ?? null) ? $state['watchers'][$key][$recipient] : [];
			$state['watchers'][$key][$recipient]=array_replace($watch, ['created_at'=>$existing['created_at'] ?? $watch['created_at']]);
			return $state['watchers'][$key][$recipient];
		}, 'watch.saved', $subject+['recipient'=>$recipient]);
		return $result['result'];
	}

	public function unwatch(string $subjectType, string|int $subjectId, string $recipient): bool {
		$subject=$this->subject($subjectType, $subjectId);
		$recipient=$this->recipient($recipient);
		$this->authorize('watch.delete', $subject+compact('recipient'));
		$result=$this->store->transaction(function(array &$state) use ($subject, $recipient): bool {
			$key=$subject['subject_key'];
			if(!isset($state['watchers'][$key][$recipient])){
				return false;
			}
			unset($state['watchers'][$key][$recipient]);
			if(($state['watchers'][$key] ?? [])===[]){
				unset($state['watchers'][$key]);
			}
			return true;
		}, 'watch.deleted', $subject+['recipient'=>$recipient]);
		return $result['result']===true;
	}

	/** @return list<string> */
	public function watchers(string $subjectType, string|int $subjectId): array {
		$key=$this->subject($subjectType, $subjectId)['subject_key'];
		$watchers=$this->store->payload()['watchers'][$key] ?? [];
		$recipients=is_array($watchers) ? array_keys($watchers) : [];
		sort($recipients, SORT_STRING);
		return $recipients;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	public function recordActivity(string $type, ?string $actor, string $subjectType, string|int $subjectId, array $data=[], array $options=[]): array {
		$subject=$this->subject($subjectType, $subjectId);
		$type=$this->key($type);
		if($type===''){
			throw new \InvalidArgumentException('Activity type cannot be empty.');
		}
		$actor=$actor!==null && trim($actor)!=='' ? trim($actor) : null;
		$activity=$this->activity($type, $actor, $subject, $data, $options);
		$this->authorize('activity.record', $activity);
		$result=$this->store->transaction(function(array &$state) use ($activity): array {
			$state['activities']=is_array($state['activities'] ?? null) ? $state['activities'] : [];
			$state['activities'][$activity['id']]=$activity;
			if(count($state['activities'])>$this->activityRetention){
				$state['activities']=array_slice($state['activities'], -$this->activityRetention, null, true);
			}
			return $activity;
		}, 'activity.recorded', ['activity'=>$activity, 'subject_key'=>$subject['subject_key']]);
		return $result['result'];
	}

	/** @param array<int,string> $mentions @param array<string,mixed> $meta @return array<string,mixed> */
	public function comment(string $subjectType, string|int $subjectId, string $actor, string $body, array $mentions=[], array $meta=[]): array {
		$body=trim($body);
		if($body===''){
			throw new \InvalidArgumentException('Comment body cannot be empty.');
		}
		if(strlen($body)>100000){
			throw new \LengthException('Comment body exceeds 100000 bytes.');
		}
		preg_match_all('/(?<![\w@])@([a-zA-Z0-9_.:-]{1,128})/', $body, $matches);
		$parsed=array_map(static fn(mixed $mention): string => rtrim((string)$mention, '.,;:!?'), $matches[1] ?? []);
		$mentions=array_merge($mentions, $parsed);
		$mentions=$this->recipients($mentions);
		$subject=$this->subject($subjectType, $subjectId);
		$this->authorize('comment.create', $subject+compact('actor', 'body', 'mentions', 'meta'));
		return $this->recordActivity('comment.created', $actor, $subjectType, $subjectId, [
			'body'=>$body,
			'mentions'=>$mentions,
			'meta'=>$this->jsonSafe($meta),
		], ['mentions'=>$mentions, 'topic'=>$subject['subject_type'].'.comments']);
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	public function assign(string $subjectType, string|int $subjectId, string $assignee, string $assignedBy, array $meta=[]): array {
		$subject=$this->subject($subjectType, $subjectId);
		$assignee=$this->recipient($assignee);
		$assignedBy=$this->recipient($assignedBy);
		$this->authorize('assignment.update', $subject+compact('assignee', 'assignedBy', 'meta'));
		$assignment=$subject+[
			'assignee'=>$assignee,
			'assigned_by'=>$assignedBy,
			'assigned_at'=>gmdate('c'),
			'meta'=>$this->jsonSafe($meta),
		];
		$activity=$this->activity('assignment.updated', $assignedBy, $subject, [
			'assignee'=>$assignee,
			'assigned_by'=>$assignedBy,
			'meta'=>$this->jsonSafe($meta),
		], ['recipients'=>[$assignee], 'topic'=>$subject['subject_type'].'.assignments']);
		$result=$this->store->transaction(function(array &$state) use ($subject, $assignment, $activity): array {
			$state['assignments'][$subject['subject_key']]=$assignment;
			$state['activities'][$activity['id']]=$activity;
			if(count($state['activities'])>$this->activityRetention){
				$state['activities']=array_slice($state['activities'], -$this->activityRetention, null, true);
			}
			return $assignment;
		}, 'assignment.updated', ['assignment'=>$assignment, 'activity'=>$activity]);
		return $result['result'];
	}

	public function unassign(string $subjectType, string|int $subjectId, string $actor): bool {
		$subject=$this->subject($subjectType, $subjectId);
		$actor=$this->recipient($actor);
		$this->authorize('assignment.delete', $subject+compact('actor'));
		$result=$this->store->transaction(function(array &$state) use ($subject): bool {
			if(!isset($state['assignments'][$subject['subject_key']])){
				return false;
			}
			unset($state['assignments'][$subject['subject_key']]);
			return true;
		}, 'assignment.deleted', $subject+compact('actor'));
		if($result['result']===true){
			$this->recordActivity('assignment.removed', $actor, $subjectType, $subjectId);
		}
		return $result['result']===true;
	}

	/** @return array<string,mixed>|null */
	public function assignment(string $subjectType, string|int $subjectId): ?array {
		$key=$this->subject($subjectType, $subjectId)['subject_key'];
		$value=$this->store->payload()['assignments'][$key] ?? null;
		return is_array($value) ? $value : null;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function activities(array $filters=[], int $limit=100, int $offset=0): array {
		$items=$this->store->payload()['activities'] ?? [];
		$items=is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
		$items=array_values(array_filter($items, function(array $activity) use ($filters): bool {
			foreach(['type', 'actor', 'subject_type', 'subject_id', 'topic'] as $field){
				if(array_key_exists($field, $filters) && (string)($activity[$field] ?? '')!==(string)$filters[$field]){
					return false;
				}
			}
			if(isset($filters['recipient']) && !$this->activityVisibleTo($activity, (string)$filters['recipient'])){
				return false;
			}
			if(isset($filters['since']) && strcmp((string)$activity['created_at'], (string)$filters['since'])<0){
				return false;
			}
			if(isset($filters['until']) && strcmp((string)$activity['created_at'], (string)$filters['until'])>0){
				return false;
			}
			return true;
		}));
		usort($items, static fn(array $left, array $right): int => strcmp((string)$right['created_at'], (string)$left['created_at']) ?: strcmp((string)$right['id'], (string)$left['id']));
		return array_slice($items, max(0, $offset), max(1, min(1000, $limit)));
	}

	/** @return array<int,array<string,mixed>> */
	public function mentions(string $recipient, bool $unreadOnly=false): array {
		$recipient=$this->recipient($recipient);
		return array_values(array_filter($this->activities(['recipient'=>$recipient], 1000), static function(array $activity) use ($recipient, $unreadOnly): bool {
			$mentions=is_array($activity['mentions'] ?? null) ? $activity['mentions'] : [];
			if(!in_array($recipient, $mentions, true)){
				return false;
			}
			return !$unreadOnly || !isset($activity['acknowledged_by'][$recipient]);
		}));
	}

	public function acknowledge(string $activityId, string $recipient, ?string $timestamp=null): bool {
		$recipient=$this->recipient($recipient);
		$this->authorize('activity.acknowledge', compact('activityId', 'recipient'));
		$result=$this->store->transaction(function(array &$state) use ($activityId, $recipient, $timestamp): bool {
			if(!is_array($state['activities'][$activityId] ?? null)){
				return false;
			}
			$state['activities'][$activityId]['acknowledged_by'][$recipient]=$timestamp!==null && trim($timestamp)!=='' ? trim($timestamp) : gmdate('c');
			return true;
		}, 'activity.acknowledged', ['activity_id'=>$activityId, 'recipient'=>$recipient]);
		return $result['result']===true;
	}

	/** @return array<string,mixed> */
	public function digest(string $recipient, ?string $since=null, ?string $until=null, int $limit=250): array {
		$recipient=$this->recipient($recipient);
		$preferences=$this->preferences($recipient);
		$since=$since ?? (is_string($preferences['last_digest_at'] ?? null) ? $preferences['last_digest_at'] : null);
		$filters=['recipient'=>$recipient];
		if($since!==null){
			$filters['since']=$since;
		}
		if($until!==null){
			$filters['until']=$until;
		}
		$items=($preferences['digest_enabled'] ?? true) ? $this->activities($filters, $limit) : [];
		$counts=[];
		foreach($items as $item){
			$counts[$item['type']]=($counts[$item['type']] ?? 0)+1;
		}
		ksort($counts);
		return [
			'type'=>'panel_activity_digest',
			'recipient'=>$recipient,
			'frequency'=>$preferences['digest_frequency'],
			'since'=>$since,
			'until'=>$until ?? gmdate('c'),
			'count'=>count($items),
			'counts_by_type'=>$counts,
			'activities'=>$items,
		];
	}

	public function acknowledgeDigest(string $recipient, ?string $timestamp=null): array {
		return $this->setPreferences($recipient, ['last_digest_at'=>$timestamp!==null && trim($timestamp)!=='' ? trim($timestamp) : gmdate('c')]);
	}

	public function shouldNotify(string $recipient, string $topic, string $channel='database'): bool {
		$recipient=$this->recipient($recipient);
		$topic=$this->topic($topic);
		$channel=$this->key($channel);
		$preferences=$this->preferences($recipient);
		if(($preferences['notifications_enabled'] ?? true)!==true || !in_array($channel, $preferences['channels'], true)){
			return false;
		}
		foreach($this->subscriptions($recipient) as $subscription){
			if($this->topicMatches((string)$subscription['topic'], $topic)){
				if(($subscription['muted'] ?? false)===true){
					return false;
				}
				$channels=$subscription['channels'] ?? [];
				return $channels===[] || in_array($channel, $channels, true);
			}
		}
		return false;
	}

	public function cursor(): int {
		return $this->store->cursor();
	}

	/** @return array<string,mixed> */
	public function changesSince(int $cursor=0, int $limit=100): array {
		return $this->store->changesSince($cursor, $limit);
	}

	public function meta(array|string $key, mixed $value=null): self {
		$values=is_array($key) ? $key : [$key=>$value];
		$this->store->transaction(function(array &$state) use ($values): void {
			$state['meta']=array_replace(is_array($state['meta'] ?? null) ? $state['meta'] : [], $this->jsonSafe($values));
		}, 'activity.meta.updated', ['keys'=>array_keys($values)]);
		return $this;
	}

	/** @return array<string,mixed> */
	public function manifest(array $meta=[]): array {
		$state=$this->store->payload();
		$watcherCount=0;
		foreach((array)($state['watchers'] ?? []) as $watchers){
			$watcherCount+=is_array($watchers) ? count($watchers) : 0;
		}
		return [
			'type'=>'panel_notification_activity_store',
			'adapter'=>'filesystem_atomic_json',
			'cursor'=>$this->cursor(),
			'counts'=>[
				'preferences'=>count((array)($state['preferences'] ?? [])),
				'subscriptions'=>count((array)($state['subscriptions'] ?? [])),
				'watchers'=>$watcherCount,
				'assignments'=>count((array)($state['assignments'] ?? [])),
				'activities'=>count((array)($state['activities'] ?? [])),
			],
			'policies'=>array_keys($this->policies),
			'capabilities'=>[
				'preferences'=>true,
				'subscriptions'=>true,
				'digests'=>true,
				'activity_feed'=>true,
				'comments'=>true,
				'mentions'=>true,
				'assignments'=>true,
				'watchers'=>true,
				'policy_hooks'=>true,
				'change_cursor'=>true,
			],
			'store'=>$this->store->manifest(),
			'meta'=>array_replace(is_array($state['meta'] ?? null) ? $state['meta'] : [], $this->jsonSafe($meta)),
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return $this->manifest();
	}

	/** @param array<string,mixed> $context */
	private function authorize(string $operation, array $context): void {
		$hooks=array_merge($this->policies['*'] ?? [], $this->policies[$operation] ?? []);
		foreach($hooks as $policy){
			try {
				$result=$policy($operation, $context, $this);
			}
			catch(\Throwable $exception){
				throw new \DomainException('Panel activity policy failed for '.$operation.': '.$exception->getMessage(), 0, $exception);
			}
			if($result===false || (is_array($result) && ($result['allowed'] ?? true)!==true)){
				$reason=is_array($result) ? trim((string)($result['reason'] ?? '')) : '';
				throw new \DomainException($reason!=='' ? $reason : 'Panel activity policy denied '.$operation.'.');
			}
		}
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $options @return array<string,mixed> */
	private function activity(string $type, ?string $actor, array $subject, array $data, array $options): array {
		$mentions=$this->recipients(array_merge((array)($options['mentions'] ?? []), (array)($data['mentions'] ?? [])));
		$recipients=$this->recipients((array)($options['recipients'] ?? []));
		return [
			'id'=>bin2hex(random_bytes(16)),
			'type'=>$type,
			'actor'=>$actor,
			'subject_type'=>$subject['subject_type'],
			'subject_id'=>$subject['subject_id'],
			'subject_key'=>$subject['subject_key'],
			'topic'=>$this->topic((string)($options['topic'] ?? $subject['subject_type'])),
			'recipients'=>$recipients,
			'mentions'=>$mentions,
			'data'=>$this->jsonSafe($data),
			'created_at'=>isset($options['created_at']) && trim((string)$options['created_at'])!=='' ? trim((string)$options['created_at']) : gmdate('c'),
			'acknowledged_by'=>[],
		];
	}

	private function activityVisibleTo(array $activity, string $recipient): bool {
		$recipient=$this->recipient($recipient);
		if(($activity['actor'] ?? null)===$recipient
			|| in_array($recipient, (array)($activity['recipients'] ?? []), true)
			|| in_array($recipient, (array)($activity['mentions'] ?? []), true)){
			return true;
		}
		$assignment=$this->assignment((string)$activity['subject_type'], (string)$activity['subject_id']);
		if(($assignment['assignee'] ?? null)===$recipient){
			return true;
		}
		if(in_array($recipient, $this->watchers((string)$activity['subject_type'], (string)$activity['subject_id']), true)){
			return true;
		}
		foreach($this->subscriptions($recipient) as $subscription){
			if(($subscription['muted'] ?? false)!==true && $this->topicMatches((string)$subscription['topic'], (string)$activity['topic'])){
				return true;
			}
		}
		return false;
	}

	/** @return array<string,mixed> */
	private function defaultPreferences(): array {
		return [
			'notifications_enabled'=>true,
			'channels'=>['database'],
			'digest_enabled'=>true,
			'digest_frequency'=>'daily',
			'last_digest_at'=>null,
			'locale'=>null,
			'timezone'=>'UTC',
			'quiet_hours'=>null,
		];
	}

	/** @param array<string,mixed> $preferences @return array<string,mixed> */
	private function normalizePreferences(array $preferences): array {
		$normalized=[];
		foreach(['notifications_enabled', 'digest_enabled'] as $key){
			if(array_key_exists($key, $preferences)){
				$normalized[$key]=(bool)$preferences[$key];
			}
		}
		if(array_key_exists('channels', $preferences)){
			$normalized['channels']=$this->channels(is_array($preferences['channels']) || is_string($preferences['channels']) ? $preferences['channels'] : []);
		}
		if(array_key_exists('digest_frequency', $preferences)){
			$normalized['digest_frequency']=$this->digestMode((string)$preferences['digest_frequency']);
		}
		foreach(['last_digest_at', 'locale', 'timezone'] as $key){
			if(array_key_exists($key, $preferences)){
				$normalized[$key]=$preferences[$key]===null ? null : trim((string)$preferences[$key]);
			}
		}
		if(array_key_exists('quiet_hours', $preferences)){
			$quiet=$preferences['quiet_hours'];
			$normalized['quiet_hours']=is_array($quiet) ? [
				'start'=>trim((string)($quiet['start'] ?? '')),
				'end'=>trim((string)($quiet['end'] ?? '')),
				'timezone'=>trim((string)($quiet['timezone'] ?? $preferences['timezone'] ?? 'UTC')) ?: 'UTC',
			] : null;
		}
		return $normalized;
	}

	/** @return array{subject_type:string,subject_id:string,subject_key:string} */
	private function subject(string $type, string|int $id): array {
		$type=$this->key($type);
		$id=trim((string)$id);
		if($type==='' || $id===''){
			throw new \InvalidArgumentException('Activity subject type and id cannot be empty.');
		}
		return ['subject_type'=>$type, 'subject_id'=>$id, 'subject_key'=>$type.':'.$id];
	}

	private function recipient(string $recipient): string {
		$recipient=trim($recipient);
		if($recipient==='' || strlen($recipient)>256 || preg_match('/[\x00-\x1F\x7F]/', $recipient)===1){
			throw new \InvalidArgumentException('Recipient must be a non-empty printable identifier up to 256 bytes.');
		}
		return $recipient;
	}

	/** @param array<int,mixed> $recipients @return list<string> */
	private function recipients(array $recipients): array {
		$normalized=[];
		foreach($recipients as $recipient){
			try {
				$recipient=$this->recipient((string)$recipient);
			}
			catch(\InvalidArgumentException){
				continue;
			}
			if(!in_array($recipient, $normalized, true)){
				$normalized[]=$recipient;
			}
		}
		return $normalized;
	}

	/** @param array<int,string>|string|null $channels @return list<string> */
	private function channels(array|string|null $channels): array {
		$channels=is_array($channels) ? $channels : [$channels];
		$normalized=[];
		foreach($channels as $channel){
			$channel=$this->key((string)$channel);
			if($channel!=='' && !in_array($channel, $normalized, true)){
				$normalized[]=$channel;
			}
		}
		return $normalized;
	}

	private function key(string $value): string {
		$value=strtolower(trim($value));
		$value=preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
		return trim($value, '.-_');
	}

	private function topic(string $topic): string {
		$topic=strtolower(trim($topic));
		$topic=preg_replace('/[^a-z0-9.*_-]+/', '.', $topic) ?? '';
		$topic=trim(preg_replace('/\.{2,}/', '.', $topic) ?? '', '.');
		if($topic===''){
			throw new \InvalidArgumentException('Subscription topic cannot be empty.');
		}
		return $topic;
	}

	private function topicMatches(string $subscription, string $topic): bool {
		if($subscription===$topic || $subscription==='*'){
			return true;
		}
		if(str_ends_with($subscription, '.*')){
			return str_starts_with($topic, substr($subscription, 0, -1));
		}
		return false;
	}

	private function digestMode(string $digest): string {
		$digest=$this->key($digest);
		return in_array($digest, ['immediate', 'hourly', 'daily', 'weekly', 'never'], true) ? $digest : 'daily';
	}

	private function jsonSafe(mixed $value): mixed {
		try {
			return json_decode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		}
		catch(\Throwable){
			return is_scalar($value) || $value===null ? $value : [];
		}
	}
}
