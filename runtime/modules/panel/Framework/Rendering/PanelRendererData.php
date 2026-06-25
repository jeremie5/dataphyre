<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Builds downloadable data responses for Panel resources.
 *
 * Export helpers reuse the visible table configuration so JSON and CSV
 * downloads reflect the operator's active resource view.
 */
trait PanelRendererData {
	/**
	 * Resolves the visible columns used by a panel export request.
	 *
	 * Export visibility follows the same resource table and saved preference logic
	 * as the interactive table renderer, so CSV and JSON downloads mirror the
	 * operator's selected column set rather than exposing hidden columns by default.
	 *
	 * @param Resource $resource Resource whose table is being exported.
	 * @param PanelRequest $request Current panel request and table preference scope.
	 * @return array<string, Column> Visible export columns keyed by column name.
	 */
	private static function exportColumns(Resource $resource, PanelRequest $request): array {
		return $resource->resourceTable()->visibleColumnsFor($request, $resource, self::tablePreferences($request));
	}

	/**
	 * Resolves the requested export format for a Panel data response.
	 *
	 * Export URLs accept `format=json`; every other value falls back to CSV so
	 * download routes remain deterministic and safe for spreadsheet-first users.
	 *
	 * @param PanelRequest $request Current Panel request.
	 * @return string Either `json` or `csv`.
	 */
	private static function exportFormat(PanelRequest $request): string {
		return strtolower((string)$request->query('format', ''))==='json' ? 'json' : 'csv';
	}

	/**
	 * Builds a structured JSON download for exported Panel records.
	 *
	 * Column metadata is reduced to name, label, and type, while row values are
	 * string-normalized through each column export contract. The surrounding
	 * payload records export kind, resource metadata, request metadata, and
	 * visible column identity for downstream tooling.
	 *
	 * @param Resource $resource Exported resource.
	 * @param PanelRequest $request Current Panel request.
	 * @param array<int,mixed> $records Records being exported.
	 * @param array<string,Column> $columns Visible export columns.
	 * @param string $filename Download filename.
	 * @param string $kind Export kind for metadata.
	 * @return PanelPageResult JSON download response.
	 */
	private static function exportJsonResult(Resource $resource, PanelRequest $request, array $records, array $columns, string $filename, string $kind): PanelPageResult {
		$columnMeta=[];
		foreach($columns as $column){
			$meta=$column->toArray();
			$columnMeta[]=[
				'name'=>$column->name(),
				'label'=>(string)($meta['label'] ?? $column->name()),
				'type'=>(string)($meta['type'] ?? 'text'),
			];
		}
		$rows=[];
		foreach($records as $record){
			$row=[];
			foreach($columns as $column){
				$row[$column->name()]=self::stringValue($column->exportValue($record));
			}
			$rows[]=$row;
		}
		$data=[
			'kind'=>$kind,
			'resource'=>$resource->toArray(),
			'request'=>$request->toArray(),
			'record_count'=>count($rows),
			'visible_columns'=>array_keys($columns),
		];
		return PanelPageResult::jsonDownload([
			'resource'=>$resource->name(),
			'exported_at'=>date('c'),
			'record_count'=>count($rows),
			'columns'=>$columnMeta,
			'records'=>$rows,
		], $filename, $data);
	}

	/**
	 * Resolves selected records for bulk export or bulk data actions.
	 *
	 * The `selected` request input is normalized to non-empty string keys. Array
	 * query results are filtered in memory, while object query builders are asked
	 * to resolve each selected key through `findRecord()` or `find()`.
	 *
	 * @param Resource $resource Resource that owns the selected records.
	 * @param PanelRequest $request Current Panel request.
	 * @return list<mixed> Selected records in request order when possible.
	 */
	private static function selectedRecords(Resource $resource, PanelRequest $request): array {
		$selected=$request->input('selected', []);
		if(!is_array($selected)){
			$selected=[$selected];
		}
		$selected=array_values(array_filter(array_map(static fn(mixed $value): string => trim((string)$value), $selected)));
		if($selected===[]){
			return [];
		}
		$query=$resource->makeQuery($resource->requestWithResolvedView($request));
		if(is_array($query)){
			return array_values(array_filter($query, static fn(mixed $record): bool => in_array($resource->recordKey($record), $selected, true)));
		}
		if(is_object($query)){
			$records=[];
			foreach($selected as $key){
				foreach(['findRecord', 'find'] as $method){
					if(method_exists($query, $method)){
						$record=$query->{$method}($key);
						if($record!==null){
							$records[]=$record;
						}
						continue 2;
					}
				}
			}
			return $records;
		}
		return [];
	}

	/**
	 * Extracts CSV import content from an uploaded file or textarea fallback.
	 *
	 * A successful `csv_file` upload wins when its temporary file is readable and
	 * non-empty. Otherwise the raw `csv_data` form field is used, supporting both
	 * file uploads and paste-based import workflows.
	 *
	 * @param PanelRequest $request Current Panel request.
	 * @return string CSV payload, possibly empty.
	 */
	private static function importCsvPayload(PanelRequest $request): string {
		$file=$request->file('csv_file');
		if(is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){
			$tmp=(string)($file['tmp_name'] ?? '');
			if($tmp!=='' && is_readable($tmp)){
				$content=file_get_contents($tmp);
				if(is_string($content) && $content!==''){
					return $content;
				}
			}
		}
		return (string)$request->input('csv_data', '');
	}

	/**
	 * Renders the import column-mapping controls for a parsed CSV payload.
	 *
	 * Each source header receives a select box containing importable resource
	 * form fields plus a skip option. Existing mapped headers are preserved so a
	 * preview/confirm cycle does not lose manual user choices.
	 *
	 * @param Resource $resource Resource being imported into.
	 * @param array<string,mixed> $parsed Parsed CSV payload from `parseImportCsv()`.
	 * @return string Mapping form section HTML, or empty string when mapping is unavailable.
	 */
	private static function importMappingHtml(Resource $resource, array $parsed): string {
		$headers=$parsed['headers'] ?? [];
		if(!is_array($headers) || $headers===[]){
			return '';
		}
		$fields=self::importableColumns($resource);
		if($fields===[]){
			return '';
		}
		$mapped=$parsed['mapped_headers'] ?? [];
		$rows='';
		foreach($headers as $index=>$header){
			$current=is_string($mapped[$index] ?? null) ? (string)$mapped[$index] : '';
			$options='<option value="__skip"'.($current==='' ? ' selected' : '').'>Skip column</option>';
			foreach($fields as $field){
				$name=(string)($field['name'] ?? '');
				if($name===''){
					continue;
				}
				$label=(string)($field['label'] ?? $name);
				$options.='<option value="'.self::e($name).'"'.($current===$name ? ' selected' : '').'>'.self::e($label.' ('.$name.')').'</option>';
			}
			$rows.='<label class="dp-panel-field"><span>'.self::e((string)$header).'</span><select name="import_map['.(int)$index.']">'.$options.'</select></label>';
		}
		return '<section class="dp-panel-form-section"><header><h2>'.self::e(self::panelText('data.column_mapping')).'</h2><p>'.self::e(self::panelText('data.column_mapping_description')).'</p></header><div class="dp-panel-form-grid dp-panel-form-grid-2">'.$rows.'</div></section>';
	}

	/**
	 * Renders a bounded preview table for imported rows.
	 *
	 * Preview columns are inferred from the parsed row keys, up to twenty rows
	 * are rendered, and per-row validation errors are summarized beside each row
	 * so users can correct mapping or source data before committing.
	 *
	 * @param array<int,array<string,mixed>|mixed> $rows Parsed import rows.
	 * @param array<string,mixed> $validation Validation summary from `importValidation()`.
	 * @return string Preview table or empty-state HTML.
	 */
	private static function importPreviewTable(array $rows, array $validation): string {
		if($rows===[]){
			return '<p class="dp-panel-empty">'.self::e(self::panelText('data.no_rows_preview')).'</p>';
		}
		$columns=[];
		foreach($rows as $row){
			if(!is_array($row)){
				continue;
			}
			foreach(array_keys($row) as $column){
				$columns[$column]=$column;
			}
		}
		if($columns===[]){
			return '<p class="dp-panel-empty">'.self::e(self::panelText('import.no_preview_columns')).'</p>';
		}
		$head='<th>'.self::e(self::panelText('import.row')).'</th>';
		foreach($columns as $column){
			$head.='<th>'.self::e((string)$column).'</th>';
		}
		$head.='<th>'.self::e(self::panelText('import.status')).'</th>';
		$body='';
		$previewRows=array_slice($rows, 0, 20, true);
		foreach($previewRows as $index=>$row){
			$rowNumber=(int)$index+1;
			$errors=$validation['row_errors'][$index] ?? [];
			$status=$errors===[] ? '<span class="dp-panel-badge dp-panel-badge-success">'.self::e(self::panelText('import.ready')).'</span>' : '<span class="dp-panel-badge dp-panel-badge-danger">'.self::e(self::panelText('import.needs_attention')).'</span>';
			if($errors!==[]){
				$messages=[];
				foreach($errors as $field=>$fieldErrors){
					foreach((array)$fieldErrors as $error){
						$messages[]=$field.': '.$error;
					}
				}
				$status.='<small>'.self::e(implode('; ', $messages)).'</small>';
			}
			$body.='<tr><td>'.self::e((string)$rowNumber).'</td>';
			foreach($columns as $column){
				$body.='<td>'.self::e(self::stringValue(is_array($row) ? ($row[$column] ?? '') : '')).'</td>';
			}
			$body.='<td>'.$status.'</td></tr>';
		}
		if(count($rows)>count($previewRows)){
			$body.='<tr><td colspan="'.(count($columns)+2).'" class="dp-panel-empty">'.self::e(self::panelText('data.preview_limit', ['count'=>count($rows)])).'</td></tr>';
		}
		return '<table class="dp-panel-table"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table>';
	}

	/**
	 * Validates parsed import rows against the resource form contract.
	 *
	 * Non-array rows are rejected, while row arrays are validated using the
	 * resource form in `import` mode. The result separates valid and invalid row
	 * counts and preserves field-level errors by source row index.
	 *
	 * @param Resource $resource Resource being imported into.
	 * @param PanelRequest $request Current Panel request.
	 * @param array<int,array<string,mixed>|mixed> $rows Parsed import rows.
	 * @return array{valid_count:int,invalid_count:int,row_errors:array<int,array<string,mixed>>} Validation summary.
	 */
	private static function importValidation(Resource $resource, PanelRequest $request, array $rows): array {
		$rowErrors=[];
		$validCount=0;
		foreach($rows as $index=>$row){
			if(!is_array($row)){
				$rowErrors[$index]=['row'=>[self::panelText('data.row_not_importable')]];
				continue;
			}
			$state=$resource->form()->validate($row, null, $request, 'import');
			if($state->invalid()){
				$rowErrors[$index]=$state->errors();
				continue;
			}
			$validCount++;
		}
		return [
			'valid_count'=>$validCount,
			'invalid_count'=>count($rowErrors),
			'row_errors'=>$rowErrors,
		];
	}

	/**
	 * Parses CSV import text into resource field payloads.
	 *
	 * The parser removes a UTF-8 BOM, auto-detects or honors the delimiter,
	 * optionally treats the first row as headers, maps headers to importable form
	 * fields by name/label or manual mapping, and records skipped source columns.
	 *
	 * @param Resource $resource Resource being imported into.
	 * @param PanelRequest $request Current Panel request.
	 * @param string $csv Raw CSV payload.
	 * @return array{rows:list<array<string,string>>,headers:list<string>,mapped_headers:array<int,string|null>,skipped_columns:list<string>} Parsed import payload.
	 */
	private static function parseImportCsv(Resource $resource, PanelRequest $request, string $csv): array {
		$csv=preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
		$delimiter=self::importDelimiter($request, $csv);
		$handle=fopen('php://temp', 'r+');
		if($handle===false){
			return ['rows'=>[], 'headers'=>[], 'skipped_columns'=>[]];
		}
		fwrite($handle, $csv);
		rewind($handle);
		$rawRows=[];
		while(($row=fgetcsv($handle, 0, $delimiter, '"', ''))!==false){
			if($row===null || $row===[null] || $row===[]){
				continue;
			}
			$row=array_map(static fn(mixed $value): string => trim((string)$value), $row);
			if(implode('', $row)===''){
				continue;
			}
			$rawRows[]=$row;
		}
		fclose($handle);
		if($rawRows===[]){
			return ['rows'=>[], 'headers'=>[], 'skipped_columns'=>[]];
		}
		$hasHeader=array_key_exists('has_header', $request->input()) ? self::truthy($request->input('has_header')) : true;
		$fields=self::importableColumns($resource);
		$fieldNames=array_map(static fn(array $field): string => (string)$field['name'], $fields);
		$fieldMap=self::importFieldMap($fields);
		$manualMap=self::manualImportMap($request, $fieldNames);
		$headers=$hasHeader ? array_shift($rawRows) : $fieldNames;
		$mappedHeaders=[];
		$skipped=[];
		foreach($headers as $index=>$header){
			$normalized=Resource::normalizeName((string)$header);
			$manual=$manualMap[$index] ?? null;
			$name=$manual!==null ? $manual : ($fieldMap[$normalized] ?? ($fields===[] ? $normalized : null));
			if($name===null || $name===''){
				$skipped[]=(string)$header;
				$mappedHeaders[$index]=null;
				continue;
			}
			$mappedHeaders[$index]=$name;
		}
		$rows=[];
		foreach($rawRows as $rawRow){
			$row=[];
			foreach($rawRow as $index=>$value){
				$name=$mappedHeaders[$index] ?? null;
				if($name===null || $name===''){
					continue;
				}
				$row[$name]=$value;
			}
			$rows[]=$row;
		}
		return [
			'rows'=>$rows,
			'headers'=>$headers,
			'mapped_headers'=>$mappedHeaders,
			'skipped_columns'=>array_values(array_unique($skipped)),
		];
	}

	/**
	 * Normalizes manual CSV column mappings from the import form.
	 *
	 * Only known importable field names are accepted. The special `__skip` value
	 * is preserved as an empty mapping for its column index.
	 *
	 * @param PanelRequest $request Current Panel request.
	 * @param list<string> $fieldNames Allowed resource field names.
	 * @return array<int,string> Column index to normalized field name or empty skip marker.
	 */
	private static function manualImportMap(PanelRequest $request, array $fieldNames): array {
		$map=$request->input('import_map', []);
		if(!is_array($map)){
			return [];
		}
		$allowed=array_fill_keys($fieldNames, true);
		$manual=[];
		foreach($map as $index=>$field){
			$field=(string)$field;
			if($field==='__skip' || $field===''){
				$manual[(int)$index]='';
				continue;
			}
			$field=Resource::normalizeName($field);
			if(isset($allowed[$field])){
				$manual[(int)$index]=$field;
			}
		}
		return $manual;
	}

	/**
	 * Resolves the delimiter used to parse an import CSV payload.
	 *
	 * Explicit comma, semicolon, pipe, and tab settings are honored. In auto
	 * mode, the first non-empty line is scored by delimiter occurrence and falls
	 * back to comma when no delimiter is detected.
	 *
	 * @param PanelRequest $request Current Panel request.
	 * @param string $csv Raw CSV payload.
	 * @return string One-character delimiter accepted by `fgetcsv()`.
	 */
	private static function importDelimiter(PanelRequest $request, string $csv): string {
		$delimiter=(string)$request->input('delimiter', 'auto');
		if($delimiter==='tab'){
			return "\t";
		}
		if(in_array($delimiter, [',', ';', '|'], true)){
			return $delimiter;
		}
		$firstLine='';
		foreach(preg_split('/\R/', $csv) ?: [] as $line){
			if(trim($line)!==''){
				$firstLine=$line;
				break;
			}
		}
		$scores=[
			','=>substr_count($firstLine, ','),
			';'=>substr_count($firstLine, ';'),
			"\t"=>substr_count($firstLine, "\t"),
			'|'=>substr_count($firstLine, '|'),
		];
		arsort($scores);
		$best=(string)array_key_first($scores);
		return ($scores[$best] ?? 0)>0 ? $best : ',';
	}

	/**
	 * Lists resource form fields that can receive imported values.
	 *
	 * Read-only fields and fields without names are excluded. Each returned item
	 * includes a display label and a type-aware sample value for import guidance.
	 *
	 * @param Resource $resource Resource being imported into.
	 * @return list<array{name:string,label:string,sample:string}> Importable field metadata.
	 */
	private static function importableColumns(Resource $resource): array {
		$columns=[];
		foreach($resource->form()->fieldsList() as $field){
			$meta=$field->toArray();
			if(($meta['readonly'] ?? false)===true){
				continue;
			}
			$name=(string)($meta['name'] ?? '');
			if($name===''){
				continue;
			}
			$columns[]=[
				'name'=>$name,
				'label'=>(string)($meta['label'] ?? $name),
				'sample'=>self::importSampleValue($meta),
			];
		}
		return $columns;
	}

	/**
	 * Chooses an example value for an importable field.
	 *
	 * Scalar defaults win, then the first option value, then a type-specific
	 * placeholder. Unknown textual fields use an empty sample to avoid implying
	 * constraints that the form does not define.
	 *
	 * @param array<string,mixed> $meta Field metadata.
	 * @return string Example import value.
	 */
	private static function importSampleValue(array $meta): string {
		if(array_key_exists('default', $meta) && is_scalar($meta['default'])){
			return self::stringValue($meta['default']);
		}
		$options=$meta['options'] ?? null;
		if(is_array($options) && $options!==[]){
			$key=array_key_first($options);
			return is_string($key) && !is_int($key) ? $key : self::stringValue($options[$key]);
		}
		return match((string)($meta['type'] ?? 'text')){
			'email'=>'person@example.com',
			'url'=>'https://example.com',
			'number', 'integer', 'int'=>'1',
			'money', 'currency', 'decimal', 'float'=>'1.00',
			'boolean', 'bool', 'checkbox', 'toggle'=>'1',
			'date'=>'2026-01-31',
			'datetime'=>'2026-01-31 12:00:00',
			default=>'',
		};
	}

	/**
	 * Builds normalized header lookup aliases for importable fields.
	 *
	 * Both field names and labels are normalized through `Resource::normalizeName`
	 * so user CSV headers can match either technical or human-readable labels.
	 *
	 * @param list<array{name:string,label:string,sample:string}> $fields Importable field metadata.
	 * @return array<string,string> Normalized header token to field name.
	 */
	private static function importFieldMap(array $fields): array {
		$map=[];
		foreach($fields as $field){
			$name=(string)($field['name'] ?? '');
			$label=(string)($field['label'] ?? $name);
			foreach([$name, $label] as $candidate){
				$normalized=Resource::normalizeName($candidate);
				if($normalized!==''){
					$map[$normalized]=$name;
				}
			}
		}
		return $map;
	}

	/**
	 * Normalizes a resource import result into imported and failed counts.
	 *
	 * Resource import handlers may return explicit imported/failed arrays,
	 * numeric counters, success booleans, or false. This helper reduces those
	 * shapes to stable counters for Panel notices and telemetry.
	 *
	 * @param mixed $result Resource import handler result.
	 * @param int $rowCount Number of attempted rows.
	 * @return array{imported_count:int,failed_count:int} Import result counters.
	 */
	private static function importResultSummary(mixed $result, int $rowCount): array {
		if(is_array($result)){
			$imported=$result['imported'] ?? $result['created'] ?? $result['saved'] ?? null;
			$failed=$result['failed'] ?? $result['errors'] ?? null;
			$importedCount=is_array($imported) ? count($imported) : (is_numeric($imported) ? max(0, (int)$imported) : null);
			$failedCount=is_array($failed) ? count($failed) : (is_numeric($failed) ? max(0, (int)$failed) : null);
			if($importedCount===null && isset($result['success']) && (bool)$result['success']===true){
				$importedCount=$rowCount;
			}
			if($importedCount===null){
				$importedCount=max(0, $rowCount-(int)($failedCount ?? 0));
			}
			$failedCount ??=max(0, $rowCount-$importedCount);
			return [
				'imported_count'=>$importedCount,
				'failed_count'=>$failedCount,
			];
		}
		if($result===false){
			return ['imported_count'=>0, 'failed_count'=>$rowCount];
		}
		return ['imported_count'=>$rowCount, 'failed_count'=>0];
	}

	/**
	 * Interprets a resource delete handler result as success or failure.
	 *
	 * Array results may expose `deleted`, `success`, or `ok`. Non-array results
	 * follow the Panel convention that only strict false means failure.
	 *
	 * @param mixed $result Delete handler result.
	 * @return bool Whether delete succeeded.
	 */
	private static function deleteOutcomeSucceeded(mixed $result): bool {
		if(is_array($result)){
			foreach(['deleted', 'success', 'ok'] as $key){
				if(array_key_exists($key, $result)){
					return (bool)$result[$key];
				}
			}
		}
		return $result!==false;
	}

	/**
	 * Interprets a force-delete handler result as success or failure.
	 *
	 * Force delete may report `force_deleted` or fall back to the standard delete
	 * result keys. Non-array false remains the failure sentinel.
	 *
	 * @param mixed $result Force-delete handler result.
	 * @return bool Whether force delete succeeded.
	 */
	private static function forceDeleteOutcomeSucceeded(mixed $result): bool {
		if(is_array($result)){
			foreach(['force_deleted', 'deleted', 'success', 'ok'] as $key){
				if(array_key_exists($key, $result)){
					return (bool)$result[$key];
				}
			}
		}
		return $result!==false;
	}

	/**
	 * Interprets a duplicate handler result as success or failure.
	 *
	 * Array results can report explicit duplication status, generic success, or
	 * ok. Other truthy shapes are treated as success for compatibility with
	 * legacy resource callbacks.
	 *
	 * @param mixed $result Duplicate handler result.
	 * @return bool Whether duplication succeeded.
	 */
	private static function duplicateOutcomeSucceeded(mixed $result): bool {
		if(is_array($result)){
			foreach(['duplicated', 'success', 'ok'] as $key){
				if(array_key_exists($key, $result)){
					return (bool)$result[$key];
				}
			}
		}
		return $result!==false;
	}

	/**
	 * Interprets a restore handler result as success or failure.
	 *
	 * Restore-specific and generic result keys are checked before falling back
	 * to strict-false failure semantics.
	 *
	 * @param mixed $result Restore handler result.
	 * @return bool Whether restore succeeded.
	 */
	private static function restoreOutcomeSucceeded(mixed $result): bool {
		if(is_array($result)){
			foreach(['restored', 'success', 'ok'] as $key){
				if(array_key_exists($key, $result)){
					return (bool)$result[$key];
				}
			}
		}
		return $result!==false;
	}

	/**
	 * Interprets a workflow transition handler result as success or failure.
	 *
	 * Transition handlers can report transition, save, update, success, or ok
	 * status. Non-array false remains the only generic failure sentinel.
	 *
	 * @param mixed $result Transition handler result.
	 * @return bool Whether transition succeeded.
	 */
	private static function transitionOutcomeSucceeded(mixed $result): bool {
		if(is_array($result)){
			foreach(['transitioned', 'saved', 'updated', 'success', 'ok'] as $key){
				if(array_key_exists($key, $result)){
					return (bool)$result[$key];
				}
			}
		}
		return $result!==false;
	}

	/**
	 * Resolves a generic record key when no Resource object is available.
	 *
	 * Common identity fields are checked in priority order. Empty and non-scalar
	 * values are ignored because generated relation operation forms require a
	 * stable scalar key.
	 *
	 * @param mixed $record Record array or object.
	 * @return string Record key or empty string.
	 */
	private static function recordKey(mixed $record): string {
		foreach(['id', 'key', 'uuid', 'name'] as $key){
			$value=self::recordValue($record, $key, null);
			if(is_scalar($value) && trim((string)$value)!==''){
				return (string)$value;
			}
		}
		return '';
	}

	/**
	 * Builds an inline text alignment attribute for a table cell.
	 *
	 * Only renderer-supported alignment tokens are emitted; unknown values
	 * produce no attribute to avoid leaking arbitrary style content.
	 *
	 * @param array<string,mixed> $meta Column metadata.
	 * @return string HTML style attribute or empty string.
	 */
	private static function alignAttr(array $meta): string {
		$align=(string)($meta['align'] ?? '');
		return in_array($align, ['left', 'center', 'right'], true) ? ' style="text-align:'.$align.'"' : '';
	}

	/**
	 * Returns the query parameter prefix for a relation table.
	 *
	 * Prefixing relation state prevents nested relation filters, sort, search,
	 * and pagination from colliding with the parent resource request state.
	 *
	 * @param RelationManager $relation Relation manager.
	 * @return string Relation query prefix.
	 */
	private static function relationPrefix(RelationManager $relation): string {
		return 'r_'.$relation->name().'_';
	}

	/**
	 * Produces a request view where relation-prefixed query keys are unscoped.
	 *
	 * Relation table helpers expect normal keys such as `q`, `sort`, and
	 * `per_page`. This method copies `r_<relation>_` query values onto those
	 * unprefixed names while retaining the original request payload.
	 *
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent Panel request.
	 * @return PanelRequest Request suitable for relation table evaluation.
	 */
	private static function relationScopedRequest(RelationManager $relation, PanelRequest $request): PanelRequest {
		$query=$request->query();
		$prefix=self::relationPrefix($relation);
		foreach($query as $key=>$value){
			$key=(string)$key;
			if(str_starts_with($key, $prefix)){
				$name=substr($key, strlen($prefix));
				if($name!==''){
					$query[$name]=$value;
				}
			}
		}
		return $request->withQuery($query, true);
	}

	/**
	 * Builds the base URL for a relation table on a parent record.
	 *
	 * Relation operation pages route to the explicit relation path. Embedded
	 * relation tables on show pages link back to the parent show route. Missing
	 * parent keys fall back to the resource index.
	 *
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent request.
	 * @param mixed $record Optional parent record.
	 * @return string Base relation URL.
	 */
	private static function relationBaseUrl(Resource $resource, RelationManager $relation, PanelRequest $request, mixed $record=null): string {
		$key=$record!==null ? $resource->recordKey($record) : (string)$request->recordKey();
		if($key===''){
			return PanelConfig::resourceUrl($resource);
		}
		if($request->operation()==='relation'){
			return PanelConfig::resourceUrl($resource, 'relation/'.rawurlencode($key).'/'.$relation->name());
		}
		return PanelConfig::resourceUrl($resource, 'show/'.rawurlencode($key));
	}

	/**
	 * Builds a relation URL while preserving unrelated parent query state.
	 *
	 * Existing state for the same relation is removed, supplied params are
	 * normalized and re-prefixed, and blank/null values clear their relation
	 * counterpart. Parent routing keys are stripped before the query is rebuilt.
	 *
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent request.
	 * @param mixed $record Parent record.
	 * @param array<string,mixed> $params Relation state updates.
	 * @return string Relation URL with query string.
	 */
	private static function relationUrl(Resource $resource, RelationManager $relation, PanelRequest $request, mixed $record, array $params): string {
		$query=$request->query();
		unset($query['resource'], $query['operation'], $query['record'], $query['relation'], $query['action']);
		$prefix=self::relationPrefix($relation);
		foreach(array_keys($query) as $key){
			if(str_starts_with((string)$key, $prefix)){
				unset($query[$key]);
			}
		}
		foreach($params as $key=>$value){
			$key=Resource::normalizeName((string)$key);
			if($key===''){
				continue;
			}
			if($value===null || (is_string($value) && trim($value)==='')){
				unset($query[$prefix.$key]);
				continue;
			}
			$query[$prefix.$key]=$value;
		}
		$query=array_filter($query, static fn(mixed $value): bool => !(is_string($value) && trim($value)==='') && $value!==null);
		$base=self::relationBaseUrl($resource, $relation, $request, $record);
		return $base.($query!==[] ? (str_contains($base, '?') ? '&' : '?').http_build_query($query) : '');
	}

	/**
	 * Builds the POST target URL for a relation operation.
	 *
	 * The URL always targets the relation route for the parent record and carries
	 * prefixed relation state so actions can return to the same filtered,
	 * paginated relation view.
	 *
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent request.
	 * @param mixed $record Parent record.
	 * @param array<string,mixed> $params Relation state updates.
	 * @return string Relation operation URL.
	 */
	private static function relationOperationUrl(Resource $resource, RelationManager $relation, PanelRequest $request, mixed $record, array $params=[]): string {
		$key=$record!==null ? $resource->recordKey($record) : (string)$request->recordKey();
		if($key===''){
			return PanelConfig::resourceUrl($resource);
		}
		$query=$request->query();
		unset($query['resource'], $query['operation'], $query['record'], $query['relation'], $query['action']);
		$prefix=self::relationPrefix($relation);
		foreach($params as $name=>$value){
			$name=Resource::normalizeName((string)$name);
			if($name===''){
				continue;
			}
			if($value===null || (is_string($value) && trim($value)==='')){
				unset($query[$prefix.$name]);
				continue;
			}
			$query[$prefix.$name]=$value;
		}
		$query=array_filter($query, static fn(mixed $value): bool => !(is_string($value) && trim($value)==='') && $value!==null);
		return PanelConfig::resourceUrl($resource, 'relation/'.rawurlencode($key).'/'.$relation->name(), $query);
	}

	/**
	 * Captures the current relation table state as unprefixed parameters.
	 *
	 * Search, sort, direction, per-page, active view, and optional filters are
	 * extracted from the relation-scoped request. The returned map is later
	 * re-prefixed by URL and hidden-input helpers.
	 *
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Relation-scoped request.
	 * @param bool $includeFilters Whether filter values should be included.
	 * @return array<string,string> Non-empty relation state parameters.
	 */
	private static function relationStateParams(RelationManager $relation, PanelRequest $request, bool $includeFilters=true): array {
		$table=$relation->resourceTable();
		$params=[
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
			'per_page'=>(string)$request->perPage($table->defaultPerPage()),
		];
		$view=Resource::normalizeName((string)$request->query('view', ''));
		if($view==='all'){
			$params['view']='all';
		}
		else {
			$active=$table->activeViewName($request);
			if($active!==''){
				$params['view']=$active;
			}
		}
		if($includeFilters){
			foreach($table->filtersList() as $filter){
				if(!$filter instanceof TableFilter){
					continue;
				}
				$value=$filter->activeValue($request);
				if($value===null){
					continue;
				}
				if(is_array($value)){
					if(($value['from'] ?? null)!==null){
						$params[$filter->name().'_from']=self::stringValue($value['from']);
					}
					if(($value['to'] ?? null)!==null){
						$params[$filter->name().'_to']=self::stringValue($value['to']);
					}
					continue;
				}
				$params[$filter->name()]=self::stringValue($value);
			}
		}
		return array_filter($params, static fn(mixed $value): bool => (string)$value!=='');
	}

	/**
	 * Builds hidden inputs that preserve parent and relation query state.
	 *
	 * Existing query parameters unrelated to the relation are copied through.
	 * Supplied relation params are written with the relation prefix so GET forms
	 * can update one part of relation state without dropping the rest.
	 *
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent request.
	 * @param array<string,mixed> $params Relation state parameters.
	 * @return string Hidden input HTML.
	 */
	private static function relationHiddenInputs(RelationManager $relation, PanelRequest $request, array $params): string {
		$prefix=self::relationPrefix($relation);
		$hidden='';
		foreach($request->query() as $key=>$value){
			$key=(string)$key;
			if(str_starts_with($key, $prefix)){
				continue;
			}
			if(is_array($value)){
				foreach($value as $item){
					$hidden.='<input type="hidden" name="'.self::e($key).'[]" value="'.self::e(self::stringValue($item)).'">';
				}
				continue;
			}
			if($value!==null && (string)$value!==''){
				$hidden.='<input type="hidden" name="'.self::e($key).'" value="'.self::e(self::stringValue($value)).'">';
			}
		}
		foreach($params as $key=>$value){
			if($value!==null && (string)$value!==''){
				$hidden.='<input type="hidden" name="'.self::e($prefix.(string)$key).'" value="'.self::e(self::stringValue($value)).'">';
			}
		}
		return $hidden;
	}

	/**
	 * Resolves enabled and authorized states for relation operations.
	 *
	 * Relation configuration supplies operation metadata, while manager
	 * capabilities and policy checks decide availability. Disabled reasons are
	 * filled for unauthorized or unsupported operations so renderers can explain
	 * missing actions.
	 *
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent request.
	 * @param mixed $record Optional parent record.
	 * @return array<string,array<string,mixed>> Operation state keyed by operation name.
	 */
	private static function relationOperationStates(Resource $resource, RelationManager $relation, PanelRequest $request, mixed $record=null): array {
		$definition=$relation->toArray();
		$entries=is_array($definition['operations'] ?? null) ? $definition['operations'] : [];
		$states=[];
		foreach(['attach', 'detach', 'associate', 'dissociate', 'reorder', 'update_pivot'] as $name){
			$entry=is_array($entries[$name] ?? null) ? $entries[$name] : ['name'=>$name];
			$enabled=match($name){
				'attach'=>$relation->canAttach(),
				'detach'=>$relation->canDetach(),
				'associate'=>$relation->canAssociate(),
				'dissociate'=>$relation->canDissociate(),
				'reorder'=>$relation->canReorder(),
				'update_pivot'=>$relation->canUpdatePivot(),
				default=>false,
			};
			$authorized=$relation->can($name, $record, $request->user(), $resource)!==false;
			$reason=is_string($entry['disabled_reason'] ?? null) ? trim((string)$entry['disabled_reason']) : '';
			if(!$authorized){
				$reason=self::panelText('data.relation_unauthorized');
			}
			elseif(!$enabled && $reason===''){
				$reason=self::panelText('data.relation_unavailable_operation');
			}
			$states[$name]=array_replace($entry, [
				'name'=>$name,
				'enabled'=>$enabled,
				'authorized'=>$authorized,
				'disabled_reason'=>$enabled && $authorized ? null : ($reason!=='' ? $reason : null),
			]);
		}
		return $states;
	}

	/**
	 * Applies the active relation table view to related records.
	 *
	 * Missing or invalid view names leave the record set unchanged. Valid views
	 * use their `matches()` contract against the relation table's parent resource
	 * and request context.
	 *
	 * @param array<int,mixed> $records Related records.
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Relation-scoped request.
	 * @return list<mixed> Records after active view filtering.
	 */
	private static function relationApplyTableView(array $records, Resource $resource, RelationManager $relation, PanelRequest $request): array {
		$viewName=$relation->resourceTable()->activeViewName($request);
		if($viewName===''){
			return $records;
		}
		$view=$relation->resourceTable()->viewsList()[$viewName] ?? null;
		if(!$view instanceof TableView){
			return $records;
		}
		return array_values(array_filter($records, static fn(mixed $record): bool => $view->matches($record, $request, $resource)));
	}

	/**
	 * Counts related records for each relation table view.
	 *
	 * The empty-string key stores the all-record count, while named views store
	 * counts computed through each view's `matches()` contract.
	 *
	 * @param array<int,mixed> $records All related records before view filtering.
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Relation-scoped request.
	 * @return array<string,int> View counts keyed by view name.
	 */
	private static function relationViewCounts(array $records, Resource $resource, RelationManager $relation, PanelRequest $request): array {
		$views=$relation->resourceTable()->viewsList();
		if($views===[]){
			return [];
		}
		$counts=[''=>count($records)];
		foreach($views as $view){
			if(!$view instanceof TableView){
				continue;
			}
			$count=0;
			foreach($records as $record){
				if($view->matches($record, $request, $resource)){
					$count++;
				}
			}
			$counts[$view->name()]=$count;
		}
		return $counts;
	}

	/**
	 * Applies visible relation table filters to records.
	 *
	 * Only visible `TableFilter` instances participate. A record is kept only
	 * when every visible active filter reports a match.
	 *
	 * @param array<int,mixed> $records Related records.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Relation-scoped request.
	 * @return list<mixed> Filtered related records.
	 */
	private static function relationApplyFilters(array $records, RelationManager $relation, PanelRequest $request): array {
		$filters=$relation->resourceTable()->filtersList();
		if($filters===[]){
			return $records;
		}
		return array_values(array_filter($records, static function(mixed $record) use ($filters, $request): bool {
			foreach($filters as $filter){
				if($filter instanceof TableFilter && $filter->isVisible($request) && $filter->matches($record, $request)===false){
					return false;
				}
			}
			return true;
		}));
	}

	/**
	 * Applies relation table free-text search to records.
	 *
	 * Searchable columns are preferred; when none are marked searchable, every
	 * relation column is considered. Each column decides how to compare its value
	 * through `matchesSearch()`.
	 *
	 * @param array<int,mixed> $records Related records.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Relation-scoped request.
	 * @return list<mixed> Search-filtered records.
	 */
	private static function relationFilterRecords(array $records, RelationManager $relation, PanelRequest $request): array {
		$query=trim((string)$request->query('q', ''));
		if($query===''){
			return $records;
		}
		$columns=$relation->resourceTable()->columnsList();
		$searchable=array_filter($columns, static fn(Column $column): bool => ($column->toArray()['searchable'] ?? false)===true);
		if($searchable===[]){
			$searchable=$columns;
		}
		return array_values(array_filter($records, static function(mixed $record) use ($searchable, $query, $request, $relation): bool {
			foreach($searchable as $column){
				if($column->matchesSearch($record, $query, $request, null, $relation->resourceTable())){
					return true;
				}
			}
			return false;
		}));
	}

	/**
	 * Resolves the active sort column and direction for a relation table.
	 *
	 * Explicit query state wins. When no sort is requested, the relation table's
	 * default sort definition is used if present.
	 *
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Relation-scoped request.
	 * @return array{0:string,1:string} Sort column and `asc` or `desc` direction.
	 */
	private static function relationSortState(RelationManager $relation, PanelRequest $request): array {
		$query=$request->query();
		$hasSort=is_array($query) && array_key_exists('sort', $query);
		$sort=Resource::normalizeName((string)($query['sort'] ?? ''));
		$direction=strtolower((string)($query['dir'] ?? 'asc'))==='desc' ? 'desc' : 'asc';
		if(!$hasSort && $sort===''){
			$default=$relation->resourceTable()->defaultSortDefinition();
			if(is_array($default)){
				$sort=Resource::normalizeName((string)($default['column'] ?? ''));
				$direction=strtolower((string)($default['direction'] ?? 'asc'))==='desc' ? 'desc' : 'asc';
			}
		}
		return [$sort, $direction];
	}

	/**
	 * Sorts relation records using the active sortable column.
	 *
	 * Sorting is skipped when no sort is active, the column is missing, or the
	 * column metadata does not opt into sorting.
	 *
	 * @param array<int,mixed> $records Related records.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Relation-scoped request.
	 * @return array<int,mixed> Sorted records.
	 */
	private static function relationSortRecords(array $records, RelationManager $relation, PanelRequest $request): array {
		[$sort, $direction]=self::relationSortState($relation, $request);
		if($sort===''){
			return $records;
		}
		$column=$relation->resourceTable()->columnsList()[$sort] ?? null;
		if(!$column instanceof Column || ($column->toArray()['sortable'] ?? false)!==true){
			return $records;
		}
		usort($records, static fn(mixed $left, mixed $right): int => $column->compareForSort($left, $right, $direction, $request, null, $relation->resourceTable()));
		return $records;
	}

	/**
	 * Resolves summary values for the current relation record set.
	 *
	 * Summary definitions are evaluated after view, filter, search, and sort
	 * state have been applied, matching what the user is currently inspecting.
	 *
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Relation-scoped request.
	 * @param array<int,mixed> $records Visible relation records.
	 * @return list<array<string,mixed>> Resolved summary payloads.
	 */
	private static function relationSummaries(Resource $resource, RelationManager $relation, PanelRequest $request, array $records): array {
		$summaries=[];
		foreach($relation->resourceTable()->summariesList() as $summary){
			if($summary instanceof TableSummary){
				$summaries[]=$summary->resolve($records, $resource, $request);
			}
		}
		return $summaries;
	}

	/**
	 * Reports whether the relation table is constrained by user state.
	 *
	 * Search text, an active view, or any visible active filter marks the table
	 * as constrained. Empty-state copy uses this to distinguish no records from
	 * no results for the current criteria.
	 *
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Relation-scoped request.
	 * @return bool Whether search, view, or filters are active.
	 */
	private static function relationHasConstraints(RelationManager $relation, PanelRequest $request): bool {
		$table=$relation->resourceTable();
		$query=trim((string)$request->query('q', ''));
		$activeView=$table->activeViewName($request);
		$hasFilters=false;
		foreach($table->filtersList() as $filter){
			if($filter instanceof TableFilter && $filter->isVisible($request) && $filter->activeValue($request)!==null){
				$hasFilters=true;
				break;
			}
		}
		return $query!=='' || $activeView!=='' || $hasFilters;
	}

	/**
	 * Renders the empty state for a relation table.
	 *
	 * The relation receives the current constraint state so it can return
	 * context-aware heading and description text. Returned text is escaped before
	 * being embedded in the table body.
	 *
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Relation-scoped request.
	 * @return string Empty-state HTML.
	 */
	private static function relationEmptyStateHtml(RelationManager $relation, PanelRequest $request): string {
		$state=$relation->resolveEmptyState($request, self::relationHasConstraints($relation, $request));
		$heading=self::e((string)($state['heading'] ?? self::panelText('data.no_related_records')));
		$description=trim((string)($state['description'] ?? ''));
		return '<div class="dp-panel-empty-state"><strong>'.$heading.'</strong>'.($description!=='' ? '<span>'.self::e($description).'</span>' : '').'</div>';
	}

	/**
	 * Renders the relation table view switcher.
	 *
	 * The all-record link is rendered first, followed by configured table views.
	 * Counts are supplied from precomputed view counts when available, otherwise
	 * each view may resolve its own badge.
	 *
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent request.
	 * @param PanelRequest $relationRequest Relation-scoped request.
	 * @param mixed $record Parent record.
	 * @param array<string,int|string|null> $counts View count map.
	 * @return string Table view navigation HTML.
	 */
	private static function relationTableViewsHtml(Resource $resource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, mixed $record, array $counts=[]): string {
		$views=$relation->resourceTable()->viewsList();
		if($views===[]){
			return '';
		}
		$params=self::relationStateParams($relation, $relationRequest, true);
		$active=$relation->resourceTable()->activeViewName($relationRequest);
		$html=self::relationTableViewLink($resource, $relation, $request, $record, $params, 'all', self::panelText('common.all'), 'neutral', $active==='', $counts[''] ?? null);
		foreach($views as $view){
			if(!$view instanceof TableView){
				continue;
			}
			$meta=$view->toArray();
			$badge=$counts[$view->name()] ?? $view->resolveBadge([], $relationRequest, $resource);
			$html.=self::relationTableViewLink($resource, $relation, $request, $record, $params, $view->name(), (string)($meta['label'] ?? $view->name()), (string)($meta['tone'] ?? 'neutral'), $active===$view->name(), $badge);
		}
		return '<nav class="dp-panel-table-views" aria-label="'.self::e(self::panelText('table.views_aria', ['table'=>(string)$relation->label()])).'">'.$html.'</nav>';
	}

	/**
	 * Renders one relation table view link.
	 *
	 * Selecting a view resets pagination to page one, preserves the rest of the
	 * relation state, applies a safe tone class, and marks the active link with
	 * `aria-current`.
	 *
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent request.
	 * @param mixed $record Parent record.
	 * @param array<string,mixed> $params Existing relation state.
	 * @param string $view View name or `all`.
	 * @param string $label Display label.
	 * @param string $tone Badge tone.
	 * @param bool $active Whether this link is active.
	 * @param mixed $badge Optional count or badge text.
	 * @return string View link HTML.
	 */
	private static function relationTableViewLink(Resource $resource, RelationManager $relation, PanelRequest $request, mixed $record, array $params, string $view, string $label, string $tone, bool $active, mixed $badge=null): string {
		if($view==='all'){
			$params['view']='all';
		}
		else {
			$params['view']=$view;
		}
		$params['page']=1;
		$class='dp-panel-table-view dp-panel-table-view-'.self::safeTone($tone).($active ? ' active' : '');
		$badgeHtml=$badge!==null && $badge!=='' ? '<small>'.self::e(self::stringValue($badge)).'</small>' : '';
		return '<a class="'.$class.'" href="'.self::e(self::relationUrl($resource, $relation, $request, $record, $params)).'"'.($active ? ' aria-current="page"' : '').' title="'.self::e(self::panelText('table.view_title', ['view'=>$label])).'"><i class="dp-panel-table-view-dot" aria-hidden="true"></i><span>'.self::e($label).'</span>'.$badgeHtml.'</a>';
	}

	/**
	 * Renders the relation table search form.
	 *
	 * Existing relation state is preserved except search and page, because a new
	 * search should start from the first page. The clear action removes only the
	 * relation search state.
	 *
	 * @return string Search form HTML.
	 */
	private static function relationSearchHtml(Resource $resource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, mixed $record): string {
		$params=self::relationStateParams($relation, $relationRequest, true);
		unset($params['q'], $params['page']);
		$query=trim((string)$relationRequest->query('q', ''));
		return '<form class="dp-panel-search" method="get" action="'.self::e(self::relationBaseUrl($resource, $relation, $request, $record)).'">'
			.self::relationHiddenInputs($relation, $request, $params)
			.'<input type="search" name="'.self::e(self::relationPrefix($relation).'q').'" value="'.self::e($query).'" placeholder="'.self::e(self::panelText('table.search_table_placeholder', ['table'=>(string)$relation->label()])).'">'
			.'<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('common.search')).'</button>'
			.($query!=='' ? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(self::relationUrl($resource, $relation, $request, $record, ['q'=>null, 'page'=>null]+$params)).'">'.self::e(self::panelText('common.clear')).'</a>' : '')
			.'</form>';
	}

	/**
	 * Renders visible relation table filters and active filter chips.
	 *
	 * Filter controls are prefixed for the relation namespace and preserve search,
	 * sort, view, and per-page state. Reset links clear filter controls while
	 * keeping the surrounding relation table context.
	 *
	 * @return string Filter form and chip HTML.
	 */
	private static function relationFiltersHtml(Resource $resource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, mixed $record): string {
		$filters=$relation->resourceTable()->filtersList();
		if($filters===[]){
			return '';
		}
		$params=self::relationStateParams($relation, $relationRequest, false);
		unset($params['page']);
		$controls='';
		$prefix=self::relationPrefix($relation);
		foreach($filters as $filter){
			if($filter instanceof TableFilter && $filter->isVisible($relationRequest, $resource, $relation->resourceTable())){
				$controls.=self::filterControl($filter, $relationRequest, $prefix);
			}
		}
		if($controls===''){
			return '';
		}
		$chips=self::relationActiveFilterChipsHtml($resource, $relation, $request, $relationRequest, $record);
		return '<form class="dp-panel-filters" method="get" action="'.self::e(self::relationBaseUrl($resource, $relation, $request, $record)).'">'
			.self::relationHiddenInputs($relation, $request, $params)
			.$controls
			.'<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('client.filter')).'</button>'
			.'<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(self::relationUrl($resource, $relation, $request, $record, $params)).'">'.self::e(self::panelText('common.reset')).'</a>'
			.'</form>'.$chips;
	}

	/**
	 * Renders clearable chips for active relation filters.
	 *
	 * Range filters clear both from/to parameters, while scalar filters clear
	 * their own parameter. Invisible filters are ignored so chips match the
	 * controls currently available to the user.
	 *
	 * @return string Active filter chip list HTML.
	 */
	private static function relationActiveFilterChipsHtml(Resource $resource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, mixed $record): string {
		$chips='';
		foreach($relation->resourceTable()->filtersList() as $filter){
			if(!$filter instanceof TableFilter){
				continue;
			}
			if(!$filter->isVisible($relationRequest, $resource, $relation->resourceTable())){
				continue;
			}
			$options=$filter->optionsFor($relationRequest);
			$value=$filter->activeValue($relationRequest, $options);
			if($value===null){
				continue;
			}
			$meta=$filter->toArray();
			$meta['options']=$options;
			$params=self::relationStateParams($relation, $relationRequest, true);
			unset($params[$filter->name()], $params[$filter->name().'_from'], $params[$filter->name().'_to'], $params['page']);
			$chips.='<a class="dp-panel-filter-chip" href="'.self::e(self::relationUrl($resource, $relation, $request, $record, $params)).'">'
				.'<span>'.self::e((string)$meta['label']).'</span>'
				.'<strong>'.self::e(self::filterValueLabel($filter, $meta, $value)).'</strong>'
				.'<small>Clear</small>'
				.'</a>';
		}
		return $chips!=='' ? '<div class="dp-panel-filter-chips" aria-label="Active relation filters">'.$chips.'</div>' : '';
	}

	/**
	 * Renders the relation table per-page selector.
	 *
	 * The current per-page value is added to the option list if it is not already
	 * configured, then values are clamped to the renderer's supported range.
	 *
	 * @return string Per-page selector form HTML.
	 */
	private static function relationPerPageHtml(Resource $resource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, mixed $record): string {
		$table=$relation->resourceTable();
		$current=$relationRequest->perPage($table->defaultPerPage());
		$options=$table->perPageOptionsList();
		if(!in_array($current, $options, true)){
			$options[]=$current;
			sort($options, SORT_NUMERIC);
		}
		$params=self::relationStateParams($relation, $relationRequest, true);
		unset($params['per_page'], $params['page']);
		$choices='';
		foreach($options as $option){
			$option=max(1, min(250, (int)$option));
			$choices.='<option value="'.$option.'"'.($option===$current ? ' selected' : '').'>'.$option.'</option>';
		}
		return '<form class="dp-panel-per-page" method="get" action="'.self::e(self::relationBaseUrl($resource, $relation, $request, $record)).'">'
			.self::relationHiddenInputs($relation, $request, $params)
			.'<label><span>'.self::e(self::panelText('data.rows')).'</span><select name="'.self::e(self::relationPrefix($relation).'per_page').'" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">'.$choices.'</select></label>'
			.'<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('common.apply')).'</button>'
			.'</form>';
	}

	/**
	 * Renders relation table pagination controls.
	 *
	 * Page bounds are clamped against total pages, record range text is derived
	 * from the clamped page, and previous/next links preserve relation state.
	 *
	 * @return string Pagination navigation HTML.
	 */
	private static function relationPaginationHtml(Resource $resource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, mixed $record, int $totalRecords, int $page, int $perPage): string {
		$totalPages=max(1, (int)ceil($totalRecords / max(1, $perPage)));
		$page=max(1, min($page, $totalPages));
		$start=$totalRecords===0 ? 0 : (($page-1)*$perPage)+1;
		$end=min($totalRecords, $page*$perPage);
		$params=self::relationStateParams($relation, $relationRequest, true);
		$params['per_page']=$perPage;
		$previous=$page>1
			? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(self::relationUrl($resource, $relation, $request, $record, $params+['page'=>$page-1])).'">'.self::e(self::panelText('data.previous')).'</a>'
			: '<span class="dp-panel-page-disabled">'.self::e(self::panelText('data.previous')).'</span>';
		$next=$page<$totalPages
			? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(self::relationUrl($resource, $relation, $request, $record, $params+['page'=>$page+1])).'">'.self::e(self::panelText('data.next')).'</a>'
			: '<span class="dp-panel-page-disabled">'.self::e(self::panelText('data.next')).'</span>';
		return '<nav class="dp-panel-pagination">'
			.'<span>'.self::e(self::panelText('data.showing_records', ['start'=>$start, 'end'=>$end, 'total'=>$totalRecords])).'</span>'
			.'<div>'.$previous.'<span>'.self::e(self::panelText('data.page_count', ['page'=>$page, 'pages'=>$totalPages])).'</span>'.$next.'</div>'
			.'</nav>';
	}

	/**
	 * Renders a relation column header with optional sort link.
	 *
	 * Non-sortable columns render escaped labels. Sortable columns toggle
	 * direction, reset pagination, and preserve other relation table state.
	 *
	 * @return string Column header HTML.
	 */
	private static function relationColumnHeader(Resource $resource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, mixed $record, Column $column): string {
		$meta=$column->toArray();
		if(($meta['sortable'] ?? false)!==true){
			return self::e((string)$meta['label']);
		}
		[$currentSort, $currentDir]=self::relationSortState($relation, $relationRequest);
		$nextDir=$currentSort===$column->name() && $currentDir==='asc' ? 'desc' : 'asc';
		$params=self::relationStateParams($relation, $relationRequest, true);
		$params['sort']=$column->name();
		$params['dir']=$nextDir;
		$params['page']=1;
		$indicator=$currentSort===$column->name() ? ($currentDir==='asc' ? ' asc' : ' desc') : '';
		return '<a class="dp-panel-sort'.$indicator.'" href="'.self::e(self::relationUrl($resource, $relation, $request, $record, $params)).'">'.self::e((string)$meta['label']).'</a>';
	}

	/**
	 * Resolves the configured related resource for a relation.
	 *
	 * Missing or unknown related resource names return null, allowing relation
	 * tables to render generic rows and limited operations when no resource
	 * metadata is available.
	 *
	 * @param RelationManager $relation Relation manager.
	 * @return ?Resource Related resource instance.
	 */
	private static function relationRelatedResource(RelationManager $relation): ?Resource {
		$name=$relation->relatedResourceName();
		if($name===null || $name===''){
			return null;
		}
		$resource=Panel::get($name);
		return $resource instanceof Resource ? $resource : null;
	}

	/**
	 * Renders row actions for one related record.
	 *
	 * Relation-specific operations are always considered. When a related resource
	 * exists and the user can view it, the renderer adds either a read-only view
	 * modal link or the standard resource row actions with return state.
	 *
	 * @return string Row action HTML.
	 */
	private static function relationRowActions(Resource $parentResource, RelationManager $relation, PanelRequest $request, PanelRelationState $state, mixed $parentRecord, mixed $childRecord): string {
		$childResource=self::relationRelatedResource($relation);
		$relationActions=self::relationRowOperationButtons($parentResource, $relation, $request, $state, $parentRecord, $childRecord, $childResource);
		if(!$childResource instanceof Resource){
			return $relationActions;
		}
		$key=$childResource->recordKey($childRecord);
		if($key===''){
			return $relationActions;
		}
		if($childResource->can('show', $childRecord, $request->user())===false){
			return $relationActions;
		}
		if($relation->isReadOnly()){
			$title=$childResource->recordTitle($childRecord);
			$recordTitle=$title!=='' ? $title : $childResource->label();
			return '<a href="'.self::e($childResource->recordUrl($childRecord, 'show')).'"'.self::resourceModalAttributes('view', self::panelText('data.view_record_title', ['record'=>$recordTitle]), self::panelText('data.view_record_description'), 'lg', 'dialog', true).'>'.self::e(self::panelText('data.view')).'</a>'.$relationActions;
		}
		$relationRequest=$relation->resourceTable()->requestWithResolvedView(self::relationScopedRequest($relation, $request));
		$returnUrl=self::relationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true));
		return self::rowActions($childResource, $childRecord, false, $request, $returnUrl).$relationActions;
	}

	/**
	 * Renders the relation attach action button.
	 *
	 * Attach uses the shared related-record selection modal with the `attach`
	 * operation token and operation availability supplied by relation state.
	 *
	 * @return string Attach button HTML.
	 */
	private static function relationAttachButton(Resource $parentResource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, PanelRelationState $state, mixed $parentRecord): string {
		return self::relationSelectRecordButton($parentResource, $relation, $request, $relationRequest, $state, $parentRecord, 'attach');
	}

	/**
	 * Renders the relation associate action button.
	 *
	 * Associate shares the related-record selector with attach but submits the
	 * `associate` operation token for relations that support ownership changes.
	 *
	 * @return string Associate button HTML.
	 */
	private static function relationAssociateButton(Resource $parentResource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, PanelRelationState $state, mixed $parentRecord): string {
		return self::relationSelectRecordButton($parentResource, $relation, $request, $relationRequest, $state, $parentRecord, 'associate');
	}

	/**
	 * Renders the shared attach/associate record-selection modal.
	 *
	 * Available records are provided by the relation manager, keyed by related
	 * resource identity when possible, and submitted with CSRF and return URL
	 * state. Empty option lists disable the submit button.
	 *
	 * @param string $action Relation operation token: `attach` or `associate`.
	 * @return string Modal trigger button HTML.
	 */
	private static function relationSelectRecordButton(Resource $parentResource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, PanelRelationState $state, mixed $parentRecord, string $action): string {
		if(!$state->operationAvailable($action)){
			return '';
		}
		$operation=$state->operation($action);
		$options='';
		$relatedResource=self::relationRelatedResource($relation);
		foreach($relation->attachableRecords($parentResource, $parentRecord, $relationRequest) as $record){
			$key=$relatedResource instanceof Resource ? $relatedResource->recordKey($record) : self::recordKey($record);
			if($key===''){
				continue;
			}
			$options.='<option value="'.self::e($key).'">'.self::e(self::relationOptionLabel($record, $relatedResource)).'</option>';
		}
		$returnUrl=self::relationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true));
		$label=(string)($operation['label'] ?? ($action==='associate' ? $relation->associateLabelText() : $relation->attachLabelText()));
		$modalLabel=(string)($operation['modal_label'] ?? $label);
		$form='<form class="dp-panel-form dp-panel-relation-attach-form" method="post" action="'.self::e(self::relationOperationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true))).'">'
			.self::csrfInput()
			.self::returnInputUrl($returnUrl)
			.'<input type="hidden" name="relation_action" value="'.self::e($action).'">'
			.'<label class="dp-panel-field"><span>'.self::e(self::panelText('data.record')).'</span><select name="related_key" required>'.($options!=='' ? $options : '<option value="">'.self::e(self::panelText('data.no_records_available')).'</option>').'</select></label>'
			.'<div class="dp-panel-toolbar"><div class="dp-panel-toolbar-actions"><button class="dp-panel-button" type="submit"'.($options==='' ? ' disabled' : '').'>'.self::e($label).'</button></div></div>'
			.'</form>';
		return '<button class="dp-panel-button dp-panel-button-secondary" type="button"'.self::contentModalAttributes('relation_'.$action.'_'.$relation->name(), $modalLabel, self::panelText('data.relation_attach_description', ['action'=>ucfirst($action)]), $form, 'md').'>'.self::e($label).'</button>';
	}

	/**
	 * Renders the legacy detach inline action for one related record.
	 *
	 * The button is hidden unless detach is enabled and authorized. The child key
	 * is resolved from the related resource when available and submitted with
	 * CSRF plus relation return state.
	 *
	 * @return string Detach form HTML.
	 */
	private static function relationDetachButton(Resource $parentResource, RelationManager $relation, PanelRequest $request, mixed $parentRecord, mixed $childRecord, ?Resource $childResource=null): string {
		if($relation->canDetach()===false || $relation->can('detach', $parentRecord, $request->user(), $parentResource)===false){
			return '';
		}
		$key=$childResource instanceof Resource ? $childResource->recordKey($childRecord) : self::recordKey($childRecord);
		if($key===''){
			return '';
		}
		$relationRequest=$relation->resourceTable()->requestWithResolvedView(self::relationScopedRequest($relation, $request));
		$returnUrl=self::relationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true));
		return '<form class="dp-panel-inline-action" method="post" action="'.self::e(self::relationOperationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true))).'">'
			.self::csrfInput()
			.self::returnInputUrl($returnUrl)
			.'<input type="hidden" name="relation_action" value="detach">'
			.'<input type="hidden" name="child_key" value="'.self::e($key).'">'
			.'<button class="dp-panel-action dp-panel-action-danger" type="submit">'.self::e($relation->detachLabelText()).'</button>'
			.'</form>';
	}

	/**
	 * Renders relation-specific row operation buttons.
	 *
	 * Pivot updates, detach, and dissociate are emitted in a stable order when
	 * the resolved relation state marks the operation available.
	 *
	 * @return string Row operation buttons HTML.
	 */
	private static function relationRowOperationButtons(Resource $parentResource, RelationManager $relation, PanelRequest $request, PanelRelationState $state, mixed $parentRecord, mixed $childRecord, ?Resource $childResource=null): string {
		$html='';
		foreach(['update_pivot', 'detach', 'dissociate'] as $action){
			if(!$state->operationAvailable($action)){
				continue;
			}
			$html.=$action==='update_pivot'
				? self::relationPivotButton($parentResource, $relation, $request, $state, $parentRecord, $childRecord, $childResource)
				: self::relationSimpleRowOperationButton($parentResource, $relation, $request, $state, $parentRecord, $childRecord, $childResource, $action);
		}
		return $html;
	}

	/**
	 * Renders a simple detach or dissociate row operation form.
	 *
	 * The form carries the operation token, child key, CSRF token, and return URL
	 * back to the current relation state. Detach uses danger tone; dissociate
	 * uses warning tone.
	 *
	 * @param string $action Relation operation token.
	 * @return string Inline operation form HTML.
	 */
	private static function relationSimpleRowOperationButton(Resource $parentResource, RelationManager $relation, PanelRequest $request, PanelRelationState $state, mixed $parentRecord, mixed $childRecord, ?Resource $childResource, string $action): string {
		$key=$childResource instanceof Resource ? $childResource->recordKey($childRecord) : self::recordKey($childRecord);
		if($key===''){
			return '';
		}
		$operation=$state->operation($action);
		$label=(string)($operation['label'] ?? ($action==='dissociate' ? $relation->dissociateLabelText() : $relation->detachLabelText()));
		$relationRequest=$relation->resourceTable()->requestWithResolvedView(self::relationScopedRequest($relation, $request));
		$returnUrl=self::relationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true));
		$tone=$action==='detach' ? 'danger' : 'warning';
		return '<form class="dp-panel-inline-action" method="post" action="'.self::e(self::relationOperationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true))).'">'
			.self::csrfInput()
			.self::returnInputUrl($returnUrl)
			.'<input type="hidden" name="relation_action" value="'.self::e($action).'">'
			.'<input type="hidden" name="child_key" value="'.self::e($key).'">'
			.'<button class="dp-panel-action dp-panel-action-'.$tone.'" type="submit">'.self::e($label).'</button>'
			.'</form>';
	}

	/**
	 * Renders the pivot metadata edit modal for a related row.
	 *
	 * Pivot fields are rendered in relation update mode using current child
	 * values as defaults. Empty field sets or missing child keys suppress the
	 * action entirely.
	 *
	 * @return string Pivot update trigger button HTML.
	 */
	private static function relationPivotButton(Resource $parentResource, RelationManager $relation, PanelRequest $request, PanelRelationState $state, mixed $parentRecord, mixed $childRecord, ?Resource $childResource=null): string {
		$key=$childResource instanceof Resource ? $childResource->recordKey($childRecord) : self::recordKey($childRecord);
		if($key===''){
			return '';
		}
		$operation=$state->operation('update_pivot');
		$fields=$relation->pivotFieldDefinitions();
		if($fields===[]){
			return '';
		}
		$defaultSection=self::panelText('record.details');
		$sections=[$defaultSection=>[]];
		foreach($fields as $field){
			if(!$field instanceof Field){
				continue;
			}
			$meta=self::fieldMeta($field, $childRecord, $request, 'relation_update_pivot');
			$name=(string)$meta['name'];
			if($name===''){
				continue;
			}
			$sections[$defaultSection][]=self::fieldHtml($name, $meta, self::recordValue($childRecord, $name, $meta['default'] ?? ''), []);
		}
		if($sections[$defaultSection]===[]){
			return '';
		}
		$relationRequest=$relation->resourceTable()->requestWithResolvedView(self::relationScopedRequest($relation, $request));
		$returnUrl=self::relationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true));
		$label=(string)($operation['label'] ?? self::panelText('data.update_pivot'));
		$form='<form class="dp-panel-form dp-panel-relation-pivot-form" method="post" action="'.self::e(self::relationOperationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true))).'">'
			.self::csrfInput()
			.self::returnInputUrl($returnUrl)
			.'<input type="hidden" name="relation_action" value="update_pivot">'
			.'<input type="hidden" name="child_key" value="'.self::e($key).'">'
			.self::formSectionsHtml($sections, 1)
			.'<div class="dp-panel-toolbar"><button class="dp-panel-button" type="submit">'.self::e($label).'</button></div>'
			.'</form>';
		return '<button class="dp-panel-action dp-panel-action-neutral" type="button"'.self::contentModalAttributes('relation_pivot_'.$relation->name().'_'.$key, (string)($operation['modal_label'] ?? $label), self::panelText('data.edit_join_metadata'), $form, 'md').'>'.self::e($label).'</button>';
	}

	/**
	 * Builds the display label for a record option in relation selectors.
	 *
	 * Related resource metadata wins when available, combining title and subtitle
	 * with record key fallback. Generic records use common descriptive fields
	 * before falling back to a localized generic record label.
	 *
	 * @return string Selector option label.
	 */
	private static function relationOptionLabel(mixed $record, ?Resource $resource=null): string {
		if($resource instanceof Resource){
			$title=$resource->recordTitle($record);
			$subtitle=$resource->recordSubtitle($record);
			return trim($title.($subtitle!=='' ? ' - '.$subtitle : '')) ?: $resource->recordKey($record);
		}
		foreach(['title', 'name', 'label', 'number', 'sku', 'id'] as $key){
			$value=self::recordValue($record, $key, null);
			if(is_scalar($value) && trim((string)$value)!==''){
				return (string)$value;
			}
		}
		return self::panelText('data.record');
	}

	/**
	 * Renders a create-related-record action with parent foreign-key prefill.
	 *
	 * The action requires relation create capability, related resource create
	 * authorization, and resolvable foreign/local key metadata. The generated URL
	 * carries prefill state and a return URL back to the relation table.
	 *
	 * @return string Create button HTML.
	 */
	private static function relationCreateButton(Resource $parentResource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, mixed $parentRecord): string {
		if($relation->canCreate()===false || $relation->can('create', $parentRecord, $request->user(), $parentResource)===false){
			return '';
		}
		$childResource=self::relationRelatedResource($relation);
		if(!$childResource instanceof Resource || $childResource->can('create', null, $request->user())===false){
			return '';
		}
		$foreign=$relation->foreignKeyName();
		$local=$relation->localKeyName();
		if($foreign===null || $local===null){
			return '';
		}
		$value=self::recordValue($parentRecord, $local, null);
		if(!is_scalar($value) && $value!==null){
			return '';
		}
		$returnUrl=self::relationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true));
		$query=[
			'prefill'=>[$foreign=>$value],
			'return_to'=>$returnUrl,
		];
		return '<a class="dp-panel-button" href="'.self::e(PanelConfig::resourceUrl($childResource, 'create', $query)).'"'.self::resourceModalAttributes('create', self::panelText('data.create_record_title', ['resource'=>$childResource->label()]), self::panelText('data.create_record_description'), 'xl', 'slide_over', true).'>'.self::e(self::panelText('data.create_record_title', ['resource'=>$childResource->label()])).'</a>';
	}

	/**
	 * Renders the relation reorder modal for the current related records.
	 *
	 * Reorder is available only when the operation state permits it and records
	 * have scalar keys. The modal posts ordered keys with CSRF and return state.
	 *
	 * @param array<int,mixed> $records Records available for ordering.
	 * @return string Reorder trigger button HTML.
	 */
	private static function relationReorderButton(Resource $parentResource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, PanelRelationState $state, mixed $parentRecord, array $records): string {
		if(!$state->operationAvailable('reorder') || $records===[]){
			return '';
		}
		$childResource=self::relationRelatedResource($relation);
		$items='';
		foreach($records as $child){
			$key=$childResource instanceof Resource ? $childResource->recordKey($child) : self::recordKey($child);
			if($key===''){
				continue;
			}
			$items.='<li class="dp-panel-relation-reorder-item" data-dp-panel-relation-reorder-item>'
				.'<input type="hidden" name="ordered_keys[]" value="'.self::e($key).'">'
				.'<span>'.self::e(self::relationOptionLabel($child, $childResource)).'</span>'
				.'<button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-relation-reorder-up>Up</button>'
				.'<button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-relation-reorder-down>Down</button>'
				.'</li>';
		}
		if($items===''){
			return '';
		}
		$operation=$state->operation('reorder');
		$label=(string)($operation['label'] ?? $relation->reorderLabelText());
		$returnUrl=self::relationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true));
		$form='<form class="dp-panel-form dp-panel-relation-reorder-form" method="post" data-dp-panel-relation-reorder action="'.self::e(self::relationOperationUrl($parentResource, $relation, $request, $parentRecord, self::relationStateParams($relation, $relationRequest, true))).'">'
			.self::csrfInput()
			.self::returnInputUrl($returnUrl)
			.'<input type="hidden" name="relation_action" value="reorder">'
			.'<ol class="dp-panel-relation-reorder-list">'.$items.'</ol>'
			.'<div class="dp-panel-toolbar"><button class="dp-panel-button" type="submit">'.self::e($label).'</button></div>'
			.'</form>';
		return '<button class="dp-panel-button dp-panel-button-secondary" type="button"'.self::contentModalAttributes('relation_reorder_'.$relation->name(), (string)($operation['modal_label'] ?? $label), self::panelText('data.reorder_description'), $form, 'md').'>'.self::e($label).'</button>';
	}

	/**
	 * Renders the relation table header summary.
	 *
	 * Header content combines parent title, relation label, description, badge,
	 * total/visible/page counts, and relation facts. Relation managers own the
	 * descriptive metadata so the renderer stays generic.
	 *
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent request.
	 * @param PanelRequest $relationRequest Relation-scoped request.
	 * @param mixed $record Parent record.
	 * @param array<int,mixed> $allRecords All relation records.
	 * @param array<int,mixed> $visibleRecords Current page records.
	 * @param int $totalRecords Filtered total records.
	 * @return string Relation header HTML.
	 */
	private static function relationHeaderHtml(Resource $resource, RelationManager $relation, PanelRequest $request, PanelRequest $relationRequest, mixed $record, array $allRecords, array $visibleRecords, int $totalRecords): string {
		$description=$relation->resolveDescription($record, $relationRequest, $resource, $allRecords);
		$parentTitle=$relation->resolveParentTitle($record, $relationRequest, $resource, $allRecords);
		if($parentTitle===null || $parentTitle===''){
			$parentTitle=$resource->recordTitle($record);
		}
		$badge=$relation->resolveBadge($allRecords, $record, $relationRequest, $resource);
		$facts=$relation->resolveFacts($allRecords, $resource, $relationRequest, $record);
		$meta='<div class="dp-panel-relation-meta">'
			.'<span>'.self::e((string)count($allRecords)).' total</span>'
			.($totalRecords!==count($allRecords) ? '<span>'.self::e((string)$totalRecords).' in view</span>' : '')
			.'<span>'.self::e((string)count($visibleRecords)).' on page</span>'
			.($badge!==null && $badge!=='' ? '<strong>'.self::e($badge).'</strong>' : '')
			.'</div>';
		return '<header class="dp-panel-relation-header"><div class="dp-panel-relation-title">'
			.'<p>'.self::e((string)$resource->label()).($parentTitle!=='' ? ' / '.self::e($parentTitle) : '').'</p>'
			.'<h2>'.self::e((string)$relation->label()).'</h2>'
			.($description!==null && $description!=='' ? '<span>'.self::e($description).'</span>' : '')
			.'</div>'
			.'<div class="dp-panel-relation-aside">'.$meta.self::summaryHtml($facts).'</div>'
			.'</header>';
	}

	/**
	 * Builds the complete state object for a relation table render.
	 *
	 * State assembly resolves relation-scoped request data, all related records,
	 * table views, filters, search, sort, summaries, pagination, inferred
	 * columns, parent metadata, operation availability, empty-state metadata, and
	 * Panel trace events. The returned state is the single source of truth for
	 * relation table HTML and operation controls.
	 *
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent request.
	 * @param mixed $record Optional parent record.
	 * @return PanelRelationState Resolved relation table state.
	 */
	private static function relationState(Resource $resource, RelationManager $relation, PanelRequest $request, mixed $record=null): PanelRelationState {
		$relationRequest=$relation->resourceTable()->requestWithResolvedView(self::relationScopedRequest($relation, $request));
		$columns=$relation->resourceTable()->columnsList();
		$allRecords=$relation->records($resource, $record, $relationRequest, true);
		$viewCounts=self::relationViewCounts($allRecords, $resource, $relation, $relationRequest);
		$records=self::relationApplyTableView($allRecords, $resource, $relation, $relationRequest);
		$records=self::relationApplyFilters($records, $relation, $relationRequest);
		$records=self::relationFilterRecords($records, $relation, $relationRequest);
		$records=self::relationSortRecords($records, $relation, $relationRequest);
		$summaries=self::relationSummaries($resource, $relation, $relationRequest, $records);
		$totalRecords=count($records);
		$page=$relationRequest->page();
		$perPage=$relationRequest->perPage($relation->resourceTable()->defaultPerPage());
		$pageRecords=array_slice($records, ($page-1)*$perPage, $perPage);
		if($columns===[] && $pageRecords!==[]){
			$first=reset($pageRecords);
			$keys=is_array($first) ? array_keys($first) : (is_object($first) ? array_keys(get_object_vars($first)) : []);
			foreach($keys as $key){
				$key=(string)$key;
				if($key!==''){
					$columns[$key]=Column::make($key);
				}
			}
		}
		[$sort, $direction]=self::relationSortState($relation, $relationRequest);
		$filters=[];
		foreach($relation->resourceTable()->filtersList() as $filter){
			if($filter instanceof TableFilter){
				$value=$filter->activeValue($relationRequest);
				if($value!==null){
					$filters[$filter->name()]=$value;
				}
			}
		}
		$parent=[
			'resource'=>$resource->name(),
			'key'=>$record!==null ? self::recordKey($record) : '',
			'title'=>$record!==null ? $resource->recordTitle($record) : '',
			'subtitle'=>$record!==null ? $resource->recordSubtitle($record) : '',
			'url'=>$record!==null ? $resource->recordUrl($record) : '',
		];
		$hasConstraints=self::relationHasConstraints($relation, $relationRequest);
		$tableState=PanelTableState::make($pageRecords, $columns, $columns, $summaries, [
			'mode'=>'relation',
			'query'=>trim((string)$relationRequest->query('q', '')),
			'filters'=>$filters,
			'sort'=>['column'=>$sort, 'direction'=>$direction],
			'active_view'=>$relation->resourceTable()->activeViewName($relationRequest),
			'page'=>$page,
			'per_page'=>$perPage,
			'total_records'=>$totalRecords,
			'all_records'=>count($allRecords),
			'has_constraints'=>$hasConstraints,
		]);
		$relationDefinition=$relation->toArray();
		$relationDefinition['operations']=self::relationOperationStates($resource, $relation, $request, $record);
		$state=PanelRelationState::make($relation, $parent, $tableState, $columns, $allRecords, $records, $pageRecords, $viewCounts, $relation->resolveFacts($allRecords, $resource, $relationRequest, $record), $relation->resolveEmptyState($relationRequest, $hasConstraints), [
			'request'=>$relationRequest->toArray(),
			'description'=>$relation->resolveDescription($record, $relationRequest, $resource, $allRecords),
			'parent_title'=>$relation->resolveParentTitle($record, $relationRequest, $resource, $allRecords),
			'badge'=>$relation->resolveBadge($allRecords, $record, $relationRequest, $resource),
			'can_create'=>$relation->canCreate(),
			'read_only'=>$relation->isReadOnly(),
			'related_resource'=>$relation->relatedResourceName(),
		], $relationDefinition);
		PanelTrace::record('relation.state', [
			'resource'=>$resource,
			'relation'=>$relation,
			'state'=>$state,
		]);
		PanelTrace::record('relation.records', [
			'resource'=>$resource,
			'relation'=>$relation,
			'record_count'=>count($pageRecords),
			'total_count'=>$totalRecords,
			'page'=>$page,
			'per_page'=>$perPage,
			'state'=>$state,
		]);
		return $state;
	}

	/**
	 * Renders a complete relation table surface.
	 *
	 * The renderer consumes a prebuilt `PanelRelationState` or resolves one,
	 * renders toolbar controls, filters, views, table rows, relation row actions,
	 * summaries, pagination, and a traceable wrapper with relation metadata.
	 *
	 * @param Resource $resource Parent resource.
	 * @param RelationManager $relation Relation manager.
	 * @param PanelRequest $request Parent request.
	 * @param mixed $record Optional parent record.
	 * @param ?PanelRelationState $state Precomputed relation state.
	 * @return string Relation table HTML.
	 */
	private static function relationTableHtml(Resource $resource, RelationManager $relation, PanelRequest $request, mixed $record=null, ?PanelRelationState $state=null): string {
		$state ??=self::relationState($resource, $relation, $request, $record);
		$relationRequest=PanelRequest::fromArray($state->meta()['request'] ?? $request->toArray());
		$columns=$state->columns();
		$allRecords=$state->allRecords();
		$records=$state->pageRecords();
		$totalRecords=$state->tableState()->totalRecords();
		$page=$state->tableState()->page();
		$perPage=$state->tableState()->perPage();
		$viewCounts=$state->viewCounts();
		$summaries=$state->tableState()->summaries();
		$hasRowActions=self::relationRelatedResource($relation) instanceof Resource || $state->operationAvailable('detach') || $state->operationAvailable('dissociate') || $state->operationAvailable('update_pivot');
		$head=self::tableHeaderRowsHtml(
			$columns,
			static fn(Column $column): string => self::relationColumnHeader($resource, $relation, $request, $relationRequest, $record, $column),
			false,
			$hasRowActions,
			$relationRequest,
			$resource,
			$relation
		);
		$body='';
		foreach($records as $child){
			$childResource=self::relationRelatedResource($relation);
			$rowLabel=$childResource instanceof Resource ? $childResource->recordTitle($child) : self::panelText('common.record');
			$body.='<tr'.self::tableRowAttributeHtml($relation->resourceTable(), $child, $relationRequest, $resource).($childResource instanceof Resource ? self::tableRowClickAttributeHtml($relation->resourceTable(), $child, $relationRequest, $childResource, $rowLabel) : '').self::tableRowPreviewAttributeHtml($relation->resourceTable(), $child, $relationRequest, $childResource instanceof Resource ? $childResource : $resource).'>';
			foreach($columns as $column){
				$meta=$column->toArray();
				$body.='<td'.self::alignAttr($meta).self::tableDataLabelAttr($meta, $column->name()).self::columnCellAttributeHtml($column, $child, $relationRequest, $resource, $relation).'>'.self::cellHtml($column, $child).'</td>';
			}
			if($hasRowActions){
				$body.='<td class="dp-panel-actions" data-label="'.self::e(self::panelText('table.actions')).'">'.self::relationRowActions($resource, $relation, $request, $state, $record, $child).'</td>';
			}
			$body.='</tr>';
		}
		if($body===''){
			$body='<tr><td colspan="'.max(1, count($columns)+($hasRowActions ? 1 : 0)).'" class="dp-panel-empty">'.self::relationEmptyStateHtml($relation, $relationRequest).'</td></tr>';
		}
		$footer=self::tableFooterRowsHtml($columns, $allRecords, false, $hasRowActions, $relationRequest, $resource, $relation);
		$tools='<div class="dp-panel-toolbar">'
			.self::relationSearchHtml($resource, $relation, $request, $relationRequest, $record)
			.'<div class="dp-panel-toolbar-actions">'.self::relationCreateButton($resource, $relation, $request, $relationRequest, $record).self::relationAttachButton($resource, $relation, $request, $relationRequest, $state, $record).self::relationAssociateButton($resource, $relation, $request, $relationRequest, $state, $record).self::relationReorderButton($resource, $relation, $request, $relationRequest, $state, $record, $allRecords).self::relationPerPageHtml($resource, $relation, $request, $relationRequest, $record).'</div>'
			.'</div>'
			.self::relationFiltersHtml($resource, $relation, $request, $relationRequest, $record)
			.self::relationTableViewsHtml($resource, $relation, $request, $relationRequest, $record, $viewCounts)
			.self::summaryHtml($summaries);
		return '<section class="dp-panel-relation">'
			.self::relationHeaderHtml($resource, $relation, $request, $relationRequest, $record, $allRecords, $records, $totalRecords)
			.$tools
			.'<div class="dp-panel-table-scroll"><table class="dp-panel-table"><thead>'.$head.'</thead><tbody>'.$body.'</tbody>'.$footer.'</table></div>'
			.self::relationPaginationHtml($resource, $relation, $request, $relationRequest, $record, $totalRecords, $page, $perPage)
			.'</section>';
	}
}
