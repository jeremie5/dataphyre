<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Renders panel dashboard, custom pages, resource pages, and page-level actions.
 *
 * This trait is mixed into the panel renderer and owns the page result contracts
 * that bridge panel definitions to HTML, JSON metadata, redirects, modal
 * responses, tracing, render hooks, authorization checks, and request-preserving
 * URLs.
 */
trait PanelRendererPages {
	/**
	 * Renders the panel home dashboard with widgets, navigation, and global search.
	*
	 * The result data includes request metadata, serialized widgets, widget state,
	 * navigation entries/groups, and the global search payload used by diagnostics and
	 * client-side refresh code. Dashboard rendering also records a `page.dashboard`
	 * trace event before resolving widget and navigation state.
	*
	 * @param PanelManager $manager Active panel manager that resolves widgets, navigation, and search.
	 * @param PanelRequest $request Current panel request.
	 * @return PanelPageResult Dashboard page result with `kind=dashboard` metadata.
	 */
	public static function dashboard(PanelManager $manager, PanelRequest $request): PanelPageResult {
		PanelTrace::record('page.dashboard', [
			'request'=>$request,
		]);
		$widgetStates=$manager->widgetStates($request);
		$widgets=array_map(static fn(PanelWidgetState $state): array => $state->jsonSerialize(), $widgetStates);
		$searchQuery=trim((string)($request->query(PanelConfig::globalSearchParameter(), $request->query('panel_search', ''))));
		$searchResults=$searchQuery!=='' ? $manager->globalSearch($searchQuery, $request) : [];
		$navigationState=$manager->navigationState($request, [
			'query'=>$searchQuery,
			'results'=>$searchResults,
		]);
		$navigation=$navigationState->entries();
		return self::page(PanelConfig::homeLabel(), self::globalSearchHtml($searchQuery, $searchResults).self::widgetsHtml($widgets).self::navigationGroupsHtml($navigationState->groups()), [
			'kind'=>'dashboard',
			'request'=>$request->toArray(),
			'widgets'=>$widgets,
			'widget_states'=>array_map(static fn(PanelWidgetState $state): array => $state->jsonSerialize()['state'], $widgetStates),
			'navigation'=>$navigation,
			'navigation_state'=>$navigationState->jsonSerialize(),
			'global_search'=>[
				'query'=>$searchQuery,
				'results'=>$searchResults,
			],
		]);
	}

	/**
	 * Renders the standard panel 404 page for unresolved resources, pages, or routes.
	*
	 * The response keeps the original request shape in its metadata so diagnostics
	 * can show what the router attempted to resolve.
	*
	 * @param PanelRequest $request Request that could not be matched.
	 * @return PanelPageResult Empty panel page result with HTTP status `404`.
	 */
	public static function notFound(PanelRequest $request): PanelPageResult {
		PanelTrace::record('page.not_found', [
			'request'=>$request,
		]);
		return self::panelEmptyPage('page.not_found', 'page.not_found_body', [
			'kind'=>'not_found',
			'request'=>$request->toArray(),
		], 404);
	}

	/**
	 * Renders a custom panel page and wraps its widgets, forms, tables, and content.
	*
	 * Custom page callbacks may return a complete `PanelPageResult`, an array with
	 * `title`, `content`, `status`, `data`, and `notifications`, or any renderable
	 * content value. The renderer preserves page metadata while placing primary
	 * forms before or after content according to the page's configured form layout.
	*
	 * @param PanelPage $panelPage Page definition being rendered.
	 * @param PanelRequest $request Current panel request.
	 * @param ?PanelManager $manager Active panel manager passed to page callbacks.
	 * @return PanelPageResult Page result with serialized page, widget, table, and form metadata.
	 */
	public static function customPage(PanelPage $panelPage, PanelRequest $request, ?PanelManager $manager=null): PanelPageResult {
		PanelTrace::record('page.custom', [
			'page'=>$panelPage,
			'request'=>$request,
		]);
		$result=$panelPage->render($request, $manager);
		if($result instanceof PanelPageResult){
			return $result;
		}
		$title=(string)$panelPage->label();
		$content='';
		$status=200;
		$data=[];
		$notifications=[];
		if(is_array($result)){
			$title=trim((string)($result['title'] ?? $title)) ?: $title;
			$content=self::pageContentValue($result['content'] ?? '');
			$status=max(100, min(599, (int)($result['status'] ?? 200)));
			$data=is_array($result['data'] ?? null) ? $result['data'] : [];
			$notifications=is_array($result['notifications'] ?? null) ? $result['notifications'] : [];
		}
		else {
			$content=self::pageContentValue($result);
		}
		$pageWidgetStates=$panelPage->widgetStates($request);
		$pageWidgets=array_map(static fn(PanelWidgetState $state): array => $state->jsonSerialize(), $pageWidgetStates);
		$pageTables=$panelPage->resolvedTables($request);
		$pageForms=self::pageScaffoldFormsHtml($panelPage, $request);
		$pageHasPrimaryForm=self::pageHasPrimaryForm($panelPage);
		$contentShell=trim($content)==='' && $pageForms!=='' ? '' : self::customPageShell($content);
		$body=$pageHasPrimaryForm
			? self::pageActionsHtml($panelPage, $request).$contentShell.$pageForms.self::widgetsHtml($pageWidgets).self::pageTablesHtml($panelPage, $request, $pageTables)
			: self::pageActionsHtml($panelPage, $request).self::widgetsHtml($pageWidgets).$pageForms.self::pageTablesHtml($panelPage, $request, $pageTables).$contentShell;
		return self::page($title, $body, array_replace([
			'kind'=>'custom_page',
			'page'=>$panelPage->toArray(),
			'request'=>$request->toArray(),
			'widgets'=>$pageWidgets,
			'widget_states'=>array_map(static fn(PanelWidgetState $state): array => $state->jsonSerialize()['state'], $pageWidgetStates),
			'tables'=>array_map(static fn(array $table): array => array_replace($table['meta'], ['record_count'=>count($table['records'])]), $pageTables),
			'forms'=>$panelPage->formsList(),
		], $data), $status, $notifications);
	}

	/**
	 * Executes a page-level action and converts its lifecycle into a page response.
	*
	 * The action pipeline enforces existence, visibility, authorization, disabled
	 * state, form submission, validation, confirmation, form-data mutation, before
	 * and after hooks, exception pages, notifications, redirects, and action state
	 * tracing. POST actions without an explicit redirect return to the page URL to
	 * avoid repeat submissions.
	*
	 * @param PanelPage $page Page that owns the action.
	 * @param PanelRequest $request Current panel request and submitted input.
	 * @param string $actionName Normalized action name from the route.
	 * @param ?PanelManager $manager Active panel manager used by lifecycle helpers.
	 * @return PanelPageResult Action form, confirmation, redirect, success, error, or lifecycle response.
	 */
	public static function pageActionResult(PanelPage $page, PanelRequest $request, string $actionName, ?PanelManager $manager=null): PanelPageResult {
		PanelTrace::record('page_action.start', [
			'page'=>$page,
			'action'=>$actionName,
			'request'=>$request,
		]);
		$action=$page->actionByName($actionName);
		if(!$action instanceof Action){
			return self::panelEmptyPage('action.not_found', 'action.page_not_found_body', [
				'kind'=>'page_action_missing',
				'page'=>$page->toArray(),
				'action'=>$actionName,
			], 404);
		}
		if(!$action->isVisible(null, $request->user(), null, $request)){
			return self::panelEmptyPage('action.not_found', 'action.page_not_found_state_body', [
				'kind'=>'page_action_hidden',
				'page'=>$page->toArray(),
				'action'=>$action->resolvedMeta(null, $request, null),
			], 404);
		}
		if($action->can(null, $request->user(), null)===false){
			return self::forbidden(null, $request);
		}
		if($action->isDisabled(null, $request->user(), null, $request)){
			$reason=$action->disabledReasonFor(null, $request->user(), null, $request) ?? self::panelText('action.unavailable_now');
			return self::page(self::panelText('action.unavailable'), '<p class="dp-panel-empty">'.self::e($reason).'</p><div class="dp-panel-toolbar"><a class="dp-panel-button" href="'.self::e(self::pageReturnUrl($page, $request)).'">'.self::e(self::panelText('common.back')).'</a></div>', [
				'kind'=>'page_action_disabled',
				'page'=>$page->toArray(),
				'action'=>$action->resolvedMeta(null, $request, null),
				'disabled_reason'=>$reason,
			], 409);
		}
		$actionMeta=$action->resolvedMeta(null, $request, null);
		$actionData=$request->input();
		$state=null;
		$actionState=$action->state(null, $request, null, 'page_action', null, $actionData, null, null, ['stage'=>'start', 'page'=>$page->name()]);
		PanelTrace::record('page_action.state', [
			'page'=>$page,
			'action'=>$action,
			'state'=>$actionState,
		]);
		$lifecycle=$action->runBeforeValidate(null, $request, null);
		if($lifecycle instanceof PanelLifecycleResult){
			return self::pageActionLifecycleResult($page, $action, $request, $lifecycle, $actionState->withLifecycle($lifecycle)->withStage('before_validate_halted'), null, [], $manager);
		}
		if($action->hasFields()){
			if((string)$request->input('__panel_action_submit', '')!=='1'){
				return self::pageActionForm($page, $action, $request, null, 200, $manager);
			}
			$state=$action->form()->submit($request, null, 'action');
			$state=$action->runAfterValidate($state, null, $request, null);
			if($state instanceof PanelLifecycleResult){
				return self::pageActionLifecycleResult($page, $action, $request, $state, $actionState->withLifecycle($state)->withStage('after_validate_halted'), null, [], $manager);
			}
			if($state->invalid()){
				return self::pageActionForm($page, $action, $request, $state, 422, $manager);
			}
			if(self::actionRequiresConfirmation($actionMeta) && !self::actionConfirmed($request)){
				return self::pageActionForm($page, $action, $request, $state, 409, $manager);
			}
			$actionData=$state->values();
			$actionState=$action->state(null, $request, null, 'page_action', $state, $actionData, null, null, ['stage'=>'validated', 'page'=>$page->name()]);
		}
		elseif(self::actionRequiresConfirmation($actionMeta) && !self::actionConfirmed($request)){
			return self::pageActionConfirmationForm($page, $action, $request, $manager);
		}
		$actionData=$action->mutateFormData($actionData, null, $request, null);
		$actionState=$actionState->withData($actionData)->withStage('mutated');
		$before=$action->runBeforeAction($actionData, null, $request, null);
		if($before instanceof PanelLifecycleResult){
			return self::pageActionLifecycleResult($page, $action, $request, $before, $actionState->withLifecycle($before)->withStage('before_action_halted'), $state, $actionData, $manager);
		}
		if($before!==null){
			$result=$before;
			$actionState=$actionState->withResult($result)->withStage('before_action_result');
		}
		else{
			try{
				$result=$action->execute(null, $actionData, null, false, $request);
			}
			catch(\Throwable $exception){
				return self::actionExceptionPage(null, $page, $request, $action, $exception, 'page_action');
			}
			$result=$action->runAfterAction($result, $actionData, null, $request, null);
			if($result instanceof PanelLifecycleResult){
				return self::pageActionLifecycleResult($page, $action, $request, $result, $actionState->withLifecycle($result)->withStage('after_action_halted'), $state, $actionData, $manager);
			}
			$actionState=$actionState->withResult($result)->withStage('completed');
		}
		$outcome=self::outcome($result, (string)($actionMeta['success_message'] ?? self::panelText('action.completed_body')));
		if($outcome['redirect']===null && is_string($actionMeta['redirect_to'] ?? null)){
			$outcome['redirect']=self::safeReturnUrl((string)$actionMeta['redirect_to']);
		}
		if($outcome['redirect']===null && strtoupper($request->method())==='POST'){
			$outcome['redirect']=self::pageReturnUrl($page, $request);
		}
		PanelTrace::record('page_action.completed', [
			'page'=>$page,
			'action'=>$action,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
			'state'=>$actionState,
		]);
		if($outcome['redirect']!==null){
			$effects=self::actionEffects($actionMeta, $outcome);
			self::flashNotifications($outcome['notifications']);
			return PanelPageResult::redirect($outcome['redirect'], [
				'kind'=>'page_action',
				'page'=>$page->toArray(),
				'action'=>$actionMeta,
				'action_state'=>$actionState->jsonSerialize(),
				'result'=>$outcome['result'],
				'effects'=>$effects,
			], $outcome['notifications'], $outcome['status']);
		}
		$effects=self::actionEffects($actionMeta, $outcome);
		$message=$outcome['message']!=='' ? self::e($outcome['message']) : 'The action finished successfully.';
		$content='<div class="dp-panel-notice dp-panel-notice-success"><strong>'.self::e(self::panelText('action.completed')).'</strong><span>'.$message.'</span></div>';
		return self::page(self::panelText('action.completed'), $content, [
			'kind'=>'page_action',
			'page'=>$page->toArray(),
			'action'=>$actionMeta,
			'action_state'=>$actionState->jsonSerialize(),
			'result'=>$outcome['result'],
			'effects'=>$effects,
		], 200, $outcome['notifications']);
	}

	/**
	 * Converts a page-action lifecycle result into redirect, halt, or notice output.
	 *
	 * @param PanelPage $page Page that owns the action.
	 * @param Action $action Action whose lifecycle hook returned the result.
	 * @param PanelRequest $request Current panel request.
	 * @param PanelLifecycleResult $result Lifecycle control result.
	 * @param PanelActionState $actionState Current action state snapshot.
	 * @param ?PanelFormState $state Form state available when validation has run.
	 * @param array<string, mixed> $input Submitted action input keys.
	 * @param ?PanelManager $manager Active panel manager.
	 * @return PanelPageResult Redirect or page result carrying lifecycle metadata.
	 */
	private static function pageActionLifecycleResult(PanelPage $page, Action $action, PanelRequest $request, PanelLifecycleResult $result, PanelActionState $actionState, ?PanelFormState $state=null, array $input=[], ?PanelManager $manager=null): PanelPageResult {
		$notifications=$result->notifications();
		if($notifications===[] && $result->message()!==''){
			$notifications[]=PanelNotification::warning($result->message());
		}
		$data=[
			'kind'=>'page_action_lifecycle_result',
			'page'=>$page->toArray(),
			'action'=>$action->resolvedMeta(null, $request, null),
			'action_state'=>$actionState->jsonSerialize(),
			'request'=>$request->toArray(),
			'input_keys'=>array_keys($input),
			'form_state'=>$state?->jsonSerialize(),
			'lifecycle'=>$result->jsonSerialize(),
		];
		PanelTrace::record('page_action.lifecycle_result', [
			'page'=>$page,
			'action'=>$action,
			'halted'=>$result->halted(),
			'redirect'=>$result->redirectTo(),
			'status'=>$result->status(),
			'state'=>$actionState,
		]);
		if($result->redirectTo()!==null){
			self::flashNotifications($notifications);
			return PanelPageResult::redirect($result->redirectTo(), $data, $notifications, $result->status());
		}
		$message=$result->message()!=='' ? $result->message() : ($result->halted() ? self::panelText('page.action_stopped') : self::panelText('page.action_lifecycle_completed'));
		$content='<div class="dp-panel-notice dp-panel-notice-warning"><span>'.self::e($message).'</span></div>'
			.'<div class="dp-panel-toolbar"><a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(self::pageReturnUrl($page, $request)).'">'.self::e(self::panelText('common.back')).'</a></div>';
		return self::page($result->halted() ? self::panelText('page.action_stopped_title') : self::panelText('page.action_lifecycle_title'), $content, $data, $result->status(), $notifications);
	}

	/**
	 * Builds the toolbar HTML for visible page actions and action groups.
	 *
	 * @param PanelPage $page Page definition containing action definitions.
	 * @param PanelRequest $request Current panel request and user context.
	 * @return string Toolbar HTML, or an empty string when no actions are visible.
	 */
	private static function pageActionsHtml(PanelPage $page, PanelRequest $request): string {
		$actions='';
		foreach($page->actionsList() as $action){
			if($action instanceof ActionGroup){
				$actions.=self::pageActionGroupButton($page, $action, $request);
				continue;
			}
			if(!$page->shouldShowActionButton($action->name())){
				continue;
			}
			if(!$action->isVisible(null, $request->user(), null, $request) || $action->can(null, $request->user(), null)===false){
				continue;
			}
			$actions.=self::pageActionButton($page, $action, $request);
		}
		return $actions!=='' ? '<div class="dp-panel-toolbar"><span></span><div class="dp-panel-toolbar-actions">'.$actions.'</div></div>' : '';
	}

	/**
	 * Renders a page action as a link, form button, modal trigger, or disabled button.
	 *
	 * @param PanelPage $page Page that owns the action.
	 * @param Action $action Action definition being rendered.
	 * @param PanelRequest $request Current panel request.
	 * @return string Action control HTML, or an empty string when metadata has no name.
	 */
	private static function pageActionButton(PanelPage $page, Action $action, PanelRequest $request): string {
		$meta=$action->resolvedMeta(null, $request, null);
		$name=(string)($meta['name'] ?? '');
		if($name===''){
			return '';
		}
		$label=self::actionLabelHtml($meta);
		$tone=self::safeTone((string)($meta['tone'] ?? 'neutral'));
		$url=self::pageActionUrl($page, $name, $request);
		$hasFields=($meta['fields']['fields'] ?? [])!==[];
		$modalContent=$action->resolveModalContent(null, $request, null);
		$modal=self::actionModalAttributes($meta, $hasFields, $modalContent);
		$disabled=$action->isDisabled(null, $request->user(), null, $request);
		$disabledAttr=self::actionDisabledAttributes($action, null, $request, null);
		$tooltipAttr=$disabled ? '' : self::actionTooltipAttributes($meta);
		$keyBindingAttr=$disabled ? '' : self::actionKeyBindingAttributes($meta);
		$extraAttr=self::actionExtraAttributes($meta);
		$classSuffix=self::actionExtraClass($meta);
		$confirm=($meta['requires_confirmation'] ?? false)===true
			? ' data-confirm="'.self::e((string)($meta['meta']['confirmation'] ?? self::panelText('action.run_action_confirm', ['action'=>(string)($meta['label'] ?? self::panelText('common.run'))]))).'"'
			: '';
		if($disabled){
			return '<button class="dp-panel-action dp-panel-action-'.$tone.' dp-panel-action-disabled'.$classSuffix.'" type="button"'.$extraAttr.$disabledAttr.'>'.$label.'</button>';
		}
		if($modalContent!==null && ($meta['has_handler'] ?? false)!==true && !$hasFields && ($meta['requires_confirmation'] ?? false)!==true){
			return '<button class="dp-panel-action dp-panel-action-'.$tone.$classSuffix.'" type="button"'.$modal.$tooltipAttr.$keyBindingAttr.$extraAttr.'>'.$label.'</button>';
		}
		if(($meta['has_handler'] ?? false)!==true || ($meta['fields']['fields'] ?? [])!==[]){
			return '<a class="dp-panel-action dp-panel-action-'.$tone.$classSuffix.'" href="'.self::e($url).'"'.$confirm.$modal.$tooltipAttr.$keyBindingAttr.$extraAttr.'>'.$label.'</a>';
		}
		return '<form class="dp-panel-inline-action" method="post" action="'.self::e($url).'">'
			.self::csrfInput()
			.'<button class="dp-panel-action dp-panel-action-'.$tone.$classSuffix.'" type="submit" name="__panel_action_confirm" value="1"'.$confirm.$modal.$tooltipAttr.$keyBindingAttr.$extraAttr.'>'.$label.'</button>'
			.'</form>';
	}

	/**
	 * Renders the confirmation step for a page action that requires explicit consent.
	 *
	 * @param PanelPage $page Page that owns the action.
	 * @param Action $action Action requiring confirmation.
	 * @param PanelRequest $request Current panel request.
	 * @param ?PanelManager $manager Active panel manager.
	 * @param int $status HTTP status used for the confirmation response.
	 * @return PanelPageResult Modal HTML or full page confirmation result.
	 */
	private static function pageActionConfirmationForm(PanelPage $page, Action $action, PanelRequest $request, ?PanelManager $manager=null, int $status=409): PanelPageResult {
		$actionMeta=$action->resolvedMeta(null, $request, null);
		$content=self::actionConfirmationContent($actionMeta, self::pageActionUrl($page, (string)$actionMeta['name'], $request), self::pageReturnUrl($page, $request), self::actionConfirmationInput($actionMeta));
		$actionState=$action->state(null, $request, null, 'page_action', null, [], null, null, ['stage'=>'confirmation', 'page'=>$page->name()]);
		$data=[
			'kind'=>'page_action_confirmation',
			'page'=>$page->toArray(),
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
	 * Renders a page action group dropdown after filtering inaccessible child actions.
	 *
	 * @param PanelPage $page Page that owns the action group.
	 * @param ActionGroup $group Action group definition.
	 * @param PanelRequest $request Current panel request and user context.
	 * @return string Dropdown HTML, or an empty string when no child actions are renderable.
	 */
	private static function pageActionGroupButton(PanelPage $page, ActionGroup $group, PanelRequest $request): string {
		$items='';
		$pending='';
		foreach($group->menuItems() as $item){
			$type=(string)($item['type'] ?? 'action');
			if($type==='section'){
				$pending.=self::actionGroupSectionHtml((string)($item['label'] ?? ''), (string)($item['description'] ?? ''));
				continue;
			}
			if($type==='divider'){
				if($items!==''){
					$pending.='<hr class="dp-panel-action-menu-divider" aria-hidden="true">';
				}
				continue;
			}
			$actionName=(string)($item['name'] ?? '');
			$action=$actionName!=='' ? $group->actionByName($actionName) : null;
			if(!$action instanceof Action){
				continue;
			}
			if(!$action->isVisible(null, $request->user(), null, $request) || $action->can(null, $request->user(), null)===false){
				continue;
			}
			if($pending!==''){
				$items.=$pending;
				$pending='';
			}
			$items.=self::pageActionButton($page, $action, $request);
		}
		if($items===''){
			return '';
		}
		$meta=$group->toArray();
		$tone=self::safeTone((string)($meta['tone'] ?? 'neutral'));
		$style=self::safeActionStyle((string)($meta['style'] ?? 'solid'));
		$size=self::safeActionSize((string)($meta['size'] ?? 'md'));
		$label=trim((string)($meta['label'] ?? self::panelText('page.action_group'))) ?: self::panelText('page.action_group');
		$icon=trim((string)($meta['icon'] ?? ''));
		$iconHtml=$icon!=='' ? '<i class="dp-panel-action-icon" aria-hidden="true">'.self::e(self::compactNavIcon($icon, $label)).'</i>' : '';
		$iconOnly=($meta['icon_only'] ?? false)===true;
		$labelClass=$iconOnly ? 'dp-panel-action-label dp-panel-sr-only' : 'dp-panel-action-label';
		$chevron=$iconOnly ? '' : '<span class="dp-panel-action-group-chevron" aria-hidden="true">&#9662;</span>';
		$summary=$iconHtml.'<span class="'.$labelClass.'">'.self::e($label).'</span>'.$chevron;
		$width=self::safeActionGroupWidth((string)($meta['dropdown_width'] ?? 'md'));
		$alignment=self::safeActionGroupAlignment((string)($meta['dropdown_alignment'] ?? 'end'));
		$class='dp-panel-action dp-panel-action-'.$tone.' dp-panel-action-style-'.$style.' dp-panel-action-size-'.$size.($iconOnly ? ' dp-panel-action-icon-only' : '');
		return '<details class="dp-panel-action-group dp-panel-action-group-width-'.$width.' dp-panel-action-group-align-'.$alignment.'"><summary class="'.$class.'"'.($iconOnly ? ' aria-label="'.self::e($label).'"' : '').'>'.$summary.'</summary><div class="dp-panel-action-menu">'.$items.'</div></details>';
	}

	/**
	 * Renders the form step for a page action with fields.
	 *
	 * @param PanelPage $page Page that owns the action.
	 * @param Action $action Form-backed page action.
	 * @param PanelRequest $request Current panel request.
	 * @param ?PanelFormState $state Existing submitted state, or `null` to hydrate.
	 * @param int $status HTTP status for the form response.
	 * @param ?PanelManager $manager Active panel manager.
	 * @return PanelPageResult Modal HTML or full page action form.
	 */
	private static function pageActionForm(PanelPage $page, Action $action, PanelRequest $request, ?PanelFormState $state=null, int $status=200, ?PanelManager $manager=null): PanelPageResult {
		$state ??=$action->form()->hydrate(null, $request);
		$actionMeta=$action->resolvedMeta(null, $request, null);
		$actionState=$action->state(null, $request, null, 'page_action', $state, $state->dehydratedValues() ?: $state->values(), null, null, ['stage'=>$state->invalid() ? 'invalid' : 'form', 'page'=>$page->name()]);
		$content=self::pageActionFormHtml($page, $action, $request, $state, [
			'cancel_url'=>self::pageReturnUrl($page, $request),
			'include_cancel'=>true,
		]);
		$data=[
			'kind'=>'page_action_form',
			'page'=>$page->toArray(),
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
	 * Renders configured page scaffold forms in sort order.
	 *
	 * @param PanelPage $page Page containing form scaffold definitions.
	 * @param PanelRequest $request Current panel request.
	 * @return string Concatenated form HTML for visible, authorized form actions.
	 */
	private static function pageScaffoldFormsHtml(PanelPage $page, PanelRequest $request): string {
		$forms=$page->formsList();
		if($forms===[]){
			return '';
		}
		uasort($forms, static fn(array $left, array $right): int => [(int)($left['sort'] ?? 100), (string)($left['action'] ?? '')] <=> [(int)($right['sort'] ?? 100), (string)($right['action'] ?? '')]);
		$html='';
		foreach($forms as $form){
			$actionName=(string)($form['action'] ?? '');
			$action=$actionName!=='' ? $page->actionByName($actionName) : null;
			if(!$action instanceof Action || !$action->isVisible(null, $request->user(), null, $request) || $action->can(null, $request->user(), null)===false){
				continue;
			}
			$state=$action->form()->hydrate(null, $request);
			$actionMeta=$action->resolvedMeta(null, $request, null);
			$title=trim((string)($form['title'] ?? $actionMeta['label'] ?? ''));
			$description=trim((string)($form['description'] ?? $actionMeta['description'] ?? ''));
			$placement=(string)($form['placement'] ?? 'embedded');
			$style=(string)($form['style'] ?? ($placement==='page' ? 'portal' : 'section'));
			$width=(string)($form['width'] ?? ($placement==='page' ? 'md' : 'full'));
			$content=self::pageActionFormHtml($page, $action, $request, $state, [
				'include_cancel'=>($form['include_cancel'] ?? false)===true,
				'cancel_url'=>is_string($form['cancel_url'] ?? null) ? (string)$form['cancel_url'] : self::pageReturnUrl($page, $request),
				'submit_label'=>is_string($form['submit_label'] ?? null) ? (string)$form['submit_label'] : null,
				'class'=>'dp-panel-page-form-native',
			]);
			$heading='';
			if($title!=='' || $description!==''){
				$heading='<header class="dp-panel-page-form-heading">'
					.($title!=='' ? '<h2>'.self::e($title).'</h2>' : '')
					.($description!=='' ? '<p>'.self::e($description).'</p>' : '')
					.'</header>';
			}
			$html.='<section class="dp-panel-page-form dp-panel-page-form-'.$placement.' dp-panel-page-form-style-'.$style.' dp-panel-page-form-width-'.$width.'" data-dp-panel-page-form="'.self::e($actionName).'">'
				.$heading
				.$content
				.'</section>';
		}
		return $html;
	}

	/**
	 * Detects whether a custom page has a form that should lead the page layout.
	 *
	 * @param PanelPage $page Page definition containing scaffold forms.
	 * @return bool `true` when any form uses `placement=page`.
	 */
	private static function pageHasPrimaryForm(PanelPage $page): bool {
		foreach($page->formsList() as $form){
			if(($form['placement'] ?? '')==='page'){
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds the HTML form used by page action forms and embedded page forms.
	 *
	 * @param PanelPage $page Page that owns the action.
	 * @param Action $action Action whose form fields should be rendered.
	 * @param PanelRequest $request Current panel request.
	 * @param PanelFormState $state Hydrated or submitted form state.
	 * @param array<string, mixed> $options Rendering options such as cancel URL and submit label.
	 * @return string Complete action form HTML.
	 */
	private static function pageActionFormHtml(PanelPage $page, Action $action, PanelRequest $request, PanelFormState $state, array $options=[]): string {
		$actionMeta=$action->resolvedMeta(null, $request, null);
		$sectionMeta=self::sectionMetaByName($actionMeta['fields']['sections'] ?? []);
		$sections=[];
		foreach($action->form()->fieldsList() as $field){
			$meta=self::fieldMeta($field, null, $request, 'action');
			$fieldVisible=$field->isVisible('action', null, $request);
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
		$summary='';
		if($state->invalid()){
			$count=array_sum(array_map('count', $state->errors()));
			$summary='<div class="dp-panel-alert">'.self::e(self::panelText('action.field_issue_summary', ['count'=>$count, 'issue'=>self::panelText($count===1 ? 'action.issue' : 'action.issues')])).'</div>';
		}
		$includeCancel=($options['include_cancel'] ?? false)===true;
		$cancelUrl=is_string($options['cancel_url'] ?? null) ? (string)$options['cancel_url'] : self::pageReturnUrl($page, $request);
		$submitLabel=trim((string)($options['submit_label'] ?? $actionMeta['label'] ?? self::panelText('common.run')));
		$class=trim('dp-panel-form '.(string)($options['class'] ?? ''));
		$actions=($includeCancel ? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e($cancelUrl).'">'.self::e(self::panelText('common.cancel')).'</a>' : '')
			.'<button class="dp-panel-button dp-panel-button-primary" type="submit">'.self::e($submitLabel).'</button>';
		return '<form class="'.self::e($class).'" method="post"'.self::formEncodingAttr($actionMeta['fields']['fields'] ?? []).' action="'.self::e(self::pageActionUrl($page, (string)$actionMeta['name'], $request)).'"'.self::accessibilityDefaultAttrs(is_array($actionMeta['fields']['meta'] ?? null) ? $actionMeta['fields']['meta'] : []).'>'
			.self::csrfInput()
			.'<input type="hidden" name="__panel_action_submit" value="1">'
			.self::actionConfirmationInput($actionMeta)
			.$summary
			.self::formSectionsHtml($sections, self::formColumnsDefinition($actionMeta['fields'] ?? []), $sectionMeta)
			.'<div class="dp-panel-toolbar dp-panel-page-form-actions"><span></span><div class="dp-panel-toolbar-actions">'.$actions.'</div></div>'
			.'</form>';
	}

	/**
	 * Builds a request-preserving URL for running a page action.
	 *
	 * @param PanelPage $page Page that owns the action.
	 * @param string $actionName Action name to encode into the path.
	 * @param PanelRequest $request Current request whose non-routing query values should persist.
	 * @return string Panel URL for the page action route.
	 */
	private static function pageActionUrl(PanelPage $page, string $actionName, PanelRequest $request): string {
		$query=self::queryWithoutPage($request);
		unset($query['resource'], $query['operation'], $query['record'], $query['relation'], $query['action']);
		return PanelConfig::url($page->name().'/action/'.rawurlencode($actionName), $query);
	}

	/**
	 * Resolves the safe return URL for a page action flow.
	 *
	 * @param PanelPage $page Page receiving the return.
	 * @param PanelRequest $request Current request that may contain an explicit return URL.
	 * @return string Safe return URL for the page without action routing fragments.
	 */
	private static function pageReturnUrl(PanelPage $page, PanelRequest $request): string {
		if(($return=self::requestProvidedReturnUrl($request))!==null){
			return $return;
		}
		$query=self::queryWithoutPage($request);
		unset($query['resource'], $query['operation'], $query['record'], $query['relation'], $query['action']);
		return PanelConfig::url($page->name(), $query);
	}

	/**
	 * Renders all table definitions attached to a custom panel page.
	 *
	 * @param PanelPage $page Page that owns the tables.
	 * @param PanelRequest $request Current panel request.
	 * @param array<int, array<string, mixed>> $tables Resolved table payloads with table, records, meta, and summaries.
	 * @return string Table sections HTML.
	 */
	private static function pageTablesHtml(PanelPage $page, PanelRequest $request, array $tables): string {
		if($tables===[]){
			return '';
		}
		$html='';
		foreach($tables as $tableData){
			$table=$tableData['table'] ?? null;
			if(!$table instanceof PageTable){
				continue;
			}
			$meta=is_array($tableData['meta'] ?? null) ? $tableData['meta'] : $table->toArray();
			$tableRequest=($tableData['request'] ?? null) instanceof PanelRequest ? $tableData['request'] : $table->requestWithResolvedView($request);
			$records=is_array($tableData['records'] ?? null) ? $tableData['records'] : [];
			$columns=$table->columnsList();
			if($columns===[] && isset($records[0])){
				$record=$records[0];
				$keys=is_array($record) ? array_keys($record) : (is_object($record) ? array_keys(get_object_vars($record)) : []);
				foreach($keys as $key){
					if(is_string($key) && Resource::normalizeName($key)!==''){
						$columns[Resource::normalizeName($key)]=Column::make((string)$key);
					}
				}
			}
			$columns=array_filter($columns, static fn(Column $column): bool => $column->isVisible($tableRequest->operation(), null, $tableRequest, null, $table));
			$head=self::tableHeaderRowsHtml(
				$columns,
				static function(Column $column): string {
					$columnMeta=$column->toArray();
					return self::e((string)($columnMeta['label'] ?? $column->name()));
				},
				false,
				false,
				$tableRequest,
				null,
				$table
			);
			$colspan=max(1, count($columns));
			$body=self::pageTableGroupedBody($table, $tableRequest, $records, $columns, $colspan);
			if($body===null){
				$body='';
				foreach($records as $record){
					$body.='<tr>';
					foreach($columns as $column){
						$body.='<td'.self::alignAttr($column->toArray()).self::columnCellAttributeHtml($column, $record, $tableRequest, null, $table).'>'.self::cellHtml($column, $record).'</td>';
					}
					$body.='</tr>';
				}
			}
			if($body===''){
				$body='<tr class="dp-panel-empty-row"><td colspan="'.$colspan.'" class="dp-panel-empty" data-label="">'.self::pageTableEmptyStateHtml($table, $tableRequest, $meta).'</td></tr>';
			}
			$footer=self::tableFooterRowsHtml($columns, $records, false, false, $tableRequest, null, $table);
			$description=trim((string)($meta['description'] ?? ''));
			$views=self::pageTableViewsHtml($page, $table, $tableRequest);
			$groups=self::pageTableGroupsHtml($page, $table, $tableRequest);
			$search=self::pageTableSearchHtml($page, $table, $tableRequest);
			$filters=self::pageTableFiltersHtml($page, $table, $tableRequest);
			$summaries=is_array($tableData['summaries'] ?? null) ? $tableData['summaries'] : [];
			$tableLabel=(string)($meta['label'] ?? self::panelText('table.table'));
			$html.='<section class="dp-panel-page-table" data-dp-panel-refresh-region="table" data-dp-panel-refresh-key="'.self::e($table->name()).'">'
				.'<header><div><h2>'.self::e($tableLabel).'</h2>'.($description!=='' ? '<p>'.self::e($description).'</p>' : '').'</div><span>'.self::e((string)count($records)).' '.self::e(self::panelText(count($records)===1 ? 'table.row' : 'table.row_plural')).'</span></header>'
				.$views
				.$groups
				.$search
				.$filters
				.self::summaryHtml($summaries)
				.'<div class="dp-panel-table-scroll"><table class="dp-panel-table"><thead>'.$head.'</thead><tbody>'.$body.'</tbody>'.$footer.'</table></div>'
				.'</section>';
		}
		return $html;
	}

	/**
	 * Builds grouped table rows for a page table when a group is active.
	 *
	 * @param PageTable $table Page table definition.
	 * @param PanelRequest $request Table-specific request.
	 * @param array<int, mixed> $records Records being rendered.
	 * @param array<string, Column> $columns Visible table columns.
	 * @param int $colspan Number of columns covered by group header rows.
	 * @return ?string Grouped body HTML, empty string for no records, or `null` when grouping is inactive.
	 */
	private static function pageTableGroupedBody(PageTable $table, PanelRequest $request, array $records, array $columns, int $colspan): ?string {
		$active=$table->activeGroupName($request);
		if($active===''){
			return null;
		}
		$group=$table->groupsList()[$active] ?? null;
		if(!$group instanceof TableGroup){
			return null;
		}
		$buckets=[];
		foreach($records as $record){
			$key=$group->resolveKey($record, null, $request, $table);
			$buckets[$key] ??=[];
			$buckets[$key][]=$record;
		}
		if($buckets===[]){
			return '';
		}
		$meta=$group->toArray();
		$direction=(string)($meta['direction'] ?? 'asc');
		uksort($buckets, static fn(string $left, string $right): int => $direction==='desc' ? strnatcasecmp($right, $left) : strnatcasecmp($left, $right));
		$body='';
		foreach($buckets as $key=>$bucket){
			$label=$group->resolveLabel((string)$key, $bucket, null, $request, $table);
			$description=$group->resolveDescription((string)$key, $bucket, null, $request, $table);
			$meta=$group->toArray();
			$summaries=$group->resolveSummaries((string)$key, $bucket, Resource::make('__page_table_'.$table->name()), $request, $table);
			$actions=$group->resolveActions((string)$key, $bucket, null, $request, $table);
			$groupId='dp-panel-group-'.substr(sha1($table->name().'|'.$group->name().'|'.(string)$key), 0, 12);
			$collapsible=($meta['collapsible'] ?? false)===true;
			$collapsed=$collapsible && ($meta['collapsed'] ?? false)===true;
			$rowCountLabel=number_format(count($bucket)).' '.self::panelText(count($bucket)===1 ? 'table.row' : 'table.row_plural');
			$header=$collapsible
				? '<button class="dp-panel-table-group-heading" type="button" data-dp-panel-group-toggle data-dp-panel-group-target="'.self::e($groupId).'" aria-expanded="'.($collapsed ? 'false' : 'true').'"><span>'.self::e($label).'</span><small>'.self::e($rowCountLabel).'</small>'.self::groupSummaryChipsHtml($summaries).'<i aria-hidden="true"></i></button>'
				: '<div class="dp-panel-table-group-heading"><span>'.self::e($label).'</span><small>'.self::e($rowCountLabel).'</small>'.self::groupSummaryChipsHtml($summaries).'</div>';
			$body.='<tr class="dp-panel-table-group-row'.($collapsible ? ' dp-panel-table-group-row-collapsible' : '').($collapsed ? ' dp-panel-table-group-row-collapsed' : '').'" data-dp-panel-group="'.self::e((string)$key).'" data-dp-panel-group-id="'.self::e($groupId).'"><td colspan="'.$colspan.'">'.$header.($description!=='' ? '<em>'.self::e($description).'</em>' : '').self::groupActionsHtml($actions).'</td></tr>';
			foreach($bucket as $record){
				$body.='<tr data-dp-panel-group-child="'.self::e($groupId).'"'.($collapsed ? ' hidden' : '').'>';
				foreach($columns as $column){
					$body.='<td'.self::alignAttr($column->toArray()).self::columnCellAttributeHtml($column, $record, $request, null, $table).'>'.self::cellHtml($column, $record).'</td>';
				}
				$body.='</tr>';
			}
		}
		return $body;
	}

	/**
	 * Chooses the empty-state message for a page table.
	 *
	 * @param PageTable $table Page table definition.
	 * @param PanelRequest $request Table-specific request.
	 * @param array<string, mixed> $meta Table metadata and optional custom empty message.
	 * @return string Empty-state HTML.
	 */
	private static function pageTableEmptyStateHtml(PageTable $table, PanelRequest $request, array $meta): string {
		$custom=trim((string)($meta['empty_message'] ?? ''));
		if($custom!==''){
			return '<div class="dp-panel-empty-state"><strong>'.self::e($custom).'</strong><span>'.self::e(self::panelText('page.table_empty_filtered_custom_body')).'</span></div>';
		}
		$filterRequest=$table->filterRequest($request);
		$query=trim((string)$filterRequest->query('q', ''));
		$activeView=$table->activeViewName($request);
		$hasFilters=false;
		foreach($table->filtersList() as $filter){
			if($filter instanceof TableFilter && $filter->isVisible($filterRequest, null, $table) && $filter->activeValue($filterRequest)!==null){
				$hasFilters=true;
				break;
			}
		}
		if($query!=='' || $activeView!=='' || $hasFilters){
			return '<div class="dp-panel-empty-state"><strong>'.self::e(self::panelText('page.table_empty_filtered_title')).'</strong><span>'.self::e(self::panelText('page.table_empty_filtered_body')).'</span></div>';
		}
		return '<div class="dp-panel-empty-state"><strong>'.self::e(self::panelText('page.table_empty_ready_title')).'</strong><span>'.self::e(self::panelText('page.table_empty_ready_body')).'</span></div>';
	}

	/**
	 * Renders view tabs for a page table.
	 *
	 * @param PanelPage $page Page that owns the table.
	 * @param PageTable $table Table with configured views.
	 * @param PanelRequest $request Current table request.
	 * @return string View navigation HTML, or empty string when no views exist.
	 */
	private static function pageTableViewsHtml(PanelPage $page, PageTable $table, PanelRequest $request): string {
		$views=$table->viewsList();
		if($views===[]){
			return '';
		}
		$active=$table->activeViewName($request);
		$html=self::pageTableViewLink($page, $table, $request, 'all', self::panelText('common.all'), 'neutral', $active==='');
		foreach($views as $view){
			if(!$view instanceof TableView){
				continue;
			}
			$meta=$view->toArray();
			$badge=$view->resolveBadge([], $table->filterRequest($request), Resource::make('__page_table'));
			$html.=self::pageTableViewLink(
				$page,
				$table,
				$request,
				$view->name(),
				(string)($meta['label'] ?? $view->name()),
				(string)($meta['tone'] ?? 'neutral'),
				$active===$view->name(),
				$badge
			);
		}
		return '<nav class="dp-panel-table-views" aria-label="'.self::e(self::panelText('table.views_aria', ['table'=>(string)($table->toArray()['label'] ?? self::panelText('table.table'))])).'">'.$html.'</nav>';
	}

	/**
	 * Renders grouping controls for a page table.
	 *
	 * @param PanelPage $page Page that owns the table.
	 * @param PageTable $table Table with configured groups.
	 * @param PanelRequest $request Current table request.
	 * @return string Group navigation HTML, or empty string when no groups exist.
	 */
	private static function pageTableGroupsHtml(PanelPage $page, PageTable $table, PanelRequest $request): string {
		$groups=$table->groupsList();
		if($groups===[]){
			return '';
		}
		$active=$table->activeGroupName($request);
		$query=self::pageTablePersistentQuery($table, $request);
		$prefix=$table->filterPrefix();
		$query[$prefix.'group']='none';
		$query['page']=1;
		$html='<a class="dp-panel-table-group'.($active==='' ? ' active' : '').'" href="'.self::e(PanelConfig::url($page->name(), self::filterQueryValues($query))).'"><span>'.self::e(self::panelText('table.ungrouped')).'</span></a>';
		foreach($groups as $group){
			if(!$group instanceof TableGroup){
				continue;
			}
			$meta=$group->toArray();
			$query[$prefix.'group']=$group->name();
			$html.='<a class="dp-panel-table-group'.($active===$group->name() ? ' active' : '').'" href="'.self::e(PanelConfig::url($page->name(), self::filterQueryValues($query))).'"'.($active===$group->name() ? ' aria-current="page"' : '').'><span>'.self::e((string)($meta['label'] ?? $group->name())).'</span></a>';
		}
		return '<nav class="dp-panel-table-groups" aria-label="'.self::e(self::panelText('table.grouping_aria', ['table'=>(string)($table->toArray()['label'] ?? self::panelText('table.table'))])).'">'.$html.'</nav>';
	}

	/**
	 * Builds one page-table view link while resetting table-local filters.
	 *
	 * @param PanelPage $page Page that owns the table.
	 * @param PageTable $table Table whose view is being selected.
	 * @param PanelRequest $request Current table request.
	 * @param string $view View name or `all`.
	 * @param string $label Display label.
	 * @param string $tone Visual tone.
	 * @param bool $active Whether this view is active.
	 * @param mixed $badge Optional badge value.
	 * @return string View link HTML.
	 */
	private static function pageTableViewLink(PanelPage $page, PageTable $table, PanelRequest $request, string $view, string $label, string $tone, bool $active, mixed $badge=null): string {
		$query=self::pageTableViewQuery($table, $request);
		$query[$table->filterPrefix().'view']=$view;
		$query['page']=1;
		$class='dp-panel-table-view dp-panel-table-view-'.self::safeTone($tone).($active ? ' active' : '');
		$badgeHtml=$badge!==null && $badge!=='' ? '<small>'.self::e(self::stringValue($badge)).'</small>' : '';
		return '<a class="'.$class.'" href="'.self::e(PanelConfig::url($page->name(), self::filterQueryValues($query))).'"'.($active ? ' aria-current="page"' : '').' title="'.self::e(self::panelText('table.view_title', ['view'=>$label])).'"><i class="dp-panel-table-view-dot" aria-hidden="true"></i><span>'.self::e($label).'</span>'.$badgeHtml.'</a>';
	}

	/**
	 * Builds the base query used when switching page-table views.
	 *
	 * @param PageTable $table Table whose view state is changing.
	 * @param PanelRequest $request Current request.
	 * @return array<string, mixed> Query with routing fields, search, view, and filters removed.
	 */
	private static function pageTableViewQuery(PageTable $table, PanelRequest $request): array {
		$query=self::queryWithoutPage($request);
		unset($query['resource'], $query['operation'], $query['record'], $query['relation'], $query['action']);
		$prefix=$table->filterPrefix();
		unset($query[$prefix.'view'], $query[$prefix.'q'], $query['q']);
		foreach($table->filtersList() as $filter){
			$name=$filter->name();
			unset($query[$name], $query[$name.'_from'], $query[$name.'_to'], $query[$prefix.$name], $query[$prefix.$name.'_from'], $query[$prefix.$name.'_to']);
		}
		return $query;
	}

	/**
	 * Renders the search form for a page table.
	 *
	 * @param PanelPage $page Page that owns the table.
	 * @param PageTable $table Table being searched.
	 * @param PanelRequest $request Current table request.
	 * @return string Search form HTML with persistent hidden query fields.
	 */
	private static function pageTableSearchHtml(PanelPage $page, PageTable $table, PanelRequest $request): string {
		$query=trim((string)$table->filterRequest($request)->query('q', ''));
		$prefix=$table->filterPrefix();
		$hidden=self::pageTableHiddenInputs($table, $request, [$prefix.'q', 'q']);
		$clearQuery=self::pageTablePersistentQuery($table, $request, [], true);
		return '<form class="dp-panel-search dp-panel-page-table-search" method="get" action="'.self::e(PanelConfig::url($page->name())).'">'
			.$hidden
			.'<input type="search" name="'.self::e($prefix.'q').'" value="'.self::e($query).'" placeholder="'.self::e(self::panelText('table.search_table_placeholder', ['table'=>(string)($table->toArray()['label'] ?? 'table')])).'" aria-label="'.self::e(self::panelText('table.search_table_placeholder', ['table'=>(string)($table->toArray()['label'] ?? 'table')])).'" data-dp-panel-search-input>'
			.'<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('common.search')).'</button>'
			.($query!=='' ? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::url($page->name(), $clearQuery)).'">'.self::e(self::panelText('common.clear')).'</a>' : '')
			.'</form>';
	}

	/**
	 * Renders visible page-table filters and active filter chips.
	 *
	 * @param PanelPage $page Page that owns the table.
	 * @param PageTable $table Table with filter definitions.
	 * @param PanelRequest $request Current panel request.
	 * @return string Filter form and chips HTML, or empty string when no filters render.
	 */
	private static function pageTableFiltersHtml(PanelPage $page, PageTable $table, PanelRequest $request): string {
		$filters=$table->filtersList();
		if($filters===[]){
			return '';
		}
		$filterRequest=$table->filterRequest($request);
		$prefix=$table->filterPrefix();
		$controls='';
		foreach($filters as $filter){
			if($filter instanceof TableFilter && $filter->isVisible($filterRequest, null, $table)){
				$controls.=self::filterControl($filter, $filterRequest, $prefix);
			}
		}
		if($controls===''){
			return '';
		}
		return '<form class="dp-panel-filters dp-panel-page-table-filters" method="get" action="'.self::e(PanelConfig::url($page->name())).'">'
			.self::pageTableHiddenInputs($table, $request)
			.$controls
			.'<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('client.filter')).'</button>'
			.'<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(self::pageTableFilterResetUrl($page, $table, $request)).'">'.self::e(self::panelText('common.reset')).'</a>'
			.'</form>'
			.self::pageTableFilterChipsHtml($page, $table, $filterRequest, $request);
	}

	/**
	 * Renders active filter chips for a page table.
	 *
	 * @param PanelPage $page Page that owns the table.
	 * @param PageTable $table Table with active filter definitions.
	 * @param PanelRequest $filterRequest Table-prefixed request used by filters.
	 * @param PanelRequest $request Original panel request used for clear URLs.
	 * @return string Active filter chip HTML, or empty string when no filters are active.
	 */
	private static function pageTableFilterChipsHtml(PanelPage $page, PageTable $table, PanelRequest $filterRequest, PanelRequest $request): string {
		$chips='';
		foreach($table->filtersList() as $filter){
			if(!$filter instanceof TableFilter || !$filter->isVisible($filterRequest, null, $table)){
				continue;
			}
			$options=$filter->optionsFor($filterRequest);
			$value=$filter->activeValue($filterRequest, $options);
			if($value===null){
				continue;
			}
			$meta=$filter->toArray();
			$meta['options']=$options;
			$chips.='<a class="dp-panel-filter-chip" href="'.self::e(self::pageTableFilterClearUrl($page, $table, $request, $filter->name())).'">'
				.'<span>'.self::e((string)$meta['label']).'</span>'
				.'<strong>'.self::e(self::filterValueLabel($filter, $meta, $value)).'</strong>'
				.'<small>'.self::e(self::panelText('common.clear')).'</small>'
				.'</a>';
		}
		return $chips!=='' ? '<div class="dp-panel-filter-chips" aria-label="'.self::e(self::panelText('filter.active_filters')).'">'.$chips.'</div>' : '';
	}

	/**
	 * Builds hidden inputs that preserve page-table query state across GET forms.
	 *
	 * @param PageTable $table Table whose prefixed query values should persist.
	 * @param PanelRequest $request Current request.
	 * @param array<int, string> $exclude Query keys to omit from the hidden input set.
	 * @return string Hidden input HTML for scalar query values.
	 */
	private static function pageTableHiddenInputs(PageTable $table, PanelRequest $request, array $exclude=[]): string {
		$query=self::pageTablePersistentQuery($table, $request);
		foreach($exclude as $key){
			unset($query[(string)$key]);
		}
		$html='';
		foreach($query as $key=>$value){
			if(is_scalar($value)){
				$html.='<input type="hidden" name="'.self::e((string)$key).'" value="'.self::e((string)$value).'">';
			}
		}
		return $html;
	}

	/**
	 * Builds a URL that clears one page-table filter while preserving other table state.
	 *
	 * @param PanelPage $page Page that owns the table.
	 * @param PageTable $table Table whose filter should be cleared.
	 * @param PanelRequest $request Current request.
	 * @param string $filterName Filter name to clear.
	 * @return string Panel URL with the selected filter removed.
	 */
	private static function pageTableFilterClearUrl(PanelPage $page, PageTable $table, PanelRequest $request, string $filterName): string {
		$query=self::pageTablePersistentQuery($table, $request, [$filterName]);
		return PanelConfig::url($page->name(), $query);
	}

	/**
	 * Builds a URL that clears all filters for one page table.
	 *
	 * @param PanelPage $page Page that owns the table.
	 * @param PageTable $table Table whose filters should reset.
	 * @param PanelRequest $request Current request.
	 * @return string Panel URL with all page-table filters removed.
	 */
	private static function pageTableFilterResetUrl(PanelPage $page, PageTable $table, PanelRequest $request): string {
		return PanelConfig::url($page->name(), self::pageTablePersistentQuery($table, $request, array_keys($table->filtersList())));
	}

	/**
	 * Produces the request query that should survive page-table controls.
	 *
	 * Routing fields are stripped, global search is removed, and selected
	 * table-prefixed filters/search values may be cleared for reset links.
	 *
	 * @param PageTable $table Table defining the filter prefix and filters.
	 * @param PanelRequest $request Current panel request.
	 * @param array<int, string> $clearFilters Filter names to remove.
	 * @param bool $clearSearch Whether to remove table-local search.
	 * @return array<string, mixed> Filtered query values safe for panel URLs.
	 */
	private static function pageTablePersistentQuery(PageTable $table, PanelRequest $request, array $clearFilters=[], bool $clearSearch=false): array {
		$query=self::queryWithoutPage($request);
		unset($query['resource'], $query['operation'], $query['record'], $query['relation'], $query['action']);
		$prefix=$table->filterPrefix();
		unset($query['q']);
		if($clearSearch){
			unset($query[$prefix.'q']);
		}
		foreach($table->filtersList() as $filter){
			$name=$filter->name();
			unset($query[$name], $query[$name.'_from'], $query[$name.'_to']);
			if($clearFilters===[] || in_array($name, $clearFilters, true)){
				unset($query[$prefix.$name], $query[$prefix.$name.'_from'], $query[$prefix.$name.'_to']);
			}
		}
		return self::filterQueryValues($query);
	}

	/**
	 * Renders the resource index table with views, filters, summaries, and pagination.
	*
	 * When records are not pre-paginated, the renderer applies the active table
	 * view, filters, search, sort, summaries, density, column visibility, grouping,
	 * bulk actions, hooks, and pagination in that order. Pre-paginated callers are
	 * trusted to provide page records and total count while still receiving table
	 * state, summaries, command bars, and result metadata.
	*
	 * @param Resource $resource Resource definition that supplies table, columns, actions, and policy.
	 * @param PanelRequest $request Current panel request with query, pagination, density, and user context.
	 * @param array<int, mixed> $records Source records or current page records when already paginated.
	 * @param ?int $totalRecords Total record count; inferred from records when omitted.
	 * @param bool $alreadyPaginated Whether filtering and pagination were already applied by the caller.
	 * @return PanelPageResult Resource index page with table state and `kind=index` metadata.
	 */
	public static function index(Resource $resource, PanelRequest $request, array $records=[], ?int $totalRecords=null, bool $alreadyPaginated=false): PanelPageResult {
		$request=$resource->requestWithResolvedView($request);
		$activeView=self::activeTableViewName($resource, $request);
		$viewCounts=$alreadyPaginated ? [] : self::tableViewCounts($resource, $request, $records);
		if(!$alreadyPaginated){
			$records=self::applyTableView($records, $resource, $request, $activeView);
			$records=self::applyFilters($records, $resource, $request);
			$records=self::filterRecords($records, $resource, $request);
			$records=self::sortRecords($records, $resource, $request);
		}
		$summaryRecords=$records;
		$summaries=self::summaryData($resource, $request, $summaryRecords);
		$totalRecords ??= count($records);
		$page=$request->page();
		$perPage=$request->perPage($resource->resourceTable()->defaultPerPage());
		$density=self::density($request);
		if(!$alreadyPaginated){
			$records=array_slice($records, ($page-1)*$perPage, $perPage);
		}
		PanelTrace::record('page.index', [
			'resource'=>$resource,
			'request'=>$request,
			'record_count'=>count($records),
			'total_count'=>$totalRecords,
			'page'=>$page,
			'per_page'=>$perPage,
			'density'=>$density,
			'summary_count'=>count($summaries),
			'active_view'=>$activeView,
		]);
		$tableState=$resource->tableState($request, $summaryRecords, $alreadyPaginated, self::tablePreferences($request));
		$allColumns=$tableState->allColumns();
		$columns=$tableState->visibleColumns();
		PanelTrace::record('table.state', [
			'resource'=>$resource,
			'request'=>$request,
			'state'=>$tableState,
		]);
		$bulkFormId='dp-panel-bulk-'.$resource->name();
		$bulkActions=self::bulkActions($resource, $request, $bulkFormId);
		$hasBulkActions=$bulkActions!=='';
		$head=self::tableHeaderRowsHtml(
			$columns,
			static fn(Column $column): string => self::columnHeader($resource, $request, $column),
			$hasBulkActions,
			true,
			$request,
			$resource,
			$resource->resourceTable()
		);
		$body=self::groupedTableBody($records, $resource, $request, $columns, $hasBulkActions, $bulkFormId);
		if($body===null){
			$body='';
			foreach($records as $record){
				$recordKey=$resource->recordKey($record);
				$recordTitle=$resource->recordTitle($record);
				$rowLabel=$recordTitle!=='' ? $recordTitle : ($recordKey!=='' ? $recordKey : self::panelText('data.record'));
				$body.='<tr tabindex="-1" data-dp-panel-row data-dp-panel-record-key="'.self::e($recordKey).'" aria-label="'.self::e($rowLabel).'"'.self::tableRowAttributeHtml($resource->resourceTable(), $record, $request, $resource).self::tableRowClickAttributeHtml($resource->resourceTable(), $record, $request, $resource, $rowLabel).self::tableRowPreviewAttributeHtml($resource->resourceTable(), $record, $request, $resource).'>';
				if($hasBulkActions){
					$body.='<td class="dp-panel-select" data-label="'.self::e(self::panelText('table.select_all_visible')).'">'.self::recordCheckbox($resource, $record, $bulkFormId).'</td>';
				}
				foreach($columns as $column){
					$meta=$column->toArray();
					$body.='<td'.self::alignAttr($meta).self::tableDataLabelAttr($meta, $column->name()).self::columnCellAttributeHtml($column, $record, $request, $resource, $resource->resourceTable()).'>'.self::editableCellHtml($column, $record, $request, $resource).'</td>';
				}
				$body.='<td class="dp-panel-actions" data-label="'.self::e(self::panelText('client.action')).'">'.self::rowActions($resource, $record, false, $request).'</td>';
				$body.='</tr>';
			}
		}
		$colspan=max(1, count($columns)+1+($hasBulkActions ? 1 : 0));
		if($body===''){
			$body='<tr class="dp-panel-empty-row"><td colspan="'.$colspan.'" class="dp-panel-empty" data-label="">'.self::emptyStateHtml($resource, $request).'</td></tr>';
		}
		$footer=self::tableFooterRowsHtml($columns, $summaryRecords, $hasBulkActions, true, $request, $resource, $resource->resourceTable());
		$totalPages=max(1, (int)ceil($totalRecords / max(1, $perPage)));
		$headerControls=PanelConfig::tableHeaderControlsMode()!=='none' ? self::resourceTableHeaderControlsHtml($resource, $request) : '';
		$metaControls=PanelConfig::commandbarBottomMode()==='meta' ? self::resourceCommandBarBottomHtml($resource, $request, $allColumns, 'dp-panel-table-meta-controls') : '';
		$table='<div class="dp-panel-table-shell" data-dp-panel-table-shell data-dp-panel-refresh-region="table" data-dp-panel-refresh-key="'.self::e($resource->name()).'">'
			.'<div class="dp-panel-table-meta'.($headerControls!=='' ? ' dp-panel-table-meta-with-header-controls' : '').'">'
			.'<div class="dp-panel-table-counts"><span><strong>'.number_format($totalRecords).'</strong> '.self::e($totalRecords===1 ? self::panelText('common.record') : self::panelText('common.records')).'</span><span>'.self::e(self::panelText('table.page_count', ['page'=>number_format($page), 'pages'=>number_format($totalPages)])).'</span></div>'
			.$headerControls
			.$metaControls
			.'</div>'
				.'<div class="dp-panel-table-scroll" tabindex="0" role="region" aria-label="'.self::e(self::panelText('table.aria_suffix', ['resource'=>(string)$resource->pluralLabel()])).'">'
			.'<table class="dp-panel-table dp-panel-table-'.$density.'"><thead>'.$head.'</thead><tbody>'.$body.'</tbody>'.$footer.'</table>'
			.'</div></div>';
		if($hasBulkActions){
			$table='<form id="'.self::e($bulkFormId).'" class="dp-panel-bulk-form" method="post">'.self::csrfInput().self::returnInput($resource, $request).'</form>'.$table.'<div class="dp-panel-bulk-bar dp-panel-bulk-bar-empty" data-dp-panel-bulk-bar hidden aria-hidden="true" aria-live="polite"><span class="dp-panel-bulk-status" data-dp-panel-bulk-status><strong data-dp-panel-selected-count>0</strong><span data-dp-panel-selected-label>'.self::e(self::panelText('table.selected')).'</span><small>'.self::e(self::panelText('table.visible_records')).'</small></span><div class="dp-panel-bulk-actions">'.$bulkActions.'<button class="dp-panel-action dp-panel-action-neutral" type="button" data-dp-panel-clear-selection>'.self::e(self::panelText('common.clear')).'</button></div></div>';
		}
		$content=self::resourceCommandBarHtml($resource, $request, $allColumns)
			.self::tablePulseHtml($resource, $request, $totalRecords, count($records), $page, $perPage, $activeView, $hasBulkActions, count($summaries), $alreadyPaginated)
			.self::tableViewsHtml($resource, $request, $activeView, $viewCounts)
			.self::summaryHtml($summaries, $alreadyPaginated)
			.$table
			.self::paginationHtml($resource, $request, $totalRecords, $page, $perPage, $totalPages);
		$hookContext=[
			'kind'=>'index',
			'resource'=>$resource,
			'request'=>$request,
			'records'=>$records,
			'total_count'=>$totalRecords,
			'page'=>$page,
			'per_page'=>$perPage,
			'active_view'=>$activeView,
			'visible_columns'=>array_keys($columns),
		];
		$content=PanelConfig::renderHook('resource.index.before', $hookContext).$content.PanelConfig::renderHook('resource.index.after', $hookContext);
		return self::page((string)$resource->pluralLabel(), $content, [
			'kind'=>'index',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'record_count'=>count($records),
			'total_count'=>$totalRecords,
			'page'=>$page,
			'per_page'=>$perPage,
			'density'=>$density,
			'visible_columns'=>array_keys($columns),
			'active_view'=>$activeView,
			'views'=>array_map(static fn(TableView $view): array => $view->toArray(), array_values($resource->tableViewsList())),
			'summaries'=>$summaries,
		]);
	}

	/**
	 * Renders a transition-aware resource status board.
	*
	 * The board is available only when the resource allows board access, supports
	 * transitions, and defines status views. Records are distributed into view
	 * columns by the first matching status view, with unmatched records collected
	 * into an `_other` column. Cards include row actions and transition metadata
	 * for client-side drag/drop when the resource permits moves.
	*
	 * @param Resource $resource Resource definition that supplies transition views and policies.
	 * @param PanelRequest $request Current panel request.
	 * @param array<int, mixed> $records Source records or already-filtered board records.
	 * @param ?int $totalRecords Total record count; inferred from records when omitted.
	 * @param bool $alreadyPaginated Whether the caller already applied filters/search/sort.
	 * @return PanelPageResult Board page or a forbidden/unavailable panel response.
	 */
	public static function statusBoard(Resource $resource, PanelRequest $request, array $records=[], ?int $totalRecords=null, bool $alreadyPaginated=false): PanelPageResult {
		if($resource->can('board', null, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$views=self::statusBoardViews($resource);
		if($resource->canTransition()===false || $views===[]){
			return self::panelEmptyPage('table.board_unavailable', 'transition.unavailable_body', [
				'kind'=>'board_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(!$alreadyPaginated){
			$records=self::applyFilters($records, $resource, $request);
			$records=self::filterRecords($records, $resource, $request);
			$records=self::sortRecords($records, $resource, $request);
		}
		$totalRecords ??=count($records);
		$columns=[];
		foreach($views as $name=>$view){
			$meta=$view->toArray();
			$columns[$name]=[
				'name'=>$name,
				'label'=>(string)($meta['label'] ?? self::panelText('field.'.Resource::normalizeName($name), [], ucwords(str_replace(['_', '-', '.'], ' ', $name)))),
				'tone'=>self::safeTone((string)($meta['tone'] ?? 'neutral')),
				'records'=>[],
			];
		}
		$otherRecords=[];
		foreach($records as $record){
			$matched=false;
			foreach($views as $name=>$view){
				if($view->matches($record, $request, $resource)){
					$columns[$name]['records'][]=$record;
					$matched=true;
					break;
				}
			}
			if(!$matched){
				$otherRecords[]=$record;
			}
		}
		if($otherRecords!==[]){
			$columns['_other']=[
				'name'=>'_other',
				'label'=>self::panelText('table.other'),
				'tone'=>'neutral',
				'records'=>$otherRecords,
			];
		}
		$returnUrl=self::boardReturnUrl($resource, $request);
		$board='';
		$columnData=[];
		$movableCount=0;
		foreach($columns as $column){
			$cards='';
			$recordKeys=[];
			foreach($column['records'] as $record){
				$key=$resource->recordKey($record);
				$title=$resource->recordTitle($record);
				$subtitle=$resource->recordSubtitle($record);
				$recordKeys[]=$key;
				$transitionTargets=self::boardTransitionTargets($resource, $record, $request);
				if($transitionTargets!==[]){
					$movableCount++;
				}
				$dragAttrs=$transitionTargets!==[]
					? ' draggable="true" data-dp-panel-board-card data-dp-panel-board-transitions="'.self::e(json_encode($transitionTargets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}').'"'
					: '';
				$actions=self::rowActions($resource, $record, false, $request, $returnUrl);
				$viewLabel=$title!=='' ? $title : ($key!=='' ? $key : self::panelText('data.record'));
				$cards.='<article class="dp-panel-board-card"'.$dragAttrs.'>'
					.'<a class="dp-panel-board-title" href="'.self::e($resource->recordUrl($record, 'show')).'"'.self::resourceModalAttributes('view', self::panelText('data.view_record_title', ['record'=>$viewLabel]), self::panelText('page.board_view_record_body'), 'lg', 'dialog', true).'>'.self::e($viewLabel).'</a>'
					.($subtitle!=='' ? '<span>'.self::e($subtitle).'</span>' : '')
					.($key!=='' ? '<small>'.self::e($key).'</small>' : '')
					.($actions!=='' ? '<div class="dp-panel-actions">'.$actions.'</div>' : '')
					.'</article>';
			}
			if($cards===''){
				$cards='<p class="dp-panel-board-empty">'.self::e(self::panelText('page.board_no_records')).'</p>';
			}
			$count=count($column['records']);
			$board.='<section class="dp-panel-board-column dp-panel-board-column-'.$column['tone'].'" data-dp-panel-board-column data-dp-panel-board-status="'.self::e((string)$column['name']).'">'
				.'<header><div><strong>'.self::e($column['label']).'</strong><small>'.self::e($column['name']==='_other' ? self::panelText('page.board_unmatched_status') : $column['name']).'</small></div><span>'.self::e((string)$count).'</span></header>'
				.'<div class="dp-panel-board-list">'.$cards.'</div>'
				.'</section>';
			$columnData[]=[
				'name'=>$column['name'],
				'label'=>$column['label'],
				'tone'=>$column['tone'],
				'count'=>$count,
				'record_keys'=>$recordKeys,
			];
		}
		PanelTrace::record('page.board', [
			'resource'=>$resource,
			'request'=>$request,
			'record_count'=>count($records),
			'total_count'=>$totalRecords,
			'column_count'=>count($columns),
		]);
		$content='<section class="dp-panel-commandbar dp-panel-commandbar-board" aria-label="'.self::e((string)$resource->pluralLabel()).' board controls" data-dp-panel-commandbar>'
			.'<div class="dp-panel-commandbar-top">'
			.'<div class="dp-panel-commandbar-search" data-dp-panel-control-group="search">'.self::boardSearchForm($resource, $request).'</div>'
			.'<div class="dp-panel-commandbar-primary" data-dp-panel-control-group="primary"><a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(self::tableReturnUrl($resource, $request)).'">'.self::actionTextHtml(self::panelText('table.view_table'), 'table').'</a>'.($resource->can('create', null, $request->user())!==false ? '<a class="dp-panel-button dp-panel-commandbar-create" href="'.self::e(PanelConfig::resourceUrl($resource, 'create')).'"'.self::resourceModalAttributes('create', self::panelText('table.create_resource_title', ['resource'=>$resource->label()]), self::panelText('table.create_resource_body'), 'xl', 'slide_over', true).'>'.self::actionTextHtml(self::panelText('table.create'), 'plus').'</a>' : '').'</div>'
			.'</div>'
			.'</section>'
			.self::boardPulseHtml($resource, $request, $columnData, $totalRecords, $movableCount, $alreadyPaginated)
			.'<section class="dp-panel-board" data-dp-panel-packed-grid="masonry" data-dp-panel-packed-grid-min="280">'.$board.'</section>';
		return self::page((string)$resource->label().' Board', $content, [
			'kind'=>'board',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'record_count'=>count($records),
			'total_count'=>$totalRecords,
			'columns'=>$columnData,
		]);
	}

	/**
	 * Renders a create or edit form for a resource record.
	*
	 * The renderer enforces create/update permissions, hydrates form state when a
	 * caller has not supplied one, runs fill lifecycle hooks, resolves live field
	 * state, groups fields into sections, keeps dependency-controlled hidden fields
	 * available for client logic, emits CSRF/return inputs, and wraps the form with
	 * configured render hooks.
	*
	 * @param Resource $resource Resource definition that owns the form.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Existing record for edit mode, or `null` for create mode.
	 * @param string $mode `create` or `edit`; other values are treated as create-like for policy.
	 * @param ?PanelFormState $state Existing state after submission/validation, or `null` to hydrate.
	 * @param int $status HTTP status for the rendered form, commonly `200` or `422`.
	 * @return PanelPageResult Form page with serialized form state metadata.
	 */
	public static function form(
		Resource $resource,
		PanelRequest $request,
		mixed $record=null,
		string $mode='create',
		?PanelFormState $state=null,
		int $status=200
	): PanelPageResult {
		if($mode==='edit' && $resource->can('update', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		if($mode!=='edit' && $resource->can('create', null, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		if($state===null){
			$resource->runBeforeFill($record, $mode, $request);
			$state=$resource->form()->hydrate($record, $request);
			$state=$resource->mutateFormStateBeforeFill($state, $record, $mode, $request);
			[$resolvedValues]=$resource->form()->resolveLiveState($state->values(), $record, $request, $mode);
			if($resolvedValues!==$state->values()){
				$state=PanelFormState::make($resolvedValues, $state->errors(), array_replace($state->meta(), [
					'live_state_resolved'=>true,
				]));
			}
			$state=$resource->runAfterFill($state, $record, $mode, $request);
		}
		PanelTrace::record('page.form', [
			'resource'=>$resource,
			'request'=>$request,
			'mode'=>$mode,
			'state'=>$state,
		]);
		$formMeta=$resource->form()->toArray();
		$sectionMeta=self::sectionMetaByName($formMeta['sections'] ?? []);
		$hiddenFields='';
		$sections=[];
		$formStats=[
			'fields'=>0,
			'visible_fields'=>0,
			'hidden_fields'=>0,
			'required_fields'=>0,
			'conditional_fields'=>0,
			'dynamic_fields'=>0,
		];
		foreach($resource->form()->fieldsList() as $field){
			$meta=self::fieldMeta($field, $record, $request, $mode);
			$formStats['fields']++;
			if(($meta['required'] ?? false)===true || ($meta['required_when'] ?? [])!==[] || ($meta['required_unless'] ?? [])!==[]){
				$formStats['required_fields']++;
			}
			if(($meta['conditional'] ?? false)===true || self::fieldDependencyControlled($meta)){
				$formStats['conditional_fields']++;
			}
			if(($meta['dynamic_options'] ?? false)===true || ($meta['hydrates'] ?? false)===true || ($meta['dehydrates'] ?? false)===true || ($meta['validates'] ?? false)===true){
				$formStats['dynamic_fields']++;
			}
			$name=(string)$meta['name'];
			$fieldVisible=$field->isVisible($mode, $record, $request);
			if($fieldVisible===false && !self::fieldDependencyControlled($meta)){
				$formStats['hidden_fields']++;
				continue;
			}
			$value=$state->value($name, self::recordValue($record, $name, $meta['default'] ?? ''));
			if(($meta['hidden'] ?? false)===true){
				$formStats['hidden_fields']++;
				$hiddenFields.=self::fieldControl($name, $meta, $value, true);
				continue;
			}
			$formStats['visible_fields']++;
			$section=trim((string)($meta['meta']['section'] ?? ''));
			$section=$section!=='' ? $section : self::panelText('record.details');
			$sections[$section] ??=[];
			$sections[$section][]=self::fieldHtml($name, $meta, $value, $state->fieldErrors($name), !$fieldVisible);
		}
		$formStats['sections']=count($sections);
		$formStats['error_fields']=count($state->errors());
		$formStats['issue_count']=array_sum(array_map('count', $state->errors()));
		$fields=self::formSectionsHtml($sections, self::formColumnsDefinition($formMeta), $sectionMeta);
		$action=PanelConfig::resourceUrl($resource, $mode==='edit' ? 'update/'.$request->recordKey() : 'store');
		$summary='';
		if($state->invalid()){
			$count=$formStats['issue_count'];
			$errorMessages=[];
			foreach($state->errors() as $messages){
				foreach((array)$messages as $message){
					$message=trim((string)$message);
					if($message!=='' && !in_array($message, $errorMessages, true)){
						$errorMessages[]=$message;
					}
				}
			}
			$errorList=$errorMessages!==[] ? '<ul>'.implode('', array_map(static fn(string $message): string => '<li>'.self::e($message).'</li>', array_slice($errorMessages, 0, 4))).'</ul>' : '';
			$summary='<div class="dp-panel-alert">'.self::e(self::panelText('action.field_issue_summary', ['count'=>$count, 'issue'=>self::panelText($count===1 ? 'action.issue' : 'action.issues')])).$errorList.'</div>';
		}
		$reactiveUrl=PanelConfig::resourceUrl($resource, $mode==='edit' ? 'edit/'.$request->recordKey() : 'create');
		$content='<form class="dp-panel-form" method="post"'.self::formEncodingAttr($formMeta['fields'] ?? []).' action="'.self::e($action).'" data-dp-panel-reactive-url="'.self::e($reactiveUrl).'"'.self::accessibilityDefaultAttrs(is_array($formMeta['meta'] ?? null) ? $formMeta['meta'] : []).'>'
			.self::csrfInput()
			.$hiddenFields
			.self::returnInput($resource, $request)
			.self::formPulseHtml($resource, $mode, $formStats, $state)
			.$summary
			.$fields
			.'<div class="dp-panel-toolbar"><button class="dp-panel-button" type="submit">'.self::e(self::panelText('common.save')).'</button></div>'
			.'</form>';
		$content=PanelConfig::renderHook('resource.form.before', [
			'kind'=>$mode,
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
			'form_state'=>$state,
			'form_stats'=>$formStats,
		]).$content.PanelConfig::renderHook('resource.form.after', [
			'kind'=>$mode,
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
			'form_state'=>$state,
			'form_stats'=>$formStats,
		]);
		return self::page(($mode==='edit' ? self::panelText('common.edit') : self::panelText('table.create')).' '.(string)$resource->label(), $content, [
			'kind'=>$mode,
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'form_state'=>$state->jsonSerialize(),
		], $status);
	}

	/**
	 * Renders the optional form pulse guidance region.
	 *
	 * @param Resource $resource Resource whose form is being rendered.
	 * @param string $mode Current form mode.
	 * @param array<string, int> $stats Form field and validation counters.
	 * @param PanelFormState $state Current form state.
	 * @return string Pulse HTML, currently empty until the form pulse UI is enabled.
	 */
	private static function formPulseHtml(Resource $resource, string $mode, array $stats, PanelFormState $state): string {
		return '';
	}

	/**
	 * Selects the guidance copy that best matches the current form state.
	 *
	 * @param string $mode Current form mode.
	 * @param array<string, int> $stats Form field and validation counters.
	 * @param PanelFormState $state Current form state.
	 * @return array{0:string,1:string} Recommendation title and body text.
	 */
	private static function formPulseRecommendation(string $mode, array $stats, PanelFormState $state): array {
		if($state->invalid()){
			return [self::panelText('form.fix_highlighted_title'), self::panelText('form.fix_highlighted_body')];
		}
		if((int)($stats['conditional_fields'] ?? 0)>0){
			return [self::panelText('form.reactive_title'), self::panelText('form.reactive_body')];
		}
		if((int)($stats['dynamic_fields'] ?? 0)>0){
			return [self::panelText('form.dynamic_title'), self::panelText('form.dynamic_body')];
		}
		if($mode==='edit'){
			return [self::panelText('form.review_save_title'), self::panelText('form.review_save_body')];
		}
		return [self::panelText('form.create_essentials_title'), self::panelText('form.create_essentials_body')];
	}

	/**
	 * Renders a resource detail page with infolist sections and related panels.
	*
	 * The page enforces view permission, records identity metadata, renders the
	 * resource infolist, and appends optional record modules such as alerts,
	 * insights, approvals, tasks, activity, changes, messages, notes, relations,
	 * line items, totals, payments, shipments, and attachments when the resource
	 * exposes data for them.
	*
	 * @param Resource $resource Resource definition that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record being displayed, or `null` for an empty identity shell.
	 * @return PanelPageResult Detail page with record identity and infolist state metadata.
	 */
	public static function show(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		if($resource->can('view', $record, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$recordTitle=$record!==null ? $resource->recordTitle($record) : (string)$resource->label();
		$recordSubtitle=$record!==null ? $resource->recordSubtitle($record) : '';
		PanelTrace::record('page.show', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
		]);
		$infolistState=$resource->infolistState($record, $request);
		$infolistMeta=$infolistState->schema();
		$sectionMeta=self::sectionMetaByName($infolistMeta['sections'] ?? []);
		$sections=[];
		foreach($infolistState->visibleSections() as $section=>$entries){
			$sections[$section] ??=[];
			foreach($entries as $entry){
				$sections[$section][]=self::showEntryHtml($entry);
			}
		}
		$show=self::showSectionsHtml($sections, self::formColumnsDefinition($infolistMeta), $sectionMeta);
		$alerts=$record!==null ? self::alertsHtml($resource, $request, $record) : '';
		$insights=$record!==null ? self::insightsHtml($resource, $request, $record) : '';
		$links=$record!==null ? self::linksHtml($resource, $request, $record) : '';
		$contacts=$record!==null ? self::contactsHtml($resource, $request, $record) : '';
		$locations=$record!==null ? self::locationsHtml($resource, $request, $record) : '';
		$approvals=$record!==null ? self::approvalsHtml($resource, $request, $record) : '';
		$tags=$record!==null ? self::tagsHtml($resource, $request, $record) : '';
		$tasks=$record!==null ? self::tasksHtml($resource, $request, $record) : '';
		$activity=$record!==null ? self::activityHtml($resource, $request, $record) : '';
		$changes=$record!==null ? self::changesHtml($resource, $request, $record) : '';
		$messages=$record!==null ? self::messagesHtml($resource, $request, $record) : '';
		$notes=$record!==null ? self::notesHtml($resource, $request, $record) : '';
		$lineItems=$record!==null ? self::itemsHtml($resource, $request, $record) : '';
		$totals=$record!==null ? self::totalsHtml($resource, $request, $record) : '';
		$payments=$record!==null ? self::paymentsHtml($resource, $request, $record) : '';
		$shipments=$record!==null ? self::shipmentsHtml($resource, $request, $record) : '';
		$attachments=$record!==null ? self::attachmentsHtml($resource, $request, $record) : '';
		$relations=self::relationsHtml($resource, $request, $record);
		$headingActions=self::rowActions($resource, $record, true, $request);
		$actionFit=method_exists($resource, 'actionFitMode') ? $resource->actionFitMode() : 'stretch';
		$identity='<section class="dp-panel-record-heading"><div class="dp-panel-record-identity"><div class="dp-panel-record-title"><span>'.self::e((string)$resource->label()).'</span><h2>'.self::e($recordTitle).'</h2>'.($recordSubtitle!=='' ? '<p>'.self::e($recordSubtitle).'</p>' : '').'</div></div>'.($headingActions!=='' ? '<div class="dp-panel-record-actions" data-dp-panel-action-fit="'.self::e($actionFit).'" role="toolbar" aria-label="'.self::e((string)$resource->label()).' actions">'.$headingActions.'</div>' : '').'</section>';
		$pulse=self::recordPulseHtml($resource, $request, $record, [
			'alerts'=>$alerts!=='',
			'approvals'=>$approvals!=='',
			'tasks'=>$tasks!=='',
			'activity'=>$activity!=='',
			'changes'=>$changes!=='',
			'messages'=>$messages!=='',
			'notes'=>$notes!=='',
			'relations'=>$relations!=='',
		]);
		$content=$identity.$pulse.$alerts.$insights.$links.$contacts.$locations.$approvals.$tags.$show.$tasks.$activity.$changes.$lineItems.$totals.$payments.$shipments.$attachments.$messages.$notes.$relations;
		$content=PanelConfig::renderHook('resource.show.before', [
			'kind'=>'show',
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
			'record_title'=>$recordTitle,
		]).$content.PanelConfig::renderHook('resource.show.after', [
			'kind'=>'show',
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
			'record_title'=>$recordTitle,
		]);
		return self::page($recordTitle, $content, [
			'kind'=>'show',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'record_identity'=>[
				'key'=>$record!==null ? $resource->recordKey($record) : '',
				'title'=>$recordTitle,
				'subtitle'=>$recordSubtitle,
				'url'=>$record!==null ? $resource->recordUrl($record) : '',
			],
			'infolist_state'=>$infolistState->jsonSerialize(),
		]);
	}

	/**
	 * Renders the primary command bar for a resource index page.
	 *
	 * @param Resource $resource Resource being listed.
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, Column> $allColumns All table columns for visibility controls.
	 * @return string Command bar HTML, or an empty string when no controls are available.
	 */
	private static function resourceCommandBarHtml(Resource $resource, PanelRequest $request, array $allColumns): string {
		if(PanelConfig::tableHeaderControlsMode()!=='none'){
			$actions=self::resourceActions($resource, $request);
			if($actions===''){
				return '';
			}
			return '<section class="dp-panel-commandbar dp-panel-commandbar-secondary" aria-label="'.self::e((string)$resource->pluralLabel()).' controls" data-dp-panel-commandbar data-dp-panel-commandbar-variant="secondary">'
				.($actions!=='' ? '<div class="dp-panel-commandbar-primary" data-dp-panel-control-group="primary">'.$actions.'</div>' : '')
				.'</section>';
		}
		$create=$resource->can('create', null, $request->user())!==false ? '<a class="dp-panel-button dp-panel-commandbar-create" href="'.self::e(PanelConfig::resourceUrl($resource, 'create')).'"'.self::resourceModalAttributes('create', self::panelText('table.create_resource_title', ['resource'=>$resource->label()]), self::panelText('table.create_resource_body'), 'xl', 'slide_over', true).'>'.self::actionTextHtml(self::panelText('table.create'), 'plus').'</a>' : '';
		$primaryActions=self::resourceActions($resource, $request).$create;
		$bottomMode=PanelConfig::commandbarBottomMode();
		$bottom=$bottomMode==='meta' ? '' : self::resourceCommandBarBottomHtml($resource, $request, $allColumns, 'dp-panel-commandbar-bottom');
		return '<section class="dp-panel-commandbar" aria-label="'.self::e((string)$resource->pluralLabel()).' controls" data-dp-panel-commandbar data-dp-panel-commandbar-bottom-mode="'.self::e($bottomMode).'">'
			.'<div class="dp-panel-commandbar-top">'
			.'<div class="dp-panel-commandbar-search" data-dp-panel-control-group="search">'.self::searchForm($resource, $request).'</div>'
			.'<div class="dp-panel-commandbar-primary" data-dp-panel-control-group="primary">'.$primaryActions.'</div>'
			.'</div>'
			.$bottom
			.'</section>';
	}

	/**
	 * Renders secondary command bar controls for view, grouping, and table tools.
	 *
	 * @param Resource $resource Resource being listed.
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, Column> $allColumns All table columns for visibility controls.
	 * @param string $class CSS class that selects compact or full placement.
	 * @return string Bottom command bar HTML, or empty string when no controls render.
	 */
	private static function resourceCommandBarBottomHtml(Resource $resource, PanelRequest $request, array $allColumns, string $class): string {
		$compact=$class==='dp-panel-table-meta-controls';
		$viewControls=self::perPageHtml($resource, $request, $compact).self::densityHtml($resource, $request);
		$groupControls=self::tableGroupsHtml($resource, $request);
		$tableActions=self::columnVisibilityHtml($resource, $request, $allColumns, $compact).self::statusBoardButtonHtml($resource, $request).self::exportButtonHtml($resource, $request).self::importButtonHtml($resource, $request);
		if($viewControls==='' && $groupControls==='' && $tableActions===''){
			return '';
		}
		$bottomMode=PanelConfig::commandbarBottomMode();
		return '<div class="'.self::e($class).'" data-dp-panel-commandbar-bottom-mode="'.self::e($bottomMode).'">'
			.'<div class="dp-panel-commandbar-view" data-dp-panel-control-group="view">'.$viewControls.'</div>'
			.'<div class="dp-panel-commandbar-utility" data-dp-panel-control-group="utility">'
			.($groupControls!=='' ? '<div class="dp-panel-commandbar-groups" data-dp-panel-control-group="groups">'.$groupControls.'</div>' : '')
			.($tableActions!=='' ? '<div class="dp-panel-commandbar-actions" data-dp-panel-control-group="actions">'.$tableActions.'</div>' : '')
			.'</div>'
			.'</div>';
	}

	/**
	 * Renders compact search/create controls inside the table metadata header.
	 *
	 * @param Resource $resource Resource being listed.
	 * @param PanelRequest $request Current panel request.
	 * @return string Header controls HTML, or empty string when no compact controls render.
	 */
	private static function resourceTableHeaderControlsHtml(Resource $resource, PanelRequest $request): string {
		$search=self::compactSearchForm($resource, $request);
		$filters=self::filtersHtml($resource, $request);
		$create=$resource->can('create', null, $request->user())!==false ? '<a class="dp-panel-button dp-panel-commandbar-create dp-panel-table-header-create" href="'.self::e(PanelConfig::resourceUrl($resource, 'create')).'"'.self::resourceModalAttributes('create', self::panelText('table.create_resource_title', ['resource'=>$resource->label()]), self::panelText('table.create_resource_body'), 'xl', 'slide_over', true).'>'.self::actionTextHtml(self::panelText('table.create'), 'plus').'</a>' : '';
		if($search==='' && $filters==='' && $create===''){
			return '';
		}
		return '<div class="dp-panel-table-header-controls" data-dp-panel-table-header-controls data-dp-panel-control-group="table-header">'
			.$search
			.$filters
			.($create!=='' ? '<div class="dp-panel-table-header-primary">'.$create.'</div>' : '')
			.'</div>';
	}

	/**
	 * Renders the optional record pulse region for detail pages.
	 *
	 * @param Resource $resource Resource that owns the record.
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Record being displayed.
	 * @param array<string, bool> $signals Available record modules and activity signals.
	 * @return string Pulse HTML, currently empty until the record pulse UI is enabled.
	 */
	private static function recordPulseHtml(Resource $resource, PanelRequest $request, mixed $record, array $signals): string {
		return '';
	}

	/**
	 * Selects the most useful next-step copy for a record detail page.
	 *
	 * @param array<string, bool> $signals Available record modules and activity signals.
	 * @return array{title:string,body:string} Recommendation copy for the detail pulse.
	 */
	private static function recordPulseNextStep(array $signals): array {
		if(($signals['alerts'] ?? false)===true){
			return ['title'=>self::panelText('page.pulse_alert_title'), 'body'=>self::panelText('page.pulse_alert_body')];
		}
		if(($signals['approvals'] ?? false)===true){
			return ['title'=>self::panelText('page.pulse_approval_title'), 'body'=>self::panelText('page.pulse_approval_body')];
		}
		if(($signals['tasks'] ?? false)===true){
			return ['title'=>self::panelText('page.pulse_task_title'), 'body'=>self::panelText('page.pulse_task_body')];
		}
		if(($signals['messages'] ?? false)===true){
			return ['title'=>self::panelText('page.pulse_message_title'), 'body'=>self::panelText('page.pulse_message_body')];
		}
		if(($signals['activity'] ?? false)===true || ($signals['changes'] ?? false)===true){
			return ['title'=>self::panelText('page.pulse_activity_title'), 'body'=>self::panelText('page.pulse_activity_body')];
		}
		if(($signals['relations'] ?? false)===true){
			return ['title'=>self::panelText('page.pulse_relations_title'), 'body'=>self::panelText('page.pulse_relations_body')];
		}
		return ['title'=>self::panelText('page.pulse_ready_title'), 'body'=>self::panelText('page.pulse_ready_body')];
	}

	/**
	 * Returns the first non-empty scalar value found on a record for candidate keys.
	 *
	 * @param mixed $record Record array or object.
	 * @param array<int, string> $keys Candidate field names.
	 * @return string First non-empty scalar value, or an empty string.
	 */
	private static function firstRecordValue(mixed $record, array $keys): string {
		foreach($keys as $key){
			$value=self::recordValue($record, (string)$key, '');
			if(is_scalar($value)){
				$value=trim((string)$value);
				if($value!==''){
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Formats a record pulse value for short human-readable display.
	 *
	 * @param string $value Raw normalized value.
	 * @return string Display value, falling back to `None` for empty input.
	 */
	private static function humanRecordPulseValue(string $value): string {
		$value=trim(str_replace('_', ' ', $value));
		return $value!=='' ? $value : 'None';
	}

}
