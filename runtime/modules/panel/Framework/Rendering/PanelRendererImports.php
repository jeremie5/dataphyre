<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Renders Panel import, export, and status transition result flows.
 *
 * The trait is mixed into the Panel renderer and forms the HTTP boundary for
 * CSV/JSON exports, CSV imports, import previews, single-record transitions,
 * and bulk transitions. Methods consistently enforce PanelConfig feature flags,
 * resource authorization, request method requirements, trace events, and result
 * metadata before returning PanelPageResult responses.
 */
trait PanelRendererImports {
	/**
	 * Streams the current resource records as CSV or JSON.
	 *
	 * Records are view-filtered, request-filtered, and sorted unless the caller
	 * already supplied a paginated or selected subset. Column values are pulled
	 * through the export column contract so hidden UI columns do not accidentally
	 * leak into downloaded data.
	 *
	 * @param Resource $resource Resource whose export policy and columns are used.
	 * @param PanelRequest $request Request carrying filters, view state, and requested export format.
	 * @param array<int, mixed> $records Records to export; empty means the caller intentionally exports no rows.
	 * @param ?int $totalRecords Optional total count for callers that already paginated records.
	 * @param bool $alreadyPaginated True when records have already been filtered and sorted by the caller.
	 * @return PanelPageResult CSV download, JSON download, forbidden response, or export failure response.
	 */
	public static function exportCsv(Resource $resource, PanelRequest $request, array $records=[], ?int $totalRecords=null, bool $alreadyPaginated=false): PanelPageResult {
		if(!PanelConfig::resourceExportsEnabled()){
			return self::forbidden($resource, $request);
		}
		$request=$resource->requestWithResolvedView($request);
		if(!$alreadyPaginated){
			$records=self::applyTableView($records, $resource, $request, self::activeTableViewName($resource, $request));
			$records=self::applyFilters($records, $resource, $request);
			$records=self::filterRecords($records, $resource, $request);
			$records=self::sortRecords($records, $resource, $request);
		}
		$columns=self::exportColumns($resource, $request);
		$format=self::exportFormat($request);
		PanelTrace::record('export.'.$format, [
			'resource'=>$resource,
			'request'=>$request,
			'record_count'=>count($records),
			'column_count'=>count($columns),
		]);
		if($format==='json'){
			return self::exportJsonResult($resource, $request, $records, $columns, $resource->name().'-'.date('Ymd-His').'.json', 'export');
		}
		$handle=fopen('php://temp', 'r+');
		if($handle===false){
			return PanelPageResult::html(self::panelText('import.unable_export'), 500, [
				'kind'=>'export',
				'resource'=>$resource->toArray(),
			]);
		}
		fputcsv($handle, array_map(static fn(Column $column): string => (string)($column->toArray()['label'] ?? $column->name()), array_values($columns)), ',', '"', '');
		foreach($records as $record){
			$row=[];
			foreach($columns as $column){
				$row[]=self::stringValue($column->exportValue($record));
			}
			fputcsv($handle, $row, ',', '"', '');
		}
		rewind($handle);
		$csv=(string)stream_get_contents($handle);
		fclose($handle);
		return PanelPageResult::csv($csv, $resource->name().'-'.date('Ymd-His').'.csv', [
			'kind'=>'export',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'record_count'=>count($records),
			'visible_columns'=>array_keys($columns),
		]);
	}

	/**
	 * Exports only the records selected by a bulk action request.
	 *
	 * Bulk export requires POST, export and bulk_export authorization, and a
	 * non-empty selection. The selected records bypass table pagination so the
	 * download contains exactly what the operator selected.
	 *
	 * @param Resource $resource Resource that owns the selected records.
	 * @param PanelRequest $request Bulk POST request containing selected keys and export format.
	 * @return PanelPageResult Download response or an empty/forbidden status page.
	 */
	public static function bulkExportCsv(Resource $resource, PanelRequest $request): PanelPageResult {
		PanelTrace::record('bulk_export.start', [
			'resource'=>$resource,
			'request'=>$request,
		]);
		if(!PanelConfig::resourceExportsEnabled()){
			return self::forbidden($resource, $request);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('export.selected_requires_selection', 'export.selected_requires_selection_body', [
				'kind'=>'bulk_export_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($resource->can('export', null, $request->user())===false || $resource->can('bulk_export', null, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$records=self::selectedRecords($resource, $request);
		if($records===[]){
			return self::panelEmptyPage('action.empty_selection', 'export.no_records_body', [
				'kind'=>'bulk_export_empty_selection',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 422);
		}
		PanelTrace::record('bulk_export.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'record_count'=>count($records),
		]);
		$result=self::exportCsv($resource, $request, $records, count($records), true);
		if(self::exportFormat($request)==='json'){
			$payload=json_decode($result->content(), true);
			return PanelPageResult::jsonDownload(is_array($payload) ? $payload : [], $resource->name().'-selected-'.date('Ymd-His').'.json', array_replace($result->data(), [
				'kind'=>'bulk_export',
				'selected_count'=>count($records),
			]));
		}
		return PanelPageResult::csv($result->content(), $resource->name().'-selected-'.date('Ymd-His').'.csv', array_replace($result->data(), [
			'kind'=>'bulk_export',
			'selected_count'=>count($records),
		]));
	}

	/**
	 * Renders the CSV import form for a resource.
	 *
	 * The form shows accepted columns, CSV input choices, delimiter controls, and
	 * a template link. It is returned only when imports are globally enabled and
	 * the resource declares that it can import records.
	 *
	 * @param Resource $resource Resource being imported into.
	 * @param PanelRequest $request Current request used for return URL and metadata.
	 * @param ?string $message Optional validation message displayed above the form.
	 * @param int $status HTTP status for re-rendered forms.
	 * @return PanelPageResult HTML form or import-unavailable response.
	 */
	public static function importForm(Resource $resource, PanelRequest $request, ?string $message=null, int $status=200): PanelPageResult {
		PanelTrace::record('import.form', [
			'resource'=>$resource,
			'request'=>$request,
		]);
		if(!PanelConfig::resourceImportsEnabled() || $resource->canImport()===false){
			return self::panelEmptyPage('import.unavailable', 'import.unavailable_body', [
				'kind'=>'import_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		$columns=self::importableColumns($resource);
		$columnLabels=array_map(static fn(array $column): string => $column['label'].' ('.$column['name'].')', $columns);
		$expected=$columnLabels!==[]
			? '<p class="dp-panel-empty">'.self::e(self::panelText('import.expected_columns', ['columns'=>implode(', ', $columnLabels)])).'</p>'
			: '<p class="dp-panel-empty">'.self::e(self::panelText('import.pass_through')).'</p>';
		$alert=$message!==null && trim($message)!=='' ? '<div class="dp-panel-alert">'.self::e($message).'</div>' : '';
		$content='<form class="dp-panel-form" method="post" enctype="multipart/form-data" action="'.self::e(PanelConfig::resourceUrl($resource, 'import')).'">'
			.self::csrfInput()
			.self::returnInput($resource, $request)
			.$alert
			.'<section class="dp-panel-form-section"><header><h2>'.self::e(self::panelText('import.csv')).'</h2><p>'.self::e(self::panelText('import.csv_description')).'</p></header><div class="dp-panel-form-grid dp-panel-form-grid-1">'
			.'<label class="dp-panel-field"><span>'.self::e(self::panelText('import.csv_file')).'</span><input type="file" name="csv_file" accept=".csv,text/csv"></label>'
			.'<label class="dp-panel-field"><span>'.self::e(self::panelText('import.csv_rows')).'</span><textarea name="csv_data" rows="10" placeholder="name,title,status"></textarea><small class="dp-panel-help">'.self::e(self::panelText('import.csv_rows_help')).'</small></label>'
			.'<label class="dp-panel-field"><span>'.self::e(self::panelText('import.delimiter')).'</span><select name="delimiter"><option value="auto">'.self::e(self::panelText('import.delimiter_auto')).'</option><option value=",">'.self::e(self::panelText('import.delimiter_comma')).'</option><option value=";">'.self::e(self::panelText('import.delimiter_semicolon')).'</option><option value="tab">'.self::e(self::panelText('import.delimiter_tab')).'</option><option value="|">'.self::e(self::panelText('import.delimiter_pipe')).'</option></select></label>'
			.'<label class="dp-panel-field"><span>'.self::e(self::panelText('import.has_header')).'</span><input type="hidden" name="has_header" value="0"><input type="checkbox" name="has_header" value="1" checked></label>'
			.'</div>'.$expected.'</section>'
			.'<div class="dp-panel-toolbar"><div class="dp-panel-toolbar-actions"><button class="dp-panel-button" type="submit">'.self::e(self::panelText('import.csv_submit')).'</button><a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::resourceUrl($resource, 'import_template')).'">'.self::e(self::panelText('import.download_template')).'</a></div><a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::resourceUrl($resource)).'">'.self::e(self::panelText('common.cancel')).'</a></div>'
			.'</form>';
		return self::page(self::panelText('import.page_title', ['resource'=>(string)$resource->label()]), $content, [
			'kind'=>'import',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'import_columns'=>$columns,
		], $status);
	}

	/**
	 * Downloads a CSV template for the resource import contract.
	 *
	 * The template uses importable column labels as headers and includes a sample
	 * row when columns provide example values. Authorization is checked separately
	 * from the form so projects can expose import help without exposing import
	 * execution.
	 *
	 * @param Resource $resource Resource that defines importable columns.
	 * @param PanelRequest $request Current request and operator context.
	 * @return PanelPageResult CSV template download or an unavailable/forbidden response.
	 */
	public static function importTemplateCsv(Resource $resource, PanelRequest $request): PanelPageResult {
		PanelTrace::record('import.template', [
			'resource'=>$resource,
			'request'=>$request,
		]);
		if(!PanelConfig::resourceImportsEnabled() || $resource->canImport()===false){
			return self::panelEmptyPage('import.template_unavailable', 'import.template_unavailable_body', [
				'kind'=>'import_template_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($resource->can('import', null, $request->user())===false || $resource->can('import_template', null, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$columns=self::importableColumns($resource);
		$headers=array_map(static fn(array $column): string => (string)($column['label'] ?? $column['name'] ?? ''), $columns);
		$sample=array_map(static fn(array $column): string => (string)($column['sample'] ?? ''), $columns);
		$handle=fopen('php://temp', 'r+');
		if($handle===false){
			return PanelPageResult::html(self::panelText('import.unable_template'), 500, [
				'kind'=>'import_template',
				'resource'=>$resource->toArray(),
			]);
		}
		fputcsv($handle, $headers, ',', '"', '');
		if(array_filter($sample, static fn(string $value): bool => $value!=='')!==[]){
			fputcsv($handle, $sample, ',', '"', '');
		}
		rewind($handle);
		$csv=(string)stream_get_contents($handle);
		fclose($handle);
		return PanelPageResult::csv($csv, $resource->name().'-import-template.csv', [
			'kind'=>'import_template',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'import_columns'=>$columns,
		]);
	}

	/**
	 * Validates and executes a CSV import request.
	 *
	 * Import is a two-phase flow: parse and validate first, then require the
	 * `__panel_import_confirm=1` confirmation before calling the resource import
	 * hook. Invalid rows return to the preview instead of mutating records.
	 *
	 * @param Resource $resource Resource receiving imported rows.
	 * @param PanelRequest $request POST request containing uploaded or pasted CSV data.
	 * @return PanelPageResult Import preview, validation form, forbidden page, or redirect with notifications.
	 */
	public static function importResult(Resource $resource, PanelRequest $request): PanelPageResult {
		PanelTrace::record('import.start', [
			'resource'=>$resource,
			'request'=>$request,
		]);
		if(!PanelConfig::resourceImportsEnabled() || $resource->canImport()===false){
			return self::panelEmptyPage('import.unavailable', 'import.unavailable_body', [
				'kind'=>'import_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if($resource->can('import', null, $request->user())===false){
			return self::forbidden($resource, $request);
		}
		$csv=self::importCsvPayload($request);
		if(trim($csv)===''){
			return self::importForm($resource, $request, self::panelText('import.choose_csv'), 422);
		}
		$parsed=self::parseImportCsv($resource, $request, $csv);
		if(($parsed['rows'] ?? [])===[]){
			return self::importForm($resource, $request, self::panelText('import.no_rows'), 422);
		}
		$rows=$parsed['rows'];
		$validation=self::importValidation($resource, $request, $rows);
		if((string)$request->input('__panel_import_confirm', '')!=='1'){
			return self::importPreview($resource, $request, $csv, $parsed, $validation);
		}
		if(($validation['invalid_count'] ?? 0)>0){
			return self::importPreview($resource, $request, $csv, $parsed, $validation, self::panelText('import.resolve_rows'), 422);
		}
		$result=$resource->importRecords($rows, $request);
		$summary=self::importResultSummary($result, count($rows));
		$message=self::panelText('import.imported', ['count'=>$summary['imported_count'], 'row'=>self::panelText($summary['imported_count']===1 ? 'import.row' : 'common.records')]);
		$outcome=self::outcome($result, $message);
		$notifications=$outcome['notifications'];
		if($summary['failed_count']>0){
			$notifications[]=PanelNotification::warning(self::panelText('import.failed', ['count'=>$summary['failed_count'], 'row'=>self::panelText($summary['failed_count']===1 ? 'import.row' : 'common.records')]));
		}
		if($summary['imported_count']===0 && $summary['failed_count']===0){
			$notifications[]=PanelNotification::info(self::panelText('import.none_imported'));
		}
		$redirect=$outcome['redirect'] ?? null;
		if($redirect===null){
			$redirect=self::actionReturnUrl($resource, $request);
		}
		PanelTrace::record('import.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'row_count'=>count($rows),
			'imported_count'=>$summary['imported_count'],
			'failed_count'=>$summary['failed_count'],
			'skipped_columns'=>$parsed['skipped_columns'] ?? [],
			'redirect'=>$redirect,
		]);
		self::flashNotifications($notifications);
		return PanelPageResult::redirect($redirect, [
			'kind'=>'import',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'rows'=>$rows,
			'row_count'=>count($rows),
			'imported_count'=>$summary['imported_count'],
			'failed_count'=>$summary['failed_count'],
			'skipped_columns'=>$parsed['skipped_columns'] ?? [],
			'validation'=>$validation,
			'result'=>$outcome['result'],
		], $notifications, $outcome['status']);
	}

	/**
	 * Applies a named status transition to a single record.
	 *
	 * The transition must exist, be available for the record, pass both generic
	 * and transition-specific authorization, and be submitted over POST before the
	 * resource mutation hook is called.
	 *
	 * @param Resource $resource Resource that defines status transitions.
	 * @param PanelRequest $request POST request carrying the transition name and operator context.
	 * @param mixed $record Target record resolved by the controller.
	 * @return PanelPageResult Redirect with transition outcome or an explanatory status page.
	 */
	public static function transitionResult(Resource $resource, PanelRequest $request, mixed $record=null): PanelPageResult {
		$transitionName=Resource::normalizeName((string)($request->input('transition', $request->query('transition', ''))));
		$transition=$transitionName!=='' ? $resource->statusTransition($transitionName) : null;
		PanelTrace::record('transition.start', [
			'resource'=>$resource,
			'request'=>$request,
			'record'=>$record,
			'transition'=>$transitionName,
		]);
		if($resource->canTransition()===false){
			return self::panelEmptyPage('transition.unavailable', 'transition.unavailable_body', [
				'kind'=>'transition_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('transition.requires_confirmation', 'transition.requires_confirmation_body', [
				'kind'=>'transition_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if($record===null){
			return self::panelEmptyPage('record.not_found', 'record.not_found_body', [
				'kind'=>'transition_missing_record',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(!is_array($transition)){
			return self::panelEmptyPage('transition.not_found', 'transition.not_found_body', [
				'kind'=>'transition_missing',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
				'transition'=>$transitionName,
			], 404);
		}
		$available=$resource->statusTransitionsList($record);
		if(!isset($available[$transitionName])){
			return self::panelEmptyPage('transition.not_available', 'transition.not_available_body', [
				'kind'=>'transition_not_available',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
				'transition'=>$transition,
			], 409);
		}
		if(
			$resource->can('transition', $record, $request->user())===false
			|| $resource->can('transition:'.$transitionName, $record, $request->user())===false
		){
			return self::forbidden($resource, $request);
		}
		$result=$resource->applyTransition($transitionName, $record, $request);
		$outcome=self::outcome($result, self::panelText('transition.completed', ['transition'=>(string)$transition['label']]));
		if($outcome['redirect']===null){
			$outcome['redirect']=self::actionReturnUrl($resource, $request);
		}
		PanelTrace::record('transition.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'transition'=>$transition,
			'redirect'=>$outcome['redirect'],
			'notifications'=>count($outcome['notifications']),
		]);
		self::flashNotifications($outcome['notifications']);
		return PanelPageResult::redirect($outcome['redirect'], [
			'kind'=>'transition',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'transition'=>$transition,
			'result'=>$outcome['result'],
		], $outcome['notifications'], $outcome['status']);
	}

	/**
	 * Applies a named status transition to selected records.
	 *
	 * Each selected record is checked independently for transition availability
	 * and authorization. Successful, unavailable, failed, and denied keys are all
	 * reported so the redirected table can explain partial outcomes.
	 *
	 * @param Resource $resource Resource that defines the transition and selected records.
	 * @param PanelRequest $request Bulk POST request carrying selected keys and transition name.
	 * @return PanelPageResult Redirect with grouped outcomes or a status page when the request cannot run.
	 */
	public static function bulkTransitionResult(Resource $resource, PanelRequest $request): PanelPageResult {
		$transitionName=Resource::normalizeName((string)($request->input('transition', $request->query('transition', ''))));
		$transition=$transitionName!=='' ? $resource->statusTransition($transitionName) : null;
		PanelTrace::record('bulk_transition.start', [
			'resource'=>$resource,
			'request'=>$request,
			'transition'=>$transitionName,
		]);
		if($resource->canTransition()===false){
			return self::panelEmptyPage('transition.bulk_unavailable', 'transition.unavailable_body', [
				'kind'=>'bulk_transition_unavailable',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 404);
		}
		if(strtoupper($request->method())!=='POST'){
			return self::panelEmptyPage('transition.bulk_requires_selection', 'transition.bulk_requires_selection_body', [
				'kind'=>'bulk_transition_requires_post',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
			], 405);
		}
		if(!is_array($transition)){
			return self::panelEmptyPage('transition.not_found', 'transition.not_found_body', [
				'kind'=>'bulk_transition_missing',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
				'transition'=>$transitionName,
			], 404);
		}
		$records=self::selectedRecords($resource, $request);
		if($records===[]){
			return self::panelEmptyPage('action.empty_selection', 'transition.no_records_body', [
				'kind'=>'bulk_transition_empty_selection',
				'resource'=>$resource->toArray(),
				'request'=>$request->toArray(),
				'transition'=>$transition,
			], 422);
		}
		$transitioned=[];
		$unavailable=[];
		$failed=[];
		$denied=[];
		$results=[];
		foreach($records as $record){
			$key=$resource->recordKey($record);
			$available=$resource->statusTransitionsList($record);
			if(!isset($available[$transitionName])){
				$unavailable[]=$key;
				continue;
			}
			if(
				$resource->can('transition', $record, $request->user())===false
				|| $resource->can('transition:'.$transitionName, $record, $request->user())===false
			){
				$denied[]=$key;
				continue;
			}
			try{
				$result=$resource->applyTransition($transitionName, $record, $request);
				$results[$key]=$result;
				if(self::transitionOutcomeSucceeded($result)){
					$transitioned[]=$key;
				}
				else {
					$failed[]=$key;
				}
			}
			catch(\Throwable $exception){
				$failed[]=$key;
				PanelTrace::record('bulk_transition.error', [
					'resource'=>$resource,
					'record_key'=>$key,
					'transition'=>$transitionName,
					'message'=>$exception->getMessage(),
				]);
			}
		}
		$notifications=[];
		if($transitioned!==[]){
			$notifications[]=PanelNotification::success(self::panelText('transition.bulk_changed', ['count'=>count($transitioned), 'record'=>self::panelText(count($transitioned)===1 ? 'common.record' : 'common.records'), 'status'=>(string)$transition['to']]));
		}
		if($unavailable!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('transition.bulk_unavailable_records', ['count'=>count($unavailable), 'record'=>self::panelText(count($unavailable)===1 ? 'common.record' : 'common.records')]));
		}
		if($failed!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('transition.bulk_failed', ['count'=>count($failed), 'record'=>self::panelText(count($failed)===1 ? 'common.record' : 'common.records')]));
		}
		if($denied!==[]){
			$notifications[]=PanelNotification::warning(self::panelText('transition.bulk_denied', ['count'=>count($denied), 'record'=>self::panelText(count($denied)===1 ? 'common.record' : 'common.records')]));
		}
		if($notifications===[]){
			$notifications[]=PanelNotification::info(self::panelText('transition.bulk_none'));
		}
		$redirect=self::actionReturnUrl($resource, $request);
		PanelTrace::record('bulk_transition.completed', [
			'resource'=>$resource,
			'request'=>$request,
			'transition'=>$transition,
			'transitioned_count'=>count($transitioned),
			'unavailable_count'=>count($unavailable),
			'failed_count'=>count($failed),
			'denied_count'=>count($denied),
			'redirect'=>$redirect,
		]);
		self::flashNotifications($notifications);
		return PanelPageResult::redirect($redirect, [
			'kind'=>'bulk_transition',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'transition'=>$transition,
			'transitioned'=>$transitioned,
			'unavailable'=>$unavailable,
			'failed'=>$failed,
			'denied'=>$denied,
			'results'=>$results,
		], $notifications);
	}

	/**
	 * Renders the import preview and confirmation screen.
	 *
	 * The preview keeps the submitted CSV payload in a hidden field, displays the
	 * parsed mapping and row validation details, and disables final confirmation
	 * while invalid rows remain. It does not call the resource import hook.
	 *
	 * @param Resource $resource Resource receiving the import.
	 * @param PanelRequest $request Current request with delimiter and header options.
	 * @param string $csv Original CSV payload that will be resubmitted on confirmation.
	 * @param array<string, mixed> $parsed Parser output containing rows, mappings, and skipped columns.
	 * @param array<string, mixed> $validation Row validation result with invalid counts and row errors.
	 * @param ?string $message Optional message shown above the preview.
	 * @param int $status HTTP status for the preview response.
	 * @return PanelPageResult HTML preview page with confirmation controls.
	 */
	private static function importPreview(Resource $resource, PanelRequest $request, string $csv, array $parsed, array $validation, ?string $message=null, int $status=200): PanelPageResult {
		$rows=$parsed['rows'] ?? [];
		$rowCount=count($rows);
		$invalidCount=(int)($validation['invalid_count'] ?? 0);
		$skipped=$parsed['skipped_columns'] ?? [];
		$alert=$message!==null && trim($message)!==''
			? '<div class="dp-panel-alert">'.self::e($message).'</div>'
			: ($invalidCount>0 ? '<div class="dp-panel-alert">'.$invalidCount.' row'.($invalidCount===1 ? '' : 's').' need attention before import.</div>' : '');
		$skippedHtml=is_array($skipped) && $skipped!==[]
			? '<p class="dp-panel-empty">Skipped columns: '.self::e(implode(', ', array_map('strval', $skipped))).'</p>'
			: '';
		$hidden='<input type="hidden" name="delimiter" value="'.self::e((string)$request->input('delimiter', 'auto')).'">'
			.'<input type="hidden" name="has_header" value="'.(self::truthy($request->input('has_header', '1')) ? '1' : '0').'">'
			.self::returnInput($resource, $request)
			.'<textarea name="csv_data" hidden>'.self::e($csv).'</textarea>';
		$content='<form class="dp-panel-form" method="post" action="'.self::e(PanelConfig::resourceUrl($resource, 'import')).'">'
			.self::csrfInput()
			.$hidden
			.$alert
			.'<section class="dp-panel-form-section"><header><h2>'.self::e(self::panelText('import.preview')).'</h2><p>'.self::e(self::panelText('import.preview_summary', ['rows'=>$rowCount, 'row'=>self::panelText($rowCount===1 ? 'import.row' : 'common.records'), 'invalid'=>$invalidCount])).'</p></header>'
			.self::importMappingHtml($resource, $parsed)
			.$skippedHtml
			.self::importPreviewTable($rows, $validation)
			.'</section>'
			.'<div class="dp-panel-toolbar"><div class="dp-panel-toolbar-actions"><button class="dp-panel-button dp-panel-button-secondary" type="submit" name="__panel_import_confirm" value="0">'.self::e(self::panelText('import.refresh_preview')).'</button><button class="dp-panel-button" type="submit" name="__panel_import_confirm" value="1"'.($invalidCount>0 ? ' disabled' : '').'>'.self::e(self::panelText('import.import_rows', ['rows'=>$rowCount, 'row'=>self::panelText($rowCount===1 ? 'import.row' : 'common.records')])).'</button></div><a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::resourceUrl($resource, 'import')).'">'.self::e(self::panelText('import.change_csv')).'</a></div>'
			.'</form>';
		PanelTrace::record('import.preview', [
			'resource'=>$resource,
			'request'=>$request,
			'row_count'=>$rowCount,
			'invalid_count'=>$invalidCount,
			'skipped_columns'=>$skipped,
		]);
		return self::page(self::panelText('import.preview_title'), $content, [
			'kind'=>'import_preview',
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'row_count'=>$rowCount,
			'invalid_count'=>$invalidCount,
			'skipped_columns'=>$skipped,
			'validation'=>$validation,
		], $status);
	}

}
