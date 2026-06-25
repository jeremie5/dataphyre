<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Renders and executes Panel bulk mutation result flows.
 *
 * The trait is the controller-facing surface for selected-record duplicate,
 * restore, update, delete, and force-delete operations. Each handler validates
 * capability flags, requires POST confirmation, resolves the selected records,
 * checks per-record authorization before mutation, records PanelTrace events,
 * flashes operator notifications, and redirects back to the originating view.
 */
trait PanelRendererBulkOperations {
	/**
	 * Duplicates the records selected by a bulk action request.
	 *
	 * Records are processed independently so one denied or failed duplicate does
	 * not prevent other selected records from succeeding. The redirect payload
	 * preserves per-record results for diagnostics and tests.
	 *
	 * @param Resource $resource Resource that supplies duplicate hooks and authorization.
	 * @param PanelRequest $request POST request containing selected record keys.
	 * @return PanelPageResult Redirect with duplicate outcomes or an empty/forbidden status page.
	 */
	public static function bulkDuplicateResult(Resource $resource, PanelRequest $request): PanelPageResult {
		PanelTrace::record('bulk_duplicate.start', [
			'resource'=>$resource,
			'request'=>$request,
		]);
		if($resource->canDuplicate()===false){
			return self::panelEmptyPage('bulk.duplicate_unavailable', 'action.duplicate_unavailable_body', [
				'kind'=>'bulk_duplicate_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('bulk.duplicate_requires_confirmation', 'bulk.duplicate_requires_confirmation_body', [
				'kind'=>'bulk_duplicate_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		$records=self::selectedRecords($resource, $request);
		if($records===[]){
			return self::panelEmptyPage('action.empty_selection', 'bulk.duplicate_no_records_body', [
				'kind'=>'bulk_duplicate_empty_selection',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		$duplicated=[];
		$failed=[];
		$denied=[];
		$results=[];
		foreach($records as $record){
			$key=$resource->recordKey($record);
			if($resource->can('duplicate', $record, $request->user())===false){
				$denied[]=$key;
				continue;
			}
			try{
				$result=$resource->duplicateRecord($record, $request);
				$results[$key]=$result;
				if(self::duplicateOutcomeSucceeded($result)){
					$duplicated[]=$key;
				}
				else {
					$failed[]=$key;
				}
			}
			catch(\Throwable $exception){
				$failed[]=$key;
				PanelTrace::record('bulk_duplicate.error', [
					'resource'=>$resource,
					'record_key'=>$key,
					'message'=>$exception->getMessage(),
				]);
			}
		}
		$notifications=[];
		if($duplicated!==[]){
			$notifications[]=PanelNotification::success(self::panelText('bulk.duplicated', ['count'=>count($duplicated), 'record'=>self::panelText(count($duplicated)===1 ? 'common.record' : 'common.records')]));
		}
		if($failed!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('bulk.duplicate_failed', ['count'=>count($failed), 'record'=>self::panelText(count($failed)===1 ? 'common.record' : 'common.records')]));
		}
		if($denied!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('bulk.duplicate_denied', ['count'=>count($denied), 'record'=>self::panelText(count($denied)===1 ? 'common.record' : 'common.records')]));
		}
		if($notifications===[]){
			$notifications[]=PanelNotification::info(self::panelText('bulk.duplicate_none'));
		}
		$redirect=self::actionReturnUrl($resource, $request);
		PanelTrace::record('bulk_duplicate.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'duplicated_count'=>count($duplicated),
			'failed_count'=>count($failed),
			'denied_count'=>count($denied),
			'redirect'=>$redirect,
		]);
		self::flashNotifications($notifications);
		return PanelPageResult::redirect($redirect, [
			'kind'=>'bulk_duplicate',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'duplicated'=>$duplicated,
			'failed'=>$failed,
			'denied'=>$denied,
			'results'=>$results,
		], $notifications);
	}

	/**
	 * Restores the records selected by a bulk action request.
	 *
	 * The resource restore capability and per-record `restore` authorization are
	 * enforced before the resource restore hook is called. Failures are isolated
	 * per record and surfaced as grouped notification counts.
	 *
	 * @param Resource $resource Resource that supplies restore hooks and authorization.
	 * @param PanelRequest $request POST request containing selected record keys.
	 * @return PanelPageResult Redirect with restore outcomes or an empty/forbidden status page.
	 */
	public static function bulkRestoreResult(Resource $resource, PanelRequest $request): PanelPageResult {
		PanelTrace::record('bulk_restore.start', [
			'resource'=>$resource,
			'request'=>$request,
		]);
		if($resource->canRestore()===false){
			return self::panelEmptyPage('bulk.restore_unavailable', 'action.restore_unavailable_body', [
				'kind'=>'bulk_restore_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('bulk.restore_requires_confirmation', 'bulk.restore_requires_confirmation_body', [
				'kind'=>'bulk_restore_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		$records=self::selectedRecords($resource, $request);
		if($records===[]){
			return self::panelEmptyPage('action.empty_selection', 'bulk.restore_no_records_body', [
				'kind'=>'bulk_restore_empty_selection',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		$restored=[];
		$failed=[];
		$denied=[];
		foreach($records as $record){
			$key=$resource->recordKey($record);
			if($resource->can('restore', $record, $request->user())===false){
				$denied[]=$key;
				continue;
			}
			try{
				$result=$resource->restoreRecord($record, $request);
				if(self::restoreOutcomeSucceeded($result)){
					$restored[]=$key;
				}
				else {
					$failed[]=$key;
				}
			}
			catch(\Throwable $exception){
				$failed[]=$key;
				PanelTrace::record('bulk_restore.error', [
					'resource'=>$resource,
					'record_key'=>$key,
					'message'=>$exception->getMessage(),
				]);
			}
		}
		$notifications=[];
		if($restored!==[]){
			$notifications[]=PanelNotification::success(self::panelText('bulk.restored', ['count'=>count($restored), 'record'=>self::panelText(count($restored)===1 ? 'common.record' : 'common.records')]));
		}
		if($failed!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('bulk.restore_failed', ['count'=>count($failed), 'record'=>self::panelText(count($failed)===1 ? 'common.record' : 'common.records')]));
		}
		if($denied!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('bulk.restore_denied', ['count'=>count($denied), 'record'=>self::panelText(count($denied)===1 ? 'common.record' : 'common.records')]));
		}
		if($notifications===[]){
			$notifications[]=PanelNotification::info(self::panelText('bulk.restore_none'));
		}
		$redirect=self::actionReturnUrl($resource, $request);
		PanelTrace::record('bulk_restore.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'restored_count'=>count($restored),
			'failed_count'=>count($failed),
			'denied_count'=>count($denied),
			'redirect'=>$redirect,
		]);
		self::flashNotifications($notifications);
		return PanelPageResult::redirect($redirect, [
			'kind'=>'bulk_restore',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'restored'=>$restored,
			'failed'=>$failed,
			'denied'=>$denied,
		], $notifications);
	}

	/**
	 * Validates and applies a bulk update form to selected records.
	 *
	 * Bulk update is a two-step flow: the first POST renders the configured bulk
	 * form for the selected records, and the confirmed submit validates form state
	 * before calling the resource bulk update hook.
	 *
	 * @param Resource $resource Resource that defines the bulk form and update hook.
	 * @param PanelRequest $request POST request with selected keys and optional submitted form values.
	 * @return PanelPageResult Bulk form, validation form, redirect with outcome, or status page.
	 */
	public static function bulkUpdateResult(Resource $resource, PanelRequest $request): PanelPageResult {
		PanelTrace::record('bulk_update.start', [
			'resource'=>$resource,
			'request'=>$request,
		]);
		if($resource->canBulkUpdate()===false){
			return self::panelEmptyPage('bulk.update_unavailable', 'bulk.update_unavailable_body', [
				'kind'=>'bulk_update_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('bulk.update_requires_selection', 'bulk.update_requires_selection_body', [
				'kind'=>'bulk_update_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		$records=self::selectedRecords($resource, $request);
		if($records===[]){
			return self::panelEmptyPage('action.empty_selection', 'bulk.update_no_records_body', [
				'kind'=>'bulk_update_empty_selection',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		if((string)$request->input('__panel_bulk_update_submit', '')!=='1'){
			return self::bulkUpdateForm($resource, $request, $records);
		}
		$state=$resource->bulkForm()->submit($request, $records, 'bulk_update');
		if($state->invalid()){
			return self::bulkUpdateForm($resource, $request, $records, $state, 422);
		}
		$result=$resource->bulkUpdateRecords($state->values(), $records, $request);
		$outcome=self::outcome($result, self::panelText('bulk.updated', ['count'=>count($records), 'record'=>self::panelText(count($records)===1 ? 'common.record' : 'common.records')]));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::actionReturnUrl($resource, $request);
		}
		PanelTrace::record('bulk_update.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'selected_count'=>count($records),
			'input_keys'=>array_keys($state->values()),
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'bulk_update',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'selected_count'=>count($records),
			'input_keys'=>array_keys($state->values()),
			'form_state'=>$state->jsonSerialize(),
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Deletes the records selected by a bulk action request.
	 *
	 * Delete uses the resource's normal delete hook and records grouped deleted,
	 * failed, and denied keys so soft-delete resources and custom hooks can report
	 * partial success without losing operator feedback.
	 *
	 * @param Resource $resource Resource that supplies delete hooks and authorization.
	 * @param PanelRequest $request POST request containing selected record keys.
	 * @return PanelPageResult Redirect with delete outcomes or an empty/forbidden status page.
	 */
	public static function bulkDeleteResult(Resource $resource, PanelRequest $request): PanelPageResult {
		PanelTrace::record('bulk_delete.start', [
			'resource'=>$resource,
			'request'=>$request,
		]);
		if($resource->canDelete()===false){
			return self::panelEmptyPage('bulk.delete_unavailable', 'action.delete_unavailable_body', [
				'kind'=>'bulk_delete_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('bulk.delete_requires_confirmation', 'bulk.delete_requires_confirmation_body', [
				'kind'=>'bulk_delete_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		$records=self::selectedRecords($resource, $request);
		if($records===[]){
			return self::panelEmptyPage('action.empty_selection', 'bulk.delete_no_records_body', [
				'kind'=>'bulk_delete_empty_selection',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		$deleted=[];
		$failed=[];
		$denied=[];
		foreach($records as $record){
			$key=$resource->recordKey($record);
			if($resource->can('delete', $record, $request->user())===false){
				$denied[]=$key;
				continue;
			}
			try{
				$result=$resource->deleteRecord($record, $request);
				if(self::deleteOutcomeSucceeded($result)){
					$deleted[]=$key;
				}
				else {
					$failed[]=$key;
				}
			}
			catch(\Throwable $exception){
				$failed[]=$key;
				PanelTrace::record('bulk_delete.error', [
					'resource'=>$resource,
					'record_key'=>$key,
					'message'=>$exception->getMessage(),
				]);
			}
		}
		$notifications=[];
		if($deleted!==[]){
			$notifications[]=PanelNotification::success(self::panelText('bulk.deleted', ['count'=>count($deleted), 'record'=>self::panelText(count($deleted)===1 ? 'common.record' : 'common.records')]));
		}
		if($failed!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('bulk.delete_failed', ['count'=>count($failed), 'record'=>self::panelText(count($failed)===1 ? 'common.record' : 'common.records')]));
		}
		if($denied!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('bulk.delete_denied', ['count'=>count($denied), 'record'=>self::panelText(count($denied)===1 ? 'common.record' : 'common.records')]));
		}
		if($notifications===[]){
			$notifications[]=PanelNotification::info(self::panelText('bulk.delete_none'));
		}
		$redirect=self::actionReturnUrl($resource, $request);
		PanelTrace::record('bulk_delete.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'deleted_count'=>count($deleted),
			'failed_count'=>count($failed),
			'denied_count'=>count($denied),
			'redirect'=>$redirect,
		]);
		self::flashNotifications($notifications);
		return PanelPageResult::redirect($redirect, [
			'kind'=>'bulk_delete',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'deleted'=>$deleted,
			'failed'=>$failed,
			'denied'=>$denied,
		], $notifications);
	}

	/**
	 * Permanently deletes the records selected by a bulk action request.
	 *
	 * Force delete is intentionally separate from normal delete so resources can
	 * expose stronger capability checks and irreversible persistence behavior.
	 * Results are grouped by successful, failed, and denied record keys.
	 *
	 * @param Resource $resource Resource that supplies force-delete hooks and authorization.
	 * @param PanelRequest $request POST request containing selected record keys.
	 * @return PanelPageResult Redirect with force-delete outcomes or an empty/forbidden status page.
	 */
	public static function bulkForceDeleteResult(Resource $resource, PanelRequest $request): PanelPageResult {
		PanelTrace::record('bulk_force_delete.start', [
			'resource'=>$resource,
			'request'=>$request,
		]);
		if($resource->canForceDelete()===false){
			return self::panelEmptyPage('bulk.force_delete_unavailable', 'action.force_delete_unavailable_body', [
				'kind'=>'bulk_force_delete_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('bulk.force_delete_requires_confirmation', 'bulk.force_delete_requires_confirmation_body', [
				'kind'=>'bulk_force_delete_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		$records=self::selectedRecords($resource, $request);
		if($records===[]){
			return self::panelEmptyPage('action.empty_selection', 'bulk.force_delete_no_records_body', [
				'kind'=>'bulk_force_delete_empty_selection',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		$forceDeleted=[];
		$failed=[];
		$denied=[];
		$results=[];
		foreach($records as $record){
			$key=$resource->recordKey($record);
			if($resource->can('force_delete', $record, $request->user())===false){
				$denied[]=$key;
				continue;
			}
			try{
				$result=$resource->forceDeleteRecord($record, $request);
				$results[$key]=$result;
				if(self::forceDeleteOutcomeSucceeded($result)){
					$forceDeleted[]=$key;
				}
				else {
					$failed[]=$key;
				}
			}
			catch(\Throwable $exception){
				$failed[]=$key;
				PanelTrace::record('bulk_force_delete.error', [
					'resource'=>$resource,
					'record_key'=>$key,
					'message'=>$exception->getMessage(),
				]);
			}
		}
		$notifications=[];
		if($forceDeleted!==[]){
			$notifications[]=PanelNotification::success(self::panelText('bulk.force_deleted', ['count'=>count($forceDeleted), 'record'=>self::panelText(count($forceDeleted)===1 ? 'common.record' : 'common.records')]));
		}
		if($failed!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('bulk.force_delete_failed', ['count'=>count($failed), 'record'=>self::panelText(count($failed)===1 ? 'common.record' : 'common.records')]));
		}
		if($denied!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('bulk.force_delete_denied', ['count'=>count($denied), 'record'=>self::panelText(count($denied)===1 ? 'common.record' : 'common.records')]));
		}
		if($notifications===[]){
			$notifications[]=PanelNotification::info(self::panelText('bulk.force_delete_none'));
		}
		$redirect=self::actionReturnUrl($resource, $request);
		PanelTrace::record('bulk_force_delete.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'force_deleted_count'=>count($forceDeleted),
			'failed_count'=>count($failed),
			'denied_count'=>count($denied),
			'redirect'=>$redirect,
		]);
		self::flashNotifications($notifications);
		return PanelPageResult::redirect($redirect, [
			'kind'=>'bulk_force_delete',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'force_deleted'=>$forceDeleted,
			'failed'=>$failed,
			'denied'=>$denied,
			'results'=>$results,
		], $notifications);
	}

	/**
	 * Returns the shared forbidden response for Panel renderer actions.
	 *
	 * The response includes resource and request metadata when available so traces
	 * and tests can identify which authorization check stopped the flow without
	 * exposing sensitive record data in the operator-facing message.
	 *
	 * @param ?Resource $resource Resource whose action was denied, or null for global Panel denials.
	 * @param PanelRequest $request Request and operator context that failed authorization.
	 * @return PanelPageResult HTTP 403 empty page with diagnostic metadata.
	 */
	public static function forbidden(?Resource $resource, PanelRequest $request): PanelPageResult {
		PanelTrace::record('request.forbidden', [
			'resource'=>$resource,
			'request'=>$request,
		]);
		return self::panelEmptyPage('forbidden.title', 'forbidden.body', [
			'kind'=>'forbidden',
			'resource'=>$resource?->toArray(),
			'request'=>$request->toArray(),
		], 403);
	}
}
