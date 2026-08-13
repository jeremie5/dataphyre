<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** First-party conformance packs for the highest-risk distributed boundaries. */
final class PanelAdapterConformanceCatalog {
	/** Collector identity, manifest, freshness, and typed-result contract. */
	public static function complianceCollector():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('compliance_collector',PanelComplianceCollector::class,1,['credentials'=>'injected_not_serialized','result'=>'typed_bounded_observation']);
		$suite=$suite->add(PanelAdapterConformanceCase::make('identity_and_manifest',static function(PanelComplianceCollector $adapter,PanelAdapterConformanceContext $context):void {
			$id=PanelOperationsGuard::name($adapter->id(),'compliance collector id');$fingerprint=$adapter->fingerprint();$capabilities=$adapter->capabilities();
			$context->same($id,$adapter->id(),'collector_id_normalization')->check(preg_match('/^[a-f0-9]{64}$/D',$fingerprint)===1,'collector_fingerprint','Collector fingerprints must be SHA-256 hex values.')->same($fingerprint,$adapter->fingerprint(),'collector_fingerprint_unstable')->check($capabilities===[]||!array_is_list($capabilities),'collector_capabilities_shape','Collector capabilities must be an object-like map.')->evidenceValue('collector_id',$id)->evidenceValue('collector_hash',$fingerprint);
			if($adapter instanceof \JsonSerializable){$manifest=$adapter->jsonSerialize();$json=json_encode($manifest,JSON_THROW_ON_ERROR);if(array_key_exists('credentials_serialized',$manifest)){$context->same(false,$manifest['credentials_serialized'],'collector_credentials_flag','Collector manifests must declare that credentials are not serialized.');}$forbidden=$context->option('forbidden_fragments',[]);if(is_string($forbidden)){$forbidden=[$forbidden];}if(is_array($forbidden)){foreach($forbidden as$fragment){if(is_string($fragment)&&$fragment!==''){$context->check(!str_contains($json,$fragment),'collector_credential_leak','Collector manifest exposed a forbidden credential fragment.');}}}}
		},'Collector identity and secret-free manifest'));
		return$suite->add(PanelAdapterConformanceCase::make('typed_observation',static function(PanelComplianceCollector $adapter,PanelAdapterConformanceContext $context):void {
			$collection=$context->option('collection_context');if(!$collection instanceof PanelComplianceCollectionContext){$context->skip('A collection_context was not supplied.');return;}$observation=$adapter->collect($collection);$context->instanceOf(PanelComplianceObservation::class,$observation,'collector_result_type')->check(in_array($observation->status(),PanelComplianceObservation::STATUSES,true),'collector_result_status','Collector returned an unsupported status.')->check(strcmp($observation->validUntil(),$observation->observedAt())>=0,'collector_freshness_window','Observation validity precedes observation time.')->check(preg_match('/^[a-f0-9]{64}$/D',$observation->digest())===1,'collector_observation_digest','Observation digest is invalid.')->same($collection->subject(),$observation->subject(),'collector_subject_binding','Collector returned evidence for another subject.')->evidenceValue('observation_digest',$observation->digest());
		},'Typed freshness-bounded observation',optional:true,maxMillis:10000));
	}

	/** @param array<string,mixed> $options */
	public static function dataSource(array $options=[]): PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('data_source',PanelDataSource::class,2,['query_ast'=>true]);
		$suite=$suite->add(PanelAdapterConformanceCase::make('capability_manifest',static function(PanelDataSource $adapter,PanelAdapterConformanceContext $context):void{
			$capabilities=$adapter->capabilities(); $context->check(!array_is_list($capabilities),'capabilities_shape','Capabilities must be an object-like map.')->evidenceValue('capability_keys',array_keys($capabilities));
			/* Every production adapter must at least survive the shared normalized grammar. */
			PanelQueryCapabilities::fromArray($capabilities)->jsonSerialize();
		},'Capability manifest'));
		$suite=$suite->add(PanelAdapterConformanceCase::make('query_envelope',static function(PanelDataSource $adapter,PanelAdapterConformanceContext $context):void{
			$query=$context->option('query'); if(!$query instanceof PanelDataQuery){ $query=PanelDataQuery::make()->limit(5); }
			PanelQueryCapabilities::fromArray($adapter->capabilities())->assertSupports($query);
			$result=$adapter->query($query); $context->instanceOf(PanelDataResult::class,$result)->check(array_is_list($result->items()),'items_shape','Result items must be a list.')->same(count($result->items()),$result->count(),'count_mismatch','Count must match items.')->check(trim($result->source())!=='','source_missing','Result source cannot be blank.')->evidenceValue('result_count',$result->count())->evidenceValue('query_fingerprint',$query->fingerprint());
			$encoded=json_encode($result,JSON_THROW_ON_ERROR);
			$context->check(is_string($encoded)&&$encoded!=='','result_serialization','Result envelopes must serialize to non-empty JSON.');
		},'Canonical query envelope'));
		$suite=$suite->add(PanelAdapterConformanceCase::make('find_semantics',static function(PanelDataSource $adapter,PanelAdapterConformanceContext $context):void{
			$known=$context->option('known_id'); $missing=$context->option('missing_id');
			$scope=$context->option('find_scope'); if(!$scope instanceof PanelDataQuery){ $scope=null; }
			if($known===null&&$missing===null){ $context->skip('known_id or missing_id was not supplied.'); return; }
			if($known!==null){ $context->check($adapter->find(is_int($known)||is_string($known)?$known:(string)$known,$scope)!==null,'known_record_missing','Known record was not found.'); }
			if($missing!==null){ $context->same(null,$adapter->find(is_int($missing)||is_string($missing)?$missing:(string)$missing,$scope),'missing_record_visible','Missing record lookup must return null.'); }
		},'Find semantics',optional:true));
		return $suite;
	}

	public static function mutableDataSource():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('mutable_data_source',PanelMutableDataSource::class,1,['scope'=>'explicit','idempotency'=>'required','optimistic_concurrency'=>'negotiated']);
		$suite=$suite->add(PanelAdapterConformanceCase::make('mutation_capabilities',static function(PanelMutableDataSource $adapter,PanelAdapterConformanceContext $context):void{
			$capabilities=PanelDataMutationCapabilities::fromArray($adapter->capabilities());$context->truthy($capabilities->enabled(),'mutations_disabled','Mutable adapters must advertise enabled mutations.')->check($capabilities->operations()!==[],'operations_missing','Mutable adapters must advertise at least one operation.')->evidenceValue('mutation_capabilities',$capabilities->jsonSerialize());
		},'Mutation capability negotiation'));
		$suite=$suite->add(PanelAdapterConformanceCase::make('idempotent_mutation',static function(PanelMutableDataSource $adapter,PanelAdapterConformanceContext $context):void{
			$mutation=$context->option('mutation');if(!$mutation instanceof PanelDataMutation){$context->skip('A mutation envelope was not supplied.');return;}
			PanelDataMutationCapabilities::fromArray($adapter->capabilities())->assertSupports($mutation);$first=$adapter->mutate($mutation);$replay=$adapter->mutate($mutation);
			$context->instanceOf(PanelDataMutationReceipt::class,$first,'receipt_missing')->same($first->receiptId(),$replay->receiptId(),'receipt_identity')->same($first->revision(),$replay->revision(),'replay_revision')->truthy($replay->replayed(),'replay_flag','An exact mutation retry must report replay.');
			$encoded=json_encode([$first,$replay],JSON_THROW_ON_ERROR);$context->check(!str_contains($encoded,$mutation->idempotencyKey()),'raw_idempotency_leak','Mutation receipts exposed the raw idempotency key.')->evidenceValue('receipt_id',$first->receiptId())->evidenceValue('revision',$first->revision());
		},'Idempotent mutation and replay evidence',destructive:true,optional:true,maxMillis:10000));
		return$suite->add(PanelAdapterConformanceCase::make('atomic_batch',static function(PanelMutableDataSource $adapter,PanelAdapterConformanceContext $context):void{
			$batch=$context->option('batch');if(!$batch instanceof PanelDataMutationBatch){$context->skip('A mutation batch was not supplied.');return;}
			PanelDataMutationCapabilities::fromArray($adapter->capabilities())->assertSupports($batch);$result=$adapter->mutateBatch($batch);$replay=$adapter->mutateBatch($batch);
			$context->instanceOf(PanelDataMutationBatchResult::class,$result,'batch_result_missing')->same($batch->count(),$result->count(),'batch_receipt_count')->truthy($replay->replayed(),'batch_replay','An exact batch retry must replay every receipt.')->evidenceValue('batch_fingerprint',$batch->fingerprint());
		},'Atomic batch execution and replay',destructive:true,optional:true,maxMillis:15000));
	}

	public static function mediaDisk(): PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('media_disk',PanelMediaDisk::class,1);
		$suite=$suite->add(PanelAdapterConformanceCase::make('path_boundary',static function(PanelMediaDisk $adapter,PanelAdapterConformanceContext $context):void{
			$context->same('probe/file.txt',$adapter->normalizePath('probe/./file.txt'),'path_normalization','Relative path normalization changed.');
			$context->throws(static fn()=>$adapter->normalizePath('../escape.txt'),[\InvalidArgumentException::class,\RuntimeException::class],'traversal_accepted');
			$context->throws(static fn()=>$adapter->normalizePath('/absolute.txt'),[\InvalidArgumentException::class,\RuntimeException::class],'absolute_accepted');
		},'Path confinement'));
		$suite=$suite->add(PanelAdapterConformanceCase::make('file_lifecycle',static function(PanelMediaDisk $adapter,PanelAdapterConformanceContext $context):void{
			$prefix=Resource::normalizeName((string)$context->option('namespace','conformance_'.bin2hex(random_bytes(5)))) ?: 'conformance';
			$source=$prefix.'/source.txt'; $copy=$prefix.'/copy.txt'; $moved=$prefix.'/moved.txt'; $payload="Dataphyre adapter conformance\n";
			try{
				$descriptor=$adapter->write($source,$payload,['overwrite'=>false,'checksum'=>hash('sha256',$payload)]);
				$context->truthy($adapter->exists($source),'write_missing')->same($payload,$adapter->read($source),'read_mismatch')->same(strlen($payload),$adapter->size($source),'size_mismatch')->same(hash('sha256',$payload),$adapter->checksum($source),'checksum_mismatch')->same($source,$descriptor['path']??null,'descriptor_path');
				$stream=$adapter->readStream($source); $context->check(is_resource($stream),'stream_missing','readStream must return a resource.'); if(is_resource($stream)){ $context->same($payload,stream_get_contents($stream),'stream_mismatch'); fclose($stream); }
				$adapter->copy($source,$copy); $adapter->move($copy,$moved); $context->truthy($adapter->exists($moved),'move_missing')->same(false,$adapter->exists($copy),'move_source_retained');
				$paths=array_map(static fn(array $item):string=>(string)($item['path']??''),$adapter->list($prefix)); $context->check(in_array($source,$paths,true)&&in_array($moved,$paths,true),'list_incomplete','List must contain lifecycle files.',$source.' and '.$moved,$paths);
				$context->throws(static fn()=>$adapter->write($source,'duplicate',['overwrite'=>false]),\Throwable::class,'overwrite_policy_missing');
				$context->evidenceValue('disk',$adapter->name())->evidenceValue('bytes',strlen($payload));
			}finally{ foreach($adapter->list($prefix) as $item){ $path=(string)($item['path']??''); if($path!==''&&str_starts_with($path,$prefix.'/')){ $adapter->delete($path); } } }
		},'Write/read/copy/move/delete lifecycle',destructive:true,maxMillis:15000));
		return $suite;
	}

	public static function mediaManager(): PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('media_manager',PanelMediaManager::class,1,[
			'catalog'=>'transactional_snapshot',
			'delivery'=>'optional_signed',
			'uploads'=>'resumable',
		]);
		$suite=$suite->add(PanelAdapterConformanceCase::make('manifest_and_boundaries',static function(PanelMediaManager $adapter,PanelAdapterConformanceContext $context):void{
			$manifest=$adapter->manifest();
			$context->instanceOf(PanelMediaDisk::class,$adapter->disk(),'manager_disk')
				->instanceOf(PanelSnapshotStore::class,$adapter->catalog(),'manager_catalog')
				->same('panel_media_manager',$manifest['type']??null,'manager_type')
				->truthy($manifest['capabilities']['resumable_uploads']??false,'resumable_uploads_missing')
				->truthy($manifest['capabilities']['change_feed']??false,'change_feed_missing')
				->check(is_array($manifest['disk']??null),'disk_manifest_missing','Manager manifests must expose their disk contract.')
				->check(is_array($manifest['catalog']??null),'catalog_manifest_missing','Manager manifests must expose their catalog contract.')
				->evidenceValue('disk',$adapter->disk()->name())
				->evidenceValue('cursor',$adapter->catalog()->cursor());
		},'Typed manager, disk, catalog, and manifest boundaries'));
		return $suite->add(PanelAdapterConformanceCase::make('upload_catalog_delivery_lifecycle',static function(PanelMediaManager $adapter,PanelAdapterConformanceContext $context):void{
			$namespace=Resource::normalizeName((string)$context->option('namespace','conformance_'.bin2hex(random_bytes(5))))?:'conformance';
			$sessionId='media_'.$namespace.'_'.bin2hex(random_bytes(4));
			if(strlen($sessionId)>128){$sessionId='media_'.bin2hex(random_bytes(12));}
			$path=$namespace.'/probe.bin';$payload=str_repeat('D',1024);$itemId=null;$cursor=$adapter->catalog()->cursor();
			try{
				$upload=$adapter->startUpload($path,strlen($payload),[
					'id'=>$sessionId,
					'chunk_size'=>1024,
					'checksum'=>hash('sha256',$payload),
					'metadata'=>['conformance'=>true],
				]);
				$context->same('open',$upload['state']??null,'upload_state')
					->same($path,$upload['target_path']??null,'upload_target');
				$chunk=$adapter->receiveChunk($sessionId,0,$payload,hash('sha256',$payload),0);
				$context->same(1,$chunk['session']['received_chunks']??null,'received_chunks')
					->truthy($chunk['session']['ready']??false,'upload_not_ready');
				$completion=$adapter->completeUpload($sessionId,[],['name'=>'Conformance probe']);
				$context->truthy($completion['ok']??false,'completion_failed');
				$item=$completion['item']??null;
				$context->check(is_array($item),'item_missing','Completion must return a media item.');
				if(!is_array($item)){return;}
				$itemId=(string)($item['id']??'');
				$context->check($itemId!==''&&$adapter->item($itemId)!==null,'catalog_item_missing','Completed media must be catalogued.')
					->same($payload,$adapter->disk()->read($path),'completed_payload')
					->check($adapter->catalog()->cursor()>$cursor,'catalog_cursor_static','Media mutations must advance the catalog cursor.')
					->check(($adapter->changes($cursor)['changes']??[])!==[],'change_feed_empty','Media mutations must publish retained changes.');
				if(($adapter->manifest()['capabilities']['private_delivery']??false)===true){
					$delivery=$adapter->issue($itemId,60,'attachment','panel-conformance');
					$context->check(is_string($delivery['token']??null)&&($delivery['token']??'')!=='','signed_token_missing','Configured private delivery must issue a token.')
						->same($itemId,$delivery['claims']['media_id']??null,'delivery_item_binding');
				}
				$context->truthy($adapter->delete($itemId),'delete_failed')->same(null,$adapter->item($itemId),'deleted_item_visible');
				$itemId=null;
			}finally{
				if(is_string($itemId)&&$itemId!==''){try{$adapter->delete($itemId);}catch(\Throwable){}}
				foreach([$path,'.panel_uploads/'.$sessionId.'/manifest.json']as$cleanup){try{$adapter->disk()->delete($cleanup);}catch(\Throwable){}}
			}
		},'Resumable upload, transactional catalog, signed delivery, change feed, and deletion',destructive:true,maxMillis:20000));
		}

		/** Atomic snapshot, rollback, retained-feed, reset, and secret-free manifest contract. */
		public static function snapshotStore():PanelAdapterConformanceSuite {
			$suite=PanelAdapterConformanceSuite::make('snapshot_store',PanelSnapshotStore::class,1,[
				'mutation_callback'=>'at_most_once_per_call',
				'commit'=>'atomic_or_explicit_uncertainty',
				'feed'=>'ordered_retained_cursor',
			]);
			$suite=$suite->add(PanelAdapterConformanceCase::make('manifest_and_snapshot_boundaries',static function(PanelSnapshotStore $adapter,PanelAdapterConformanceContext $context):void {
				$snapshot=$adapter->snapshot();
				$manifest=$adapter->manifest();
				$context->same($adapter->cursor(),$snapshot['sequence']??null,'snapshot_cursor')
					->same($adapter->payload(),$snapshot['payload']??null,'snapshot_payload')
					->check(is_string($snapshot['schema']??null)&&($snapshot['schema']??'')!=='','snapshot_schema','Snapshot stores require a stable schema.')
					->check(is_array($manifest)&&!array_is_list($manifest),'snapshot_manifest_shape','Snapshot manifests must be object-like maps.')
					->same($manifest,$adapter->jsonSerialize(),'snapshot_json_manifest');
				$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);
				$forbidden=$context->option('forbidden_fragments',[]);
				if(is_string($forbidden)){$forbidden=[$forbidden];}
				if(is_array($forbidden)){
					foreach($forbidden as $fragment){
						if(is_string($fragment)&&$fragment!==''){
							$context->check(!str_contains($encoded,$fragment),'snapshot_manifest_secret','Snapshot manifest exposed a forbidden fragment.');
						}
					}
				}
				$context->evidenceValue('base_cursor',$adapter->cursor());
			},'Typed snapshot and secret-free manifest boundaries'));
			return $suite->add(PanelAdapterConformanceCase::make('atomic_lifecycle_and_cursor_resets',static function(PanelSnapshotStore $adapter,PanelAdapterConformanceContext $context):void {
				$before=$adapter->snapshot();
				$calls=0;
				try{
					$adapter->transaction(static function(array &$payload)use(&$calls):void {
						$calls++;
						$payload['__panel_snapshot_conformance_rollback']='must-not-commit';
						throw new \RuntimeException('snapshot-conformance-rollback');
					},'conformance.rollback');
					$context->check(false,'snapshot_rollback_exception_missing','A failed snapshot mutation did not throw.');
				}catch(\RuntimeException $error){
					$context->same('snapshot-conformance-rollback',$error->getMessage(),'snapshot_rollback_exception');
				}
				$context->same(1,$calls,'snapshot_callback_replayed')
					->same($before,$adapter->snapshot(),'snapshot_rollback_changed_state');

				$marker='snapshot-conformance-'.bin2hex(random_bytes(8));
				try{
					$commit=$adapter->transaction(static function(array &$payload)use($marker):string {
						$payload['__panel_snapshot_conformance']=$marker;
						return $marker;
					},'conformance.commit',['marker_digest'=>hash('sha256',$marker),'api_token'=>'must-not-survive']);
					$context->same($marker,$commit['result']??null,'snapshot_transaction_result')
						->same($marker,$commit['snapshot']['payload']['__panel_snapshot_conformance']??null,'snapshot_transaction_payload')
						->same((int)$before['sequence']+1,$commit['snapshot']['sequence']??null,'snapshot_transaction_cursor');
					$feed=$adapter->changesSince((int)$before['sequence'],1000);
					$context->check(($feed['reset_required']??false)===true||($feed['changes']??[])!==[],'snapshot_change_feed_empty','A committed snapshot mutation was absent from both feed and reset snapshot.');
					$future=$adapter->changesSince($adapter->cursor()+1000,1);
					$context->truthy($future['reset_required']??false,'snapshot_future_cursor_not_reset')
						->same('future_cursor',$future['reset_reason']??null,'snapshot_future_cursor_reason')
						->same($adapter->payload(),$future['snapshot']['payload']??null,'snapshot_future_cursor_payload');
					$encoded=json_encode($adapter,JSON_THROW_ON_ERROR);
					$context->check(!str_contains($encoded,$marker),'snapshot_manifest_marker_leak','Snapshot manifest exposed transaction material.')
						->check(!str_contains($encoded,'must-not-survive'),'snapshot_manifest_secret_leak','Snapshot manifest exposed secret-shaped event metadata.');
				}finally{
					try{
						$adapter->transaction(static function(array &$payload):void {
							unset($payload['__panel_snapshot_conformance'],$payload['__panel_snapshot_conformance_rollback']);
						},'conformance.cleanup');
					}catch(\Throwable){}
				}
				$context->evidenceValue('final_cursor',$adapter->cursor());
			},'Atomic rollback, callback delivery, retained changes, future resets, and cleanup',destructive:true,maxMillis:10000));
		}

		public static function operationStore(): PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('operation_store',PanelOperationStore::class,1);
		return $suite->add(PanelAdapterConformanceCase::make('optimistic_lifecycle',static function(PanelOperationStore $adapter,PanelAdapterConformanceContext $context):void{
			$id='conformance_'.bin2hex(random_bytes(6)); $key='idem_'.$id;
			$record=PanelOperationRecord::make('conformance','Adapter conformance',['id'=>$id,'idempotency_key'=>$key,'max_attempts'=>2,'total'=>2]);
			try{
				$created=$adapter->create($record); $context->same(1,$created->revision(),'create_revision')->same($id,$adapter->get($id)?->id(),'get_mismatch')->same($id,$adapter->findByIdempotencyKey($key)?->id(),'idempotency_lookup')->same($id,$adapter->create($record)->id(),'idempotency_replay');
				$running=$adapter->save($created->start('conformance'),$created->revision()); $context->same(2,$running->revision(),'save_revision');
				$context->throws(static fn()=>$adapter->save($running,$created->revision()),PanelOperationConflict::class,'stale_write_accepted');
				$updated=$adapter->update($id,static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->progress(1,2,'Half',1,0),$running->revision());
				$context->same(3,$updated->revision(),'update_revision')->same(1,$updated->processed(),'update_payload')->check(count($adapter->all(['id'=>$id],10))===1,'list_missing','Filtered list must contain the record.')->evidenceValue('final_revision',$updated->revision());
			}finally{ $adapter->delete($id); }
			$context->same(null,$adapter->get($id),'delete_visible','Deleted record remains visible.');
		},'Optimistic/idempotent store lifecycle',destructive:true,maxMillis:15000));
	}

	public static function leasedOperationStore():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('leased_operation_store',PanelLeasedOperationStore::class,1,['delivery'=>'at_least_once','ownership'=>'fenced']);
		return $suite->add(PanelAdapterConformanceCase::make('lease_fencing',static function(PanelLeasedOperationStore $adapter,PanelAdapterConformanceContext $context):void{
			$id='lease_conformance_'.bin2hex(random_bytes(6)); $lease=null; $second=null;
			try{
				$record=PanelOperationRecord::make('lease_conformance','Lease conformance',['id'=>$id,'created_at'=>$adapter->currentTime(),'max_attempts'=>3,'total'=>2]); $adapter->create($record);
				$reservation=$adapter->acquireLease($id,'conformance-a',30); $context->instanceOf(PanelOperationReservation::class,$reservation,'lease_missing'); if(!$reservation instanceof PanelOperationReservation){ return; }
				$lease=$reservation->lease(); $context->check($lease->fence()>0,'fence_missing','Lease fences must be positive.')->same(PanelOperationStatus::RUNNING,$reservation->record()->status(),'lease_not_running');
				$mutated=$adapter->mutateLease($lease,static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->progress(1,2,'Half',1,0)); $lease=$mutated->lease(); $context->same(1,$mutated->record()->processed(),'fenced_mutation_missing');
				$renewed=$adapter->renewLease($lease,30); $lease=$renewed->lease(); $context->check(strcmp($lease->expiresAt(),$lease->renewedAt())>0,'renewal_window_invalid','Renewed lease expiry must follow renewal time.');
				$forged=PanelOperationLease::make($id,$lease->worker(),str_repeat('f',48),$lease->fence(),$lease->acquiredAt(),$lease->expiresAt(),$lease->renewedAt()); $context->throws(static fn()=>$adapter->inspectLease($forged),PanelOperationLeaseLost::class,'forged_lease_accepted');
				$released=$adapter->releaseLease($lease,0); $lease=null; $context->same(PanelOperationStatus::RETRY_WAIT,$released->status(),'lease_release_status');
				$second=$adapter->acquireLease($id,'conformance-b',30); $context->instanceOf(PanelOperationReservation::class,$second,'lease_reacquire_missing'); if(!$second instanceof PanelOperationReservation){ return; }
				$context->check($second->lease()->fence()>$renewed->lease()->fence(),'fence_not_monotonic','Reacquired leases must advance the fence.')->throws(static fn()=>$adapter->inspectLease($renewed->lease()),PanelOperationLeaseLost::class,'stale_lease_accepted');
				$finished=$adapter->finishLease($second->lease(),static fn(PanelOperationRecord $current):PanelOperationRecord=>$current->complete(['ok'=>true],PanelOperationStatus::COMPLETED,$adapter->currentTime())); $second=null; $context->same(PanelOperationStatus::COMPLETED,$finished->status(),'lease_finish_status')->same([], $adapter->activeLeaseManifests(),'lease_retained');
			}finally{
				foreach([$second,$lease] as $owned){ try{ if($owned instanceof PanelOperationReservation){ $adapter->releaseLease($owned->lease(),0); } elseif($owned instanceof PanelOperationLease){ $adapter->releaseLease($owned,0); } }catch(\Throwable){} }
				try{ $adapter->delete($id); }catch(\Throwable){}
			}
			$context->same(null,$adapter->get($id),'lease_cleanup_visible');
		},'Lease ownership, renewal, fencing, release, and cleanup',destructive:true,maxMillis:15000));
	}

	public static function commandFabricStore():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('command_fabric_store',PanelCommandFabricStore::class,1,[
			'journal'=>'atomic','outbox'=>'atomic','delivery'=>'at_least_once',
		]);
		return$suite->add(PanelAdapterConformanceCase::make('atomic_state_and_change_feed',static function(PanelCommandFabricStore $adapter,PanelAdapterConformanceContext $context):void{
			$before=$adapter->payload();
			$context->same(PanelCommandFabricState::validate($before),$before,'initial_state_invalid');
			try{
				$adapter->transaction(static function(array &$state):void{$state['revision']++;throw new \RuntimeException('command-fabric rollback probe');},'conformance_rollback');
				$context->check(false,'rollback_exception_missing','A failed command-fabric mutation did not throw.');
			}catch(\RuntimeException $error){
				$context->same('command-fabric rollback probe',$error->getMessage(),'rollback_exception_changed');
			}
			$context->same($before,$adapter->payload(),'rollback_changed_state','A failed command-fabric mutation changed durable state.');
			$marker='fabric-conformance-'.bin2hex(random_bytes(8));
			$commit=$adapter->transaction(static function(array &$state)use($marker):string{$state['revision']++;return$marker;},'conformance_commit',['marker_digest'=>hash('sha256',$marker),'api_token'=>'must-not-survive']);
			$after=$adapter->payload();
			$context->same($marker,$commit['result']??null,'transaction_result')
				->same((int)$before['revision']+1,$after['revision']??null,'revision_commit')
				->same($after,$commit['snapshot']['payload']??null,'snapshot_payload');
			$feed=$adapter->changesSince(0,1000);
			$context->check(is_array($feed['changes']??null),'change_feed_shape','Command-fabric change feeds must contain a changes list.')
				->check(($feed['reset_required']??null)===true||($feed['changes']??[])!==[],'change_feed_empty','A committed command-fabric mutation was absent from both feed and reset snapshot.');
			if(($feed['reset_required']??false)===true){$context->same($after,$feed['snapshot']['payload']??null,'reset_snapshot_payload');}
			if($adapter instanceof \JsonSerializable){
				$encoded=json_encode($adapter,JSON_THROW_ON_ERROR);
				$context->check(!str_contains($encoded,$marker),'manifest_marker_leak','A command-fabric manifest exposed transaction material.')
					->check(!str_contains($encoded,'must-not-survive'),'manifest_secret_leak','A command-fabric manifest exposed secret-shaped change metadata.');
			}
			$context->evidenceValue('base_revision',$before['revision']??null)->evidenceValue('final_revision',$after['revision']??null);
		},'Atomic rollback, commit snapshots, retained change feeds, and secret-free manifests',destructive:true,maxMillis:10000));
	}

	public static function leasedCommandFabricStore():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('leased_command_fabric_store',PanelLeasedCommandFabricStore::class,1,[
			'ownership'=>'renewable_fenced','cursor'=>'atomic_fenced','delivery'=>'at_least_once',
		]);
		return$suite->add(PanelAdapterConformanceCase::make('fenced_subscriber_lifecycle',static function(PanelLeasedCommandFabricStore $adapter,PanelAdapterConformanceContext $context):void{
			$subscriber='fabric_conformance_'.bin2hex(random_bytes(6));$worker='worker-'.bin2hex(random_bytes(6));$lease=null;$replacement=null;
			try{
				$lease=$adapter->acquireSubscriberLease($subscriber,$worker,30);
				$context->instanceOf(PanelCommandFabricSubscriberLease::class,$lease,'lease_missing');
				if(!$lease instanceof PanelCommandFabricSubscriberLease){return;}
				$context->same(null,$adapter->acquireSubscriberLease($subscriber,$worker.'-other',30),'duplicate_lease','A live subscriber lease allowed a second owner.')
					->same($lease->fence(),$adapter->inspectSubscriberLease($lease)->fence(),'inspect_fence');
				$adapter->advanceSubscriberCursor($lease,0);
				$renewed=$adapter->renewSubscriberLease($lease,30);$lease=$renewed;
				$active=$adapter->activeSubscriberLeaseManifests();$encoded=json_encode($active,JSON_THROW_ON_ERROR);
				$context->check($active!==[],'active_lease_missing','A live subscriber lease was absent from the active manifest.')
					->check(!str_contains($encoded,$lease->token()),'active_token_leak','Active subscriber manifests exposed a bearer token.')
					->check(!str_contains($encoded,'token_hash'),'active_token_hash_leak','Active subscriber manifests exposed a bearer-token digest.');
				$forged=PanelCommandFabricSubscriberLease::make($subscriber,$worker,str_repeat('f',64),$lease->fence(),$lease->acquiredAt(),$lease->expiresAt(),$lease->renewedAt());
				$context->throws(static fn()=>$adapter->inspectSubscriberLease($forged),PanelCommandFabricLeaseLost::class,'forged_lease_accepted');
				$firstFence=$lease->fence();$adapter->releaseSubscriberLease($lease);$lease=null;
				$replacement=$adapter->acquireSubscriberLease($subscriber,$worker.'-replacement',30);
				$context->instanceOf(PanelCommandFabricSubscriberLease::class,$replacement,'replacement_missing');
				if(!$replacement instanceof PanelCommandFabricSubscriberLease){return;}
				$context->same($firstFence+1,$replacement->fence(),'fence_not_monotonic');
				$context->throws(static fn()=>$adapter->advanceSubscriberCursor($renewed,0),PanelCommandFabricLeaseLost::class,'stale_cursor_accepted');
				$adapter->advanceSubscriberCursor($replacement,0);
				if($adapter instanceof \JsonSerializable){$manifest=json_encode($adapter,JSON_THROW_ON_ERROR);$context->check(!str_contains($manifest,$replacement->token()),'manifest_token_leak','The leased store manifest exposed a bearer token.');}
				$context->evidenceValue('subscriber_hash',hash('sha256',$subscriber))->evidenceValue('final_fence',$replacement->fence());
			}finally{
				foreach([$replacement,$lease]as$owned){if($owned instanceof PanelCommandFabricSubscriberLease){try{$adapter->releaseSubscriberLease($owned);}catch(\Throwable){}}}
			}
		},'Exclusive acquisition, renewal, bearer secrecy, monotonic fencing, stale-owner rejection, and cleanup',destructive:true,maxMillis:15000));
	}

	public static function migrationStore():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('migration_store',PanelMigrationStore::class,1,['transactions'=>'fenced','delivery'=>'resumable']);
		return$suite->add(PanelAdapterConformanceCase::make('transactional_lifecycle',static function(PanelMigrationStore $adapter,PanelAdapterConformanceContext $context):void{
			$scope=Resource::normalizeName((string)$context->option('scope','migration_conformance_'.bin2hex(random_bytes(5))))?:'migration_conformance';$state=$adapter->state($scope,null);$base=$state->version();$core=preg_split('/[-+]/',$base->semantic(),2)[0]??'0.0.0';$parts=explode('.',$core);$target=PanelMigrationVersion::make(((int)($parts[0]??0)+1).'.0.0',$base->schema()+1);$id=$scope.'.upgrade';
			$definition=PanelMigrationDefinition::make($id,$scope,$base,$target,static function(PanelMigrationContext $migration):PanelMigrationBatch{$data=$migration->data();$data['conformance_applied']=true;return PanelMigrationBatch::complete($data,1);},['down'=>static function(PanelMigrationContext $migration):PanelMigrationBatch{$data=$migration->data();unset($data['conformance_applied']);return PanelMigrationBatch::complete($data,1);}]);$registry=new PanelMigrationRegistry([$definition]);$plan=(new PanelMigrationPlanner($registry))->plan($state,$target);$lease=null;
			try{$lease=$adapter->acquire($scope,null,'conformance-worker',30);$context->instanceOf(PanelMigrationLease::class,$lease,'lease_missing');if(!$lease instanceof PanelMigrationLease){return;}$begun=$adapter->begin($lease,$plan,'conformance');$runId=$begun->runId();$context->check(is_string($runId)&&$runId!=='','run_id_missing','Migration begin must return a run id.');if(!is_string($runId)||$runId===''){return;}$running=$adapter->applyBatch($lease,$runId,$plan,$definition,'conformance');$context->same(1,$running->jsonSerialize()['step_index']??null,'checkpoint_missing');$completed=$adapter->complete($lease,$runId,$plan);$context->same('completed',$completed->status(),'completion_status')->same(true,$adapter->state($scope)->data()['conformance_applied']??null,'state_not_applied')->instanceOf(PanelMigrationSnapshot::class,$adapter->snapshot($runId),'backup_missing');$adapter->beginRollback($lease,$runId,$plan);$adapter->applyCompensation($lease,$runId,$plan,$definition,'conformance');$rolled=$adapter->completeRollback($lease,$runId,$plan);$context->same('rolled_back',$rolled->status(),'rollback_status')->same($base->semantic(),$adapter->state($scope)->version()->semantic(),'rollback_version')->check(!array_key_exists('conformance_applied',$adapter->state($scope)->data()),'rollback_data','Compensation must remove conformance state.')->check(($adapter->manifest()['capabilities']['fencing']??false)===true,'fencing_capability','Migration stores must advertise fencing.')->evidenceValue('run_id',$runId);
			}finally{if($lease instanceof PanelMigrationLease){try{$adapter->release($lease);}catch(\Throwable){}}}
		},'Transactional plan, backup, checkpoint, completion, and compensation lifecycle',destructive:true,maxMillis:20000));
	}

	public static function telemetryExporter():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('telemetry_exporter',PanelTelemetryExporter::class,1,['signals'=>['trace','span','event','measurement'],'schema_version'=>1]);
		return$suite->add(PanelAdapterConformanceCase::make('signal_lifecycle',static function(PanelTelemetryExporter $adapter,PanelAdapterConformanceContext $context):void{
			$trace=PanelTelemetryContext::root(true,[], '',str_repeat('a',32),str_repeat('b',16));$signal=PanelTelemetrySignal::event('adapter.conformance',$trace,1.0,['password'=>'conformance-secret-value','contract'=>'telemetry_exporter']);$adapter->export($signal);$adapter->flush();$manifest=$adapter->manifest();$context->check(!array_is_list($manifest),'manifest_shape','Exporter manifest must be an object-like map.')->check((int)($manifest['schema_version']??0)>=1,'manifest_version','Exporter manifest must declare a positive schema version.');$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);$context->check(!str_contains($encoded,'conformance-secret-value'),'manifest_secret','Exporter manifest retained raw signal secrets.')->check(!str_contains($encoded,str_repeat('a',32)),'manifest_trace_payload','Exporter manifest must report health/capabilities, not retained trace payloads.')->evidenceValue('manifest_type',$manifest['type']??$adapter::class);
		},'Sanitized signal export, flush, and secret-free manifest',maxMillis:5000));
	}

	public static function iamStore():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('iam_store',PanelIamStore::class,1,['scope'=>'tenant_required','authorization'=>'fail_closed','schema_version'=>1]);
		$suite=$suite->add(PanelAdapterConformanceCase::make('tenant_atomicity',static function(PanelIamStore $adapter,PanelAdapterConformanceContext $context):void{
			$tenant='iam_scope_'.bin2hex(random_bytes(5));$other=$tenant.'_other';$before=$adapter->read($tenant);$otherBefore=$adapter->read($other);
			try{$adapter->transaction($tenant,static function(array &$state):void{$state['principals']['transient']=['invalid'=>'rollback'];throw new \RuntimeException('rollback probe');},'iam.conformance.rollback');}catch(\RuntimeException){}
			$context->same($before,$adapter->read($tenant),'rollback_changed','A failed IAM transaction changed tenant state.')->same($otherBefore,$adapter->read($other),'cross_tenant_read','Tenant reads crossed their scope.');
			$result=$adapter->transaction($tenant,static fn(array &$state):string=>'committed','iam.conformance.noop');$context->same('committed',$result,'transaction_result')->same($otherBefore,$adapter->read($other),'cross_tenant_write','A tenant transaction changed another tenant.');
			$manifest=$adapter->manifest();$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);$context->check(($manifest['capabilities']['tenant_scoped']??false)===true,'tenant_scope_capability','IAM stores must advertise tenant-scoped access.')->check(!str_contains($encoded,$tenant),'manifest_tenant_leak','IAM store manifests must not serialize tenant identifiers.')->evidenceValue('adapter',$manifest['adapter']??$adapter::class);
		},'Tenant isolation, rollback, and secret-free manifest'));
		return$suite->add(PanelAdapterConformanceCase::make('control_plane_lifecycle',static function(PanelIamStore $adapter,PanelAdapterConformanceContext $context):void{
			$tenant='iam_lifecycle_'.bin2hex(random_bytes(5));$subject='principal_'.bin2hex(random_bytes(4));$manager=new PanelIamManager($adapter,str_repeat('i',32),static fn():bool=>true,['clock'=>static fn():string=>'2026-07-14T12:00:00Z','high_risk_permissions'=>['root.*']]);
			$create=PanelIamMutation::make('principal.create',$tenant,'principal',$subject,'operator','Conformance principal.','create-'.$subject);$principal=PanelIamPrincipal::make($subject,'Conformance principal',['now'=>'2026-07-14T12:00:00Z']);$first=$manager->createPrincipal($create,$principal);$replay=$manager->createPrincipal($create,$principal);
			$context->same(1,$first->revision(),'create_revision')->check($replay->replayed(),'idempotency_replay','An exact IAM retry must replay its receipt.')->same(null,$manager->principal($tenant.'_other',$subject),'cross_tenant_subject','A subject was visible across tenants.');
			$grant=PanelIamMutation::make('membership.grant',$tenant,'principal',$subject,'operator','Grant viewer.','grant-'.$subject,0);$membership=$manager->grant($grant,['viewer'],['orders.read']);$context->same(1,$membership->revision(),'grant_revision')->same(['orders.read'],$manager->membership($tenant,'principal',$subject)?->permissions(),'grant_permissions')->check($manager->verifyAudit($tenant),'audit_chain','IAM audit chain verification failed.');
			$stale=PanelIamMutation::make('membership.suspend',$tenant,'principal',$subject,'operator','Suspend stale.','suspend-'.$subject,0);$context->throws(static fn()=>$manager->suspend($stale),PanelIamConflict::class,'stale_revision_accepted');$encoded=json_encode($manager->manifest(),JSON_THROW_ON_ERROR);$context->check(!str_contains($encoded,str_repeat('i',32)),'audit_key_leak','IAM manifests must not serialize integrity keys.')->evidenceValue('audit_events',count($manager->audit($tenant)));
		},'Optimistic, idempotent, tenant-scoped IAM lifecycle',destructive:true,maxMillis:10000));
	}

	public static function studioStore():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('studio_store',PanelStudioStore::class,1,['scope'=>'tenant_required','schema'=>'portable_blueprint','authorization'=>'manager_boundary']);
		return$suite->add(PanelAdapterConformanceCase::make('portable_blueprint_lifecycle',static function(PanelStudioStore $adapter,PanelAdapterConformanceContext $context):void{
			$tenant='studio_scope_'.bin2hex(random_bytes(5));$documentId='document_'.bin2hex(random_bytes(4));$document=PanelStudioDocument::make($tenant,$documentId,'Conformance document');$first=PanelStudioDefinition::from(['kind'=>'page','key'=>'root','properties'=>['label'=>'Conformance'],'children'=>[['kind'=>'form','key'=>'form','properties'=>[],'children'=>[['kind'=>'form_section','key'=>'main','properties'=>[],'children'=>[['kind'=>'field','key'=>'name','properties'=>['label'=>'Name','type'=>'text'],'children'=>[]]]]]]]]);$second=PanelStudioDefinition::from(['kind'=>'page','key'=>'root','properties'=>['label'=>'Updated'],'children'=>[['kind'=>'form','key'=>'form','properties'=>[],'children'=>[['kind'=>'form_section','key'=>'main','properties'=>[],'children'=>[['kind'=>'field','key'=>'name','properties'=>['label'=>'Name','type'=>'text'],'children'=>[]]]]]]]]);$materializer=new PanelStudioMaterializer();$registry=PanelStudioSchemaRegistry::defaults();$firstArtifact=$materializer->materialize($first,$registry)->artifact();$secondArtifact=$materializer->materialize($second,$registry)->artifact();
			$save=$adapter->save($document,$first,0,'save-'.$documentId,'author','2026-07-14T12:00:00+00:00',$firstArtifact);$replay=$adapter->save($document,$first,0,'save-'.$documentId,'author','2026-07-14T12:01:00+00:00',$firstArtifact);$context->same(1,$save->revision(),'save_revision')->check($replay->replayed(),'idempotency_replay','Studio save retries must replay their receipt.')->same($firstArtifact->fingerprint(),$save->artifactFingerprint(),'receipt_artifact')->same(1,$adapter->head($tenant,$documentId)?->number(),'replay_mutated')->same(null,$adapter->head($tenant.'_other',$documentId),'tenant_isolation');
			$context->throws(static fn()=>$adapter->save($document,$second,0,'stale-'.$documentId,'author','2026-07-14T12:02:00+00:00',$secondArtifact),\RuntimeException::class,'stale_write_accepted');$approve=$adapter->approve($tenant,$documentId,1,'approve-'.$documentId,'reviewer','2026-07-14T12:03:00+00:00');$published=$adapter->publish($tenant,$documentId,$approve->revision(),'publish-'.$documentId,'author',1,'2026-07-14T12:04:00+00:00');$context->same('published',$adapter->published($tenant,$documentId)?->state(),'publish_state')->same($firstArtifact->fingerprint(),$adapter->published($tenant,$documentId)?->artifactFingerprint(),'publish_artifact')->check($adapter->verify($tenant,$documentId),'history_integrity','Studio revision chain verification failed.');
			$draft=$adapter->save($document,$second,$published->revision(),'update-'.$documentId,'author','2026-07-14T12:05:00+00:00',$secondArtifact);$rollback=$adapter->rollback($tenant,$documentId,$published->revision(),$draft->revision(),'rollback-'.$documentId,'operator','2026-07-14T12:06:00+00:00');$context->same($first->hash(),$adapter->published($tenant,$documentId)?->contentHash(),'rollback_content')->same($firstArtifact->fingerprint(),$adapter->published($tenant,$documentId)?->artifactFingerprint(),'rollback_artifact')->same('rollback',$adapter->head($tenant,$documentId)?->action(),'rollback_action')->check($adapter->cursor()>0,'cursor_missing','Studio stores must expose a change cursor.');
			$feed=$adapter->changesSince(0,100);$context->check(is_array($feed['changes']??null),'cursor_feed_shape','Studio cursor feeds must contain a changes list.');$manifest=$adapter->manifest();$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);$context->check(($manifest['capabilities']['optimistic_revisions']??false)===true,'optimistic_capability','Studio stores must advertise optimistic revisions.')->check(($manifest['capabilities']['reset_snapshot']??null)==='studio_state_envelope_v1','reset_contract','Studio stores must advertise the portable reset envelope.')->check(!str_contains($encoded,'save-'.$documentId),'idempotency_leak','Studio store manifests must not serialize idempotency keys.')->evidenceValue('final_revision',$rollback->revision());
		},'Tenant-scoped optimistic, idempotent, approved publication and rollback lifecycle',destructive:true,maxMillis:15000));
	}

	public static function agentWorkflowStore():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('agent_workflow_store',PanelAgentWorkflowStore::class,1,[
			'ownership'=>'renewable_fenced',
			'idempotency'=>'scope_bound',
			'audit'=>'hash_chain',
		]);

		return $suite->add(PanelAdapterConformanceCase::make('fenced_idempotent_lifecycle',static function(PanelAgentWorkflowStore $adapter,PanelAdapterConformanceContext $context):void{
			$seed=bin2hex(random_bytes(8));
			$actor=new PanelAgentRequestContext('conformance','tenant_'.$seed,'operator_'.$seed,'session_'.$seed,'request_'.$seed);
			$planHash=hash('sha256','panel-agent-conformance-plan-'.$seed);
			$requestHash=hash('sha256','panel-agent-conformance-request-'.$seed);
			$idempotencyKey='panel-agent-conformance-'.$seed;
			$nonce=substr(hash('sha256','panel-agent-conformance-nonce-'.$seed),0,32);
			$startedAt=time();
			$baseRevision=$adapter->revision();
			$baseAudit=count($adapter->audit());

			$planned=PanelAgentAuditReceipt::create(
				$baseAudit+1,
				'plan_validated',
				$actor,
				$planHash,
				'planned',
				['conformance'=>true,'api_token'=>'must-not-survive'],
				$adapter->lastAuditHash(),
				$startedAt,
			);
			$plannedRevision=$adapter->append($planned,$baseRevision);
			$context->same($baseRevision+1,$plannedRevision,'append_revision')
				->same($planned->hash(),$adapter->lastAuditHash(),'audit_head')
				->same($baseAudit+1,count($adapter->audit()),'audit_append');

			$stale=PanelAgentAuditReceipt::create($baseAudit+2,'plan_approved',$actor,$planHash,'approved',[],$planned->hash(),$startedAt+1);
			try{
				$adapter->append($stale,$baseRevision);
				$context->check(false,'stale_append_accepted','A stale optimistic audit append was accepted.');
			}catch(PanelAgentException $exception){
				$context->same('revision_conflict',$exception->errorCode(),'stale_append_code');
			}

			$reservation=$adapter->reserve($planHash,$actor->scopeFingerprint(),$idempotencyKey,$requestHash,[$nonce],$plannedRevision);
			$context->check($reservation->acquiredNew(),'reservation_missing','The store did not acquire a new execution reservation.')
				->same($baseRevision+2,$reservation->revision(),'reservation_revision')
				->check(is_string($reservation->id())&&$reservation->id()!=='','reservation_id','The reservation id is missing.');

			try{
				$adapter->lookup($planHash,$actor->scopeFingerprint(),$idempotencyKey,$requestHash);
				$context->check(false,'in_progress_lookup_visible','An in-progress reservation was returned as a completed result.');
			}catch(PanelAgentException $exception){
				$context->same('execution_in_progress',$exception->errorCode(),'in_progress_code');
			}

			$renewed=$adapter->renew((string)$reservation->id(),$reservation->revision(),30);
			$context->same($reservation->id(),$renewed->id(),'renewal_owner')
				->same($baseRevision+3,$renewed->revision(),'renewal_revision')
				->check($renewed->expiresAt()>=$reservation->expiresAt(),'renewal_expiry','Lease renewal shortened the ownership window.');

			$pending=PanelAgentExecutionResult::make(true,'executed',$planHash,[['ordinal'=>1,'ok'=>true]],$renewed->revision());
			try{
				$adapter->complete((string)$renewed->id(),$pending,$actor,'execution_completed','executed',[], $startedAt+2,$reservation->revision());
				$context->check(false,'stale_owner_completed','A stale lease owner completed the workflow.');
			}catch(PanelAgentException $exception){
				$context->same('revision_conflict',$exception->errorCode(),'stale_owner_code');
			}

			$completed=$adapter->complete(
				(string)$renewed->id(),
				$pending,
				$actor,
				'execution_completed',
				'executed',
				['authorization'=>'Bearer must-not-survive'],
				$startedAt+3,
				$renewed->revision(),
			);
			$context->same($baseRevision+4,$completed->storeRevision(),'completion_revision')
				->check($completed->receipt() instanceof PanelAgentAuditReceipt,'completion_receipt','Completion did not append an audit receipt.')
				->same('[REDACTED]',$completed->receipt()?->details()['authorization']??null,'completion_redaction');

			$lookup=$adapter->lookup($planHash,$actor->scopeFingerprint(),$idempotencyKey,$requestHash);
			$context->instanceOf(PanelAgentExecutionResult::class,$lookup,'result_lookup')
				->same($completed->receipt()?->hash(),$lookup?->receipt()?->hash(),'result_receipt');
			$replay=$adapter->reserve($planHash,$actor->scopeFingerprint(),$idempotencyKey,$requestHash,[$nonce],$baseRevision);
			$context->check(!$replay->acquiredNew(),'idempotency_reexecuted','An exact completed retry acquired a new execution lease.')
				->same($adapter->revision(),$replay->revision(),'replay_revision');

			try{
				$adapter->lookup($planHash,$actor->scopeFingerprint(),$idempotencyKey,hash('sha256','different-'.$seed));
				$context->check(false,'idempotency_conflict_accepted','An idempotency key was reused for another request.');
			}catch(PanelAgentException $exception){
				$context->same('idempotency_conflict',$exception->errorCode(),'idempotency_conflict_code');
			}

			$cancelledPlan=hash('sha256','panel-agent-conformance-cancel-'.$seed);
			$cancelReceipt=PanelAgentAuditReceipt::create(count($adapter->audit())+1,'plan_cancelled',$actor,$cancelledPlan,'cancelled',[],$adapter->lastAuditHash(),$startedAt+4);
			$cancelRevision=$adapter->cancel($cancelledPlan,$cancelReceipt,$adapter->revision());
			$context->same($baseRevision+5,$cancelRevision,'cancel_revision')
				->truthy($adapter->cancelled($cancelledPlan),'cancel_tombstone');
			try{
				$adapter->reserve($cancelledPlan,$actor->scopeFingerprint(),'cancel-'.$seed,hash('sha256','cancel-request-'.$seed),[substr(hash('sha256','cancel-nonce-'.$seed),0,32)],$cancelRevision);
				$context->check(false,'cancelled_plan_reserved','A cancelled plan acquired an execution lease.');
			}catch(PanelAgentException $exception){
				$context->same('plan_cancelled',$exception->errorCode(),'cancelled_plan_code');
			}

			if($adapter instanceof \JsonSerializable){
				$manifest=json_encode($adapter,JSON_THROW_ON_ERROR);
				$context->check(!str_contains($manifest,$idempotencyKey),'manifest_idempotency_leak','The store manifest exposed an idempotency key.')
					->check(!str_contains($manifest,$nonce),'manifest_nonce_leak','The store manifest exposed a signed-intent nonce.')
					->check(!str_contains($manifest,'must-not-survive'),'manifest_secret_leak','The store manifest exposed conformance secrets.');
			}

			$context->evidenceValue('base_revision',$baseRevision)
				->evidenceValue('final_revision',$adapter->revision())
				->evidenceValue('audit_receipts',count($adapter->audit()));
		},'Optimistic append, fenced renewal, scope-bound replay, cancellation, and audit integrity',destructive:true,maxMillis:15000));
	}

	/** Durable inbox state, channel receipts, and secret-free health contract. */
	public static function notificationAdapter():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('notification_adapter',PanelNotificationAdapter::class,1,[
			'persistence'=>'durable_state_machine',
			'delivery'=>'receipt_required',
		]);
		return $suite->add(PanelAdapterConformanceCase::make('inbox_and_delivery_lifecycle',static function(PanelNotificationAdapter $adapter,PanelAdapterConformanceContext $context):void {
			$seed=bin2hex(random_bytes(6));$id='notification_conformance_'.$seed;$recipient='recipient_'.$seed;
			$before=$adapter->counts(true,$recipient);
			try{
				$item=$adapter->store([
					'id'=>$id,
					'type'=>'info',
					'title'=>'Adapter conformance',
					'message'=>'Notification adapter lifecycle probe.',
					'recipient'=>$recipient,
					'channels'=>['database'],
					'created_at'=>'2026-07-20T12:00:00Z',
				]);
				$context->instanceOf(PanelInboxNotification::class,$item,'stored_type')
					->same($id,$item->id(),'stored_id')
					->same($id,$adapter->get($id)?->id(),'lookup')
					->same(1,count($adapter->forRecipient($recipient)),'recipient_scope')
					->same((int)($before['total']??0)+1,(int)($adapter->counts(true,$recipient)['total']??0),'total_count')
					->same((int)($before['unread']??0)+1,(int)($adapter->counts(true,$recipient)['unread']??0),'unread_count')
					->truthy($adapter->markRead($id,'2026-07-20T12:01:00Z'),'mark_read')
					->check($adapter->get($id)?->isRead()===true,'read_state','markRead did not persist.')
					->truthy($adapter->markUnread($id),'mark_unread')
					->check($adapter->get($id)?->isUnread()===true,'unread_state','markUnread did not persist.')
					->truthy($adapter->dismiss($id,'2026-07-20T12:02:00Z'),'dismiss')
					->same(null,array_values(array_filter($adapter->all(),static fn(PanelInboxNotification $candidate):bool=>$candidate->id()===$id))[0]??null,'dismiss_visibility')
					->truthy($adapter->restore($id),'restore');
				$receipts=$adapter->deliver($item,$context->option('delivery_channel','database'));
				$context->check(array_is_list($receipts)&&count($receipts)===1,'delivery_receipts','Delivery must return one receipt for one selected channel.')
					->same($id,$receipts[0]['notification_id']??null,'delivery_notification')
					->check(is_string($receipts[0]['status']??null)&&trim($receipts[0]['status'])!=='','delivery_status','Delivery receipts require a status.');
				$manifest=$adapter->manifest();$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);
				$context->check(!array_is_list($manifest),'manifest_shape','Notification manifests must be object-like maps.')
					->check(is_array($manifest['capabilities']??null),'manifest_capabilities','Notification manifests must declare capabilities.');
				$forbidden=$context->option('forbidden_fragments',[]);if(is_string($forbidden)){$forbidden=[$forbidden];}
				if(is_array($forbidden)){foreach($forbidden as$fragment){if(is_string($fragment)&&$fragment!==''){$context->check(!str_contains($encoded,$fragment),'manifest_secret','Notification manifest exposed a forbidden fragment.');}}}
				$context->evidenceValue('notification_id',$id)->evidenceValue('delivery_status',$receipts[0]['status']??null);
			}
			finally{
				if(method_exists($adapter,'delete')){try{$adapter->delete($id);}catch(\Throwable){}}
			}
		},'Inbox persistence, reversible state, delivery receipts, and secret-free manifest',destructive:true,maxMillis:10000));
	}

	/** Bounded typed global-search provider contract. */
	public static function searchProvider():PanelAdapterConformanceSuite {
		$suite=PanelAdapterConformanceSuite::make('search_provider',PanelSearchProvider::class,1,[
			'result'=>'typed_bounded_page',
			'failure'=>'partial_diagnostics',
		]);
		return $suite->add(PanelAdapterConformanceCase::make('bounded_search_page',static function(PanelSearchProvider $adapter,PanelAdapterConformanceContext $context):void {
			$manifest=$adapter->toArray();$context->check($adapter->name()!=='','provider_name','Search providers require a stable name.')
				->same($adapter->name(),$manifest['name']??null,'manifest_name')
				->truthy(($manifest['page_results']??false)===true,'page_capability')
				->truthy(($manifest['iterable_results']??false)===true,'iterable_capability');
			$request=$context->option('request');if(!$request instanceof PanelRequest){$request=PanelRequest::fromArray([]);}
			$query=trim((string)$context->option('query','conformance')) ?: 'conformance';
			$limit=max(1,min(10,(int)$context->option('limit',3)));
			$page=$adapter->searchPage($query,$request,null,$limit);
			$context->instanceOf(PanelSearchPage::class,$page,'page_type')
				->check(count($page)<=$limit,'page_limit','Search providers exceeded the requested result limit.',$limit,count($page));
			foreach($page->results() as$result){$context->instanceOf(PanelSearchResult::class,$result,'result_type');}
			$minimum=max(0,(int)$context->option('minimum_results',0));
			$context->check(count($page)>=$minimum,'minimum_results','Search provider returned fewer results than required.',$minimum,count($page));
			$encoded=json_encode([$manifest,$page],JSON_THROW_ON_ERROR);
			$forbidden=$context->option('forbidden_fragments',[]);if(is_string($forbidden)){$forbidden=[$forbidden];}
			if(is_array($forbidden)){foreach($forbidden as$fragment){if(is_string($fragment)&&$fragment!==''){$context->check(!str_contains($encoded,$fragment),'search_manifest_secret','Search output exposed a forbidden fragment.');}}}
			$context->evidenceValue('provider',$adapter->name())->evidenceValue('result_count',count($page));
		},'Stable identity, bounded typed page, and serializable diagnostics',maxMillis:5000));
	}
}
