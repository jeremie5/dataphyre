<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Isolated test harness for Dataphyre Panel resources, pages, and assertions.
 *
 * The harness creates a predictable panel instance, registers test resources and
 * UI objects, renders full/fragment/modal requests, builds typed state objects,
 * and exposes assertion helpers that fail with exported diagnostic values.
 */
final class PanelTestHarness {

	private PanelInstance $panel;

	/**
	 * Creates the isolated panel surface used by the harness.
	 *
	 * A supplied PanelInstance is reused as-is; a supplied manager receives a new
	 * deterministic test panel; null creates both pieces. Construction has no live
	 * request dependency and keeps all registrations scoped to the harness panel.
	 *
	 * @param PanelInstance|PanelManager|null $panel Existing panel or manager used to seed the harness.
	 */
	private function __construct(PanelInstance|PanelManager|null $panel=null) {
		$this->panel=$panel instanceof PanelInstance
			? $panel
			: new PanelInstance('test', $panel instanceof PanelManager ? $panel : new PanelManager(), [
				'panel_name'=>'test',
				'panel_label'=>'Test Panel',
			]);
	}

	/**
	 * Creates an isolated panel test harness around a supplied panel or a default test panel.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelInstance|PanelManager|null $panel Panel instance or manager used to build the isolated test surface.
	 * @return self The same harness instance for fluent test setup.
	 */
	public static function make(PanelInstance|PanelManager|null $panel=null): self {
		return new self($panel);
	}

	/**
	 * Returns the panel instance owned by the harness.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @return PanelInstance Panel test object described by the native return type.
	 */
	public function panel(): PanelInstance {
		return $this->panel;
	}

	/**
	 * Returns the manager attached to the harness panel.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @return PanelManager Panel test object described by the native return type.
	 */
	public function manager(): PanelManager {
		return $this->panel->manager();
	}

	/**
	 * Registers resources, pages, widgets, navigation items, or commands into the isolated test panel.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param Resource|array<string,mixed> $resource Resource instance, resource class/name, or array definition under test.
	 * @return self The same harness instance for fluent test setup.
	 */
	public function register(Resource|array $resource): self {
		$this->panel->register($resource);
		return $this;
	}

	/**
	 * Registers resources, pages, widgets, navigation items, or commands into the isolated test panel.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelPage|array<string,mixed> $page Panel page instance or array definition registered in the harness.
	 * @return self The same harness instance for fluent test setup.
	 */
	public function registerPage(PanelPage|array $page): self {
		$this->panel->registerPage($page);
		return $this;
	}

	/**
	 * Registers resources, pages, widgets, navigation items, or commands into the isolated test panel.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param Widget|array<string,mixed> $widget Widget instance or array definition registered in the harness.
	 * @return self The same harness instance for fluent test setup.
	 */
	public function registerWidget(Widget|array $widget): self {
		$this->panel->registerWidget($widget);
		return $this;
	}

	/**
	 * Registers resources, pages, widgets, navigation items, or commands into the isolated test panel.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param NavigationItem|array<string,mixed> $item Navigation item instance or array definition registered in the harness.
	 * @return self The same harness instance for fluent test setup.
	 */
	public function registerNavigationItem(NavigationItem|array $item): self {
		$this->panel->registerNavigationItem($item);
		return $this;
	}

	/**
	 * Registers resources, pages, widgets, navigation items, or commands into the isolated test panel.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelCommand|array<string,mixed> $command Command instance or array definition registered in the harness.
	 * @return self The same harness instance for fluent test setup.
	 */
	public function registerCommand(PanelCommand|array $command): self {
		$this->panel->registerCommand($command);
		return $this;
	}

	/**
	 * Builds a PanelRequest from array data for dispatch tests.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param array<string,mixed> $data Request, form, action, or command payload used to build state.
	 * @return PanelRequest Panel test object described by the native return type.
	 */
	public function request(array $data=[]): PanelRequest {
		return PanelRequest::fromArray($data);
	}

	/**
	 * Dispatches a PanelRequest through the harness panel and returns the page result.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelRequest|array|null $request Panel request object or array converted before dispatch.
	 * @return PanelPageResult Rendered or dispatched panel result containing status, data, headers, notifications, and HTML.
	 */
	public function dispatch(PanelRequest|array|null $request=null): PanelPageResult {
		return $this->panel->dispatch($request ?? []);
	}

	/**
	 * Renders a full page, fragment request, or modal request for a resource operation.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param Resource|string|null $resource Resource instance, resource class/name, or array definition under test.
	 * @param string $operation Panel operation such as index, show, create, edit, store, update, or action.
	 * @param array{query?:array<string,mixed>,headers?:array<string,string>,record?:mixed,tenant?:mixed,partial?:string,modal?:bool}|array<string,mixed> $context Render context containing query, headers, record, tenant, and partial-modal metadata.
	 * @return PanelPageResult Rendered or dispatched panel result containing status, data, headers, notifications, and HTML.
	 */
	public function render(Resource|string|null $resource=null, string $operation='index', array $context=[]): PanelPageResult {
		return $this->panel->render($resource, $operation, $context);
	}

	/**
	 * Renders a full page, fragment request, or modal request for a resource operation.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param Resource|string|null $resource Resource instance, resource class/name, or array definition under test.
	 * @param string $operation Panel operation such as index, show, create, edit, store, update, or action.
	 * @param array{query?:array<string,mixed>,headers?:array<string,string>,record?:mixed,tenant?:mixed,partial?:string,modal?:bool}|array<string,mixed> $context Render context containing query, headers, record, tenant, and partial-modal metadata.
	 * @return PanelPageResult Rendered or dispatched panel result containing status, data, headers, notifications, and HTML.
	 */
	public function fragment(Resource|string|null $resource=null, string $operation='index', array $context=[]): PanelPageResult {
		$query=is_array($context['query'] ?? null) ? $context['query'] : [];
		$headers=is_array($context['headers'] ?? null) ? $context['headers'] : [];
		$context['query']=array_replace($query, ['__panel_partial'=>'fragment']);
		$context['headers']=array_replace($headers, ['X-Requested-With'=>'DataphyrePanelFragment']);
		return $this->render($resource, $operation, $context);
	}

	/**
	 * Renders a full page, fragment request, or modal request for a resource operation.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param Resource|string|null $resource Resource instance, resource class/name, or array definition under test.
	 * @param string $operation Panel operation such as index, show, create, edit, store, update, or action.
	 * @param array{query?:array<string,mixed>,headers?:array<string,string>,record?:mixed,tenant?:mixed,partial?:string,modal?:bool}|array<string,mixed> $context Render context containing query, headers, record, tenant, and partial-modal metadata.
	 * @return PanelPageResult Rendered or dispatched panel result containing status, data, headers, notifications, and HTML.
	 */
	public function modal(Resource|string|null $resource=null, string $operation='show', array $context=[]): PanelPageResult {
		$query=is_array($context['query'] ?? null) ? $context['query'] : [];
		$headers=is_array($context['headers'] ?? null) ? $context['headers'] : [];
		$context['query']=array_replace($query, ['__panel_partial'=>'modal']);
		$context['headers']=array_replace($headers, ['X-Requested-With'=>'DataphyrePanelModal']);
		return $this->render($resource, $operation, $context);
	}

	/**
	 * Resolves a registered resource by normalized name or fails the test.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param string $name Resource, page, command, or action name.
	 * @return Resource Panel test object described by the native return type.
	 */
	public function resource(string $name): Resource {
		$resource=$this->panel->get($name);
		if(!$resource instanceof Resource){
			self::fail("Panel resource '{$name}' is not registered.");
		}
		return $resource;
	}

	/**
	 * Builds a PanelPage object for test registration or inspection.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param string $name Resource, page, command, or action name.
	 * @return PanelPage Panel test object described by the native return type.
	 */
	public function page(string $name): PanelPage {
		$page=$this->panel->getPage($name);
		if(!$page instanceof PanelPage){
			self::fail("Panel page '{$name}' is not registered.");
		}
		return $page;
	}

	/**
	 * Builds a typed panel state object for resource tables, forms, actions, navigation, or commands.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param string|Resource $resource Resource instance, resource class/name, or array definition under test.
	 * @param array<int,mixed> $records Synthetic records used to create table state.
	 * @param array<string,mixed> $query Query parameters applied to table/navigation/command state.
	 * @param array<string,mixed> $preferences Table preference state such as visible columns, density, or pagination settings.
	 * @return PanelTableState Typed panel state object ready for assertions.
	 */
	public function tableState(string|Resource $resource, array $records=[], array $query=[], array $preferences=[]): PanelTableState {
		$resource=is_string($resource) ? $this->resource($resource) : $resource;
		$request=$this->request([
			'resource'=>$resource->name(),
			'operation'=>'index',
			'query'=>$query,
		]);
		return $resource->tableState($request, $records, false, $preferences);
	}

	/**
	 * Builds a typed panel state object for resource tables, forms, actions, navigation, or commands.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param string|Resource $resource Resource instance, resource class/name, or array definition under test.
	 * @param mixed $record Record object/array/id used by form or action state.
	 * @param array<string,mixed> $input Raw form input values.
	 * @param string $operation Panel operation such as index, show, create, edit, store, update, or action.
	 * @param bool $validate Whether form state should run validation immediately.
	 * @return PanelFormState Typed panel state object ready for assertions.
	 */
	public function formState(string|Resource $resource, mixed $record=null, array $input=[], string $operation='create', bool $validate=false): PanelFormState {
		$resource=is_string($resource) ? $this->resource($resource) : $resource;
		$request=$this->request([
			'method'=>$input!==[] ? 'POST' : 'GET',
			'resource'=>$resource->name(),
			'operation'=>$operation,
			'input'=>$input,
		]);
		return $resource->form()->state($record, $request, $operation, $input, $validate);
	}

	/**
	 * Builds form state and runs validation against the supplied values.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param string|Resource $resource Resource instance, resource class/name, or array definition under test.
	 * @param array<string,mixed> $values Form values expected to pass validation.
	 * @param mixed $record Record object/array/id used by form or action state.
	 * @param string $operation Panel operation such as index, show, create, edit, store, update, or action.
	 * @return PanelFormState Typed panel state object ready for assertions.
	 */
	public function validateForm(string|Resource $resource, array $values, mixed $record=null, string $operation='store'): PanelFormState {
		$resource=is_string($resource) ? $this->resource($resource) : $resource;
		$request=$this->request([
			'method'=>'POST',
			'resource'=>$resource->name(),
			'operation'=>$operation,
			'input'=>$values,
		]);
		return $resource->form()->validate($values, $record, $request, $operation);
	}

	/**
	 * Builds a typed panel state object for resource tables, forms, actions, navigation, or commands.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param string|Resource $resource Resource instance, resource class/name, or array definition under test.
	 * @param string $action Action name resolved from the resource.
	 * @param mixed $record Record object/array/id used by form or action state.
	 * @param array<string,mixed> $data Request, form, action, or command payload used to build state.
	 * @param string $mode Action execution mode, such as row action or bulk action.
	 * @return PanelActionState Typed panel state object ready for assertions.
	 */
	public function actionState(string|Resource $resource, string $action, mixed $record=null, array $data=[], string $mode='action'): PanelActionState {
		$resource=is_string($resource) ? $this->resource($resource) : $resource;
		$actionObject=$resource->actionByName($action);
		if(!$actionObject instanceof Action){
			self::fail("Panel action '{$action}' is not registered on resource '{$resource->name()}'.");
		}
		$request=$this->request([
			'resource'=>$resource->name(),
			'operation'=>'action',
			'action'=>$action,
			'input'=>$data,
		]);
		return $actionObject->state($record, $request, $resource, $mode, null, $data);
	}

	/**
	 * Builds action state, authorizes visibility/enabled state, and optionally executes lifecycle hooks.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param string|Resource $resource Resource instance, resource class/name, or array definition under test.
	 * @param string $action Action name resolved from the resource.
	 * @param mixed $record Record object/array/id used by form or action state.
	 * @param array<string,mixed> $data Request, form, action, or command payload used to build state.
	 * @param bool $runLifecycle Whether action lifecycle hooks should be executed.
	 * @return PanelActionState Typed panel state object ready for assertions.
	 */
	public function runAction(string|Resource $resource, string $action, mixed $record=null, array $data=[], bool $runLifecycle=true): PanelActionState {
		$resource=is_string($resource) ? $this->resource($resource) : $resource;
		$actionObject=$resource->actionByName($action);
		if(!$actionObject instanceof Action){
			self::fail("Panel action '{$action}' is not registered on resource '{$resource->name()}'.");
		}
		$request=$this->request([
			'method'=>'POST',
			'resource'=>$resource->name(),
			'operation'=>'action',
			'action'=>$action,
			'input'=>$data,
		]);
		$formState=$actionObject->hasFields() ? $actionObject->form()->state($record, $request, 'action', $data, true) : null;
		if($formState instanceof PanelFormState && $formState->invalid()){
			return $actionObject->state($record, $request, $resource, 'action', $formState, $data, null, null, ['stage'=>'validation_failed']);
		}
		$result=$actionObject->execute($record, $data, $resource, $runLifecycle, $request);
		return $actionObject->state($record, $request, $resource, 'action', $formState, $data, $result, null, ['stage'=>'executed']);
	}

	/**
	 * Builds a typed panel state object for resource tables, forms, actions, navigation, or commands.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param array<string,mixed> $query Query parameters applied to table/navigation/command state.
	 * @param array<string,mixed> $search Navigation search payload.
	 * @return PanelNavigationState Typed panel state object ready for assertions.
	 */
	public function navigationState(array $query=[], array $search=[]): PanelNavigationState {
		return $this->panel->navigationState($this->request(['query'=>$query]), $search);
	}

	/**
	 * Builds a typed panel state object for resource tables, forms, actions, navigation, or commands.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param ?string $query Query parameters applied to table/navigation/command state.
	 * @return PanelCommandState Typed panel state object ready for assertions.
	 */
	public function commandState(?string $query=null): PanelCommandState {
		return $this->panel->commandState($this->request([]), $query);
	}

	/**
	 * Returns the component registry manifest used by panel tests.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @return array<string,mixed> Panel manifest or nested data payload used by tests.
	 */
	public function manifest(): array {
		return $this->panel->panelManifest($this->request([]), ['testing'=>true]);
	}

	/**
	 * Builds an accessibility audit object from rendered panel output.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelPageResult|string $result Panel result, rendered HTML string, or accessibility audit under assertion.
	 * @param array<string,mixed> $meta Extra audit metadata recorded with accessibility findings.
	 * @return PanelAccessibilityAudit Panel test object described by the native return type.
	 */
	public function accessibilityAudit(PanelPageResult|string $result, array $meta=[]): PanelAccessibilityAudit {
		return PanelAccessibilityAudit::from($result, $meta);
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelPageResult $result Panel result, rendered HTML string, or accessibility audit under assertion.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertOk(PanelPageResult $result): void {
		self::assertStatus($result, 200);
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelPageResult $result Panel result, rendered HTML string, or accessibility audit under assertion.
	 * @param int $status Expected HTTP-like status code.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertStatus(PanelPageResult $result, int $status): void {
		if($result->status()!==$status){
			self::fail("Expected Panel response status {$status}, got {$result->status()}.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelPageResult $result Panel result, rendered HTML string, or accessibility audit under assertion.
	 * @param ?string $to Expected redirect target, or null to assert any redirect.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertRedirect(PanelPageResult $result, ?string $to=null): void {
		if(!$result->isRedirect()){
			self::fail('Expected Panel response to redirect.');
		}
		if($to!==null && $result->redirectTo()!==$to){
			self::fail("Expected Panel redirect to '{$to}', got '".(string)$result->redirectTo()."'.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelPageResult|string $result Panel result, rendered HTML string, or accessibility audit under assertion.
	 * @param string $needle Text expected to be present or absent in rendered output.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertSee(PanelPageResult|string $result, string $needle): void {
		$html=$result instanceof PanelPageResult ? $result->content() : $result;
		if(!str_contains($html, $needle)){
			self::fail("Expected Panel output to contain '{$needle}'.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelPageResult|string $result Panel result, rendered HTML string, or accessibility audit under assertion.
	 * @param string $needle Text expected to be present or absent in rendered output.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertDontSee(PanelPageResult|string $result, string $needle): void {
		$html=$result instanceof PanelPageResult ? $result->content() : $result;
		if(str_contains($html, $needle)){
			self::fail("Expected Panel output not to contain '{$needle}'.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelPageResult $result Panel result, rendered HTML string, or accessibility audit under assertion.
	 * @param string $path Dot-path into a result data payload.
	 * @param mixed $expected Expected value compared against result or state data.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertData(PanelPageResult $result, string $path, mixed $expected): void {
		$actual=self::getPath($result->data(), $path);
		if($actual!==$expected){
			self::fail("Expected Panel result data '{$path}' to equal ".self::export($expected).", got ".self::export($actual).'.');
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelPageResult $result Panel result, rendered HTML string, or accessibility audit under assertion.
	 * @param ?string $message Expected notification text or assertion failure message.
	 * @param ?string $type Expected notification type.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertNotification(PanelPageResult $result, ?string $message=null, ?string $type=null): void {
		foreach($result->notifications() as $notification){
			$matchesMessage=$message===null || str_contains((string)($notification['message'] ?? ''), $message);
			$matchesType=$type===null || (string)($notification['type'] ?? $notification['tone'] ?? '')===$type;
			if($matchesMessage && $matchesType){
				return;
			}
		}
		self::fail('Expected Panel notification was not present.');
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelPageResult|string|PanelAccessibilityAudit $result Panel result, rendered HTML string, or accessibility audit under assertion.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertAccessible(PanelPageResult|string|PanelAccessibilityAudit $result): void {
		$audit=$result instanceof PanelAccessibilityAudit ? $result : PanelAccessibilityAudit::from($result);
		if(!$audit->passed()){
			self::fail('Expected Panel output to pass accessibility audit, got: '.self::export($audit->issues('error')).'.');
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelTableState $state Panel state object being asserted.
	 * @param int $count Expected visible row count.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertTableCount(PanelTableState $state, int $count): void {
		$actual=count($state->records());
		if($actual!==$count){
			self::fail("Expected table to contain {$count} records, got {$actual}.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelTableState $state Panel state object being asserted.
	 * @param int $total Expected total row count.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertTableTotal(PanelTableState $state, int $total): void {
		if($state->totalRecords()!==$total){
			self::fail("Expected table total {$total}, got {$state->totalRecords()}.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelTableState $state Panel state object being asserted.
	 * @param string $column Table column name under assertion.
	 * @param bool $visible Whether the table column should be visible.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertTableColumn(PanelTableState $state, string $column, bool $visible=true): void {
		$columns=$visible ? $state->visibleColumns() : $state->allColumns();
		if(!array_key_exists($column, $columns)){
			self::fail("Expected table ".($visible ? 'visible ' : '')."column '{$column}' to exist.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelTableState $state Panel state object being asserted.
	 * @param string $filter Table filter name under assertion.
	 * @param mixed $expected Expected value compared against result or state data.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertTableFilter(PanelTableState $state, string $filter, mixed $expected=null): void {
		$filters=$state->filterValues();
		if(!array_key_exists($filter, $filters)){
			self::fail("Expected table filter '{$filter}' to be active.");
		}
		if(func_num_args()>=3 && $filters[$filter]!==$expected){
			self::fail("Expected table filter '{$filter}' to equal ".self::export($expected).", got ".self::export($filters[$filter]).'.');
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelFormState $state Panel state object being asserted.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertFormValid(PanelFormState $state): void {
		if($state->invalid()){
			self::fail('Expected form to be valid, got errors: '.self::export($state->errors()).'.');
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelFormState $state Panel state object being asserted.
	 * @param ?string $field Form field name under assertion.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertFormInvalid(PanelFormState $state, ?string $field=null): void {
		if($state->valid()){
			self::fail('Expected form to be invalid.');
		}
		if($field!==null && $state->fieldErrors($field)===[]){
			self::fail("Expected form field '{$field}' to have validation errors.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelFormState $state Panel state object being asserted.
	 * @param string $field Form field name under assertion.
	 * @param mixed $expected Expected value compared against result or state data.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertFormValue(PanelFormState $state, string $field, mixed $expected): void {
		$actual=$state->value($field);
		if($actual!==$expected){
			self::fail("Expected form field '{$field}' to equal ".self::export($expected).", got ".self::export($actual).'.');
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelActionState $state Panel state object being asserted.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertActionVisible(PanelActionState $state): void {
		if(($state->meta()['visible'] ?? false)!==true){
			self::fail("Expected action '{$state->actionName()}' to be visible.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelActionState $state Panel state object being asserted.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertActionHidden(PanelActionState $state): void {
		if(($state->meta()['visible'] ?? true)!==false){
			self::fail("Expected action '{$state->actionName()}' to be hidden.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelActionState $state Panel state object being asserted.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertActionEnabled(PanelActionState $state): void {
		if(($state->meta()['disabled'] ?? false)===true){
			self::fail("Expected action '{$state->actionName()}' to be enabled.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelActionState $state Panel state object being asserted.
	 * @param ?string $reason Expected disabled reason for an action.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertActionDisabled(PanelActionState $state, ?string $reason=null): void {
		if(($state->meta()['disabled'] ?? false)!==true){
			self::fail("Expected action '{$state->actionName()}' to be disabled.");
		}
		if($reason!==null && !str_contains((string)($state->meta()['disabled_reason'] ?? ''), $reason)){
			self::fail("Expected action '{$state->actionName()}' disabled reason to contain '{$reason}'.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelActionState $state Panel state object being asserted.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertAuthorized(PanelActionState $state): void {
		if(($state->meta()['authorized'] ?? false)!==true){
			self::fail("Expected action '{$state->actionName()}' to be authorized.");
		}
	}

	/**
	 * Asserts panel test state and throws a descriptive failure when the expectation is not met.
	 *
	 * Panel test helpers are intentionally deterministic: they avoid a live request cycle, build typed state objects, and surface failures as assertion exceptions with exported expected/actual values.
	 *
	 * @param PanelActionState $state Panel state object being asserted.
	 * @return void The assertion passes silently; failures throw RuntimeException with a diagnostic message.
	 */
	public static function assertUnauthorized(PanelActionState $state): void {
		if(($state->meta()['authorized'] ?? true)!==false){
			self::fail("Expected action '{$state->actionName()}' to be unauthorized.");
		}
	}

	/**
	 * Reads a nested value from assertion data using dot-path segments.
	 *
	 * Missing segments return null so assertion messages can compare absent data
	 * without throwing. Present null values are indistinguishable from missing
	 * paths by design because callers use this only for equality diagnostics.
	 *
	 * @param array<string,mixed> $data Panel result data payload.
	 * @param string $path Dot-separated assertion path.
	 * @return mixed Value at the path, or null when any segment is absent.
	 */
	private static function getPath(array $data, string $path): mixed {
		$current=$data;
		foreach(explode('.', $path) as $segment){
			if(is_array($current) && array_key_exists($segment, $current)){
				$current=$current[$segment];
				continue;
			}
			return null;
		}
		return $current;
	}

	/**
	 * Converts assertion values into compact diagnostic text.
	 *
	 * JSON is preferred so arrays and scalar values are easy to compare in failure
	 * output. Values that cannot be JSON-encoded fall back to var_export so the
	 * harness still emits a useful representation instead of hiding the value.
	 *
	 * @param mixed $value Expected or actual value included in an assertion failure.
	 * @return string JSON or PHP representation safe for exception messages.
	 */
	private static function export(mixed $value): string {
		$encoded=json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if($encoded!==false){
			return $encoded;
		}
		return var_export($value, true);
	}

	/**
	 * Stops the current assertion with a stable test failure exception.
	 *
	 * Centralizing failure creation keeps all harness assertions on AssertionError,
	 * which test runners can classify separately from panel runtime exceptions.
	 *
	 * @param string $message Human-readable assertion failure message.
	 * @return never This method always throws.
	 * @throws \AssertionError Always thrown with the supplied message.
	 */
	private static function fail(string $message): never {
		throw new \AssertionError($message);
	}
}
