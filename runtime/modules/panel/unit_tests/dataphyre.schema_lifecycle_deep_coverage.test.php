<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Field;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\ResourceForm;
use Dataphyre\Panel\Schema;
use Dataphyre\Panel\SchemaLifecycle;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel', 'permission']);

test('schema lifecycle covers factories normalized fields metadata and descriptions', static function(Context $t): void {
	$field=Field::make('status')->label('Status')->required()->rules(['required', 'string'])
		->hydrateUsing(static fn(mixed $value): mixed => $value)
		->dehydrateUsing(static fn(mixed $value): mixed => $value)
		->validateUsing(static fn(): array => [])
		->stateUsing(static fn(): array => [], 'kind')
		->live()->dependsOn('kind');
	$lifecycle=SchemaLifecycle::make([$field, 'ignored', new stdClass()], [
		'source'=>'make',
	]);
	$t->same(['status'], array_keys($lifecycle->fields()));
	$t->same(['source'=>'make'], $lifecycle->meta());

	$form=ResourceForm::make()->field($field)->meta(['source'=>'form']);
	$fromForm=SchemaLifecycle::fromForm($form);
	$t->same(['status'], array_keys($fromForm->fields()));
	$t->same('form', $fromForm->meta()['source'] ?? null);

	$schema=Schema::make([$field])->meta(['source'=>'schema']);
	$fromSchema=SchemaLifecycle::fromSchema($schema);
	$t->same(['status'], array_keys($fromSchema->fields()));
	$t->same('schema', $fromSchema->meta()['source'] ?? null);

	$described=SchemaLifecycle::make([
		$field,
		Field::make('readonly')->readonly()->dehydrated(true),
		Field::make(''),
	], ['surface'=>'description'])->describe('edit');
	$t->same('schema_lifecycle', $described['type']);
	$t->same(2, $described['field_count']);
	$t->same('edit', $described['fields']['status']['operation'] ?? null);
	$t->isTrue($described['fields']['status']['required'] ?? false);
	$t->isTrue($described['fields']['status']['live'] ?? false);
	$t->isTrue($described['fields']['status']['reactive'] ?? false);
	$t->isTrue($described['fields']['status']['hydrates'] ?? false);
	$t->isTrue($described['fields']['status']['dehydrates'] ?? false);
	$t->isTrue($described['fields']['status']['validates'] ?? false);
	$t->isTrue($described['fields']['readonly']['dehydrated'] ?? false);
	$t->same(['surface'=>'description'], $described['meta']);
})->tag('panel', 'schema', 'lifecycle', 'coverage')->group('framework-coverage');

test('schema lifecycle covers hydration dehydration validation submission and field skipping', static function(Context $t): void {
	$title=Field::make('title')->default('fallback')->required()
		->hydrateUsing(static fn(mixed $value): string => 'H:'.(string)$value)
		->dehydrateUsing(static fn(mixed $value): string => 'D:'.(string)$value)
		->validateUsing(static fn(mixed $value): ?string => $value==='' ? 'Title custom error.' : null);
	$fields=[
		$title,
		Field::make('prefill_only')->default('prefill-default'),
		Field::make('public_value')->default('public-default'),
		Field::make('getter_value')->default('getter-default'),
		Field::make('missing_value')->default('missing-default'),
		Field::make('avatar')->file(),
		Field::make('readonly_value')->readonly()->default('readonly-default'),
		Field::make('forced_readonly')->readonly()->dehydrated(true)->default('forced-default'),
		Field::make('not_dehydrated')->dehydrated(false),
		Field::make('hidden_value')->visibleUsing(static fn(): bool => false),
	];
	$lifecycle=SchemaLifecycle::make($fields, ['lifecycle_trace_prefix'=>'']);
	$record=new class {
		public string $public_value='public-property';
		public function getGetterValue(): string {
			return 'getter-method';
		}
	};
	$hydrateRequest=PanelRequest::fromArray([
		'operation'=>'edit',
		'query'=>['prefill'=>[
			'title'=>'prefilled-title',
			'prefill_only'=>'query-prefill',
		]],
		'input'=>['title'=>'submitted-title'],
	]);
	$hydrated=$lifecycle->hydrate($record, $hydrateRequest);
	$t->same('H:submitted-title', $hydrated->value('title'));
	$t->same('query-prefill', $hydrated->value('prefill_only'));
	$t->same('public-property', $hydrated->value('public_value'));
	$t->same('getter-method', $hydrated->value('getter_value'));
	$t->same('missing-default', $hydrated->value('missing_value'));
	$t->same('hydrate', $hydrated->mode());
	$t->same('schema', $hydrated->meta()['lifecycle'] ?? null);

	$nullRequestHydration=SchemaLifecycle::make([Field::make('plain')->default('plain-default')])->hydrate();
	$t->same('plain-default', $nullRequestHydration->value('plain'));

	$blankUpload=['name'=>'', 'type'=>'', 'tmp_name'=>'', 'error'=>UPLOAD_ERR_NO_FILE, 'size'=>0];
	$submitRequest=PanelRequest::fromArray([
		'method'=>'POST',
		'operation'=>'edit',
		'input'=>[
			'title'=>'hello',
			'readonly_value'=>'client-readonly',
			'forced_readonly'=>'client-forced',
			'not_dehydrated'=>'client-skip',
			'hidden_value'=>'client-hidden',
		],
		'files'=>['avatar'=>$blankUpload],
	]);
	$dehydrated=$lifecycle->dehydrate($submitRequest, [
		'avatar'=>'stored-avatar.pdf',
	]);
	$t->same('D:hello', $dehydrated->value('title'));
	$t->same('stored-avatar.pdf', $dehydrated->value('avatar'));
	$t->same('client-forced', $dehydrated->value('forced_readonly'));
	$t->isFalse(array_key_exists('readonly_value', $dehydrated->values()));
	$t->isFalse(array_key_exists('not_dehydrated', $dehydrated->values()));
	$t->isFalse(array_key_exists('hidden_value', $dehydrated->values()));
	$t->same('dehydrate', $dehydrated->mode());
	$t->same('hello', $dehydrated->rawValues()['title'] ?? null);

	$invalid=$lifecycle->validate(['title'=>''], null, $submitRequest);
	$t->isTrue($invalid->invalid());
	$t->contains('Title is required.', $invalid->fieldErrors('title'));
	$t->contains('Title custom error.', $invalid->fieldErrors('title'));
	$t->isFalse(array_key_exists('readonly_value', $invalid->errors()));
	$t->isFalse(array_key_exists('hidden_value', $invalid->errors()));
	$t->same('validate', $invalid->mode());

	$submitted=$lifecycle->submit($submitRequest, ['avatar'=>'stored-avatar.pdf']);
	$t->isTrue($submitted->valid());
	$t->same('D:hello', $submitted->value('title'));
})->tag('panel', 'schema', 'lifecycle', 'coverage')->group('framework-coverage');

test('schema lifecycle resolves cascading live state and dehydrates against resolved siblings', static function(Context $t): void {
	$country=Field::make('country')->stateUsing(static fn(): array => [
		'value'=>'CA',
		'set'=>[
			'target'=>['value'=>'server-target', 'force_value'=>true, 'propagate'=>true],
			'plain'=>'server-plain',
			'external'=>'server-external',
			'...'=>'ignored',
		],
		'fields'=>'not-an-array',
	]);
	$fields=[
		$country,
		Field::make('postal')->postalCodeCountryField('country'),
		Field::make('target'),
		Field::make('plain'),
		Field::make('unchanged')->default('same'),
		Field::make('request_only'),
		Field::make('explicit'),
		Field::make('blank_upload')->file(),
		Field::make('file_ok')->file(),
	];
	$lifecycle=SchemaLifecycle::make($fields, ['source'=>'live']);
	$blankMultiple=[
		'name'=>['', ''],
		'type'=>['', ''],
		'tmp_name'=>['', ''],
		'error'=>[UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_FILE],
		'size'=>[0, 0],
	];
	$validFile=['name'=>'new.txt', 'type'=>'text/plain', 'tmp_name'=>__FILE__, 'error'=>UPLOAD_ERR_OK, 'size'=>1];
	$request=PanelRequest::fromArray([
		'method'=>'POST',
		'operation'=>'edit',
		'query'=>['prefill'=>['unchanged'=>'same']],
		'input'=>[
			'country'=>'US',
			'postal'=>'h2x 1y4',
			'target'=>'client-target',
			'plain'=>'client-plain',
			'unchanged'=>'same',
			'request_only'=>'request-value',
		],
		'files'=>['blank_upload'=>$blankMultiple, 'file_ok'=>$validFile],
	]);
	$record=[
		'country'=>'US',
		'postal'=>'old-postal',
		'target'=>'old-target',
		'plain'=>'old-plain',
		'unchanged'=>'same',
		'request_only'=>'old-request',
		'explicit'=>'old-explicit',
		'blank_upload'=>'stored.pdf',
		'file_ok'=>'old.txt',
	];

	[$resolved, $updates, $serverValues]=$lifecycle->resolveLiveState([
		'country'=>'US', 'postal'=>'h2x 1y4', 'target'=>'client-target', 'plain'=>'client-plain',
	], $record, $request, 'edit');
	$t->same('CA', $resolved['country'] ?? null);
	$t->same('server-target', $resolved['target'] ?? null);
	$t->same('server-plain', $resolved['plain'] ?? null);
	$t->same('server-external', $resolved['external'] ?? null);
	$t->same([], $updates['postal'] ?? null);
	$t->isTrue($serverValues['target']['force_value'] ?? false);
	$t->isTrue($serverValues['target']['propagate'] ?? false);
	$t->isFalse($serverValues['plain']['force_value'] ?? true);

	$dehydrated=$lifecycle->dehydrate($request, $record);
	$t->same('CA', $dehydrated->value('country'));
	$t->same('H2X1Y4', $dehydrated->value('postal'));
	$t->same('server-target', $dehydrated->value('target'));

	$state=$lifecycle->state($record, $request, null, ['explicit'=>'explicit-input'], true);
	$t->same('CA', $state->value('country'));
	$t->same('server-target', $state->value('target'));
	$t->same('server-external', $state->value('external'));
	$t->same('explicit-input', $state->value('explicit'));
	$t->same('stored.pdf', $state->value('blank_upload'));
	$t->same('new.txt', $state->value('file_ok')['name'] ?? null);
	$t->same('H2X1Y4', $state->dehydratedValue('postal'));
	$t->isTrue($state->isDirty('external'));
	$t->isTrue($state->serverValues()['target']['force_value'] ?? false);
	$t->isTrue($state->valid());
	$t->same('state', $state->mode());

	$emptyLifecycle=SchemaLifecycle::make([Field::make('')]);
	$t->same([[], [], []], $emptyLifecycle->resolveLiveState([]));
	$t->same([], $emptyLifecycle->state()->values());
	$t->same(0, $emptyLifecycle->describe()['field_count']);
})->tag('panel', 'schema', 'lifecycle', 'coverage')->group('framework-coverage');

test('schema lifecycle private helpers cover records prefill uploads and stable comparisons', static function(Context $t): void {
	$t->same('array-value', $t->nonPublic(SchemaLifecycle::class)->invoke('recordValue',['code'=>'array-value'], 'code', 'default'));
	$t->same('default', $t->nonPublic(SchemaLifecycle::class)->invoke('recordValue',[], 'code', 'default'));
	$record=new class {
		public string $public_value='public-value';
		public function getOrderCode(): string {
			return 'getter-value';
		}
	};
	$t->same('public-value', $t->nonPublic(SchemaLifecycle::class)->invoke('recordValue',$record, 'public_value', 'default'));
	$t->same('getter-value', $t->nonPublic(SchemaLifecycle::class)->invoke('recordValue',$record, 'order_code', 'default'));
	$t->same('default', $t->nonPublic(SchemaLifecycle::class)->invoke('recordValue',$record, 'missing', 'default'));
	$t->same('default', $t->nonPublic(SchemaLifecycle::class)->invoke('recordValue',null, 'missing', 'default'));

	$invalidPrefill=PanelRequest::fromArray(['query'=>['prefill'=>'invalid']]);
	$t->same([], $t->nonPublic(SchemaLifecycle::class)->invoke('prefillValues',$invalidPrefill));
	$validPrefill=PanelRequest::fromArray(['query'=>['prefill'=>[
		' First Name '=>'Ada',
		'count'=>2,
		'nullable'=>null,
		'nested'=>['ignored'],
		'...'=>'ignored',
	]]]);
	$t->same([
		'first_name'=>'Ada', 'count'=>2, 'nullable'=>null,
	], $t->nonPublic(SchemaLifecycle::class)->invoke('prefillValues',$validPrefill));

	$t->same(true, $t->nonPublic(SchemaLifecycle::class)->invoke('fileInputBlank','not-an-upload'));
	$t->same(true, $t->nonPublic(SchemaLifecycle::class)->invoke('fileInputBlank',['name'=>'', 'error'=>UPLOAD_ERR_OK]));
	$t->same(false, $t->nonPublic(SchemaLifecycle::class)->invoke('fileInputBlank',['name'=>'one.txt', 'error'=>UPLOAD_ERR_OK]));
	$t->same(false, $t->nonPublic(SchemaLifecycle::class)->invoke('fileInputBlank',[
		'name'=>['', 'two.txt'], 'error'=>[UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK],
	]));
	$t->same(true, $t->nonPublic(SchemaLifecycle::class)->invoke('fileInputBlank',[
		'name'=>['', ''], 'error'=>[UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_FILE],
	]));
	$t->same(true, $t->nonPublic(SchemaLifecycle::class)->invoke('fileInputBlank',[]));
	$t->same(false, $t->nonPublic(SchemaLifecycle::class)->invoke('fileInputBlank',['unexpected'=>true]));

	$t->same(true, $t->nonPublic(SchemaLifecycle::class)->invoke('valuesMatch',true, 1));
	$t->same(false, $t->nonPublic(SchemaLifecycle::class)->invoke('valuesMatch',false, 1));
	$t->same(true, $t->nonPublic(SchemaLifecycle::class)->invoke('valuesMatch',1, '1'));
	$t->same(true, $t->nonPublic(SchemaLifecycle::class)->invoke('valuesMatch',['b'=>2, 'a'=>['y'=>2, 'x'=>1]],
		(object)['a'=>(object)['x'=>1, 'y'=>2], 'b'=>2],));
	$t->same(false, $t->nonPublic(SchemaLifecycle::class)->invoke('valuesMatch',[1, 2], [2, 1]));
	$t->same('[1,2]', $t->nonPublic(SchemaLifecycle::class)->invoke('stableValue',[1, 2]));
	$t->same('scalar', $t->nonPublic(SchemaLifecycle::class)->invoke('normalizeComparableValue','scalar'));

	$stateValues=['same'=>'value'];
	$serverValues=[];
	$resolved=$t->nonPublic(SchemaLifecycle::class)->capture(
		'applyResolvedStateValue',
		stateValues:$stateValues,
		serverValues:$serverValues,
		name:'same',
		value:'value',
		flags:['force_value'=>true],
	);
	$t->same(false,$resolved->result());
	$t->isTrue($resolved->argument('serverValues')['same']['force_value'] ?? false);
})->tag('panel', 'schema', 'lifecycle', 'coverage')->group('framework-coverage');
