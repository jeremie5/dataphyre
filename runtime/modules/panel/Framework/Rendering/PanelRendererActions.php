<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Renders and executes panel resource actions from HTTP requests.
 *
 * This trait is the action-handling portion of the panel renderer. It converts
 * relation operations, custom actions, form saves, inline edits, destructive
 * operations, notes, messages, tags, attachments, tasks, and approvals into
 * `PanelPageResult` responses. Every entry point is responsible for checking
 * feature availability, HTTP method requirements, record presence, permission
 * gates, lifecycle hooks, notifications, redirects, and trace payloads before a
 * browser-facing result is returned.
 *
 * Security boundary: request data is normalized before it becomes action input,
 * but persistence and domain validation live on the `Resource`, `Action`, or
 * `RelationManager` that receives the operation.
 */
trait PanelRendererActions {
	/**
	 * Renders a relation page or dispatches a submitted relation action.
	 *
	 * Missing relations return a 404 page and failed relation view checks return a
	 * forbidden response. POST requests with a `relation_action` value are routed
	 * to the relation action executor; all other requests render the relation
	 * table and expose relation state in the result data payload.
	 *
	 * @param Resource $resource Resource that owns the relation.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Parent resource record, or null for relation screens that can report missing state.
	 * @return PanelPageResult Relation table page, relation action redirect, not-found page, or forbidden response.
	 */
	public static function relation(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		$relationName=$request->relationName();
		$relation=$relationName!==null ? $resource->relationManager($relationName) : null;
		if(!$relation instanceof RelationManager){
			return self::panelEmptyPage('relation.not_found', 'relation.not_found_body', [
				'kind'=>'relation_missing',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($relation->can('view', $record, $request->user(), $resource)===false){
			return self::forbidden($resource, $request);
		}
		$relationAction=Resource::normalizeName((string)$request->input('relation_action', ''));
		if(strtoupper($request->method())==='POST' && $relationAction!==''){
			return self::relationActionResult($resource, $relation, $request, $record, $relationAction);
		}
		PanelTrace::record('relation.render', [
			'resource'=>$resource,
			'relation'=>$relation,
			'request'=>$request,
		]);
		$relationState=self::relationState($resource, $relation, $request, $record);
		return self::page((string)$relation->label(), self::relationTableHtml($resource, $relation, $request, $record, $relationState), [
			'kind'=>'relation',
			'resource'=>$resource->toArray(),
			'relation'=>$relation->toArray(),
			'relation_state'=>$relationState->jsonSerialize(),
			'request'=>$request->toArray(),
		]);
	}

	/**
	 * Executes a relation mutation submitted from a relation table.
	 *
	 * Supported actions are attach, detach, associate, dissociate, reorder, and
	 * update_pivot. The method checks parent record presence, relation capability,
	 * per-action permission, required row keys, and fallback redirect behavior
	 * before returning a redirect result with normalized effects and flashed
	 * notifications.
	 *
	 * @param Resource $resource Resource that owns the relation.
	 * @param RelationManager $relation Relation manager receiving the mutation.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Parent resource record.
	 * @param string $action Normalized relation action name.
	 * @return PanelPageResult Redirect, validation page, not-found page, or forbidden response.
	 */
	private static function relationActionResult(Resource $resource, RelationManager $relation, PanelRequest $request, mixed $record, string $action): PanelPageResult {
		PanelTrace::record('relation.action.start', [
			'resource'=>$resource,
			'relation'=>$relation,
			'action'=>$action,
			'request'=>$request,
			'record'=>$record,
		]);
		if($record===null){
			return self::panelEmptyPage('record.not_found', 'relation.parent_not_found_body', [
				'kind'=>'relation_action_missing_record',
				'resource'=>$resource->toArray(),
				'relation'=>$relation->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(!in_array($action, ['attach', 'detach', 'associate', 'dissociate', 'reorder', 'update_pivot'], true)){
			return self::panelEmptyPage('relation.action_not_found', 'relation.action_not_found_body', [
				'kind'=>'relation_action_missing',
				'resource'=>$resource->toArray(),
				'relation'=>$relation->toArray(),
				'action'=>$action,
				'request'=>$request->toArray(),
			], 404);
		}
		if($relation->can($action, $record, $request->user(), $resource)===false){
			return self::forbidden($resource, $request);
		}
		if($action==='attach' || $action==='associate'){
			$can=$action==='attach' ? $relation->canAttach() : $relation->canAssociate();
			if($can===false){
				return self::panelEmptyPage('relation.unavailable', 'relation.unavailable_body', [
					'kind'=>'relation_'.$action.'_unavailable',
					'resource'=>$resource->toArray(),
					'relation'=>$relation->toArray(),
					'request'=>$request->toArray(),
				], 404, ['action'=>$action]);
			}
			$relatedKey=trim((string)$request->input('related_key', ''));
			if($relatedKey===''){
				return self::panelEmptyPage('relation.choose_record', 'relation.choose_record_body', [
					'kind'=>'relation_'.$action.'_missing_key',
					'resource'=>$resource->toArray(),
					'relation'=>$relation->toArray(),
					'request'=>$request->toArray(),
				], 422);
			}
			$result=$action==='attach'
				? $relation->attachRecord($resource, $record, $relatedKey, $request)
				: $relation->associateRecord($resource, $record, $relatedKey, $request);
			$outcome=self::outcome($result, self::panelText($action==='attach' ? 'relation.attached' : 'relation.associated'));
		}
		elseif($action==='detach' || $action==='dissociate'){
			$can=$action==='detach' ? $relation->canDetach() : $relation->canDissociate();
			if($can===false){
				return self::panelEmptyPage('relation.unavailable', 'relation.unavailable_body', [
					'kind'=>'relation_'.$action.'_unavailable',
					'resource'=>$resource->toArray(),
					'relation'=>$relation->toArray(),
					'request'=>$request->toArray(),
				], 404, ['action'=>$action]);
			}
			$childKey=trim((string)$request->input('child_key', ''));
			if($childKey===''){
				return self::panelEmptyPage('relation.choose_row', 'relation.choose_row_body', [
					'kind'=>'relation_'.$action.'_missing_key',
					'resource'=>$resource->toArray(),
					'relation'=>$relation->toArray(),
					'request'=>$request->toArray(),
				], 422);
			}
			$result=$action==='detach'
				? $relation->detachRecord($resource, $record, $childKey, $request)
				: $relation->dissociateRecord($resource, $record, $childKey, $request);
			$outcome=self::outcome($result, self::panelText($action==='detach' ? 'relation.detached' : 'relation.dissociated'));
		}
		elseif($action==='reorder'){
			if($relation->canReorder()===false){
				return self::panelEmptyPage('relation.reorder_unavailable', 'relation.reorder_unavailable_body', [
					'kind'=>'relation_reorder_unavailable',
					'resource'=>$resource->toArray(),
					'relation'=>$relation->toArray(),
					'request'=>$request->toArray(),
				], 404);
			}
			$ordered=$request->input('ordered_keys', []);
			$ordered=is_array($ordered) ? $ordered : [$ordered];
			$ordered=array_values(array_filter(array_map(static fn(mixed $value): string => trim((string)$value), $ordered), static fn(string $value): bool => $value!==''));
			if($ordered===[]){
				return self::panelEmptyPage('relation.choose_order', 'relation.choose_order_body', [
					'kind'=>'relation_reorder_missing_keys',
					'resource'=>$resource->toArray(),
					'relation'=>$relation->toArray(),
					'request'=>$request->toArray(),
				], 422);
			}
			$result=$relation->reorderRecords($resource, $record, $ordered, $request);
			$outcome=self::outcome($result, self::panelText('relation.reordered'));
		}
		else {
			if($relation->canUpdatePivot()===false){
				return self::panelEmptyPage('relation.pivot_unavailable', 'relation.pivot_unavailable_body', [
					'kind'=>'relation_update_pivot_unavailable',
					'resource'=>$resource->toArray(),
					'relation'=>$relation->toArray(),
					'request'=>$request->toArray(),
				], 404);
			}
			$childKey=trim((string)$request->input('child_key', ''));
			if($childKey===''){
				return self::panelEmptyPage('relation.choose_row', 'relation.choose_row_body', [
					'kind'=>'relation_update_pivot_missing_key',
					'resource'=>$resource->toArray(),
					'relation'=>$relation->toArray(),
					'request'=>$request->toArray(),
				], 422);
			}
			$values=[];
			foreach($relation->pivotFieldDefinitions() as $field){
				if($field instanceof Field){
					$values[$field->name()]=$request->input($field->name(), null);
				}
			}
			$result=$relation->updatePivotRecord($resource, $record, $childKey, $values, $request);
			$outcome=self::outcome($result, self::panelText('relation.pivot_updated'));
		}
		if($outcome['redirect']===null){
			$outcome['redirect']=self::requestProvidedReturnUrl($request) ?? self::relationOperationUrl($resource, $relation, $request, $record, self::relationStateParams($relation, self::relationScopedRequest($relation, $request), true));
		}
		PanelTrace::record('relation.action.completed', [
			'resource'=>$resource,
			'relation'=>$relation,
			'action'=>$action,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		$effects=self::normalizeActionEffects($outcome['effects'] ?? []);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'relation_action',
			'resource'=>$resource->toArray(),
			'relation'=>$relation->toArray(),
			'action'=>$action,
			'request'=>$request->toArray(),
			'result'=>$outcome['result'],
			'effects'=>$effects,
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Executes or renders a custom resource action.
	 *
	 * The action pipeline resolves visibility, authorization, disabled state, bulk
	 * selection, modal-only content, form hydration, validation hooks,
	 * confirmation gates, form-data mutation, before/after action hooks,
	 * exception pages, normalized outcomes, effects, redirects, and notifications.
	 *
	 * @param Resource $resource Resource that declares the action.
	 * @param PanelRequest $request Current panel request.
	 * @param string $actionName Action name from the route or request.
	 * @param mixed $record Single record, selected record list for bulk actions, or null.
	 * @return PanelPageResult Form page, confirmation page, completion page, redirect, or error response.
	 */
	public static function actionResult(Resource $resource, PanelRequest $request, string $actionName, mixed $record=null): PanelPageResult {
		PanelTrace::record('action.start', [
			'resource'=>$resource,
			'action'=>$actionName,
			'request'=>$request,
		]);
		$action=$resource->actionByName($actionName);
		if(!$action instanceof Action){
			return self::panelEmptyPage('action.not_found', 'action.not_found_body', [
				'kind'=>'action_missing',
				'resource'=>$resource->toArray(),
				'action'=>$actionName,
			], 404);
		}
		if(!$action->isVisible($record, $request->user(), $resource, $request)){
			return self::panelEmptyPage('action.not_found', 'action.not_found_state_body', [
				'kind'=>'action_hidden',
				'resource'=>$resource->toArray(),
				'action'=>$action->resolvedMeta($record, $request, $resource),
			], 404);
		}
		if($action->can($record, $request->user(), $resource)===false){
			return self::forbidden($resource, $request);
		}
		if($action->isDisabled($record, $request->user(), $resource, $request)){
			$reason=$action->disabledReasonFor($record, $request->user(), $resource, $request) ?? self::panelText('action.unavailable_now');
			return self::page(self::panelText('action.unavailable'), '<p class="dp-panel-empty">'.self::e($reason).'</p><div class="dp-panel-toolbar"><a class="dp-panel-button" href="'.self::e(self::actionReturnUrl($resource, $request)).'">'.self::e(self::panelText('common.back')).'</a></div>', [
				'kind'=>'action_disabled',
				'resource'=>$resource->toArray(),
				'action'=>$action->resolvedMeta($record, $request, $resource),
				'disabled_reason'=>$reason,
			], 409);
		}
		$rawActionMeta=$action->toArray();
		if(($rawActionMeta['bulk'] ?? false)===true && $record===null){
			$record=self::selectedRecords($resource, $request);
			if($record===[] && ($rawActionMeta['allow_empty_selection'] ?? false)!==true){
				return self::panelEmptyPage('action.empty_selection', 'action.empty_selection_run_body', [
					'kind'=>'action_empty_selection',
					'resource'=>$resource->toArray(),
					'action'=>$action->resolvedMeta($record, $request, $resource),
				], 422);
			}
		}
		$actionMeta=$action->resolvedMeta($record, $request, $resource);
		$modalContent=$action->resolveModalContent($record, $request, $resource);
		if(!$action->hasFields() && ($actionMeta['has_handler'] ?? false)!==true && $modalContent!==null){
			$content=self::modalContentHtml($modalContent) ?? '<p class="dp-panel-empty">'.self::e(self::panelText('action.no_details')).'</p>';
			$returnUrl=self::actionReturnUrl($resource, $request);
			return self::page((string)($actionMeta['modal_heading'] ?? $actionMeta['label'] ?? self::panelText('action.default_label')), $content.'<div class="dp-panel-toolbar"><a class="dp-panel-button" href="'.self::e($returnUrl).'">'.self::e(self::panelText('common.back')).'</a></div>', [
				'kind'=>'action_content',
				'resource'=>$resource->toArray(),
				'action'=>$actionMeta,
				'request'=>$request->toArray(),
			]);
		}
		$actionData=$request->input();
		$state=null;
		$actionMode=($actionMeta['bulk'] ?? false)===true && is_array($record) ? 'bulk_action' : 'action';
		$actionState=$action->state($record, $request, $resource, $actionMode, null, $actionData, null, null, ['stage'=>'start']);
		PanelTrace::record('action.state', [
			'resource'=>$resource,
			'action'=>$action,
			'state'=>$actionState,
		]);
		$lifecycleContext=[
			'action'=>$actionMeta,
			'action_state'=>$actionState,
			'return_url'=>self::actionReturnUrl($resource, $request),
			'trace'=>'action.lifecycle_result',
		];
		$lifecycle=$action->runBeforeValidate($record, $request, $resource);
		if($lifecycle instanceof PanelLifecycleResult){
			$lifecycleContext['action_state']=$actionState->withLifecycle($lifecycle)->withStage('before_validate_halted');
			return self::lifecycleResult($resource, $request, $record, 'action', $lifecycle, null, [], $lifecycleContext);
		}
		if($action->hasFields()){
			if((string)$request->input('__panel_action_submit', '')!=='1'){
				return self::actionForm($resource, $action, $request, $record);
			}
			$state=$action->form()->submit($request, $record, 'action');
			$state=$action->runAfterValidate($state, $record, $request, $resource);
			if($state instanceof PanelLifecycleResult){
				$lifecycleContext['action_state']=$actionState->withLifecycle($state)->withStage('after_validate_halted');
				return self::lifecycleResult($resource, $request, $record, 'action', $state, null, [], $lifecycleContext);
			}
			if($state->invalid()){
				return self::actionForm($resource, $action, $request, $record, $state, 422);
			}
			if(self::actionRequiresConfirmation($actionMeta) && !self::actionConfirmed($request)){
				return self::actionForm($resource, $action, $request, $record, $state, 409);
			}
			$actionData=$state->values();
			$actionState=$action->state($record, $request, $resource, $actionMode, $state, $actionData, null, null, ['stage'=>'validated']);
			$lifecycleContext['action_state']=$actionState;
		}
		elseif(self::actionRequiresConfirmation($actionMeta) && !self::actionConfirmed($request)){
			return self::actionConfirmationForm($resource, $action, $request, $record);
		}
		$actionData=$action->mutateFormData($actionData, $record, $request, $resource);
		$actionState=$actionState->withData($actionData)->withStage('mutated');
		$lifecycleContext['action_state']=$actionState;
		$before=$action->runBeforeAction($actionData, $record, $request, $resource);
		if($before instanceof PanelLifecycleResult){
			$lifecycleContext['action_state']=$actionState->withLifecycle($before)->withStage('before_action_halted');
			return self::lifecycleResult($resource, $request, $record, 'action', $before, $state, $actionData, $lifecycleContext);
		}
		if($before!==null){
			$result=$before;
			$actionState=$actionState->withResult($result)->withStage('before_action_result');
		}
		else{
			try{
				$result=$action->execute($record, $actionData, $resource, false, $request);
			}
			catch(\Throwable $exception){
				return self::actionExceptionPage($resource, null, $request, $action, $exception, 'action');
			}
			$result=$action->runAfterAction($result, $actionData, $record, $request, $resource);
			if($result instanceof PanelLifecycleResult){
				$lifecycleContext['action_state']=$actionState->withLifecycle($result)->withStage('after_action_halted');
				return self::lifecycleResult($resource, $request, $record, 'action', $result, $state, $actionData, $lifecycleContext);
			}
			$actionState=$actionState->withResult($result)->withStage('completed');
		}
		$outcome=self::outcome($result, (string)($actionMeta['success_message'] ?? self::panelText('action.completed_body')));
		if($outcome['redirect']===null && is_string($actionMeta['redirect_to'] ?? null)){
			$outcome['redirect']=self::safeReturnUrl((string)$actionMeta['redirect_to']);
		}
		if($outcome['redirect']===null && strtoupper($request->method())==='POST'){
			$outcome['redirect']=self::actionReturnUrl($resource, $request);
		}
		PanelTrace::record('action.completed', [
			'resource'=>$resource,
			'action'=>$action,
			'bulk'=>($actionMeta['bulk'] ?? false)===true,
			'selected_count'=>is_array($record) && ($actionMeta['bulk'] ?? false)===true ? count($record) : null,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
			'state'=>$actionState,
		]);
		if($outcome['redirect']!==null){
			$effects=self::actionEffects($actionMeta, $outcome);
			self::flashNotifications($outcome['notifications']);
			return PanelPageResult::redirect($outcome['redirect'], [
				'kind'=>'action',
				'resource'=>$resource->toArray(),
				'action'=>$actionMeta,
				'action_state'=>$actionState->jsonSerialize(),
				'result'=>$outcome['result'],
				'effects'=>$effects,
			], $outcome['notifications'], $outcome['status']);
		}
		$effects=self::actionEffects($actionMeta, $outcome);
		$content='<p class="dp-panel-empty">'.self::e(self::panelText('action.completed_body')).'</p>';
		if($outcome['message']!==''){
			$content='<p>'.self::e($outcome['message']).'</p>';
		}
		return self::page(self::panelText('action.completed'), $content, [
			'kind'=>'action',
			'resource'=>$resource->toArray(),
			'action'=>$actionMeta,
			'action_state'=>$actionState->jsonSerialize(),
			'result'=>$outcome['result'],
			'effects'=>$effects,
		], 200, $outcome['notifications']);
	}

	/**
	 * Renders a failed action page after an action handler throws.
	 *
	 * The exception class, message, file, and line are included in the result data
	 * for diagnostics. The visible page shows a localized failure message and a
	 * back link appropriate to the page or resource context.
	 *
	 * @param ?Resource $resource Resource context when the failure came from a resource action.
	 * @param ?PanelPage $page Page context when the failure came from a page action.
	 * @param PanelRequest $request Current panel request.
	 * @param Action $action Action that failed.
	 * @param \Throwable $exception Exception raised by action execution.
	 * @param string $kind Trace and data kind prefix.
	 * @return PanelPageResult HTTP 500 failure page with an error notification.
	 */
	private static function actionExceptionPage(?Resource $resource, ?PanelPage $page, PanelRequest $request, Action $action, \Throwable $exception, string $kind='action'): PanelPageResult {
		PanelTrace::record($kind.'.failed', [
			'resource'=>$resource,
			'page'=>$page,
			'action'=>$action,
			'exception'=>get_class($exception),
			'message'=>$exception->getMessage(),
		]);
		$actionMeta=$action->resolvedMeta(null, $request, $resource);
		$label=(string)($actionMeta['label'] ?? self::panelText('action.default_label'));
		$message=$exception->getMessage()!=='' ? $exception->getMessage() : self::panelText('action.failed_body');
		$content='<section class="dp-panel-form-section">'
			.'<div class="dp-panel-section-heading"><h2>'.self::e(self::panelText('action.failed_title', ['action'=>$label])).'</h2><p>'.self::e($message).'</p></div>'
			.'</section>'
			.'<div class="dp-panel-toolbar"><a class="dp-panel-button" href="'.self::e($page instanceof PanelPage ? self::pageReturnUrl($page, $request) : ($resource instanceof Resource ? self::actionReturnUrl($resource, $request) : PanelConfig::url())).'">'.self::e(self::panelText('common.back')).'</a></div>';
		$data=[
			'kind'=>$kind.'_failed',
			'action'=>$actionMeta,
			'request'=>$request->toArray(),
			'exception'=>[
				'class'=>get_class($exception),
				'message'=>$message,
				'file'=>$exception->getFile(),
				'line'=>$exception->getLine(),
			],
		];
		if($resource instanceof Resource){
			$data['resource']=$resource->toArray();
		}
		if($page instanceof PanelPage){
			$data['page']=$page->toArray();
		}
		return self::page($label.' failed', $content, $data, 500, [
			PanelNotification::error($message, $label.' failed'),
		]);
	}

	/**
	 * Renders the form page for an action that declares fields.
	 *
	 * The form preserves selected bulk ids, return URL, confirmation marker, field
	 * visibility state, section layout, validation errors, modal metadata, and the
	 * current `PanelActionState`. Modal requests receive raw HTML; normal requests
	 * receive a full panel page.
	 *
	 * @param Resource $resource Resource that owns the action.
	 * @param Action $action Action whose form should be rendered.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Action record or bulk-selection list.
	 * @param ?PanelFormState $state Existing submitted state, or null to hydrate from the action form.
	 * @param int $status HTTP status for the rendered form.
	 * @return PanelPageResult Action form page or modal HTML response.
	 */
	private static function actionForm(Resource $resource, Action $action, PanelRequest $request, mixed $record=null, ?PanelFormState $state=null, int $status=200): PanelPageResult {
		$state ??=$action->form()->hydrate($record, $request);
		$actionMeta=$action->resolvedMeta($record, $request, $resource);
		$actionMode=($actionMeta['bulk'] ?? false)===true && is_array($record) ? 'bulk_action' : 'action';
		$actionState=$action->state($record, $request, $resource, $actionMode, $state, $state->dehydratedValues() ?: $state->values(), null, null, ['stage'=>$state->invalid() ? 'invalid' : 'form']);
		$sectionMeta=self::sectionMetaByName($actionMeta['fields']['sections'] ?? []);
		$sections=[];
		foreach($action->form()->fieldsList() as $field){
			$meta=self::fieldMeta($field, $record, $request, 'action');
			$fieldVisible=$field->isVisible('action', $record, $request);
			if($fieldVisible===false && !self::fieldDependencyControlled($meta)){
				continue;
			}
			$name=(string)$meta['name'];
			$value=$state->value($name, $request->input($name, $meta['default'] ?? ''));
			$section=trim((string)($meta['meta']['section'] ?? ''));
			$section=$section!=='' ? $section : self::panelText('record.details');
			$sections[$section] ??=[];
			$sections[$section][]=self::fieldHtml($name, $meta, $value, $state->fieldErrors($name), !$fieldVisible);
		}
		$hidden='<input type="hidden" name="__panel_action_submit" value="1">';
		$hidden.=self::actionConfirmationInput($actionMeta);
		$hidden.=self::returnInput($resource, $request);
		$selected=$request->input('selected', []);
		$selected=is_array($selected) ? $selected : [$selected];
		foreach($selected as $value){
			$value=trim((string)$value);
			if($value!==''){
				$hidden.='<input type="hidden" name="selected[]" value="'.self::e($value).'">';
			}
		}
		$summary='';
		if($state->invalid()){
			$count=array_sum(array_map('count', $state->errors()));
			$summary='<div class="dp-panel-alert">'.self::e(self::panelText('action.field_issue_summary', ['count'=>$count, 'issue'=>self::panelText($count===1 ? 'action.issue' : 'action.issues')])).'</div>';
		}
		$recordKey=$request->recordKey();
		$actionUrl=self::actionUrl($resource, (string)$actionMeta['name'], $recordKey, $request);
		$returnUrl=self::actionReturnUrl($resource, $request);
		$actionFormMeta=is_array($actionMeta['fields']['meta'] ?? null) ? $actionMeta['fields']['meta'] : [];
		if(!is_array($actionFormMeta['accessibility'] ?? null)){
			$resourceFormMeta=$resource->form()->toArray()['meta'] ?? [];
			if(is_array($resourceFormMeta['accessibility'] ?? null)){
				$actionFormMeta['accessibility']=$resourceFormMeta['accessibility'];
			}
		}
		$content='<form class="dp-panel-form" method="post"'.self::formEncodingAttr($actionMeta['fields']['fields'] ?? []).' action="'.self::e($actionUrl).'" data-dp-panel-reactive-url="'.self::e($actionUrl).'"'.self::accessibilityDefaultAttrs($actionFormMeta).'>'
			.self::csrfInput()
			.$hidden
			.$summary
			.self::formSectionsHtml($sections, (int)($actionMeta['fields']['columns'] ?? 1), $sectionMeta)
			.'<div class="dp-panel-toolbar"><a class="dp-panel-button dp-panel-button-secondary" href="'.self::e($returnUrl).'">'.self::e(self::panelText('common.cancel')).'</a><button class="dp-panel-button" type="submit">'.self::e((string)$actionMeta['label']).'</button></div>'
			.'</form>';
		$data=[
			'kind'=>'action_form',
			'resource'=>$resource->toArray(),
			'action'=>$actionMeta,
			'modal'=>[
				'enabled'=>(bool)($actionMeta['modal'] ?? false),
				'heading'=>(string)($actionMeta['modal_heading'] ?? $actionMeta['label'] ?? ''),
				'description'=>(string)($actionMeta['modal_description'] ?? ''),
				'submit_label'=>(string)($actionMeta['modal_submit_label'] ?? $actionMeta['label'] ?? self::panelText('common.run')),
				'cancel_label'=>(string)($actionMeta['modal_cancel_label'] ?? self::panelText('common.cancel')),
				'width'=>(string)($actionMeta['modal_width'] ?? 'md'),
				'style'=>(string)($actionMeta['meta']['modal_style'] ?? 'dialog'),
			],
			'request'=>$request->toArray(),
			'form_state'=>$state->jsonSerialize(),
			'action_state'=>$actionState->jsonSerialize(),
		];
		if($request->isPanelModalRequest()){
			return PanelPageResult::html($content, $status, $data);
		}
		return self::page((string)$actionMeta['label'], $content, $data, $status);
	}

	/**
	 * Renders a confirmation-only action form.
	 *
	 * This path is used for actions without fields that still require explicit
	 * confirmation. Selected bulk ids and return URL are preserved as hidden
	 * inputs so the follow-up POST can execute the same operation.
	 *
	 * @param Resource $resource Resource that owns the action.
	 * @param Action $action Action requiring confirmation.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Action record or bulk-selection list.
	 * @param int $status HTTP status for the confirmation page.
	 * @return PanelPageResult Confirmation page or modal HTML response.
	 */
	private static function actionConfirmationForm(Resource $resource, Action $action, PanelRequest $request, mixed $record=null, int $status=409): PanelPageResult {
		$actionMeta=$action->resolvedMeta($record, $request, $resource);
		$recordKey=$request->recordKey();
		$hidden=self::actionConfirmationInput($actionMeta).self::returnInput($resource, $request);
		$selected=$request->input('selected', []);
		$selected=is_array($selected) ? $selected : [$selected];
		foreach($selected as $value){
			$value=trim((string)$value);
			if($value!==''){
				$hidden.='<input type="hidden" name="selected[]" value="'.self::e($value).'">';
			}
		}
		$content=self::actionConfirmationContent($actionMeta, self::actionUrl($resource, (string)$actionMeta['name'], $recordKey, $request), self::actionReturnUrl($resource, $request), $hidden);
		$actionMode=($actionMeta['bulk'] ?? false)===true && is_array($record) ? 'bulk_action' : 'action';
		$actionState=$action->state($record, $request, $resource, $actionMode, null, [], null, null, ['stage'=>'confirmation']);
		$data=[
			'kind'=>'action_confirmation',
			'resource'=>$resource->toArray(),
			'action'=>$actionMeta,
			'action_state'=>$actionState->jsonSerialize(),
			'request'=>$request->toArray(),
		];
		if($request->isPanelModalRequest()){
			return PanelPageResult::html($content, $status, $data);
		}
		return self::page((string)($actionMeta['modal_heading'] ?? $actionMeta['label'] ?? self::panelText('action.confirm_action')), $content, $data, $status);
	}

	/**
	 * Reports whether resolved action metadata requires confirmation.
	 *
	 * @param array<string,mixed> $actionMeta Resolved action metadata.
	 * @return bool True when the action must receive the confirmation marker before execution.
	 */
	private static function actionRequiresConfirmation(array $actionMeta): bool {
		return ($actionMeta['requires_confirmation'] ?? false)===true;
	}

	/**
	 * Reports whether the current request includes the action confirmation marker.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return bool True when confirmation is present in submitted input or query data.
	 */
	private static function actionConfirmed(PanelRequest $request): bool {
		return (string)$request->input('__panel_action_confirm', $request->query('__panel_action_confirm', ''))==='1';
	}

	/**
	 * Builds the hidden confirmation marker for actions that require it.
	 *
	 * @param array<string,mixed> $actionMeta Resolved action metadata.
	 * @return string Hidden input markup or an empty string when confirmation is unnecessary.
	 */
	private static function actionConfirmationInput(array $actionMeta): string {
		return self::actionRequiresConfirmation($actionMeta) ? '<input type="hidden" name="__panel_action_confirm" value="1">' : '';
	}

	/**
	 * Resolves the confirmation message shown before action execution.
	 *
	 * @param array<string,mixed> $actionMeta Resolved action metadata.
	 * @return string Custom confirmation copy or the localized default message.
	 */
	private static function actionConfirmationMessage(array $actionMeta): string {
		$message=trim((string)($actionMeta['meta']['confirmation'] ?? ''));
		if($message!==''){
			return $message;
		}
		return self::panelText('action.run_action_confirm', ['action'=>(string)($actionMeta['label'] ?? self::panelText('client.action'))]);
	}

	/**
	 * Builds the confirmation form HTML for a destructive or sensitive action.
	 *
	 * @param array<string,mixed> $actionMeta Resolved action metadata.
	 * @param string $actionUrl URL receiving the confirmed POST.
	 * @param string $returnUrl URL used by the cancel link.
	 * @param string $hidden Additional hidden inputs to preserve request context.
	 * @return string Escaped confirmation form HTML.
	 */
	private static function actionConfirmationContent(array $actionMeta, string $actionUrl, string $returnUrl, string $hidden=''): string {
		$tone=self::safeTone((string)($actionMeta['tone'] ?? 'neutral'));
		$submit=(string)($actionMeta['modal_submit_label'] ?? $actionMeta['label'] ?? self::panelText('common.confirm'));
		$cancel=(string)($actionMeta['modal_cancel_label'] ?? self::panelText('common.cancel'));
		return '<form class="dp-panel-form" method="post" action="'.self::e($actionUrl).'">'
			.self::csrfInput()
			.$hidden
			.'<section class="dp-panel-form-section">'
			.'<div class="dp-panel-section-heading"><h2>'.self::e((string)($actionMeta['modal_heading'] ?? $actionMeta['label'] ?? self::panelText('action.confirm_action'))).'</h2><p>'.self::e(self::actionConfirmationMessage($actionMeta)).'</p></div>'
			.'</section>'
			.'<div class="dp-panel-toolbar"><a class="dp-panel-button dp-panel-button-secondary" href="'.self::e($returnUrl).'">'.self::e($cancel).'</a><button class="dp-panel-button dp-panel-action-'.$tone.'" type="submit">'.self::e($submit).'</button></div>'
			.'</form>';
	}

	/**
	 * Renders the bulk-update form for selected resource records.
	 *
	 * The form preserves selected ids, return URL, hidden fields, validation
	 * errors, and selected-count diagnostics. It does not execute persistence;
	 * submission is handled through the save lifecycle path.
	 *
	 * @param Resource $resource Resource whose bulk form should be rendered.
	 * @param PanelRequest $request Current panel request.
	 * @param array<int,mixed> $records Selected records being edited.
	 * @param ?PanelFormState $state Existing submitted state, or null to hydrate from the bulk form.
	 * @param int $status HTTP status for the rendered form.
	 * @return PanelPageResult Bulk-update form page.
	 */
	private static function bulkUpdateForm(Resource $resource, PanelRequest $request, array $records, ?PanelFormState $state=null, int $status=200): PanelPageResult {
		$state ??=$resource->bulkForm()->hydrate($records, $request);
		$formMeta=$resource->bulkForm()->toArray();
		$sectionMeta=self::sectionMetaByName($formMeta['sections'] ?? []);
		$sections=[];
		$hidden='<input type="hidden" name="__panel_bulk_update_submit" value="1">';
		$hidden.=self::returnInput($resource, $request);
		$selected=$request->input('selected', []);
		$selected=is_array($selected) ? $selected : [$selected];
		foreach($selected as $value){
			$value=trim((string)$value);
			if($value!==''){
				$hidden.='<input type="hidden" name="selected[]" value="'.self::e($value).'">';
			}
		}
		foreach($resource->bulkForm()->fieldsList() as $field){
			$meta=self::fieldMeta($field, $records, $request, 'bulk_update');
			$fieldVisible=$field->isVisible('bulk_update', $records, $request);
			if($fieldVisible===false && !self::fieldDependencyControlled($meta)){
				continue;
			}
			$name=(string)$meta['name'];
			$value=$state->value($name, $request->input($name, $meta['default'] ?? ''));
			if(($meta['hidden'] ?? false)===true){
				$hidden.=self::fieldControl($name, $meta, $value, true);
				continue;
			}
			$section=trim((string)($meta['meta']['section'] ?? ''));
			$section=$section!=='' ? $section : self::panelText('record.details');
			$sections[$section] ??=[];
			$sections[$section][]=self::fieldHtml($name, $meta, $value, $state->fieldErrors($name), !$fieldVisible);
		}
		$summary='<div class="dp-panel-notice dp-panel-notice-info"><span>'.self::e(self::panelText('action.selected_records_will_update', ['count'=>count($records), 'record'=>self::panelText(count($records)===1 ? 'common.record' : 'common.records')])).'</span></div>';
		if($state->invalid()){
			$count=array_sum(array_map('count', $state->errors()));
			$summary='<div class="dp-panel-alert">'.self::e(self::panelText('action.field_issue_summary', ['count'=>$count, 'issue'=>self::panelText($count===1 ? 'action.issue' : 'action.issues')])).'</div>'.$summary;
		}
		$reactiveUrl=PanelConfig::resourceUrl($resource, 'bulk_update');
		$content='<form class="dp-panel-form" method="post"'.self::formEncodingAttr($formMeta['fields'] ?? []).' action="'.self::e(PanelConfig::resourceUrl($resource, 'bulk_update')).'" data-dp-panel-reactive-url="'.self::e($reactiveUrl).'">'
			.self::csrfInput()
			.$hidden
			.$summary
			.self::formSectionsHtml($sections, (int)($formMeta['columns'] ?? 1), $sectionMeta)
			.'<div class="dp-panel-toolbar"><a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(self::actionReturnUrl($resource, $request)).'">'.self::e(self::panelText('common.cancel')).'</a><button class="dp-panel-button" type="submit">'.self::e(self::panelText('action.update_selected')).'</button></div>'
			.'</form>';
		return self::page(self::panelText('action.bulk_update_title', ['resource'=>(string)$resource->label()]), $content, [
			'kind'=>'bulk_update_form',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'selected_count'=>count($records),
			'form_state'=>$state->jsonSerialize(),
		], $status);
	}

	/**
	 * Handles create, update, and bulk-update form submissions.
	 *
	 * The save pipeline checks create/update permission, runs resource lifecycle
	 * hooks, validates form state, supports bulk selected records, mutates form
	 * data, persists through the resource, normalizes outcomes, flashes
	 * notifications, and returns either a redirect or completion page.
	 *
	 * @param Resource $resource Resource receiving the save.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Existing record for update, selected records for bulk update, or null for create.
	 * @param string $mode Save mode such as `store`, `update`, or `bulk_update`.
	 * @return PanelPageResult Redirect, form re-render, lifecycle result, or completion page.
	 */
	public static function saveResult(Resource $resource, PanelRequest $request, mixed $record=null, string $mode='store'): PanelPageResult {
		if($mode==='update' && $resource->can('update', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		if($mode!=='update' && $resource->can('create', null, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		PanelTrace::record('save.start', [
			'resource'=>$resource,
			'request'=>$request,
			'mode'=>$mode,
		]);
		$lifecycle=$resource->runBeforeValidate($record, $mode, $request);
		if($lifecycle instanceof PanelLifecycleResult){
			return self::lifecycleResult($resource, $request, $record, $mode, $lifecycle, null, []);
		}
		$state=$resource->form()->submit($request, $record, $mode);
		$state=$resource->runAfterValidate($state, $record, $mode, $request);
		if($state instanceof PanelLifecycleResult){
			return self::lifecycleResult($resource, $request, $record, $mode, $state, null, []);
		}
		if($state->invalid()){
			PanelTrace::record('save.validation_failed', [
				'resource'=>$resource,
				'request'=>$request,
				'state'=>$state,
			]);
			return self::form($resource, $request, $record, $mode==='update' ? 'edit' : 'create', $state, 422);
		}
		$input=$state->values();
		$input=$resource->mutateFormData($input, $record, $mode, $request);
		$input=$resource->runBeforeSave($input, $record, $mode, $request);
		if($input instanceof PanelLifecycleResult){
			return self::lifecycleResult($resource, $request, $record, $mode, $input, $state, []);
		}
		$result=$resource->saveRecord($input, $record, $mode, $request, true, true);
		$result=$resource->runAfterSave($result, $input, $record, $mode, $request);
		if($result instanceof PanelLifecycleResult){
			return self::lifecycleResult($resource, $request, $record, $mode, $result, $state, $input);
		}
		if(is_array($result) && array_key_exists('ok', $result) && $result['ok']===false){
			$errors=is_array($result['errors'] ?? null) ? $result['errors'] : [];
			if($errors===[]){
				$message=trim((string)($result['message'] ?? ''));
				$errors=['_form'=>$message!=='' ? $message : self::panelText('action.save_failed')];
			}
			PanelTrace::record('save.failed', [
				'resource'=>$resource,
				'request'=>$request,
				'mode'=>$mode,
				'input_keys'=>array_keys($input),
				'errors'=>$errors,
			]);
			return self::form($resource, $request, $record, $mode==='update' ? 'edit' : 'create', $state->withErrors($errors), 422);
		}
		$outcome=self::outcome($result, self::panelText('action.saved'));
		if($outcome['redirect']===null && self::requestProvidedReturnUrl($request)!==null){
			$outcome['redirect']=self::requestProvidedReturnUrl($request);
		}
		if($outcome['redirect']===null && ($request->isPanelFragmentRequest() || $request->isPanelModalRequest())){
			$outcome['redirect']=PanelConfig::resourceUrl($resource);
		}
		PanelTrace::record('save.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'mode'=>$mode,
			'input_keys'=>array_keys($input),
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		if($outcome['redirect']!==null){
			self::flashNotifications($outcome['notifications']);
			return PanelPageResult::redirect($outcome['redirect'], [
				'kind'=>$mode,
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
				'input_keys'=>array_keys($input),
				'form_state'=>$state->jsonSerialize(),
				'result'=>$outcome['result'],
			], $outcome['notifications'], $outcome['status']);
		}
		$content='<p>'.self::e($outcome['message']).'</p>'
			.'<div class="dp-panel-toolbar"><a class="dp-panel-button" href="'.self::e(PanelConfig::resourceUrl($resource)).'">'.self::e(self::panelText('common.back')).'</a></div>';
		return self::page($mode==='update' ? self::panelText('action.updated') : self::panelText('action.created'), $content, [
			'kind'=>$mode,
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'input_keys'=>array_keys($input),
			'form_state'=>$state->jsonSerialize(),
			'result'=>$outcome['result'],
		], 200, $outcome['notifications']);
	}

	/**
	 * Handles a single inline table-cell update.
	 *
	 * Inline updates require an existing record, a POST request, an editable table
	 * column, and `update` permission. The submitted value is coerced according to
	 * the column type before being sent to the resource update hook.
	 *
	 * @param Resource $resource Resource that owns the table column.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record being updated.
	 * @return PanelPageResult JSON payload for inline requests, redirect, validation page, not-found page, or forbidden response.
	 */
	public static function inlineUpdateResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		if(strtoupper($request->method())!=='POST'){
			return PanelPageResult::json(['ok'=>false, 'message'=>self::panelText('action.inline_requires_post')], 405);
		}
		if($record===null || $resource->can('update', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$field=Resource::normalizeName((string)$request->input('field', ''));
		$columns=$resource->resourceTable()->columnsList();
		$column=$columns[$field] ?? null;
		$indexRequest=$request->withQueryValue('operation', 'index');
		if(!$column instanceof Column || !$column->isEditable($record, $indexRequest, $resource, $resource->resourceTable())){
			return PanelPageResult::json(['ok'=>false, 'message'=>self::panelText('action.column_not_editable')], 422);
		}
		$value=self::inlineUpdateValue($column, $request->input('value', null));
		PanelTrace::record('inline_update.start', [
			'resource'=>$resource,
			'request'=>$request,
			'field'=>$field,
		]);
		$result=$resource->saveRecord([$field=>$value], $record, 'inline_update', $request);
		if($result instanceof PanelLifecycleResult){
			return self::lifecycleResult($resource, $request, $record, 'inline_update', $result, null, [$field=>$value]);
		}
		$outcome=self::outcome($result, self::panelText('action.saved'));
		$updatedRecord=is_array($record) ? array_replace($record, [$field=>$value]) : $record;
		$formatted=self::stringValue($column->formatValue($column->resolveValue($updatedRecord, $value), $updatedRecord));
		$payload=[
			'ok'=>$outcome['status']<400,
			'message'=>$outcome['message'],
			'field'=>$field,
			'value'=>$value,
			'formatted'=>$formatted,
			'notifications'=>$outcome['notifications'],
		];
		if(strtolower((string)$request->header('x-requested-with', ''))==='dataphyrepanelinline'){
			return PanelPageResult::json($payload, $outcome['status']);
		}
		self::flashNotifications($outcome['notifications']);
		$return=self::requestProvidedReturnUrl($request) ?? PanelConfig::resourceUrl($resource);
		return PanelPageResult::redirect($return, [
			'kind'=>'inline_update',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'field'=>$field,
		], $outcome['notifications'], $outcome['status']>=400 ? $outcome['status'] : 303);
	}

	/**
	 * Coerces an inline-edit value according to table column metadata.
	 *
	 * Boolean columns use panel truthiness, numeric columns cast numeric input, and
	 * every other column receives the original submitted value.
	 *
	 * @param Column $column Column definition being edited.
	 * @param mixed $value Raw submitted value.
	 * @return mixed boolean flag, numeric value, null for blank numeric input, or trimmed scalar text.
	 */
	private static function inlineUpdateValue(Column $column, mixed $value): mixed {
		$type=$column->editableInputType();
		if($type==='checkbox' || $type==='boolean'){
			return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'live', 'enabled'], true) ? 1 : 0;
		}
		if(in_array($type, ['number', 'integer', 'int'], true)){
			$text=trim((string)$value);
			if($text===''){
				return null;
			}
			return str_contains($text, '.') ? (float)$text : (int)$text;
		}
		return is_scalar($value) || $value instanceof \Stringable ? trim((string)$value) : '';
	}

	/**
	 * Converts a lifecycle halt into a panel page result.
	 *
	 * Lifecycle results can redirect, provide notifications, return effects, or
	 * request a form re-render. The data payload preserves action/form state,
	 * input keys, and contextual diagnostics for traces.
	 *
	 * @param Resource $resource Resource whose lifecycle halted.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record or selected record payload involved in the operation.
	 * @param string $mode Operation mode such as `store`, `update`, or `action`.
	 * @param PanelLifecycleResult $result Lifecycle result returned by a hook.
	 * @param ?PanelFormState $state Optional form state at the halt point.
	 * @param array<string,mixed> $input Submitted input values.
	 * @param array<string,mixed> $context Additional result context.
	 * @return PanelPageResult Redirect, form re-render, or lifecycle status page.
	 */
	private static function lifecycleResult(Resource $resource, PanelRequest $request, mixed $record, string $mode, PanelLifecycleResult $result, ?PanelFormState $state=null, array $input=[], array $context=[]): PanelPageResult {
		$notifications=$result->notifications();
		if($notifications===[] && $result->message()!==''){
			$notifications[]=PanelNotification::warning($result->message());
		}
		$data=[
			'kind'=>'lifecycle_result',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'mode'=>$mode,
			'input_keys'=>array_keys($input),
			'form_state'=>$state?->jsonSerialize(),
			'lifecycle'=>$result->jsonSerialize(),
		];
		if(isset($context['action']) && is_array($context['action'])){
			$data['action']=$context['action'];
		}
		if(($context['action_state'] ?? null) instanceof PanelActionState){
			$data['action_state']=$context['action_state']->jsonSerialize();
		}
		PanelTrace::record((string)($context['trace'] ?? 'save.lifecycle_result'), [
			'resource'=>$resource,
			'request'=>$request,
			'mode'=>$mode,
			'halted'=>$result->halted(),
			'redirect'=>$result->redirectTo(),
			'status'=>$result->status(),
			'action_state'=>$context['action_state'] ?? null,
		]);
		if($result->redirectTo()!==null){
			self::flashNotifications($notifications);
			return PanelPageResult::redirect($result->redirectTo(), $data, $notifications, $result->status());
		}
		$message=$result->message()!=='' ? $result->message() : ($result->halted() ? self::panelText('action.operation_stopped') : self::panelText('action.lifecycle_completed'));
		$returnUrl=is_string($context['return_url'] ?? null) && trim((string)$context['return_url'])!=='' ? (string)$context['return_url'] : ($mode==='update' && $record!==null ? self::showReturnUrl($resource, $record) : PanelConfig::resourceUrl($resource));
		$content='<div class="dp-panel-notice dp-panel-notice-warning"><span>'.self::e($message).'</span></div>'
			.'<div class="dp-panel-toolbar"><a class="dp-panel-button dp-panel-button-secondary" href="'.self::e($returnUrl).'">'.self::e(self::panelText('common.back')).'</a></div>';
		return self::page($result->halted() ? self::panelText('action.operation_stopped_title') : self::panelText('action.lifecycle_result_title'), $content, $data, $result->status(), $notifications);
	}

	/**
	 * Handles a soft delete request for a resource record.
	 *
	 * Soft delete requires delete capability, POST submission, an existing record,
	 * and `delete` permission. The resource performs the domain operation; this
	 * renderer normalizes outcome messages, redirects, notifications, and traces.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record to delete.
	 * @return PanelPageResult Redirect, method error, unavailable page, not-found page, or forbidden response.
	 */
	public static function deleteResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		PanelTrace::record('delete.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		if($resource->canDelete()===false){
			return self::page(self::panelText('action.delete_unavailable'), '<p class="dp-panel-empty">'.self::e(self::panelText('action.delete_unavailable_body')).'</p>', [
				'kind'=>'delete_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::page(self::panelText('action.delete_requires_confirmation'), '<p class="dp-panel-empty">'.self::e(self::panelText('action.delete_requires_confirmation_body')).'</p>', [
				'kind'=>'delete_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::page(self::panelText('record.not_found'), '<p class="dp-panel-empty">'.self::e(self::panelText('record.not_found_body')).'</p>', [
				'kind'=>'delete_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($resource->can('delete', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$result=$resource->deleteRecord($record, $request);
		$outcome=self::outcome($result, self::panelText('action.deleted'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::actionReturnUrl($resource, $request);
		}
		PanelTrace::record('delete.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'delete',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Handles a permanent delete request for a resource record.
	 *
	 * Force delete follows the same response contract as soft delete but checks
	 * force-delete availability and `force_delete` permission before calling the
	 * resource's permanent deletion hook.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record to permanently delete.
	 * @return PanelPageResult Redirect, method error, unavailable page, not-found page, or forbidden response.
	 */
	public static function forceDeleteResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		PanelTrace::record('force_delete.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		if($resource->canForceDelete()===false){
			return self::page(self::panelText('action.force_delete_unavailable'), '<p class="dp-panel-empty">'.self::e(self::panelText('action.force_delete_unavailable_body')).'</p>', [
				'kind'=>'force_delete_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::page(self::panelText('action.force_delete_requires_confirmation'), '<p class="dp-panel-empty">'.self::e(self::panelText('action.force_delete_requires_confirmation_body')).'</p>', [
				'kind'=>'force_delete_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::page(self::panelText('record.not_found'), '<p class="dp-panel-empty">'.self::e(self::panelText('record.not_found_body')).'</p>', [
				'kind'=>'force_delete_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($resource->can('force_delete', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$result=$resource->forceDeleteRecord($record, $request);
		$outcome=self::outcome($result, self::panelText('action.permanently_deleted'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::actionReturnUrl($resource, $request);
		}
		PanelTrace::record('force_delete.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'force_delete',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Handles duplication of a resource record.
	 *
	 * Duplication requires duplicate capability, POST submission, an existing
	 * record, and `duplicate` permission. The resource returns the domain outcome;
	 * this renderer applies the redirect and notification response contract.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record to duplicate.
	 * @return PanelPageResult Redirect, method error, unavailable page, not-found page, or forbidden response.
	 */
	public static function duplicateResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		PanelTrace::record('duplicate.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		if($resource->canDuplicate()===false){
			return self::page(self::panelText('action.duplicate_unavailable'), '<p class="dp-panel-empty">'.self::e(self::panelText('action.duplicate_unavailable_body')).'</p>', [
				'kind'=>'duplicate_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::page(self::panelText('action.duplicate_requires_confirmation'), '<p class="dp-panel-empty">'.self::e(self::panelText('action.duplicate_requires_confirmation_body')).'</p>', [
				'kind'=>'duplicate_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::page(self::panelText('record.not_found'), '<p class="dp-panel-empty">'.self::e(self::panelText('record.not_found_body')).'</p>', [
				'kind'=>'duplicate_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($resource->can('duplicate', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$result=$resource->duplicateRecord($record, $request);
		$outcome=self::outcome($result, self::panelText('action.duplicated'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::actionReturnUrl($resource, $request);
		}
		PanelTrace::record('duplicate.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'duplicate',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Handles restoration of a soft-deleted resource record.
	 *
	 * Restore requires restore capability, POST submission, an existing record, and
	 * `restore` permission. The response follows the shared mutation redirect and
	 * notification contract.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record to restore.
	 * @return PanelPageResult Redirect, method error, unavailable page, not-found page, or forbidden response.
	 */
	public static function restoreResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		PanelTrace::record('restore.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		if($resource->canRestore()===false){
			return self::page(self::panelText('action.restore_unavailable'), '<p class="dp-panel-empty">'.self::e(self::panelText('action.restore_unavailable_body')).'</p>', [
				'kind'=>'restore_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::page(self::panelText('action.restore_requires_confirmation'), '<p class="dp-panel-empty">'.self::e(self::panelText('action.restore_requires_confirmation_body')).'</p>', [
				'kind'=>'restore_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::page(self::panelText('record.not_found'), '<p class="dp-panel-empty">'.self::e(self::panelText('record.not_found_body')).'</p>', [
				'kind'=>'restore_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($resource->can('restore', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$result=$resource->restoreRecord($record, $request);
		$outcome=self::outcome($result, self::panelText('action.restored'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::actionReturnUrl($resource, $request);
		}
		PanelTrace::record('restore.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'restore',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Adds a note to a resource record.
	 *
	 * Notes require note capability, POST submission, an existing record, `note`
	 * and `note:create` permissions, and a non-empty note body. The resource
	 * persists the note; the renderer flashes notifications and redirects back to
	 * the requested return URL or record show page.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record receiving the note.
	 * @return PanelPageResult Redirect, validation page, method error, unavailable page, not-found page, or forbidden response.
	 */
	public static function noteResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		PanelTrace::record('note.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		if($resource->canAddNote()===false){
			return self::panelEmptyPage('record.notes_unavailable', 'record.notes_unavailable_body', [
				'kind'=>'note_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('record.note_requires_submission', 'record.note_requires_submission_body', [
				'kind'=>'note_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::panelEmptyPage('record.not_found', 'record.not_found_body', [
				'kind'=>'note_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($resource->can('note', $record, $request->user())===false || $resource->can('note:create', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$note=trim((string)$request->input('note', ''));
		if($note===''){
			return self::panelEmptyPage('record.note_empty', 'record.note_empty_body', [
				'kind'=>'note_empty',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		$result=$resource->addNote($record, $note, $request);
		$outcome=self::outcome($result, self::panelText('record.note_added'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::requestProvidedReturnUrl($request) ?? self::showReturnUrl($resource, $record);
		}
		PanelTrace::record('note.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'note',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Sends a message related to a resource record.
	 *
	 * Message submission requires message capability, POST submission, an existing
	 * record, `message` and `message:send` permissions, and a non-empty body.
	 * Channel, recipient, and subject are normalized into the message payload
	 * passed to the resource.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record that provides message context.
	 * @return PanelPageResult Redirect, validation page, method error, unavailable page, not-found page, or forbidden response.
	 */
	public static function messageResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		PanelTrace::record('message.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		if($resource->canSendMessage()===false){
			return self::panelEmptyPage('record.messages_unavailable', 'record.messages_unavailable_body', [
				'kind'=>'message_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('record.message_requires_submission', 'record.message_requires_submission_body', [
				'kind'=>'message_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::panelEmptyPage('record.not_found', 'record.not_found_body', [
				'kind'=>'message_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($resource->can('message', $record, $request->user())===false || $resource->can('message:send', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$body=trim((string)$request->input('body', $request->input('message', '')));
		if($body===''){
			return self::panelEmptyPage('record.message_empty', 'record.message_empty_body', [
				'kind'=>'message_empty',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		$message=[
			'channel'=>Resource::normalizeName((string)$request->input('channel', 'email')),
			'recipient'=>trim((string)$request->input('recipient', '')),
			'subject'=>trim((string)$request->input('subject', '')),
			'body'=>$body,
		];
		$result=$resource->sendMessage($record, $message, $request);
		$outcome=self::outcome($result, self::panelText('record.message_sent'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::requestProvidedReturnUrl($request) ?? self::showReturnUrl($resource, $record);
		}
		PanelTrace::record('message.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'channel'=>$message['channel'],
			'has_recipient'=>$message['recipient']!=='',
			'has_subject'=>$message['subject']!=='',
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'message',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'message'=>$message,
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Adds or removes a normalized tag on a resource record.
	 *
	 * Tag updates require tag capability, POST submission, an existing record,
	 * valid tag/action input, and layered tag permissions including the concrete
	 * tag and add/remove action.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record being tagged.
	 * @return PanelPageResult Redirect, validation page, method error, unavailable page, not-found page, or forbidden response.
	 */
	public static function tagResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		PanelTrace::record('tag.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		if($resource->canUpdateTag()===false){
			return self::panelEmptyPage('record.tags_unavailable', 'record.tags_unavailable_body', [
				'kind'=>'tag_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('record.tag_requires_submission', 'record.tag_requires_submission_body', [
				'kind'=>'tag_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::panelEmptyPage('record.not_found', 'record.not_found_body', [
				'kind'=>'tag_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		$tag=Resource::normalizeName((string)$request->input('tag', ''));
		$action=Resource::normalizeName((string)$request->input('tag_action', $request->input('action', 'add')));
		if($tag==='' || !in_array($action, ['add', 'remove'], true)){
			return self::panelEmptyPage('record.tag_not_selected', 'record.tag_not_selected_body', [
				'kind'=>'tag_missing',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		if(
			$resource->can('tag', $record, $request->user())===false
			|| $resource->can('tag:update', $record, $request->user())===false
			|| $resource->can('tag:'.$action, $record, $request->user())===false
			|| $resource->can('tag:'.$tag, $record, $request->user())===false
		){
			return self::forbidden($resource, $request);
		}
		$result=$resource->updateTag($record, $tag, $action, $request);
		$outcome=self::outcome($result, self::panelText($action==='add' ? 'record.tag_added' : 'record.tag_removed'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::requestProvidedReturnUrl($request) ?? self::showReturnUrl($resource, $record);
		}
		PanelTrace::record('tag.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'tag'=>$tag,
			'action'=>$action,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'tag',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'tag'=>$tag,
			'action'=>$action,
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Attaches an uploaded file to a resource record.
	 *
	 * Attachments require attachment capability, POST submission, an existing
	 * record, attachment permissions, and an uploaded file resolved from the panel
	 * request. File persistence and validation are delegated to the resource.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record receiving the attachment.
	 * @return PanelPageResult Redirect, validation page, method error, unavailable page, not-found page, or forbidden response.
	 */
	public static function attachResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		PanelTrace::record('attach.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		if($resource->canAttach()===false){
			return self::panelEmptyPage('record.attachments_unavailable', 'record.attachments_unavailable_body', [
				'kind'=>'attach_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('record.attachment_requires_upload', 'record.attachment_requires_upload_body', [
				'kind'=>'attach_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::panelEmptyPage('record.not_found', 'record.not_found_body', [
				'kind'=>'attach_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($resource->can('attachment', $record, $request->user())===false || $resource->can('attachment:create', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$file=self::uploadedAttachmentFile($request);
		if($file===null){
			return self::panelEmptyPage('record.no_file_selected', 'record.no_file_selected_body', [
				'kind'=>'attach_missing_file',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		$result=$resource->attachFile($record, $file, $request);
		$outcome=self::outcome($result, self::panelText('record.attachment_added'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::requestProvidedReturnUrl($request) ?? self::showReturnUrl($resource, $record);
		}
		PanelTrace::record('attach.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'filename'=>$file['name'] ?? null,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'attach',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'file'=>self::attachmentFileSummary($file),
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Updates task state or routes task creation for a resource record.
	 *
	 * Existing task updates require POST, record presence, task selection, and
	 * layered task permissions. Requests with a create/add task action delegate to
	 * the task creation helper.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record whose task state changes.
	 * @return PanelPageResult Redirect, validation page, method error, unavailable page, not-found page, or forbidden response.
	 */
	public static function taskResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		PanelTrace::record('task.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		if($resource->canUpdateTask()===false && $resource->canCreateTask()===false){
			return self::panelEmptyPage('record.tasks_unavailable', 'record.tasks_unavailable_body', [
				'kind'=>'task_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('record.task_requires_submission', 'record.task_requires_submission_body', [
				'kind'=>'task_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::panelEmptyPage('record.not_found', 'record.not_found_body', [
				'kind'=>'task_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		$taskAction=Resource::normalizeName((string)$request->input('task_action', ''));
		$creating=in_array($taskAction, ['create', 'add'], true);
		if($creating){
			return self::taskCreateResult($resource, $request, $record);
		}
		$task=Resource::normalizeName((string)$request->input('task', $request->query('task', '')));
		if($task===''){
			return self::panelEmptyPage('record.task_not_selected', 'record.task_not_selected_body', [
				'kind'=>'task_missing',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		if(
			$resource->can('task', $record, $request->user())===false
			|| $resource->can('task:update', $record, $request->user())===false
			|| $resource->can('task:'.$task, $record, $request->user())===false
		){
			return self::forbidden($resource, $request);
		}
		$completed=self::truthy($request->input('completed', '1'));
		$result=$resource->updateTask($record, $task, $completed, $request);
		$outcome=self::outcome($result, self::panelText($completed ? 'record.task_completed' : 'record.task_reopened'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::requestProvidedReturnUrl($request) ?? self::showReturnUrl($resource, $record);
		}
		PanelTrace::record('task.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'task'=>$task,
			'completed'=>$completed,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'task',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'task'=>$task,
			'completed'=>$completed,
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Resolves an approval on a resource record.
	 *
	 * Approval resolution requires approval capability, POST submission, record
	 * presence, valid approval and approve/reject decision input, and layered
	 * approval permissions including the concrete approval and decision.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record whose approval is resolved.
	 * @return PanelPageResult Redirect, validation page, method error, unavailable page, not-found page, or forbidden response.
	 */
	public static function approvalResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		PanelTrace::record('approval.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		if($resource->canResolveApproval()===false){
			return self::panelEmptyPage('record.approval_unavailable', 'record.approval_unavailable_body', [
				'kind'=>'approval_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('record.approval_requires_submission', 'record.approval_requires_submission_body', [
				'kind'=>'approval_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::panelEmptyPage('record.not_found', 'record.not_found_body', [
				'kind'=>'approval_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		$approval=Resource::normalizeName((string)$request->input('approval', $request->query('approval', '')));
		$decision=Resource::normalizeName((string)$request->input('decision', $request->query('decision', '')));
		if($approval==='' || !in_array($decision, ['approve', 'reject'], true)){
			return self::panelEmptyPage('record.approval_not_selected', 'record.approval_not_selected_body', [
				'kind'=>'approval_missing',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		if(
			$resource->can('approval', $record, $request->user())===false
			|| $resource->can('approval:resolve', $record, $request->user())===false
			|| $resource->can('approval:'.$approval, $record, $request->user())===false
			|| $resource->can('approval:'.$approval.':'.$decision, $record, $request->user())===false
		){
			return self::forbidden($resource, $request);
		}
		$result=$resource->resolveApproval($record, $approval, $decision, $request);
		$outcome=self::outcome($result, self::panelText($decision==='approve' ? 'record.approval_accepted' : 'record.approval_rejected'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::requestProvidedReturnUrl($request) ?? self::showReturnUrl($resource, $record);
		}
		PanelTrace::record('approval.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'approval'=>$approval,
			'decision'=>$decision,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'approval',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'approval'=>$approval,
			'decision'=>$decision,
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Creates a new task for a resource record.
	 *
	 * Task creation requires create-task capability, task permissions, and a
	 * non-empty title. Optional description, due date, and assignee are forwarded
	 * to the resource as trimmed strings.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record receiving the task.
	 * @return PanelPageResult Redirect, validation page, unavailable page, or forbidden response.
	 */
	private static function taskCreateResult(Resource $resource, PanelRequest $request, mixed $record): PanelPageResult {
		if($resource->canCreateTask()===false){
			return self::panelEmptyPage('record.task_creation_unavailable', 'record.task_creation_unavailable_body', [
				'kind'=>'task_create_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($resource->can('task', $record, $request->user())===false || $resource->can('task:create', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$title=trim((string)$request->input('title', ''));
		if($title===''){
			return self::panelEmptyPage('record.task_title_empty', 'record.task_title_empty_body', [
				'kind'=>'task_create_empty',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		$task=[
			'title'=>$title,
			'description'=>trim((string)$request->input('description', '')),
			'due'=>trim((string)$request->input('due', '')),
			'assignee'=>trim((string)$request->input('assignee', '')),
		];
		$result=$resource->createTask($record, $task, $request);
		$outcome=self::outcome($result, self::panelText('record.task_added'));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::requestProvidedReturnUrl($request) ?? self::showReturnUrl($resource, $record);
		}
		PanelTrace::record('task.created', [
			'resource'=>$resource,
			'request'=>$request,
			'input_keys'=>array_keys(array_filter($task, static fn(string $value): bool => $value!=='')),
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'task_create',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'task'=>$task,
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

}
