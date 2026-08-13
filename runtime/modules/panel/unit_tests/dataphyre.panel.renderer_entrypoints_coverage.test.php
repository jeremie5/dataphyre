<?php
declare(strict_types=1);
// @dataphyre-test-discovery-dependency framework-source
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\FormSection;
use Dataphyre\Panel\PanelFormState;
use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelAssetCapabilityManifest;
use Dataphyre\Panel\PanelDataSurfaceDefinition;
use Dataphyre\Panel\PanelDataSurfaceProjection;
use Dataphyre\Panel\PanelDataSurfaceRange;
use Dataphyre\Panel\PanelDataSurfaceType;
use Dataphyre\Panel\PanelDataSurfaceWindowResult;
use Dataphyre\Panel\PanelLifecycleResult;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelNavigationTarget;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Test\Context;
use Dataphyre\Test\TypeInventory;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,array{0:string,1:string}> */
function dp_panel_renderer_entrypoints(): array {
	$methods=[];
	$inventory=TypeInventory::of(PanelRenderer::class);
	foreach($inventory->publicMethods(static:true) as $method){
		$file=str_replace('\\', '/', (string)$method->getFileName());
		if(str_contains($file, '/Framework/Rendering/')){
			$methods[$method->getName().' GET']=[$method->getName(), 'GET'];
			$methods[$method->getName().' POST']=[$method->getName(), 'POST'];
		}
	}
	ksort($methods);
	return $methods;
}

dataset('panel renderer public entrypoints', dp_panel_renderer_entrypoints());

function dp_panel_renderer_resource(): Resource {
	$records=[
		['id'=>1, 'name'=>'Ada', 'status'=>'open', 'position'=>1],
		['id'=>2, 'name'=>'Grace', 'status'=>'closed', 'position'=>2],
	];
	$relation=RelationManager::make('items')
		->label('Items')
		->description('Related order items')
		->queryUsing(static fn(): array=>$records)
		->authorize(static fn(): bool=>true)
		->columns([
			Column::make('id')->label('ID')->sortable(),
			Column::make('name')->label('Name')->searchable(),
			Column::make('status')->label('Status'),
		])
		->filters([
			TableFilter::make('status')->type('select')->options(['open'=>'Open', 'closed'=>'Closed']),
		])
		->create()
		->attach()
		->detach()
		->associate()
		->dissociate()
		->reorderable(true, 'position')
		->attachableRecordsUsing(static fn(): array=>$records)
		->attachUsing(static fn(): array=>['ok'=>true])
		->detachUsing(static fn(): array=>['ok'=>true])
		->associateUsing(static fn(): array=>['ok'=>true])
		->dissociateUsing(static fn(): array=>['ok'=>true])
		->reorderUsing(static fn(): array=>['ok'=>true], 'position')
		->updatePivotUsing(static fn(): array=>['ok'=>true]);

	return Resource::make('orders')
		->label('Order')
		->pluralLabel('Orders')
		->authorize(static fn(): bool=>true)
		->fields([
			Field::make('name')->label('Name')->required()->section('identity'),
			Field::make('status')->select(['open'=>'Open', 'closed'=>'Closed'])->section('identity'),
			Field::make('hidden_token')->hiddenField('token'),
			Field::make('summary')->displayOnly('Read-only summary'),
			Field::make('bio')->textarea(4)->placeholder('Biography')->characterCounter()->section('profile'),
			Field::make('active')->toggle('Active', 'Inactive'),
			Field::make('priority')->radio(['low'=>'Low', 'high'=>'High'])->inlineChoices(),
			Field::make('channels')->checkboxList(['email'=>'Email', 'sms'=>'SMS'])->choiceColumns(2),
			Field::make('segment')->toggleButtons(['retail'=>'Retail', 'wholesale'=>'Wholesale']),
			Field::make('country')->countrySelect(),
			Field::make('period')->dateRange('2026-01-01', '2026-12-31'),
			Field::make('price')->money('CAD', 2)->section('billing'),
			Field::make('volume')->slider(0, 100, 5, true),
			Field::make('score')->rating(5),
			Field::make('keywords')->tags(['priority', 'review']),
			Field::make('password')->password()->autocomplete('new-password'),
			Field::make('markdown')->markdown()->section('content'),
			Field::make('html')->htmlEditor()->section('content'),
			Field::make('source')->codeEditor('php')->section('content'),
			Field::make('metadata')->keyValue(),
			Field::make('brand_color')->color('#336699')->colorSwatch(),
			Field::make('document')->fileUpload(['application/pdf'], 1048576),
			Field::make('avatar')->imageUpload(1048576)->customUploader(true, '/panel/upload'),
			Field::make('contacts')->repeater([
				Field::make('email')->email(),
				Field::make('phone')->phone(),
			], 0, 3),
			Field::make('content')->builder([
				'text'=>[
					'label'=>'Text',
					'fields'=>[Field::make('body')->textarea()],
				],
			]),
			Field::make('address')->address('CA'),
		])
		->formSections([
			FormSection::make('identity')->label('Identity')->description('Core record identity')->columns(2)->meta(['tab'=>'Profile']),
			FormSection::make('profile')->label('Profile')->collapsible()->meta(['tab'=>'Profile']),
			FormSection::make('billing')->label('Billing')->meta(['step'=>'Payment']),
			FormSection::make('content')->label('Content')->meta(['step'=>'Content']),
		])
		->columns([
			Column::make('id')->label('ID')->sortable(),
			Column::make('name')->label('Name')->searchable(),
			Column::make('status')->label('Status'),
		])
		->filters([
			TableFilter::make('status')->type('select')->options(['open'=>'Open', 'closed'=>'Closed']),
		])
		->actions([
			Action::make('archive')->label('Archive')->handle(static fn(): array=>['ok'=>true]),
		])
		->relations([$relation])
		->queryUsing(static fn(): array=>$records)
		->saveUsing(static fn(array $data): array=>$data+['id'=>1])
		->importUsing(static fn(array $rows): array=>['imported'=>count($rows)])
		->transitionUsing(static fn(): array=>['ok'=>true])
		->bulkUpdateUsing(static fn(array $data, array $selected): array=>['updated'=>count($selected), 'data'=>$data])
		->duplicateUsing(static fn(mixed $record): array=>(array)$record+['id'=>2])
		->restoreUsing(static fn(): bool=>true)
		->deleteUsing(static fn(): bool=>true)
		->forceDeleteUsing(static fn(): bool=>true)
		->statusTransition('approve', 'approved', 'Approve', 'open', 'success')
		->statusTransition('close', 'closed', 'Close', 'approved', 'neutral')
		->statusWidgets()
		->alertsUsing(static fn(): array=>[[
			'title'=>'Payment review', 'message'=>'Manual review required', 'tone'=>'warning',
			'url'=>'/panel/orders/1', 'action'=>'Review', 'meta'=>['High value'],
		]])
		->insightsUsing(static fn(): array=>[[
			'label'=>'Lifetime value', 'value'=>'$129.99', 'description'=>'Current account', 'tone'=>'success', 'icon'=>'chart',
		]])
		->linksUsing(static fn(): array=>[[
			'label'=>'Invoice', 'url'=>'/invoices/1', 'description'=>'Primary invoice', 'group'=>'Billing', 'icon'=>'document',
		]])
		->contactsUsing(static fn(): array=>[[
			'name'=>'Ada Lovelace', 'role'=>'Buyer', 'email'=>'ada@example.test', 'phone'=>'+15555550100',
			'company'=>'Analytical Engines', 'location'=>'Toronto', 'status'=>'verified', 'url'=>'/contacts/1',
		]])
		->locationsUsing(static fn(): array=>[[
			'label'=>'Warehouse', 'type'=>'shipping', 'status'=>'verified', 'address'=>'1 Test Way',
			'city'=>'Toronto', 'subdivision'=>'ON', 'postal_code'=>'M5V 1A1', 'country'=>'CA',
			'lat'=>'43.64', 'lng'=>'-79.38', 'timezone'=>'America/Toronto', 'url'=>'https://maps.example.test/1',
		]])
		->tagsUsing(static fn(): array=>[[
			'name'=>'priority', 'label'=>'Priority', 'tone'=>'danger', 'description'=>'Requires attention',
		]])
		->tagUsing(static fn(): array=>['ok'=>true])
		->itemsUsing(static fn(): array=>[[
			'label'=>'Widget', 'sku'=>'SKU-1', 'quantity'=>2, 'unit_price'=>1299, 'total'=>2598, 'currency'=>'CAD',
		]])
		->totalsUsing(static fn(): array=>[[
			'label'=>'Grand total', 'value'=>2598, 'currency'=>'CAD', 'tone'=>'success',
		]])
		->approvalsUsing(static fn(): array=>[[
			'name'=>'finance', 'label'=>'Finance review', 'status'=>'pending', 'requested_by'=>'Ada', 'requested_at'=>'2026-07-10',
		]])
		->approvalUsing(static fn(): array=>['ok'=>true])
		->activityUsing(static fn(): array=>[[
			'title'=>'Order created', 'description'=>'Created by Ada', 'type'=>'created', 'actor'=>'Ada', 'at'=>'2026-07-10 12:00:00',
		]])
		->changesUsing(static fn(): array=>[[
			'field'=>'status', 'label'=>'Status', 'before'=>'draft', 'after'=>'open', 'actor'=>'Ada', 'at'=>'2026-07-10 12:05:00',
		]])
		->paymentsUsing(static fn(): array=>[[
			'label'=>'Visa', 'amount'=>2598, 'currency'=>'CAD', 'status'=>'paid', 'type'=>'card', 'at'=>'2026-07-10',
		]])
		->shipmentsUsing(static fn(): array=>[[
			'label'=>'Shipment 1', 'carrier'=>'Canada Post', 'tracking'=>'TRACK-1', 'status'=>'in_transit', 'url'=>'/shipments/1',
		]])
		->notesUsing(static fn(): array=>[[
			'body'=>'Internal context', 'author'=>'Ada', 'at'=>'2026-07-10 12:10:00', 'tone'=>'neutral',
		]])
		->noteUsing(static fn(): array=>['ok'=>true])
		->attachmentsUsing(static fn(): array=>[[
			'name'=>'invoice.pdf', 'url'=>'/files/invoice.pdf', 'type'=>'application/pdf', 'size'=>2048, 'at'=>'2026-07-10',
		]])
		->attachUsing(static fn(): array=>['ok'=>true])
		->messagesUsing(static fn(): array=>[[
			'subject'=>'Order update', 'body'=>'Ready to ship', 'sender'=>'Ada', 'channel'=>'email', 'status'=>'sent', 'at'=>'2026-07-10',
		]])
		->messageUsing(static fn(): array=>['ok'=>true])
		->tasksUsing(static fn(): array=>[[
			'name'=>'confirm-address', 'label'=>'Confirm address', 'description'=>'Verify destination', 'status'=>'open',
			'assignee'=>'Ada', 'due_at'=>'2026-07-11',
		]])
		->taskUsing(static fn(): array=>['ok'=>true])
		->createTaskUsing(static fn(): array=>['ok'=>true]);
}

function dp_panel_renderer_request(string $method='POST'): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>$method,
		'resource'=>'orders',
		'operation'=>'contract',
		'record'=>'1',
		'action'=>'archive',
		'query'=>[
			'page'=>1,
			'per_page'=>25,
			'field'=>'status',
			'format'=>'json',
			'sort'=>'name',
			'direction'=>'asc',
		],
		'input'=>[
			'id'=>1,
			'name'=>'Ada',
			'status'=>'open',
			'hidden_token'=>'token',
			'bio'=>'First programmer',
			'active'=>'1',
			'priority'=>'high',
			'channels'=>['email', 'sms'],
			'segment'=>'retail',
			'country'=>'CA',
			'period'=>['start'=>'2026-01-01', 'end'=>'2026-07-10'],
			'price'=>'129.99',
			'volume'=>75,
			'score'=>5,
			'keywords'=>['priority', 'review'],
			'markdown'=>'# Ready',
			'html'=>'<p>Ready</p>',
			'source'=>'<?php echo "ready";',
			'metadata'=>['source'=>'unit', 'state'=>'ready'],
			'brand_color'=>'#336699',
			'document'=>'invoice.pdf',
			'avatar'=>'avatar.png',
			'contacts'=>[['email'=>'ada@example.test', 'phone'=>'+15555550100']],
			'content'=>[['type'=>'text', 'body'=>'Ready']],
			'address'=>['address1'=>'1 Test Way', 'city'=>'Toronto', 'subdivision'=>'ON', 'postal_code'=>'M5V 1A1'],
			'field'=>'status',
			'action'=>'archive',
			'selected'=>['1'],
			'records'=>['1'],
			'csv_data'=>"name,status\nAda,open\nGrace,closed",
			'delimiter'=>',',
			'has_header'=>'1',
			'import_map'=>[0=>'name', 1=>'status'],
			'transition'=>'approve',
			'tag'=>'priority',
			'tag_action'=>'add',
			'note'=>'Internal note',
			'message'=>['body'=>'Order update', 'channel'=>'email'],
			'task'=>['label'=>'Confirm address'],
			'decision'=>'approve',
			'approval'=>'finance',
		],
		'headers'=>[
			'Accept'=>'application/json',
			'X-Dataphyre-Panel'=>'1',
		],
		'user'=>['id'=>7],
	]);
}

/** @return array{definition:PanelDataSurfaceDefinition,window:PanelDataSurfaceWindowResult} */
function dp_panel_renderer_data_surface_fixture(): array {
	$projection=PanelDataSurfaceProjection::make(['id', 'name'], 'id', ['title'=>'name']);
	$definition=PanelDataSurfaceDefinition::make(
		'renderer-contract',
		'orders',
		'orders-source',
		PanelDataSurfaceType::TABLE,
		$projection,
		PanelDataSurfaceRange::make(0, 25, 0, 0)
	);
	return [
		'definition'=>$definition,
		'window'=>new PanelDataSurfaceWindowResult(
			$definition->id(),
			$definition->resource(),
			$definition->surface(),
			$projection,
			[['key'=>'1', 'position'=>0, 'visible'=>true, 'data'=>['id'=>1, 'name'=>'Ada']]],
			$definition->defaultRange(),
			1,
			false,
			false,
			null,
			null
		),
	];
}

function dp_panel_renderer_argument(ReflectionParameter $parameter, Resource $resource, PanelRequest $request): mixed {
	$type=$parameter->getType();
	$types=$type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
	$name=strtolower($parameter->getName());
	if($name==='record'){
		return ['id'=>1, 'name'=>'Ada', 'status'=>'open'];
	}
	if($name==='records'){
		return [['id'=>1, 'name'=>'Ada', 'status'=>'open']];
	}
	if($name==='totalrecords'){
		return 1;
	}
	foreach($types as $candidate){
		if(!$candidate instanceof ReflectionNamedType || $candidate->isBuiltin()){
			continue;
		}
		$fixture=dp_panel_renderer_data_surface_fixture();
		$argument=match($candidate->getName()){
			Resource::class=>$resource,
			PanelRequest::class=>$request,
			PanelManager::class=>(function() use ($resource): PanelManager {
				PanelManager::flush();
				$manager=PanelManager::instance();
				$manager->register($resource);
				return $manager;
			})(),
			PanelPage::class=>PanelPage::make('contract')->label('Contract'),
			PanelFormState::class=>PanelFormState::make(['name'=>'Ada', 'status'=>'open']),
			PanelDataSurfaceDefinition::class=>$fixture['definition'],
			PanelDataSurfaceWindowResult::class=>$fixture['window'],
			default=>null,
		};
		if($argument!==null){
			return $argument;
		}
		if($parameter->isDefaultValueAvailable()){
			return $parameter->getDefaultValue();
		}
		throw new RuntimeException('Unsupported renderer argument type '.$candidate->getName().'.');
	}
	if($parameter->isDefaultValueAvailable()){
		return $parameter->getDefaultValue();
	}
	foreach($types as $candidate){
		if(!$candidate instanceof ReflectionNamedType || !$candidate->isBuiltin()){
			continue;
		}
		return match($candidate->getName()){
			'array'=>match($name){
				'records'=>[['id'=>1, 'name'=>'Ada', 'status'=>'open']],
				default=>[],
			},
			'bool'=>false,
			'int'=>1,
			'string'=>match($name){
				'asset'=>'panel.css',
				'actionname'=>'archive',
				'mode'=>'create',
				default=>'contract',
			},
			default=>['id'=>1, 'name'=>'Ada', 'status'=>'open'],
		};
	}
	return ['id'=>1, 'name'=>'Ada', 'status'=>'open'];
}

test('panel renderer public entrypoint returns a bounded page asset or manifest result', static function(Context $t, string $methodName, string $requestMethod): void {
	$resource=dp_panel_renderer_resource();
	$request=dp_panel_renderer_request($requestMethod);
	$inventory=$t->inventory(PanelRenderer::class);
	$method=$inventory->method($methodName);
	$arguments=array_map(
		static fn(ReflectionParameter $parameter): mixed=>dp_panel_renderer_argument($parameter, $resource, $request),
		$method->getParameters()
	);
	$result=$inventory->invokeWithArguments($method, null, $arguments);
	if($result instanceof PanelPageResult){
		$t->between(100, 599, $result->status());
		$t->notEmpty($result->headers()['Content-Type'] ?? '');
		return;
	}
	if(is_array($result)){
		$t->notEmpty($result);
		return;
	}
	if($result instanceof PanelAssetCapabilityManifest){
		$t->same('capability', $result->mode());
		$t->notEmpty($result->capabilities());
		return;
	}
	$t->isTrue(is_string($result) || $result===null);
})->with('panel renderer public entrypoints')->tag('panel', 'renderer', 'entrypoint', 'coverage')->maxMillis(10000);

test('panel renderer lifecycle short circuits preserve root action effects', static function(Context $t): void {
	$effects=['modal_navigation'=>'back', 'refresh'=>'table'];
	$resourceAction=Action::make('return_parent')
		->effects($effects)
		->beforeValidateUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('Stopped for review'))
		->handle(static fn(): string=>'unused');
	$resource=Resource::make('orders')->actions([$resourceAction]);
	$request=PanelRequest::fromArray([
		'method'=>'GET', 'resource'=>'orders', 'operation'=>'action', 'action'=>'return_parent', 'user'=>['id'=>7],
	]);
	$resourceResult=PanelRenderer::actionResult($resource, $request, 'return_parent', ['id'=>1]);
	$t->same('back', $resourceResult->data()['effects']['modal_navigation'] ?? null);
	$t->same(['table'], $resourceResult->data()['effects']['refresh'] ?? null);

	$pageAction=Action::make('return_parent')
		->effects($effects)
		->beforeValidateUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::halt('Stopped for review'))
		->handle(static fn(): string=>'unused');
	$page=PanelPage::make('workflow')->actions([$pageAction]);
	$pageResult=PanelRenderer::pageActionResult($page, PanelRequest::fromArray([
		'method'=>'GET', 'resource'=>'workflow', 'operation'=>'action', 'action'=>'return_parent', 'user'=>['id'=>7],
	]), 'return_parent');
	$t->same('back', $pageResult->data()['effects']['modal_navigation'] ?? null);
	$t->same(['table'], $pageResult->data()['effects']['refresh'] ?? null);
})->tag('panel', 'renderer', 'lifecycle', 'effects')->maxMillis(2000);

test('page actions accept safe return_to targets and reject external return targets', static function(Context $t): void {
	$action=Action::make('save')->handle(static fn(): array=>['message'=>'Saved']);
	$page=PanelPage::make('workflow')->actions([$action]);
	$internal=PanelNavigationTarget::normalize(PanelConfig::url('orders/1', ['tab'=>'history']));
	$t->isTrue(is_string($internal));
	$internalResult=PanelRenderer::pageActionResult($page, PanelRequest::fromArray([
		'method'=>'POST', 'resource'=>'workflow', 'operation'=>'action', 'action'=>'save',
		'input'=>['return_to'=>$internal], 'user'=>['id'=>7],
	]), 'save');
	$t->same($internal, $internalResult->headers()['Location'] ?? null);

	$externalResult=PanelRenderer::pageActionResult($page, PanelRequest::fromArray([
		'method'=>'POST', 'resource'=>'workflow', 'operation'=>'action', 'action'=>'save',
		'input'=>['return_to'=>'https://evil.example/phish'], 'user'=>['id'=>7],
	]), 'save');
	$t->same(PanelConfig::url('workflow'), $externalResult->headers()['Location'] ?? null);
})->tag('panel', 'renderer', 'page-action', 'return')->maxMillis(2000);

test('page lifecycle redirects stay inside the panel boundary', static function(Context $t): void {
	$external=Action::make('redirect_external')
		->beforeValidateUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::redirect('https://evil.example/phish', 'Redirect'))
		->handle(static fn(): string=>'unused');
	$internalUrl=PanelConfig::url('workflow', ['from'=>'child']);
	$internal=Action::make('redirect_internal')
		->beforeValidateUsing(static fn(): PanelLifecycleResult=>PanelLifecycleResult::redirect($internalUrl, 'Redirect'))
		->handle(static fn(): string=>'unused');
	$page=PanelPage::make('workflow')->actions([$external, $internal]);
	$request=static fn(string $action): PanelRequest=>PanelRequest::fromArray([
		'method'=>'GET', 'resource'=>'workflow', 'operation'=>'action', 'action'=>$action, 'user'=>['id'=>7],
	]);

	$blocked=PanelRenderer::pageActionResult($page, $request('redirect_external'), 'redirect_external');
	$t->same(422, $blocked->status());
	$t->same(null, $blocked->headers()['Location'] ?? null);
	$t->same(null, $blocked->data()['lifecycle']['redirect_to'] ?? null);
	$allowed=PanelRenderer::pageActionResult($page, $request('redirect_internal'), 'redirect_internal');
	$t->same($internalUrl, $allowed->headers()['Location'] ?? null);
})->tag('panel', 'renderer', 'page-action', 'redirect', 'security')->maxMillis(2000);

test('resource forms expose a progressive cancel path without leaking route state', static function(Context $t): void {
	$resource=dp_panel_renderer_resource();
	$request=PanelRequest::fromArray([
		'method'=>'GET',
		'resource'=>'orders',
		'operation'=>'create',
		'query'=>[
			'panel_theme'=>'glass',
			'uri'=>'debug/orders/create',
			'operation'=>'create',
			'__panel_partial'=>'modal',
		],
		'user'=>['id'=>7],
	]);
	$html=PanelRenderer::form($resource, $request, null, 'create')->content();
	$t->contains('data-dp-panel-modal-cancel="1"', $html);
	preg_match('/<a[^>]+data-dp-panel-modal-cancel="1"[^>]*>/', $html, $cancelMatch);
	$cancelHtml=(string)($cancelMatch[0] ?? '');
	$t->contains('href="'.htmlspecialchars(PanelConfig::resourceUrl($resource, '', ['panel_theme'=>'glass']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"', $cancelHtml);
	$t->notContains('operation=create', $cancelHtml);
	$t->notContains('__panel_partial', $cancelHtml);

	$returnTo=PanelConfig::resourceUrl($resource, '', ['view'=>'risk']);
	$returnHtml=PanelRenderer::form($resource, PanelRequest::fromArray([
		'method'=>'GET',
		'resource'=>'orders',
		'operation'=>'create',
		'input'=>['return_to'=>$returnTo],
		'user'=>['id'=>7],
	]), null, 'create')->content();
	$canonicalReturn=PanelNavigationTarget::normalize($returnTo);
	$t->isTrue(is_string($canonicalReturn));
	$t->contains('href="'.htmlspecialchars((string)$canonicalReturn, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" data-dp-panel-modal-cancel="1"', $returnHtml);
})->tag('panel', 'renderer', 'form', 'return', 'progressive-enhancement')->maxMillis(3000);
