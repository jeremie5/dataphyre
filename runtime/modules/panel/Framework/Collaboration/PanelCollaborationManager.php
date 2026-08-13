<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * High-level collaboration API for threads, comments, presence, and activity.
 *
 * Every durable mutation and its hash-chained receipt are committed in one
 * store transaction. Presence tokens are returned once and only their hashes
 * are stored; feeds and manifests always expose redacted public state.
 */
final class PanelCollaborationManager implements \JsonSerializable {

	private $policy=null;

	public function __construct(private readonly PanelCollaborationStore $store, ?callable $policy=null) {
		$this->policy=$policy;
	}
	public function store():PanelCollaborationStore{return$this->store;}

	public function policy(?callable $policy): self { $this->policy=$policy; return $this; }

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	public function createThread(string $actor, string $subjectType, string|int $subjectId, string $title='', array $meta=[], ?string $id=null): array {
		$actor=PanelCollaborationStateEngine::identifier($actor, 'actor');
		$subject=PanelCollaborationStateEngine::subject($subjectType, $subjectId);
		$id=$id!==null ? PanelCollaborationStateEngine::identifier($id, 'thread id', 128) : 'thread-'.bin2hex(random_bytes(12));
		$title=trim($title);
		if(strlen($title)>500){ throw new \LengthException('Collaboration thread title exceeds 500 bytes.'); }
		$meta=PanelCollaborationStateEngine::sanitize($meta);
		$this->authorize('thread.create', $actor, $subject+compact('id', 'title', 'meta'));
		return $this->store->transaction(function(array &$state) use ($actor, $subject, $id, $title, $meta): array {
			if(isset($state['threads'][$id])){ throw new \RuntimeException('Collaboration thread already exists: '.$id); }
			$now=gmdate('c');
			$thread=[
				'id'=>$id, 'subject_type'=>$subject['subject_type'], 'subject_id'=>$subject['subject_id'],
				'subject_key'=>$subject['subject_key'], 'title'=>$title, 'status'=>'open',
				'created_by'=>$actor, 'created_at'=>$now, 'updated_at'=>$now, 'meta'=>$meta,
			];
			$state['threads'][$id]=$thread;
			$state['thread_comments'][$id]=[];
			$receipt=PanelCollaborationStateEngine::receipt($state, 'thread.created', $actor, $subject+['thread_id'=>$id], $thread);
			return $thread+['receipt'=>$receipt->toArray()];
		}, 'collaboration.thread.created', ['thread_id'=>$id, 'actor'=>$actor]+$subject);
	}

	/** @return array<string,mixed>|null */
	public function thread(string $id): ?array {
		$value=$this->store->state()['threads'][PanelCollaborationStateEngine::identifier($id, 'thread id', 128)] ?? null;
		return is_array($value) ? $value : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function threads(?string $subjectType=null, string|int|null $subjectId=null, ?string $status=null): array {
		$subject=$subjectType!==null && $subjectId!==null ? PanelCollaborationStateEngine::subject($subjectType, $subjectId) : null;
		$items=array_values(array_filter((array)($this->store->state()['threads'] ?? []), static function(mixed $thread) use ($subject, $status): bool {
			return is_array($thread)
				&& ($subject===null || ($thread['subject_key'] ?? null)===$subject['subject_key'])
				&& ($status===null || ($thread['status'] ?? null)===$status);
		}));
		usort($items, static fn(array $left, array $right): int => strcmp((string)$right['updated_at'], (string)$left['updated_at']) ?: strcmp((string)$left['id'], (string)$right['id']));
		return $items;
	}

	/** @return array<string,mixed> */
	public function setThreadStatus(string $id, string $status, string $actor): array {
		$id=PanelCollaborationStateEngine::identifier($id, 'thread id', 128);
		$actor=PanelCollaborationStateEngine::identifier($actor, 'actor');
		$status=PanelCollaborationStateEngine::key($status, 'open');
		if(!in_array($status, ['open', 'resolved', 'closed', 'archived'], true)){ throw new \InvalidArgumentException('Unsupported collaboration thread status.'); }
		$this->authorize('thread.status', $actor, compact('id', 'status'));
		return $this->store->transaction(function(array &$state) use ($id, $status, $actor): array {
			if(!is_array($state['threads'][$id] ?? null)){ throw new \RuntimeException('Collaboration thread does not exist.'); }
			$state['threads'][$id]['status']=$status;
			$state['threads'][$id]['updated_at']=gmdate('c');
			$thread=$state['threads'][$id];
			$receipt=PanelCollaborationStateEngine::receipt($state, 'thread.status_changed', $actor, ['thread_id'=>$id], ['status'=>$status]);
			return $thread+['receipt'=>$receipt->toArray()];
		}, 'collaboration.thread.status_changed', compact('id', 'status', 'actor'));
	}

	/** @param array<int,string> $mentions @param array<string,mixed> $meta @return array<string,mixed> */
	public function comment(string $threadId, string $actor, string $body, array $mentions=[], array $meta=[]): array {
		$threadId=PanelCollaborationStateEngine::identifier($threadId, 'thread id', 128);
		$actor=PanelCollaborationStateEngine::identifier($actor, 'actor');
		$body=trim($body);
		if($body==='' || strlen($body)>100000){ throw new \InvalidArgumentException('Comment body must contain 1-100000 bytes.'); }
		$mentions=PanelCollaborationStateEngine::mentions($mentions, $body);
		$meta=PanelCollaborationStateEngine::sanitize($meta);
		$this->authorize('comment.create', $actor, compact('threadId', 'body', 'mentions', 'meta'));
		return $this->store->transaction(function(array &$state) use ($threadId, $actor, $body, $mentions, $meta): array {
			$thread=$state['threads'][$threadId] ?? null;
			if(!is_array($thread)){ throw new \RuntimeException('Collaboration thread does not exist.'); }
			$id='comment-'.bin2hex(random_bytes(12));
			$comment=[
				'id'=>$id, 'thread_id'=>$threadId, 'author'=>$actor, 'body'=>$body,
				'mentions'=>$mentions, 'created_at'=>gmdate('c'), 'meta'=>$meta,
			];
			$state['comments'][$id]=$comment;
			$state['thread_comments'][$threadId][]=$id;
			$state['threads'][$threadId]['updated_at']=$comment['created_at'];
			$receipt=PanelCollaborationStateEngine::receipt($state, 'comment.created', $actor, [
				'thread_id'=>$threadId, 'subject_type'=>$thread['subject_type'], 'subject_id'=>$thread['subject_id'],
			], $comment);
			return $comment+['receipt'=>$receipt->toArray()];
		}, 'collaboration.comment.created', ['thread_id'=>$threadId, 'actor'=>$actor, 'mentions'=>$mentions]);
	}

	/** @return array<int,array<string,mixed>> */
	public function comments(string $threadId): array {
		$threadId=PanelCollaborationStateEngine::identifier($threadId, 'thread id', 128);
		$state=$this->store->state(); $items=[];
		foreach((array)($state['thread_comments'][$threadId] ?? []) as $id){
			if(is_array($state['comments'][$id] ?? null)){ $items[]=$state['comments'][$id]; }
		}
		return $items;
	}

	/** @return array<int,array<string,mixed>> */
	public function mentions(string $userId): array {
		$userId=PanelCollaborationStateEngine::identifier($userId, 'user');
		$items=array_values(array_filter((array)($this->store->state()['comments'] ?? []), static fn(mixed $comment): bool => is_array($comment) && in_array($userId, (array)($comment['mentions'] ?? []), true)));
		usort($items, static fn(array $left, array $right): int => strcmp((string)$right['created_at'], (string)$left['created_at']));
		return $items;
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	public function assign(string $subjectType, string|int $subjectId, string $assignee, string $actor, array $meta=[]): array {
		$subject=PanelCollaborationStateEngine::subject($subjectType, $subjectId);
		$assignee=PanelCollaborationStateEngine::identifier($assignee, 'assignee');
		$actor=PanelCollaborationStateEngine::identifier($actor, 'actor');
		$meta=PanelCollaborationStateEngine::sanitize($meta);
		$this->authorize('assignment.update', $actor, $subject+compact('assignee', 'meta'));
		return $this->store->transaction(function(array &$state) use ($subject, $assignee, $actor, $meta): array {
			$assignment=$subject+['assignee'=>$assignee, 'assigned_by'=>$actor, 'assigned_at'=>gmdate('c'), 'meta'=>$meta];
			$state['assignments'][$subject['subject_key']]=$assignment;
			$receipt=PanelCollaborationStateEngine::receipt($state, 'assignment.updated', $actor, $subject, $assignment);
			return $assignment+['receipt'=>$receipt->toArray()];
		}, 'collaboration.assignment.updated', $subject+compact('assignee', 'actor'));
	}

	public function unassign(string $subjectType, string|int $subjectId, string $actor): bool {
		$subject=PanelCollaborationStateEngine::subject($subjectType, $subjectId);
		$actor=PanelCollaborationStateEngine::identifier($actor, 'actor');
		$this->authorize('assignment.delete', $actor, $subject);
		return $this->store->transaction(function(array &$state) use ($subject, $actor): bool {
			if(!isset($state['assignments'][$subject['subject_key']])){ return false; }
			$previous=$state['assignments'][$subject['subject_key']];
			unset($state['assignments'][$subject['subject_key']]);
			PanelCollaborationStateEngine::receipt($state, 'assignment.removed', $actor, $subject, ['previous'=>$previous]);
			return true;
		}, 'collaboration.assignment.removed', $subject+compact('actor'));
	}

	/** @return array<string,mixed>|null */
	public function assignment(string $subjectType, string|int $subjectId): ?array {
		$key=PanelCollaborationStateEngine::subject($subjectType, $subjectId)['subject_key'];
		$value=$this->store->state()['assignments'][$key] ?? null;
		return is_array($value) ? $value : null;
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	public function watch(string $subjectType, string|int $subjectId, string $userId, array $meta=[]): array {
		$subject=PanelCollaborationStateEngine::subject($subjectType, $subjectId);
		$userId=PanelCollaborationStateEngine::identifier($userId, 'user');
		$meta=PanelCollaborationStateEngine::sanitize($meta);
		$this->authorize('watch.create', $userId, $subject+compact('meta'));
		return $this->store->transaction(function(array &$state) use ($subject, $userId, $meta): array {
			$watch=$subject+['user_id'=>$userId, 'created_at'=>gmdate('c'), 'meta'=>$meta];
			$state['watchers'][$subject['subject_key']][$userId]=$watch;
			$receipt=PanelCollaborationStateEngine::receipt($state, 'watch.created', $userId, $subject, $watch);
			return $watch+['receipt'=>$receipt->toArray()];
		}, 'collaboration.watch.created', $subject+['user_id'=>$userId]);
	}

	public function unwatch(string $subjectType, string|int $subjectId, string $userId): bool {
		$subject=PanelCollaborationStateEngine::subject($subjectType, $subjectId);
		$userId=PanelCollaborationStateEngine::identifier($userId, 'user');
		$this->authorize('watch.delete', $userId, $subject);
		return $this->store->transaction(function(array &$state) use ($subject, $userId): bool {
			if(!isset($state['watchers'][$subject['subject_key']][$userId])){ return false; }
			unset($state['watchers'][$subject['subject_key']][$userId]);
			PanelCollaborationStateEngine::receipt($state, 'watch.removed', $userId, $subject);
			return true;
		}, 'collaboration.watch.removed', $subject+['user_id'=>$userId]);
	}

	/** @return list<string> */
	public function watchers(string $subjectType, string|int $subjectId): array {
		$key=PanelCollaborationStateEngine::subject($subjectType, $subjectId)['subject_key'];
		$users=array_keys((array)($this->store->state()['watchers'][$key] ?? [])); sort($users, SORT_STRING); return $users;
	}

	/** @param array<int,string>|string $channels @return array<string,mixed> */
	public function subscribe(string $topic, string $userId, array|string $channels=['database'], string $mode='immediate'): array {
		$topic=$this->topic($topic); $userId=PanelCollaborationStateEngine::identifier($userId, 'user');
		$channels=$this->channels($channels); $mode=PanelCollaborationStateEngine::key($mode, 'immediate');
		if(!in_array($mode, ['immediate', 'hourly', 'daily', 'weekly', 'muted'], true)){ $mode='immediate'; }
		$this->authorize('subscription.update', $userId, compact('topic', 'channels', 'mode'));
		return $this->store->transaction(function(array &$state) use ($topic, $userId, $channels, $mode): array {
			$subscription=['topic'=>$topic, 'user_id'=>$userId, 'channels'=>$channels, 'mode'=>$mode, 'updated_at'=>gmdate('c')];
			$state['subscriptions'][$topic][$userId]=$subscription;
			$receipt=PanelCollaborationStateEngine::receipt($state, 'subscription.updated', $userId, ['topic'=>$topic], $subscription);
			return $subscription+['receipt'=>$receipt->toArray()];
		}, 'collaboration.subscription.updated', compact('topic', 'userId', 'channels', 'mode'));
	}

	public function unsubscribe(string $topic, string $userId): bool {
		$topic=$this->topic($topic); $userId=PanelCollaborationStateEngine::identifier($userId, 'user');
		$this->authorize('subscription.delete', $userId, compact('topic'));
		return $this->store->transaction(function(array &$state) use ($topic, $userId): bool {
			if(!isset($state['subscriptions'][$topic][$userId])){ return false; }
			unset($state['subscriptions'][$topic][$userId]);
			PanelCollaborationStateEngine::receipt($state, 'subscription.removed', $userId, ['topic'=>$topic]);
			return true;
		}, 'collaboration.subscription.removed', compact('topic', 'userId'));
	}

	/** @return array<int,array<string,mixed>> */
	public function subscriptions(?string $userId=null): array {
		if($userId!==null){ $userId=PanelCollaborationStateEngine::identifier($userId, 'user'); }
		$items=[];
		foreach((array)($this->store->state()['subscriptions'] ?? []) as $subscribers){
			foreach((array)$subscribers as $subscription){
				if(is_array($subscription) && ($userId===null || ($subscription['user_id'] ?? null)===$userId)){ $items[]=$subscription; }
			}
		}
		usort($items, static fn(array $left, array $right): int => strcmp((string)$left['topic'], (string)$right['topic']));
		return $items;
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	public function acquirePresence(string $scope, string $userId, int $ttlSeconds=60, array $meta=[]): array {
		$scope=PanelCollaborationStateEngine::identifier($scope, 'presence scope', 256);
		$userId=PanelCollaborationStateEngine::identifier($userId, 'user');
		$ttl=max(5, min(300, $ttlSeconds)); $meta=PanelCollaborationStateEngine::sanitize($meta);
		$this->authorize('presence.acquire', $userId, compact('scope', 'ttl', 'meta'));
		$token=bin2hex(random_bytes(24)); $hash=hash('sha256', $token); $leaseId=substr($hash, 0, 24);
		$lease=$this->store->transaction(function(array &$state) use ($scope, $userId, $ttl, $meta, $hash, $leaseId): array {
			$now=time();
			$lease=[
				'lease_id'=>$leaseId, 'lease_hash'=>$hash, 'scope'=>$scope, 'user_id'=>$userId,
				'acquired_at'=>gmdate('c', $now), 'last_seen_at'=>gmdate('c', $now),
				'expires_at'=>gmdate('c', $now+$ttl), 'meta'=>$meta,
			];
			$state['presence'][$scope][$userId]=$lease;
			PanelCollaborationStateEngine::receipt($state, 'presence.acquired', $userId, ['scope'=>$scope], ['lease_id'=>$leaseId, 'expires_at'=>$lease['expires_at'], 'meta'=>$meta]);
			return PanelCollaborationStateEngine::sanitize($lease);
		}, 'collaboration.presence.acquired', compact('scope', 'userId', 'leaseId'));
		$lease['lease_token']=$token;
		return $lease;
	}

	/** @return array<string,mixed> */
	public function heartbeatPresence(string $scope, string $userId, string $leaseToken, int $ttlSeconds=60): array {
		$scope=PanelCollaborationStateEngine::identifier($scope, 'presence scope', 256);
		$userId=PanelCollaborationStateEngine::identifier($userId, 'user'); $ttl=max(5, min(300, $ttlSeconds));
		$this->authorize('presence.heartbeat', $userId, compact('scope', 'ttl'));
		return $this->store->transaction(function(array &$state) use ($scope, $userId, $leaseToken, $ttl): array {
			$lease=$state['presence'][$scope][$userId] ?? null;
			if(!is_array($lease) || !hash_equals((string)($lease['lease_hash'] ?? ''), hash('sha256', $leaseToken))){
				throw new \UnexpectedValueException('Presence lease token is invalid.');
			}
			$now=time(); $lease['last_seen_at']=gmdate('c', $now); $lease['expires_at']=gmdate('c', $now+$ttl);
			$state['presence'][$scope][$userId]=$lease;
			return PanelCollaborationStateEngine::sanitize($lease);
		}, 'collaboration.presence.heartbeat', compact('scope', 'userId'));
	}

	public function releasePresence(string $scope, string $userId, string $leaseToken): bool {
		$scope=PanelCollaborationStateEngine::identifier($scope, 'presence scope', 256);
		$userId=PanelCollaborationStateEngine::identifier($userId, 'user');
		$this->authorize('presence.release', $userId, compact('scope'));
		return $this->store->transaction(function(array &$state) use ($scope, $userId, $leaseToken): bool {
			$lease=$state['presence'][$scope][$userId] ?? null;
			if(!is_array($lease) || !hash_equals((string)($lease['lease_hash'] ?? ''), hash('sha256', $leaseToken))){ return false; }
			unset($state['presence'][$scope][$userId]);
			PanelCollaborationStateEngine::receipt($state, 'presence.released', $userId, ['scope'=>$scope], ['lease_id'=>$lease['lease_id'] ?? null]);
			return true;
		}, 'collaboration.presence.released', compact('scope', 'userId'));
	}

	/** @return array<int,array<string,mixed>> */
	public function presence(string $scope, ?int $at=null): array {
		$scope=PanelCollaborationStateEngine::identifier($scope, 'presence scope', 256); $at=$at ?? time(); $items=[];
		foreach((array)($this->store->state()['presence'][$scope] ?? []) as $lease){
			if(is_array($lease) && (strtotime((string)($lease['expires_at'] ?? '')) ?: 0)>$at){ $items[]=PanelCollaborationStateEngine::sanitize($lease); }
		}
		usort($items, static fn(array $left, array $right): int => strcmp((string)$left['user_id'], (string)$right['user_id']));
		return $items;
	}

	public function typing(string $threadId, string $userId, bool $typing=true, int $ttlSeconds=12): bool {
		$threadId=PanelCollaborationStateEngine::identifier($threadId, 'thread id', 128);
		$userId=PanelCollaborationStateEngine::identifier($userId, 'user'); $ttl=max(3, min(30, $ttlSeconds));
		$this->authorize('typing.update', $userId, compact('threadId', 'typing', 'ttl'));
		return $this->store->transaction(function(array &$state) use ($threadId, $userId, $typing, $ttl): bool {
			if(!isset($state['threads'][$threadId])){ throw new \RuntimeException('Collaboration thread does not exist.'); }
			if(!$typing){ unset($state['typing'][$threadId][$userId]); return true; }
			$state['typing'][$threadId][$userId]=['thread_id'=>$threadId, 'user_id'=>$userId, 'updated_at'=>gmdate('c'), 'expires_at'=>gmdate('c', time()+$ttl)];
			return true;
		}, 'collaboration.typing.updated', compact('threadId', 'userId', 'typing'));
	}

	/** @return list<string> */
	public function typingUsers(string $threadId, ?int $at=null): array {
		$threadId=PanelCollaborationStateEngine::identifier($threadId, 'thread id', 128); $at=$at ?? time(); $users=[];
		foreach((array)($this->store->state()['typing'][$threadId] ?? []) as $user=>$indicator){
			if(is_array($indicator) && (strtotime((string)($indicator['expires_at'] ?? '')) ?: 0)>$at){ $users[]=(string)$user; }
		}
		sort($users, SORT_STRING); return $users;
	}

	/** @return array{presence:int,typing:int} */
	public function cleanupExpired(?int $at=null): array {
		$at=$at ?? time();
		return $this->store->transaction(static function(array &$state) use ($at): array {
			$removedPresence=0; $removedTyping=0;
			foreach((array)($state['presence'] ?? []) as $scope=>$leases){
				foreach((array)$leases as $user=>$lease){
					if(!is_array($lease) || (strtotime((string)($lease['expires_at'] ?? '')) ?: 0)<=$at){ unset($state['presence'][$scope][$user]); $removedPresence++; }
				}
				if(($state['presence'][$scope] ?? [])===[]){ unset($state['presence'][$scope]); }
			}
			foreach((array)($state['typing'] ?? []) as $thread=>$indicators){
				foreach((array)$indicators as $user=>$indicator){
					if(!is_array($indicator) || (strtotime((string)($indicator['expires_at'] ?? '')) ?: 0)<=$at){ unset($state['typing'][$thread][$user]); $removedTyping++; }
				}
				if(($state['typing'][$thread] ?? [])===[]){ unset($state['typing'][$thread]); }
			}
			return ['presence'=>$removedPresence, 'typing'=>$removedTyping];
		}, 'collaboration.leases.cleaned', ['at'=>$at]);
	}

	/** @return array<int,PanelCollaborationReceipt> */
	public function receipts(?string $action=null, int $limit=100): array {
		$state=$this->store->state(); $items=[];
		foreach(array_reverse((array)($state['receipt_order'] ?? [])) as $id){
			$value=$state['receipts'][$id] ?? null;
			if(is_array($value) && ($action===null || ($value['action'] ?? null)===$action)){ $items[]=new PanelCollaborationReceipt($value); }
			if(count($items)>=max(1, min(1000, $limit))){ break; }
		}
		return $items;
	}

	/** @return array{valid:bool,count:int,first_invalid:?string,head_hash:string} */
	public function verifyReceipts(): array { return PanelCollaborationStateEngine::verifyReceipts($this->store->state()); }
	public function cursor(): int { return $this->store->cursor(); }
	public function changesSince(int $cursor=0, int $limit=100): array { return $this->store->changesSince($cursor, $limit); }

	/** @return array<string,mixed> */
	public function manifest(): array {
		$state=$this->store->state(); $presence=0; $typing=0;
		foreach((array)($state['presence'] ?? []) as $items){ $presence+=count((array)$items); }
		foreach((array)($state['typing'] ?? []) as $items){ $typing+=count((array)$items); }
		return [
			'type'=>'panel_collaboration_manager', 'cursor'=>$this->cursor(),
			'counts'=>[
				'threads'=>count((array)($state['threads'] ?? [])), 'comments'=>count((array)($state['comments'] ?? [])),
				'assignments'=>count((array)($state['assignments'] ?? [])), 'presence'=>$presence,
				'typing'=>$typing, 'receipts'=>count((array)($state['receipts'] ?? [])),
			],
			'policy_callback'=>$this->policy!==null,
			'receipt_chain'=>$this->verifyReceipts(),
			'capabilities'=>[
				'threads'=>true, 'comments'=>true, 'mentions'=>true, 'assignments'=>true,
				'watchers'=>true, 'subscriptions'=>true, 'presence_leases'=>true,
				'typing_indicators'=>true, 'ordered_change_feed'=>true,
				'policy_callback'=>true, 'immutable_activity_receipts'=>true,
			],
			'store'=>$this->store->manifest(),
		];
	}

	public function jsonSerialize(): array { return $this->manifest(); }

	/** @param array<string,mixed> $context */
	private function authorize(string $operation, ?string $actor, array $context): void {
		if($this->policy===null){ return; }
		try { $result=($this->policy)($operation, $actor, PanelCollaborationStateEngine::sanitize($context), $this); }
		catch(PanelCollaborationPolicyException $exception){ throw $exception; }
		catch(\Throwable $exception){ throw new PanelCollaborationPolicyException($operation, $actor, 'Collaboration policy failed: '.$exception->getMessage()); }
		if($result===false || (is_array($result) && ($result['allowed'] ?? true)!==true)){
			$reason=is_array($result) ? trim((string)($result['reason'] ?? '')) : '';
			throw new PanelCollaborationPolicyException($operation, $actor, $reason);
		}
	}

	private function topic(string $topic): string {
		$topic=strtolower(trim($topic)); $topic=trim(preg_replace('/[^a-z0-9.*_-]+/', '.', $topic) ?? '', '.');
		if($topic===''){ throw new \InvalidArgumentException('Collaboration subscription topic cannot be empty.'); }
		return substr($topic, 0, 256);
	}

	/** @param array<int,string>|string $channels @return list<string> */
	private function channels(array|string $channels): array {
		$result=[];
		foreach(is_array($channels) ? $channels : [$channels] as $channel){
			$channel=PanelCollaborationStateEngine::key((string)$channel, '');
			if($channel!=='' && !in_array($channel, $result, true)){ $result[]=$channel; }
		}
		return $result!==[] ? $result : ['database'];
	}
}
