<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Renders table summary data for Panel resource lists.
 *
 * Summaries are resolved from the resource table configuration and escaped into
 * compact HTML cards that can be embedded above a table view.
 */
trait PanelRendererTables {
	/**
	 * Resolves table summary payloads for the current resource state.
	 *
	 * Summary definitions are evaluated against the record set already selected for
	 * rendering, so callers control whether values represent a full filtered set or
	 * a paginated page. Non-summary entries are ignored to keep mixed table metadata
	 * tolerant during resource configuration.
	 *
	 * @param Resource $resource Resource whose table summaries are being rendered.
	 * @param PanelRequest $request Current panel request and query state.
	 * @param list<mixed> $records Records available to summary resolvers.
	 * @return array<int, array<string, mixed>> Resolved summary payloads in display order.
	 */
	private static function summaryData(Resource $resource, PanelRequest $request, array $records): array {
		$summaries=[];
		foreach($resource->resourceTable()->summariesList() as $summary){
			if(!$summary instanceof TableSummary){
				continue;
			}
			$summaries[]=$summary->resolve($records, $resource, $request);
		}
		return $summaries;
	}

	/**
	 * Renders table summary cards from resolved summary payloads.
	 *
	 * Malformed summary entries are skipped, tone classes are allow-listed, and label/value/type text
	 * is escaped. When records were already paginated, the note documents that aggregates are page-scoped.
	 *
	 * @param array<int, mixed> $summaries Resolved table summary payloads.
	 * @param bool $alreadyPaginated Whether the supplied records are already page-bounded.
	 * @return string Summary card section HTML or empty string.
	 */
	private static function summaryHtml(array $summaries, bool $alreadyPaginated=false): string {
		if($summaries===[]){
			return '';
		}
		$html='';
		foreach($summaries as $summary){
			if(!is_array($summary)){
				continue;
			}
			$tone=self::safeTone((string)($summary['tone'] ?? 'neutral'));
			$label=self::e((string)($summary['label'] ?? ''));
			$value=self::e(self::stringValue($summary['formatted'] ?? $summary['value'] ?? ''));
			$type=self::e((string)($summary['type'] ?? 'summary'));
			$html.='<article class="dp-panel-summary dp-panel-summary-'.$tone.'">'
				.'<span class="dp-panel-summary-label">'.$label.'</span>'
				.'<strong class="dp-panel-summary-value">'.$value.'</strong>'
				.'<small class="dp-panel-summary-type">'.$type.'</small>'
				.'</article>';
		}
		if($html===''){
			return '';
		}
		$note=$alreadyPaginated ? '<p>Summaries reflect the records supplied to this page.</p>' : '';
		return '<section class="dp-panel-summaries">'.$html.$note.'</section>';
	}

	/**
	 * Resolves the active table view name for a resource and request.
	 *
	 * delegates to Resource so URL query state, defaults, and resource-specific view rules remain the
	 * single source of truth for table view selection.
	 *
	 * @param Resource $resource Resource whose table view is being resolved.
	 * @param PanelRequest $request Current panel request.
	 * @return string Active view name, or an empty string for the all/default view.
	 */
	private static function activeTableViewName(Resource $resource, PanelRequest $request): string {
		return $resource->activeTableViewName($request);
	}

	/**
	 * Resolves the active table group object for the current request.
	 *
	 * empty or unknown group names return null; only registered TableGroup instances are accepted.
	 *
	 * @param Resource $resource Resource owning the group list.
	 * @param PanelRequest $request Current panel request.
	 * @return TableGroup|null Active table group.
	 */
	private static function activeTableGroup(Resource $resource, PanelRequest $request): ?TableGroup {
		$name=$resource->activeTableGroupName($request);
		if($name===''){
			return null;
		}
		$group=$resource->tableGroupsList()[$name] ?? null;
		return $group instanceof TableGroup ? $group : null;
	}

	/**
	 * Renders the table grouping navigation while preserving active table state.
	 *
	 * search, sort, per-page, view, filters, visible columns, and density are carried forward through
	 * group links, while page resets to one when the group changes.
	 *
	 * @param Resource $resource Resource owning table groups.
	 * @param PanelRequest $request Current panel request.
	 * @return string Group navigation HTML or empty string.
	 */
	private static function tableGroupsHtml(Resource $resource, PanelRequest $request): string {
		$groups=$resource->tableGroupsList();
		if($groups===[]){
			return '';
		}
		$active=$resource->activeTableGroupName($request);
		$params=[
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
			'per_page'=>(string)$request->perPage($resource->resourceTable()->defaultPerPage()),
		]+self::activeViewParams($resource, $request)+self::activeGroupParams($resource, $request)+self::activeFilterParams($resource, $request)+self::activeColumnParams($request)+self::activeDensityParams($request);
		$params=self::filterQueryValues($params);
		$html=self::tableGroupLink($resource, $params, 'none', self::panelText('table.ungrouped'), $active==='');
		foreach($groups as $group){
			if(!$group instanceof TableGroup){
				continue;
			}
			$meta=$group->toArray();
			$html.=self::tableGroupLink($resource, $params, $group->name(), (string)($meta['label'] ?? $group->name()), $active===$group->name());
		}
		return '<nav class="dp-panel-table-groups" aria-label="'.self::e(self::panelText('table.grouping_aria', ['table'=>(string)$resource->pluralLabel()])).'">'.$html.'</nav>';
	}

	/**
	 * Renders one group-switching link.
	 *
	 * group none is represented explicitly, pagination resets to the first page, and active links emit
	 * aria-current for navigation accessibility.
	 *
	 * @param Resource $resource Resource URL owner.
	 * @param array<string, mixed> $params Query parameters to preserve.
	 * @param string $group Target group name or none.
	 * @param string $label Link label.
	 * @param bool $active Whether this group is active.
	 * @return string Group link HTML.
	 */
	private static function tableGroupLink(Resource $resource, array $params, string $group, string $label, bool $active): string {
		if($group!=='' && $group!=='none'){
			$params['group']=$group;
		}
		else {
			$params['group']='none';
		}
		$params['page']=1;
		return '<a class="dp-panel-table-group'.($active ? ' active' : '').'" href="'.self::e(PanelConfig::resourceUrl($resource, '', $params)).'"'.($active ? ' aria-current="page"' : '').'><span>'.self::e($label).'</span></a>';
	}

	/**
	 * Returns the table views that participate in the status board.
	 *
	 * only view names declared by the resource as status views and backed by registered TableView
	 * instances are returned.
	 *
	 * @param Resource $resource Resource owning table views.
	 * @return array<string, TableView> Status board views keyed by name.
	 */
	private static function statusBoardViews(Resource $resource): array {
		$views=[];
		$allViews=$resource->tableViewsList();
		foreach($resource->statusViewNames() as $name){
			$view=$allViews[$name] ?? null;
			if($view instanceof TableView){
				$views[$name]=$view;
			}
		}
		return $views;
	}

	/**
	 * Resolves status-board transition targets available for one record.
	 *
	 * both generic transition permission and transition-specific permission must pass. Empty transition
	 * names or target statuses are ignored, and the returned map is target status to transition name.
	 *
	 * @param Resource $resource Resource owning transition definitions.
	 * @param mixed $record Record being moved.
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, string> Allowed target status to transition-name map.
	 */
	private static function boardTransitionTargets(Resource $resource, mixed $record, PanelRequest $request): array {
		$targets=[];
		if($resource->canTransition()===false || $resource->can('transition', $record, $request->user())===false){
			return [];
		}
		foreach($resource->statusTransitionsList($record) as $transition){
			$name=(string)($transition['name'] ?? '');
			$to=Resource::normalizeName((string)($transition['to'] ?? ''));
			if($name==='' || $to===''){
				continue;
			}
			if($resource->can('transition:'.$name, $record, $request->user())===false){
				continue;
			}
			$targets[$to]=$name;
		}
		return $targets;
	}

	/**
	 * Renders the status-board pulse placeholder.
	 *
	 * the current renderer returns no board pulse markup while preserving a dedicated extension point
	 * for future board insight UI.
	 *
	 * @param Resource $resource Resource owning the board.
	 * @param PanelRequest $request Current panel request.
	 * @param array<int, mixed> $columns Board columns.
	 * @param int $totalRecords Total board records.
	 * @param int $movableCount Count of cards with available transitions.
	 * @param bool $alreadyPaginated Whether records were already bounded.
	 * @return string Board pulse HTML.
	 */
	private static function boardPulseHtml(Resource $resource, PanelRequest $request, array $columns, int $totalRecords, int $movableCount, bool $alreadyPaginated=false): string {
		return '';
	}

	/**
	 * Chooses board guidance text from current search, filter, card, and lane state.
	 *
	 * the return tuple is headline and body text. It does not inspect records, execute transitions, or
	 * mutate board state; it only interprets counts already computed by the caller.
	 *
	 * @param string $query Active board search query.
	 * @param int $filterCount Active filter count.
	 * @param int $totalRecords Total card count.
	 * @param int $emptyColumns Empty lane count.
	 * @param int $movableCount Movable card count.
	 * @param int $columnCount Board lane count.
	 * @return array{0: string, 1: string} Recommendation headline and body.
	 */
	private static function boardPulseRecommendation(string $query, int $filterCount, int $totalRecords, int $emptyColumns, int $movableCount, int $columnCount): array {
		if($totalRecords===0 && ($query!=='' || $filterCount>0)){
			return [self::panelText('table.board_no_cards_match_title'), self::panelText('table.board_no_cards_match_body')];
		}
		if($totalRecords===0){
			return [self::panelText('table.board_first_cards_title'), self::panelText('table.board_first_cards_body')];
		}
		if($movableCount>0){
			return [self::panelText('table.board_move_cards_title'), self::panelText('table.board_move_cards_body')];
		}
		if($emptyColumns>0 && $emptyColumns<$columnCount){
			return [self::panelText('table.board_quiet_lanes_title'), self::panelText('table.board_quiet_lanes_body')];
		}
		if($query!=='' || $filterCount>0){
			return [self::panelText('table.board_focused_title'), self::panelText('table.board_focused_body')];
		}
		return [self::panelText('table.board_balanced_title'), self::panelText('table.board_balanced_body')];
	}

	/**
	 * Filters records through the active TableView matcher.
	 *
	 * missing, empty, or unregistered views leave records unchanged; registered views receive the
	 * record, request, and resource context and the result is reindexed for table rendering.
	 *
	 * @param array<int, mixed> $records Candidate records.
	 * @param Resource $resource Resource owning table views.
	 * @param PanelRequest $request Current panel request.
	 * @param string $activeView Active view name.
	 * @return array<int, mixed> Records matching the active view.
	 */
	private static function applyTableView(array $records, Resource $resource, PanelRequest $request, string $activeView): array {
		if($activeView===''){
			return $records;
		}
		$view=$resource->tableViewsList()[$activeView] ?? null;
		if(!$view instanceof TableView){
			return $records;
		}
		return array_values(array_filter($records, static fn(mixed $record): bool => $view->matches($record, $request, $resource)));
	}

	/**
	 * Counts how many records match each registered table view.
	 *
	 * the empty-string key represents the all-record count, and non-TableView entries are ignored.
	 * Counts are computed from the already supplied in-memory records.
	 *
	 * @param Resource $resource Resource owning table views.
	 * @param PanelRequest $request Current panel request.
	 * @param array<int, mixed> $records Records to count.
	 * @return array<string, int> View counts keyed by view name.
	 */
	private static function tableViewCounts(Resource $resource, PanelRequest $request, array $records): array {
		$views=$resource->tableViewsList();
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
	 * Renders saved table view navigation while preserving compatible table state.
	 *
	 * the all-view link clears view-specific state, registered view links inherit only state not
	 * replaced by view defaults, and optional badges come from provided counts or view badge resolution.
	 *
	 * @param Resource $resource Resource owning table views.
	 * @param PanelRequest $request Current panel request.
	 * @param string $activeView Active view name.
	 * @param array<string, mixed> $counts Optional precomputed counts.
	 * @return string View navigation HTML or empty string.
	 */
	private static function tableViewsHtml(Resource $resource, PanelRequest $request, string $activeView, array $counts=[]): string {
		$views=$resource->tableViewsList();
		if($views===[]){
			return '';
		}
		$baseParams=[
			'q'=>trim((string)$request->query('q', '')),
		];
		$allParams=self::filterQueryValues($baseParams+self::activeColumnParams($request)+self::activeGroupParams($resource, $request));
		$html=self::tableViewLink($resource, $allParams, 'all', self::panelText('common.all'), 'neutral', $activeView==='', $counts[''] ?? null);
		foreach($views as $view){
			if(!$view instanceof TableView){
				continue;
			}
			$meta=$view->toArray();
			$defaults=$view->queryDefaults();
			$params=$baseParams;
			if(array_key_exists('q', $defaults)){
				unset($params['q']);
			}
			if(!array_key_exists('visible_columns', $defaults)){
				$params+=self::activeColumnParams($request);
			}
			$params+=self::activeGroupParams($resource, $request);
			$params=self::filterQueryValues($params);
			$badge=$counts[$view->name()] ?? $view->resolveBadge([], $request, $resource);
			$html.=self::tableViewLink(
				$resource,
				$params,
				$view->name(),
				(string)($meta['label'] ?? $view->name()),
				(string)($meta['tone'] ?? 'neutral'),
				$activeView===$view->name(),
				$badge
			);
		}
		return '<nav class="dp-panel-table-views" aria-label="'.self::e(self::panelText('table.views_aria', ['table'=>(string)$resource->pluralLabel()])).'">'.$html.'</nav>';
	}

	/**
	 * Renders one saved-view navigation link.
	 *
	 * empty query values are removed, pagination resets to page one, tone is allow-listed, and active
	 * view links expose aria-current.
	 *
	 * @param Resource $resource Resource URL owner.
	 * @param array<string, mixed> $params Query parameters to preserve.
	 * @param string $view Target view name.
	 * @param string $label View label.
	 * @param string $tone View tone.
	 * @param bool $active Whether this view is active.
	 * @param mixed $badge Optional badge value.
	 * @return string View link HTML.
	 */
	private static function tableViewLink(Resource $resource, array $params, string $view, string $label, string $tone, bool $active, mixed $badge=null): string {
		$params=array_filter($params, static fn(mixed $value): bool => (string)$value!=='');
		if($view!==''){
			$params['view']=$view;
		}
		else {
			unset($params['view']);
		}
		$params['page']=1;
		$url=self::e(PanelConfig::resourceUrl($resource, '', $params));
		$class='dp-panel-table-view dp-panel-table-view-'.self::safeTone($tone).($active ? ' active' : '');
		$badgeHtml=$badge!==null && $badge!=='' ? '<small>'.self::e(self::stringValue($badge)).'</small>' : '';
		return '<a class="'.$class.'" href="'.$url.'"'.($active ? ' aria-current="page"' : '').' title="'.self::e(self::panelText('table.view_title', ['view'=>$label])).'"><i class="dp-panel-table-view-dot" aria-hidden="true"></i><span>'.self::e($label).'</span>'.$badgeHtml.'</a>';
	}

	/**
	 * Renders the table pulse placeholder.
	 *
	 * the current renderer emits no pulse UI while retaining a stable method boundary for future table
	 * insight chrome that can use already-computed counts and state.
	 *
	 * @return string Table pulse HTML.
	 */
	private static function tablePulseHtml(Resource $resource, PanelRequest $request, int $totalRecords, int $visibleRecords, int $page, int $perPage, string $activeView, bool $hasBulkActions, int $summaryCount, bool $alreadyPaginated=false): string {
		return '';
	}

	/**
	 * Chooses table guidance text from search, filter, view, bulk action, and summary state.
	 *
	 * returns a two-item headline/body tuple based entirely on caller-supplied state and never queries
	 * resources or mutates preferences.
	 *
	 * @return array{0: string, 1: string} Recommendation headline and body.
	 */
	private static function tablePulseRecommendation(string $query, int $filterCount, string $activeView, int $totalRecords, bool $hasBulkActions, int $summaryCount): array {
		if($totalRecords===0 && ($query!=='' || $filterCount>0 || $activeView!=='')){
			return ['Nothing matches this slice.', 'Clear one constraint at a time to find which search, filter, or view is hiding the records.'];
		}
		if($query!=='' || $filterCount>0){
			return ['You are looking at a narrowed slice.', 'Use the visible rows for focused work, or clear constraints before comparing the wider dataset.'];
		}
		if($activeView!==''){
			return ['This saved view is in control.', 'Work through this perspective first, then switch to All when you need the full resource picture.'];
		}
		if($hasBulkActions && $totalRecords>1){
			return ['Select rows when the work repeats.', 'Bulk actions are available here, so repeated updates can happen without opening each record.'];
		}
		if($summaryCount>0){
			return ['Read the summaries before drilling in.', 'The table has aggregate signals, so scan those totals before opening individual records.'];
		}
		return ['This list is ready for exploration.', 'Search, filter, sort, or open a record when you need more context.'];
	}

	/**
	 * Applies the free-text table search query across searchable columns.
	 *
	 * searchable columns are preferred, all columns become the fallback search surface, and matching is
	 * delegated to each Column so formatting/search rules stay column-owned.
	 *
	 * @param array<int, mixed> $records Candidate records.
	 * @param Resource $resource Resource owning the table columns.
	 * @param PanelRequest $request Current panel request.
	 * @return array<int, mixed> Records matching the query.
	 */
	private static function filterRecords(array $records, Resource $resource, PanelRequest $request): array {
		$query=trim((string)$request->query('q', ''));
		if($query===''){
			return $records;
		}
		$columns=$resource->resourceTable()->columnsList();
		$searchable=array_filter($columns, static fn(Column $column): bool => ($column->toArray()['searchable'] ?? false)===true);
		if($searchable===[]){
			$searchable=$columns;
		}
		return array_values(array_filter($records, static function(mixed $record) use ($searchable, $query, $request, $resource): bool {
			foreach($searchable as $column){
				if($column->matchesSearch($record, $query, $request, $resource, $resource->resourceTable())){
					return true;
				}
			}
			return false;
		}));
	}

	/**
	 * Builds the session preference key for the current table surface.
	 *
	 * resource names are normalized and dashboard is used as a stable fallback when the request is not
	 * scoped to a resource.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return string Session preference key.
	 */
	private static function tablePreferenceKey(PanelRequest $request): string {
		return Resource::normalizeName((string)($request->resourceName() ?? 'dashboard')) ?: 'dashboard';
	}

	/**
	 * Reads persisted table preferences from the active PHP session.
	 *
	 * preferences are unavailable without an active session, and only array-shaped data under the
	 * current table preference key is returned.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, mixed> Persisted table preferences.
	 */
	private static function tablePreferences(PanelRequest $request): array {
		if(PHP_SESSION_ACTIVE!==session_status()){
			return [];
		}
		$all=is_array($_SESSION[self::TABLE_PREF_SESSION_KEY] ?? null) ? $_SESSION[self::TABLE_PREF_SESSION_KEY] : [];
		$key=self::tablePreferenceKey($request);
		return is_array($all[$key] ?? null) ? $all[$key] : [];
	}

	/**
	 * Merges table preferences into the active PHP session.
	 *
	 * no-op without an active session; new preferences replace same-named existing keys while
	 * preserving unrelated preferences for the same table.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, mixed> $preferences Preferences to persist.
	 * @return void
	 */
	private static function saveTablePreferences(PanelRequest $request, array $preferences): void {
		if(PHP_SESSION_ACTIVE!==session_status()){
			return;
		}
		$key=self::tablePreferenceKey($request);
		$all=is_array($_SESSION[self::TABLE_PREF_SESSION_KEY] ?? null) ? $_SESSION[self::TABLE_PREF_SESSION_KEY] : [];
		$current=is_array($all[$key] ?? null) ? $all[$key] : [];
		$all[$key]=array_replace($current, $preferences);
		$_SESSION[self::TABLE_PREF_SESSION_KEY]=$all;
	}

	/**
	 * Clears persisted table preferences for the current table key.
	 *
	 * no-op without an active session and does not disturb preferences for other resources.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return void
	 */
	private static function clearTablePreferences(PanelRequest $request): void {
		if(PHP_SESSION_ACTIVE!==session_status()){
			return;
		}
		$key=self::tablePreferenceKey($request);
		if(is_array($_SESSION[self::TABLE_PREF_SESSION_KEY] ?? null)){
			unset($_SESSION[self::TABLE_PREF_SESSION_KEY][$key]);
		}
	}

	/**
	 * Detects the query flag that resets stored table preferences.
	 *
	 * only the exact reset_table_view=1 flag is treated as an intentional reset.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return bool Whether table preferences should be cleared.
	 */
	private static function resettingTablePreferences(PanelRequest $request): bool {
		return (string)$request->query('reset_table_view', '')==='1';
	}

	/**
	 * Resolves the visible column set for the current request and stored preferences.
	 *
	 * hidden-by-operation columns are excluded, non-toggleable columns always remain visible, requested
	 * toggleable columns win, defaults apply when no request preference exists, and an empty result falls back to all
	 * available columns.
	 *
	 * @param array<string, Column> $columns Candidate columns keyed by name.
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, Column> Visible columns.
	 */
	private static function visibleColumns(array $columns, PanelRequest $request): array {
		$requested=self::requestedColumns($request);
		$available=[];
		$visible=[];
		foreach($columns as $name=>$column){
			if(!$column instanceof Column){
				continue;
			}
			if(!$column->isVisible($request->operation(), null, $request)){
				continue;
			}
			$available[$name]=$column;
			$meta=$column->toArray();
			if(($meta['toggleable'] ?? true)===false){
				$visible[$name]=$column;
				continue;
			}
			if($requested!==[] && in_array($column->name(), $requested, true)){
				$visible[$name]=$column;
				continue;
			}
			if($requested===[] && ($meta['visible_by_default'] ?? true)===true){
				$visible[$name]=$column;
			}
		}
		return $visible!==[] ? $visible : $available;
	}

	/**
	 * Applies visible table filters to the in-memory record set.
	 *
	 * invisible filters do not constrain records, and each visible TableFilter owns its own matching
	 * semantics. The output is reindexed for rendering.
	 *
	 * @param array<int, mixed> $records Candidate records.
	 * @param Resource $resource Resource owning filters.
	 * @param PanelRequest $request Current panel request.
	 * @return array<int, mixed> Filtered records.
	 */
	private static function applyFilters(array $records, Resource $resource, PanelRequest $request): array {
		$filters=$resource->resourceTable()->filtersList();
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
	 * Sorts records using the active sortable column state.
	 *
	 * unrecognized, unsortable, or empty sort columns leave record order unchanged; sortable columns
	 * delegate comparison to Column so type-aware sorting stays column-owned.
	 *
	 * @param array<int, mixed> $records Records to sort.
	 * @param Resource $resource Resource owning columns.
	 * @param PanelRequest $request Current panel request.
	 * @return array<int, mixed> Sorted records.
	 */
	private static function sortRecords(array $records, Resource $resource, PanelRequest $request): array {
		[$sort, $direction]=self::sortState($resource, $request);
		if($sort===''){
			return $records;
		}
		$columns=$resource->resourceTable()->columnsList();
		$column=$columns[$sort] ?? null;
		if(!$column instanceof Column || ($column->toArray()['sortable'] ?? false)!==true){
			return $records;
		}
		usort($records, static fn(mixed $left, mixed $right): int => $column->compareForSort($left, $right, $direction, $request, $resource, $resource->resourceTable()));
		return $records;
	}

	/**
	 * Resolves active sort column and direction from query state or table defaults.
	 *
	 * explicit query parameters win, direction is constrained to asc or desc, and default sort
	 * definitions apply only when no sort query was provided.
	 *
	 * @param Resource $resource Resource owning table defaults.
	 * @param PanelRequest $request Current panel request.
	 * @return array{0: string, 1: string} Sort column and direction.
	 */
	private static function sortState(Resource $resource, PanelRequest $request): array {
		$query=$request->query();
		$hasSort=is_array($query) && array_key_exists('sort', $query);
		$sort=Resource::normalizeName((string)($query['sort'] ?? ''));
		$direction=strtolower((string)($query['dir'] ?? 'asc'))==='desc' ? 'desc' : 'asc';
		if(!$hasSort && $sort===''){
			$default=$resource->resourceTable()->defaultSortDefinition();
			if(is_array($default)){
				$sort=Resource::normalizeName((string)($default['column'] ?? ''));
				$direction=strtolower((string)($default['direction'] ?? 'asc'))==='desc' ? 'desc' : 'asc';
			}
		}
		return [$sort, $direction];
	}

	/**
	 * Renders grouped table body rows when an active TableGroup is selected.
	 *
	 * records are bucketed by the group key resolver, buckets are sorted by group direction, group
	 * summaries/actions are rendered before child rows, and collapsed groups hide their child rows without dropping them
	 * from the DOM.
	 *
	 * @param array<int, mixed> $records Records to render.
	 * @param Resource $resource Resource owning the table and records.
	 * @param PanelRequest $request Current panel request.
	 * @param array<int, Column> $columns Visible columns.
	 * @param bool $hasBulkActions Whether select cells should be included.
	 * @param string $bulkFormId Bulk action form id.
	 * @return string|null Grouped tbody HTML, empty string for no buckets, or null when grouping is inactive.
	 */
	private static function groupedTableBody(array $records, Resource $resource, PanelRequest $request, array $columns, bool $hasBulkActions, string $bulkFormId): ?string {
		$group=self::activeTableGroup($resource, $request);
		if(!$group instanceof TableGroup){
			return null;
		}
		$buckets=[];
		foreach($records as $record){
			$key=$group->resolveKey($record, $resource, $request, $resource->resourceTable());
			$buckets[$key] ??=[];
			$buckets[$key][]=$record;
		}
		if($buckets===[]){
			return '';
		}
		$meta=$group->toArray();
		$direction=(string)($meta['direction'] ?? 'asc');
		uksort($buckets, static fn(string $left, string $right): int => $direction==='desc' ? strnatcasecmp($right, $left) : strnatcasecmp($left, $right));
		$colspan=max(1, count($columns)+1+($hasBulkActions ? 1 : 0));
		$body='';
		foreach($buckets as $key=>$bucket){
			$label=$group->resolveLabel((string)$key, $bucket, $resource, $request, $resource->resourceTable());
			$description=$group->resolveDescription((string)$key, $bucket, $resource, $request, $resource->resourceTable());
			$summaries=$group->resolveSummaries((string)$key, $bucket, $resource, $request, $resource->resourceTable());
			$actions=$group->resolveActions((string)$key, $bucket, $resource, $request, $resource->resourceTable());
			$groupId='dp-panel-group-'.substr(sha1($resource->name().'|'.$group->name().'|'.(string)$key), 0, 12);
			$collapsible=($meta['collapsible'] ?? false)===true;
			$collapsed=$collapsible && ($meta['collapsed'] ?? false)===true;
			$countLabel=self::panelText('table.record_count', ['count'=>number_format(count($bucket)), 'record'=>count($bucket)===1 ? self::panelText('common.record') : self::panelText('common.records')]);
			$header=$collapsible
				? '<button class="dp-panel-table-group-heading" type="button" data-dp-panel-group-toggle data-dp-panel-group-target="'.self::e($groupId).'" aria-expanded="'.($collapsed ? 'false' : 'true').'"><span>'.self::e($label).'</span><small>'.self::e($countLabel).'</small>'.self::groupSummaryChipsHtml($summaries).'<i aria-hidden="true"></i></button>'
				: '<div class="dp-panel-table-group-heading"><span>'.self::e($label).'</span><small>'.self::e($countLabel).'</small>'.self::groupSummaryChipsHtml($summaries).'</div>';
			$body.='<tr class="dp-panel-table-group-row'.($collapsible ? ' dp-panel-table-group-row-collapsible' : '').($collapsed ? ' dp-panel-table-group-row-collapsed' : '').'" data-dp-panel-group="'.self::e((string)$key).'" data-dp-panel-group-id="'.self::e($groupId).'"><td colspan="'.$colspan.'">'.$header.($description!=='' ? '<em>'.self::e($description).'</em>' : '').self::groupActionsHtml($actions).'</td></tr>';
			foreach($bucket as $record){
				$recordKey=$resource->recordKey($record);
				$recordTitle=$resource->recordTitle($record);
				$rowLabel=$recordTitle!=='' ? $recordTitle : ($recordKey!=='' ? $recordKey : self::panelText('data.record'));
				$body.='<tr tabindex="-1" data-dp-panel-row data-dp-panel-group-child="'.self::e($groupId).'" data-dp-panel-record-key="'.self::e($recordKey).'" aria-label="'.self::e($rowLabel).'"'.self::tableRowAttributeHtml($resource->resourceTable(), $record, $request, $resource).self::tableRowClickAttributeHtml($resource->resourceTable(), $record, $request, $resource, $rowLabel).self::tableRowPreviewAttributeHtml($resource->resourceTable(), $record, $request, $resource).($collapsed ? ' hidden' : '').'>';
				if($hasBulkActions){
					$body.='<td class="dp-panel-select" data-label="'.self::e(self::panelText('table.select_all_visible')).'">'.self::recordCheckbox($resource, $record, $bulkFormId).'</td>';
				}
				foreach($columns as $column){
					$meta=$column->toArray();
					$body.='<td'.self::alignAttr($meta).self::tableDataLabelAttr($meta, $column->name()).self::columnCellAttributeHtml($column, $record, $request, $resource).'>'.self::editableCellHtml($column, $record, $request, $resource).'</td>';
				}
				$body.='<td class="dp-panel-actions" data-label="'.self::e(self::panelText('client.action')).'">'.self::rowActions($resource, $record, false, $request).'</td>';
				$body.='</tr>';
			}
		}
		return $body;
	}

	/**
	 * Renders compact summary chips for a grouped table bucket.
	 *
	 * malformed summaries are skipped, empty label/value pairs are omitted, tones are allow-listed, and
	 * emitted text is escaped.
	 *
	 * @param array<int, mixed> $summaries Group summary payloads.
	 * @return string Group summary chip HTML or empty string.
	 */
	private static function groupSummaryChipsHtml(array $summaries): string {
		if($summaries===[]){
			return '';
		}
		$html='';
		foreach($summaries as $summary){
			if(!is_array($summary)){
				continue;
			}
			$tone=self::safeTone((string)($summary['tone'] ?? 'neutral'));
			$label=trim((string)($summary['label'] ?? ''));
			$value=self::stringValue($summary['formatted'] ?? $summary['value'] ?? '');
			if($label==='' && $value===''){
				continue;
			}
			$html.='<b class="dp-panel-table-group-chip dp-panel-table-group-chip-'.$tone.'">'.($label!=='' ? '<span>'.self::e($label).'</span>' : '').'<strong>'.self::e($value).'</strong></b>';
		}
		return $html!=='' ? '<span class="dp-panel-table-group-chips">'.$html.'</span>' : '';
	}

	/**
	 * Renders action links attached to a grouped table bucket.
	 *
	 * actions require a label and URL, tone and icon text are normalized, target attributes are escaped,
	 * and malformed action payloads are ignored.
	 *
	 * @param array<int, mixed> $actions Group action payloads.
	 * @return string Group actions navigation HTML or empty string.
	 */
	private static function groupActionsHtml(array $actions): string {
		if($actions===[]){
			return '';
		}
		$html='';
		foreach($actions as $action){
			if(!is_array($action)){
				continue;
			}
			$label=trim((string)($action['label'] ?? ''));
			$url=trim((string)($action['url'] ?? ''));
			if($label==='' || $url===''){
				continue;
			}
			$tone=self::safeTone((string)($action['tone'] ?? 'neutral'));
			$icon=trim((string)($action['icon'] ?? ''));
			$target=trim((string)($action['target'] ?? ''));
			$targetAttr=$target!=='' ? ' target="'.self::e($target).'" rel="noopener noreferrer"' : '';
			$iconHtml=$icon!=='' ? '<span aria-hidden="true">'.self::e(self::compactNavIcon($icon, $label)).'</span>' : '';
			$html.='<a class="dp-panel-table-group-action dp-panel-table-group-action-'.$tone.'" href="'.self::e($url).'"'.$targetAttr.'>'.$iconHtml.'<strong>'.self::e($label).'</strong></a>';
		}
		return $html!=='' ? '<nav class="dp-panel-table-group-actions" aria-label="'.self::e(self::panelText('client.action')).'">'.$html.'</nav>' : '';
	}

	/**
	 * Builds baseline route parameters for a resource table operation.
	 *
	 * resource is always included; the operation parameter is omitted for the index operation so URLs
	 * stay canonical.
	 *
	 * @param Resource $resource Resource being routed.
	 * @param string $operation Target operation.
	 * @return array<string, string> Route parameters.
	 */
	private static function tableRouteParams(Resource $resource, string $operation='index'): array {
		$params=['resource'=>$resource->name()];
		if($operation!=='' && $operation!=='index'){
			$params['operation']=$operation;
		}
		return $params;
	}

	/**
	 * Renders the full table search form and filter launcher.
	 *
	 * query, sort, pagination, view, group, filters, visible columns, and density state are preserved
	 * through hidden inputs, while the visible search value and all attributes are escaped.
	 *
	 * @param Resource $resource Resource being searched.
	 * @param PanelRequest $request Current panel request.
	 * @return string Search and filters HTML.
	 */
	private static function searchForm(Resource $resource, PanelRequest $request): string {
		$query=trim((string)$request->query('q', ''));
		$sort=trim((string)$request->query('sort', ''));
		$dir=trim((string)$request->query('dir', ''));
		$perPage=$request->perPage($resource->resourceTable()->defaultPerPage());
		$hidden='';
		foreach(array_merge(self::tableRouteParams($resource), ['sort'=>$sort, 'dir'=>$dir, 'per_page'=>(string)$perPage], self::activeViewParams($resource, $request), self::activeGroupParams($resource, $request), self::activeFilterParams($resource, $request), self::activeColumnParams($request), self::activeDensityParams($request)) as $key=>$value){
			if($value!==''){
				$hidden.='<input type="hidden" name="'.self::e($key).'" value="'.self::e($value).'">';
			}
		}
		return '<form class="dp-panel-search" method="get" action="'.self::e(PanelConfig::resourceUrl($resource)).'">'
			.$hidden
			.'<input type="search" name="q" value="'.self::e($query).'" placeholder="'.self::e(self::panelText('table.search_placeholder')).'" aria-label="'.self::e(self::panelText('table.search_resource_aria', ['resource'=>(string)$resource->pluralLabel()])).'" data-dp-panel-search-input>'
			.'<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('common.search')).'</button>'
			.($query!=='' ? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::resourceUrl($resource)).'">'.self::e(self::panelText('common.clear', [], 'Clear')).'</a>' : '')
			.'</form>'
			.self::filtersHtml($resource, $request);
	}

	/**
	 * Renders the compact table search form without the filter launcher.
	 *
	 * preserves the same core table state as searchForm() while omitting expanded filter controls for
	 * compact layouts.
	 *
	 * @param Resource $resource Resource being searched.
	 * @param PanelRequest $request Current panel request.
	 * @return string Compact search form HTML.
	 */
	private static function compactSearchForm(Resource $resource, PanelRequest $request): string {
		$query=trim((string)$request->query('q', ''));
		$sort=trim((string)$request->query('sort', ''));
		$dir=trim((string)$request->query('dir', ''));
		$perPage=$request->perPage($resource->resourceTable()->defaultPerPage());
		$hidden='';
		foreach(array_merge(self::tableRouteParams($resource), ['sort'=>$sort, 'dir'=>$dir, 'per_page'=>(string)$perPage], self::activeViewParams($resource, $request), self::activeGroupParams($resource, $request), self::activeFilterParams($resource, $request), self::activeColumnParams($request), self::activeDensityParams($request)) as $key=>$value){
			if($value!==''){
				$hidden.='<input type="hidden" name="'.self::e($key).'" value="'.self::e($value).'">';
			}
		}
		return '<form class="dp-panel-search dp-panel-search-compact" method="get" action="'.self::e(PanelConfig::resourceUrl($resource)).'">'
			.$hidden
			.'<input type="search" name="q" value="'.self::e($query).'" placeholder="'.self::e(self::panelText('table.search_placeholder')).'" aria-label="'.self::e(self::panelText('table.search_resource_aria', ['resource'=>(string)$resource->pluralLabel()])).'" data-dp-panel-search-input>'
			.'<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('common.search')).'</button>'
			.($query!=='' ? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::resourceUrl($resource)).'">'.self::e(self::panelText('common.clear', [], 'Clear')).'</a>' : '')
			.'</form>';
	}

	/**
	 * Renders the status-board search form and board filter launcher.
	 *
	 * preserves board route parameters, search, sort, direction, and active filters while targeting the
	 * board operation rather than the table index.
	 *
	 * @param Resource $resource Board resource.
	 * @param PanelRequest $request Current panel request.
	 * @return string Board search and filters HTML.
	 */
	private static function boardSearchForm(Resource $resource, PanelRequest $request): string {
		$query=trim((string)$request->query('q', ''));
		$sort=trim((string)$request->query('sort', ''));
		$dir=trim((string)$request->query('dir', ''));
		$hidden='';
		foreach(array_merge(self::tableRouteParams($resource, 'board'), ['sort'=>$sort, 'dir'=>$dir], self::activeFilterParams($resource, $request)) as $key=>$value){
			if($value!==''){
				$hidden.='<input type="hidden" name="'.self::e($key).'" value="'.self::e($value).'">';
			}
		}
		$action=self::e(PanelConfig::resourceUrl($resource, 'board'));
		return '<form class="dp-panel-search" method="get" action="'.$action.'">'
			.$hidden
			.'<input type="search" name="q" value="'.self::e($query).'" placeholder="'.self::e(self::panelText('table.search_board_placeholder')).'" aria-label="'.self::e(self::panelText('table.search_board_placeholder')).'" data-dp-panel-search-input>'
			.'<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('common.search')).'</button>'
			.($query!=='' ? '<a class="dp-panel-button dp-panel-button-secondary" href="'.$action.'">'.self::e(self::panelText('common.clear', [], 'Clear')).'</a>' : '')
			.'</form>'
			.self::boardFiltersHtml($resource, $request);
	}

	/**
	 * Renders board filter controls inside a modal-launcher template.
	 *
	 * only visible filters render controls, active filter chips point back to board URLs, and query/sort
	 * state is preserved through hidden inputs.
	 *
	 * @param Resource $resource Board resource.
	 * @param PanelRequest $request Current panel request.
	 * @return string Board filter launcher HTML or empty string.
	 */
	private static function boardFiltersHtml(Resource $resource, PanelRequest $request): string {
		$filters=$resource->resourceTable()->filtersList();
		if($filters===[]){
			return '';
		}
		$params=array_filter(self::tableRouteParams($resource, 'board')+[
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
		], static fn(mixed $value): bool => (string)$value!=='');
		$hidden='';
		foreach($params as $key=>$value){
			$hidden.='<input type="hidden" name="'.self::e((string)$key).'" value="'.self::e((string)$value).'">';
		}
		$controls='';
		foreach($filters as $filter){
			if($filter instanceof TableFilter && $filter->isVisible($request, $resource, $resource->resourceTable())){
				$controls.=self::filterControl($filter, $request);
			}
		}
		if($controls===''){
			return '';
		}
		$activeChips=self::boardActiveFilterChipsHtml($resource, $request);
		$activeCount=count(self::activeFilterIndicators($resource, $request));
		$body='<form class="dp-panel-filters" method="get" action="'.self::e(PanelConfig::resourceUrl($resource, 'board')).'">'
			.$hidden
			.$controls
			.'<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('client.filter')).'</button>'
			.'<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(self::boardFilterResetUrl($resource, $request)).'">'.self::e(self::panelText('common.reset', [], 'Reset')).'</a>'
			.'</form>'
			.$activeChips;
		return self::filterModalLauncher($resource, $activeCount, self::panelText('table.board_filters'), self::panelText('table.board_filters_description'), $body);
	}

	/**
	 * Renders table filter controls inside a modal-launcher template.
	 *
	 * visible filters become controls, active chips preserve clear/reset behavior, and table state is
	 * preserved through hidden inputs so filtering does not discard sort, view, group, columns, or density.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @return string Filter launcher HTML or empty string.
	 */
	private static function filtersHtml(Resource $resource, PanelRequest $request): string {
		$filters=$resource->resourceTable()->filtersList();
		if($filters===[]){
			return '';
		}
		$params=self::tableRouteParams($resource)+[
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
			'per_page'=>(string)$request->perPage($resource->resourceTable()->defaultPerPage()),
		]+self::activeViewParams($resource, $request)+self::activeGroupParams($resource, $request)+self::activeColumnParams($request)+self::activeDensityParams($request);
		$params=array_filter($params, static fn(mixed $value): bool => (string)$value!=='');
		$hidden='';
		foreach($params as $key=>$value){
			$hidden.='<input type="hidden" name="'.self::e((string)$key).'" value="'.self::e((string)$value).'">';
		}
		$controls='';
		foreach($filters as $filter){
			if($filter instanceof TableFilter && $filter->isVisible($request, $resource, $resource->resourceTable())){
				$controls.=self::filterControl($filter, $request);
			}
		}
		if($controls===''){
			return '';
		}
		$activeChips=self::activeFilterChipsHtml($resource, $request);
		$resetUrl=self::filterResetUrl($resource, $request);
		$activeCount=count(self::activeFilterIndicators($resource, $request));
		$body='<form class="dp-panel-filters" method="get" action="'.self::e(PanelConfig::resourceUrl($resource)).'">'
			.$hidden
			.$controls
			.'<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('client.filter')).'</button>'
			.'<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e($resetUrl).'">'.self::e(self::panelText('common.reset', [], 'Reset')).'</a>'
			.'</form>'
			.$activeChips;
		return self::filterModalLauncher($resource, $activeCount, self::panelText('table.filters'), self::panelText('table.filters_description', [], 'Narrow this table without losing your place.'), $body);
	}

	/**
	 * Wraps filter form HTML in a template-backed modal launcher.
	 *
	 * the template id is deterministic for the resource and heading, active count is surfaced on the
	 * trigger, and heading/description/body stay within the filter modal boundary.
	 *
	 * @param Resource $resource Resource owning the filters.
	 * @param int $activeCount Number of active filters.
	 * @param string $heading Modal heading.
	 * @param string $description Modal description.
	 * @param string $body Trusted filter form HTML.
	 * @return string Filter launcher HTML.
	 */
	private static function filterModalLauncher(Resource $resource, int $activeCount, string $heading, string $description, string $body): string {
		$id='dp-panel-filter-template-'.substr(sha1($resource->name().'|'.$heading), 0, 10);
		return '<div class="dp-panel-filter-panel dp-panel-filter-modal-panel">'
			.'<button class="dp-panel-filter-trigger" type="button" data-dp-panel-filter-modal data-dp-panel-filter-template="'.self::e($id).'" data-dp-panel-filter-heading="'.self::e($heading).'" data-dp-panel-filter-description="'.self::e($description).'"><span>'.self::e(self::panelText('table.filters')).'</span><strong>'.self::e((string)$activeCount).'</strong></button>'
			.'<template id="'.self::e($id).'"><section class="dp-panel-filter-modal-content">'.$body.'</section></template>'
			.'</div>';
	}

	/**
	 * Renders the form control for one table filter.
	 *
	 * range, select/enum, boolean, date, and text controls are generated from filter metadata and active
	 * values; options come from the filter and all labels, names, placeholders, and values are escaped.
	 *
	 * @param TableFilter $filter Filter definition.
	 * @param PanelRequest $request Current panel request.
	 * @param string $namePrefix Optional control name prefix.
	 * @return string Filter control HTML.
	 */
	private static function filterControl(TableFilter $filter, PanelRequest $request, string $namePrefix=''): string {
		$meta=$filter->toArray();
		$meta['options']=$filter->optionsFor($request);
		$name=(string)$meta['name'];
		$inputName=$namePrefix.$name;
		$type=(string)($meta['type'] ?? 'text');
		$value=$filter->activeValue($request, is_array($meta['options'] ?? null) ? $meta['options'] : []);
		$label='<span>'.self::e((string)$meta['label']).'</span>';
		if(($meta['range'] ?? false)===true){
			$inputType=match($type){
				'date_range'=>'date',
				'number_range', 'numeric_range', 'money_range'=>'number',
				default=>'text',
			};
			$from=is_array($value) ? ($value['from'] ?? '') : '';
			$to=is_array($value) ? ($value['to'] ?? '') : '';
			return '<label class="dp-panel-filter dp-panel-filter-range">'.$label.'<span class="dp-panel-range-controls">'
				.'<input type="'.$inputType.'" name="'.self::e($inputName.'_from').'" value="'.self::e(self::stringValue($from)).'" placeholder="'.self::e(self::panelText('table.filter_from')).'">'
				.'<input type="'.$inputType.'" name="'.self::e($inputName.'_to').'" value="'.self::e(self::stringValue($to)).'" placeholder="'.self::e(self::panelText('table.filter_to')).'">'
				.'</span></label>';
		}
		if(in_array($type, ['select', 'enum'], true) || ($meta['options'] ?? [])!==[]){
			return '<label class="dp-panel-filter">'.$label.'<select name="'.self::e($inputName).'"><option value="">'.self::e(self::panelText('common.any')).'</option>'.self::optionHtml(is_array($meta['options'] ?? null) ? $meta['options'] : [], $value).'</select></label>';
		}
		if(in_array($type, ['boolean', 'bool', 'checkbox', 'toggle'], true)){
			return '<label class="dp-panel-filter">'.$label.'<select name="'.self::e($inputName).'">'
				.'<option value="">'.self::e(self::panelText('common.any')).'</option>'
				.'<option value="1"'.(self::truthy($value) && $value!==null ? ' selected' : '').'>'.self::e(self::panelText('common.yes')).'</option>'
				.'<option value="0"'.($value!==null && !self::truthy($value) ? ' selected' : '').'>'.self::e(self::panelText('common.no')).'</option>'
				.'</select></label>';
		}
		$inputType=$type==='date' ? 'date' : 'text';
		return '<label class="dp-panel-filter">'.$label.'<input type="'.$inputType.'" name="'.self::e($inputName).'" value="'.self::e(self::stringValue($value)).'"></label>';
	}

	/**
	 * Builds query parameters representing active visible filters.
	 *
	 * invisible filters are ignored, range filters are serialized as from/to keys, scalar filters use
	 * their filter name, and null active values are omitted.
	 *
	 * @param Resource $resource Resource owning filters.
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, string> Active filter query parameters.
	 */
	private static function activeFilterParams(Resource $resource, PanelRequest $request): array {
		$params=[];
		foreach($resource->resourceTable()->filtersList() as $filter){
			if(!$filter instanceof TableFilter){
				continue;
			}
			if(!$filter->isVisible($request, $resource, $resource->resourceTable())){
				continue;
			}
			$value=$filter->activeValue($request);
			if($value!==null){
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
		return $params;
	}

	/**
	 * Renders clearable active filter chips for table index URLs.
	 *
	 * indicator clear keys are honored when provided, tones are allow-listed, and a reset chip is added
	 * when at least one active filter exists.
	 *
	 * @param Resource $resource Resource owning filters.
	 * @param PanelRequest $request Current panel request.
	 * @return string Active filter chips HTML or empty string.
	 */
	private static function activeFilterChipsHtml(Resource $resource, PanelRequest $request): string {
		$chips='';
		$indicators=self::activeFilterIndicators($resource, $request);
		foreach($indicators as $indicator){
			$tone=self::safeTone((string)($indicator['tone'] ?? 'neutral'));
			$clear=is_array($indicator['clear'] ?? null) ? $indicator['clear'] : [(string)($indicator['filter'] ?? '')];
			$chips.='<a class="dp-panel-filter-chip dp-panel-filter-chip-'.$tone.'" href="'.self::e(self::filterClearUrl($resource, $request, (string)($indicator['filter'] ?? ''), $clear)).'">'
				.'<span>'.self::e((string)($indicator['label'] ?? self::panelText('client.filter'))).'</span>'
				.'<strong>'.self::e((string)($indicator['value'] ?? '')).'</strong>'
				.'<small>'.self::e(self::panelText('common.clear')).'</small>'
				.'</a>';
		}
		if($chips===''){
			return '';
		}
		$reset='<a class="dp-panel-filter-chip dp-panel-filter-chip-reset" href="'.self::e(self::filterResetUrl($resource, $request)).'"><span>'.self::e(self::panelText('table.filters')).'</span><strong>'.self::e(self::panelText('common.clear_all')).'</strong><small>'.self::e(self::panelText('common.reset')).'</small></a>';
		return '<div class="dp-panel-filter-chips" aria-label="'.self::e(self::panelText('filter.active_filters')).'">'.$chips.$reset.'</div>';
	}

	/**
	 * Renders clearable active filter chips for board URLs.
	 *
	 * mirrors table filter chips but builds clear/reset URLs against the board operation so board state
	 * is not accidentally routed back to the index table.
	 *
	 * @param Resource $resource Resource owning filters.
	 * @param PanelRequest $request Current panel request.
	 * @return string Board active filter chips HTML or empty string.
	 */
	private static function boardActiveFilterChipsHtml(Resource $resource, PanelRequest $request): string {
		$chips='';
		foreach(self::activeFilterIndicators($resource, $request) as $indicator){
			$tone=self::safeTone((string)($indicator['tone'] ?? 'neutral'));
			$clear=is_array($indicator['clear'] ?? null) ? $indicator['clear'] : [(string)($indicator['filter'] ?? '')];
			$chips.='<a class="dp-panel-filter-chip dp-panel-filter-chip-'.$tone.'" href="'.self::e(self::boardFilterClearUrl($resource, $request, (string)($indicator['filter'] ?? ''), $clear)).'">'
				.'<span>'.self::e((string)($indicator['label'] ?? self::panelText('client.filter'))).'</span>'
				.'<strong>'.self::e((string)($indicator['value'] ?? '')).'</strong>'
				.'<small>'.self::e(self::panelText('common.clear')).'</small>'
				.'</a>';
		}
		if($chips===''){
			return '';
		}
		$reset='<a class="dp-panel-filter-chip dp-panel-filter-chip-reset" href="'.self::e(self::boardFilterResetUrl($resource, $request)).'"><span>'.self::e(self::panelText('table.filters')).'</span><strong>'.self::e(self::panelText('common.clear_all')).'</strong><small>'.self::e(self::panelText('common.reset')).'</small></a>';
		return '<div class="dp-panel-filter-chips" aria-label="'.self::e(self::panelText('filter.active_filters')).'">'.$chips.$reset.'</div>';
	}

	/**
	 * Collects visible filter indicator payloads for chip rendering.
	 *
	 * only visible TableFilter instances contribute indicators, and only array-shaped indicators are
	 * returned to the renderer.
	 *
	 * @param Resource $resource Resource owning filters.
	 * @param PanelRequest $request Current panel request.
	 * @return array<int, array<string, mixed>> Active filter indicators.
	 */
	private static function activeFilterIndicators(Resource $resource, PanelRequest $request): array {
		$indicators=[];
		foreach($resource->resourceTable()->filtersList() as $filter){
			if(!$filter instanceof TableFilter){
				continue;
			}
			if(!$filter->isVisible($request, $resource, $resource->resourceTable())){
				continue;
			}
			foreach($filter->indicators($request) as $indicator){
				if(is_array($indicator)){
					$indicators[]=$indicator;
				}
			}
		}
		return $indicators;
	}

	/**
	 * Converts a filter value into a human-readable label.
	 *
	 * ranges render from/to text, booleans render yes/no labels, option filters prefer option labels,
	 * and all other values use the shared string conversion helper.
	 *
	 * @param TableFilter $filter Filter definition, reserved for future value formatting.
	 * @param array<string, mixed> $meta Filter metadata.
	 * @param mixed $value Active filter value.
	 * @return string Display label.
	 */
	private static function filterValueLabel(TableFilter $filter, array $meta, mixed $value): string {
		$type=(string)($meta['type'] ?? 'text');
		if(is_array($value)){
			$from=self::stringValue($value['from'] ?? '');
			$to=self::stringValue($value['to'] ?? '');
			if($from!=='' && $to!==''){
				return $from.' to '.$to;
			}
			return $from!=='' ? 'from '.$from : 'to '.$to;
		}
		if(in_array($type, ['boolean', 'bool', 'checkbox', 'toggle'], true)){
			return self::truthy($value) ? 'Yes' : 'No';
		}
		$options=is_array($meta['options'] ?? null) ? $meta['options'] : [];
		if($options!==[]){
			return self::optionLabel($options, (string)$value) ?? self::stringValue($value);
		}
		return self::stringValue($value);
	}

	/**
	 * Builds the base table query parameters used by filter reset and clear links.
	 *
	 * search, sort, direction, per-page, view, group, visible columns, and density are preserved while
	 * active filter parameters are intentionally excluded.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, mixed> Filter base query parameters.
	 */
	private static function filterBaseParams(Resource $resource, PanelRequest $request): array {
		$params=[
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
			'per_page'=>(string)$request->perPage($resource->resourceTable()->defaultPerPage()),
		]+self::activeViewParams($resource, $request)+self::activeGroupParams($resource, $request)+self::activeColumnParams($request)+self::activeDensityParams($request);
		return array_filter($params, static fn(mixed $value): bool => (string)$value!=='');
	}

	/**
	 * Builds the table URL that clears all active filters while preserving other table state.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @return string Reset URL.
	 */
	private static function filterResetUrl(Resource $resource, PanelRequest $request): string {
		$params=self::filterBaseParams($resource, $request);
		return PanelConfig::resourceUrl($resource, '', $params);
	}

	/**
	 * Builds the table URL that clears one filter or explicit filter keys.
	 *
	 * current filter params are merged into the base state, requested clear keys are removed, and page
	 * is dropped so the cleared result starts from the first page.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @param string $filterName Filter name used for default clear keys.
	 * @param array<int, string> $clearKeys Explicit query keys to remove.
	 * @return string Clear-filter URL.
	 */
	private static function filterClearUrl(Resource $resource, PanelRequest $request, string $filterName, array $clearKeys=[]): string {
		$params=self::filterBaseParams($resource, $request)+self::activeFilterParams($resource, $request);
		$clearKeys=$clearKeys!==[] ? $clearKeys : [$filterName, $filterName.'_from', $filterName.'_to'];
		foreach($clearKeys as $key){
			unset($params[$key]);
		}
		unset($params['page']);
		return PanelConfig::resourceUrl($resource, '', $params);
	}

	/**
	 * Builds board query parameters preserved by board filter reset and clear links.
	 *
	 * board filter URLs preserve search, sort, and direction only; table-only state such as visible
	 * columns, density, and per-page is intentionally not carried to the board.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, string> Board filter base parameters.
	 */
	private static function boardFilterBaseParams(PanelRequest $request): array {
		return array_filter([
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
		], static fn(mixed $value): bool => (string)$value!=='');
	}

	/**
	 * Builds the board URL that clears all active filters.
	 *
	 * @param Resource $resource Board resource.
	 * @param PanelRequest $request Current panel request.
	 * @return string Board reset URL.
	 */
	private static function boardFilterResetUrl(Resource $resource, PanelRequest $request): string {
		$params=self::boardFilterBaseParams($request);
		return PanelConfig::resourceUrl($resource, 'board', $params);
	}

	/**
	 * Builds the board URL that clears one filter or explicit filter keys.
	 *
	 * current active filter parameters are merged into board base state, clear keys are removed, and any
	 * page parameter is discarded.
	 *
	 * @param Resource $resource Board resource.
	 * @param PanelRequest $request Current panel request.
	 * @param string $filterName Filter name used for default clear keys.
	 * @param array<int, string> $clearKeys Explicit query keys to remove.
	 * @return string Board clear-filter URL.
	 */
	private static function boardFilterClearUrl(Resource $resource, PanelRequest $request, string $filterName, array $clearKeys=[]): string {
		$params=self::boardFilterBaseParams($request)+self::activeFilterParams($resource, $request);
		$clearKeys=$clearKeys!==[] ? $clearKeys : [$filterName, $filterName.'_from', $filterName.'_to'];
		foreach($clearKeys as $key){
			unset($params[$key]);
		}
		unset($params['page']);
		return PanelConfig::resourceUrl($resource, 'board', $params);
	}

	/**
	 * Builds query parameters that preserve the active table view.
	 *
	 * an explicit view=all query is preserved, otherwise only non-empty active resource view names are
	 * emitted.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, string> Active view query parameters.
	 */
	private static function activeViewParams(Resource $resource, PanelRequest $request): array {
		if(Resource::normalizeName((string)$request->query('view', ''))==='all'){
			return ['view'=>'all'];
		}
		$view=self::activeTableViewName($resource, $request);
		return $view!=='' ? ['view'=>$view] : [];
	}

	/**
	 * Builds query parameters that preserve visible-column preferences.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, string> Visible-column query parameter or empty array.
	 */
	private static function activeColumnParams(PanelRequest $request): array {
		$columns=self::requestedColumns($request);
		return $columns!==[] ? ['visible_columns'=>implode(',', $columns)] : [];
	}

	/**
	 * Builds query parameters that preserve the active table group.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, string> Active group query parameter or empty array.
	 */
	private static function activeGroupParams(Resource $resource, PanelRequest $request): array {
		$group=$resource->activeTableGroupName($request);
		return $group!=='' ? ['group'=>$group] : [];
	}

	/**
	 * Builds query parameters that preserve non-default table density.
	 *
	 * density params are disabled when density controls are globally disabled and normal density is
	 * treated as the URL-clean default.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, string> Active density query parameter or empty array.
	 */
	private static function activeDensityParams(PanelRequest $request): array {
		if(!PanelConfig::tableDensityControlsEnabled()){
			return [];
		}
		$density=self::density($request);
		return $density!=='normal' ? ['density'=>$density] : [];
	}

	/**
	 * Resolves table density from query state, stored preferences, or defaults.
	 *
	 * reset requests clear preferences, query density persists to session preferences, and returned
	 * values are constrained to compact, normal, or comfortable.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return string Density token.
	 */
	private static function density(PanelRequest $request): string {
		if(!PanelConfig::tableDensityControlsEnabled()){
			return 'normal';
		}
		if(self::resettingTablePreferences($request)){
			self::clearTablePreferences($request);
			return 'normal';
		}
		$query=$request->query();
		$hasDensity=is_array($query) && array_key_exists('density', $query);
		$density=Resource::normalizeName((string)($hasDensity ? $query['density'] : (self::tablePreferences($request)['density'] ?? 'normal')));
		$density=in_array($density, ['compact', 'normal', 'comfortable'], true) ? $density : 'normal';
		if($hasDensity){
			self::saveTablePreferences($request, ['density'=>$density]);
		}
		return $density;
	}

	/**
	 * Resolves requested visible columns from query state or stored preferences.
	 *
	 * reset requests clear preferences, comma strings become arrays, column names are normalized and
	 * deduplicated, and submitted/query changes persist back to session preferences.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return array<int, string> Requested column names.
	 */
	private static function requestedColumns(PanelRequest $request): array {
		if(self::resettingTablePreferences($request)){
			self::clearTablePreferences($request);
			return [];
		}
		$query=$request->query();
		$hasColumns=is_array($query) && array_key_exists('visible_columns', $query);
		$submitted=is_array($query) && array_key_exists('table_prefs', $query);
		$value=$hasColumns ? $query['visible_columns'] : (self::tablePreferences($request)['visible_columns'] ?? []);
		if(is_string($value)){
			$value=explode(',', $value);
		}
		if(!is_array($value)){
			return [];
		}
		$columns=array_values(array_unique(array_filter(array_map(
			static fn(mixed $column): string => Resource::normalizeName((string)$column),
			$value
		))));
		if($hasColumns || $submitted){
			self::saveTablePreferences($request, ['visible_columns'=>$columns]);
		}
		return $columns;
	}

	/**
	 * Renders the visible-column preference picker.
	 *
	 * only currently visible and toggleable columns are listed, current preferences fall back to
	 * visible-by-default columns, submitted preferences preserve table state, and the reset link clears persisted table
	 * view preferences.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, Column> $columns Candidate columns.
	 * @param bool $compact Whether compact picker labels should be used.
	 * @return string Column picker HTML or empty string.
	 */
	private static function columnVisibilityHtml(Resource $resource, PanelRequest $request, array $columns, bool $compact=false): string {
		$toggleable=array_filter($columns, static fn(Column $column): bool => $column->isVisible($request->operation(), null, $request, $resource, $resource->resourceTable()) && ($column->toArray()['toggleable'] ?? true)===true);
		if($toggleable===[]){
			return '';
		}
		$current=self::requestedColumns($request);
		if($current===[]){
			$current=array_map(
				static fn(Column $column): string => $column->name(),
				array_filter($toggleable, static fn(Column $column): bool => ($column->toArray()['visible_by_default'] ?? true)===true)
			);
		}
		$params=self::tableRouteParams($resource)+[
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
			'per_page'=>(string)$request->perPage($resource->resourceTable()->defaultPerPage()),
			'table_prefs'=>'1',
		]+self::activeViewParams($resource, $request)+self::activeGroupParams($resource, $request)+self::activeFilterParams($resource, $request)+self::activeDensityParams($request);
		$params=array_filter($params, static fn(mixed $value): bool => (string)$value!=='');
		$hidden='';
		foreach($params as $key=>$value){
			$hidden.='<input type="hidden" name="'.self::e((string)$key).'" value="'.self::e((string)$value).'">';
		}
		$controls='';
		foreach($toggleable as $column){
			$meta=$column->toArray();
			$checked=in_array($column->name(), $current, true) ? ' checked' : '';
			$controls.='<label data-dp-panel-column-option><input type="checkbox" name="visible_columns[]" value="'.self::e($column->name()).'"'.$checked.'> <span>'.self::e((string)$meta['label']).'</span></label>';
		}
		$selectedCount=count(array_intersect(array_map(static fn(Column $column): string => $column->name(), $toggleable), $current));
		$class=$compact ? 'dp-panel-column-picker dp-panel-column-picker-compact' : 'dp-panel-column-picker';
		$label=$compact ? self::panelText('table.columns_compact') : self::panelText('table.columns');
		return '<details class="'.$class.'" data-dp-panel-column-picker><summary>'.self::e($label).' <small data-dp-panel-column-count>'.number_format($selectedCount).'/'.number_format(count($toggleable)).'</small></summary>'
			.'<form method="get" action="'.self::e(PanelConfig::resourceUrl($resource)).'">'
			.$hidden
			.'<input class="dp-panel-column-search" type="search" placeholder="'.self::e(self::panelText('table.find_columns')).'" aria-label="'.self::e(self::panelText('table.find_columns')).'" data-dp-panel-column-search>'
			.'<div class="dp-panel-column-actions"><button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-columns-select="all">'.self::e(self::panelText('common.all')).'</button><button class="dp-panel-button dp-panel-button-secondary" type="button" data-dp-panel-columns-select="none">'.self::e(self::panelText('common.none')).'</button></div>'
			.'<div class="dp-panel-column-options" data-dp-panel-column-options>'.$controls.'</div>'
			.'<div class="dp-panel-column-footer"><button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('common.apply')).'</button>'
			.'<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::resourceUrl($resource, '', ['reset_table_view'=>'1'])).'">'.self::e(self::panelText('common.reset')).'</a></div>'
			.'</form></details>';
	}

	/**
	 * Renders the table density selector.
	 *
	 * density controls are suppressed when disabled globally, active density is resolved through the
	 * preference system, and density links preserve the current table state while marking table_prefs for persistence.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @return string Density navigation HTML or empty string.
	 */
	private static function densityHtml(Resource $resource, PanelRequest $request): string {
		if(!PanelConfig::tableDensityControlsEnabled()){
			return '';
		}
		$current=self::density($request);
		$params=self::tableRouteParams($resource)+[
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
			'per_page'=>(string)$request->perPage($resource->resourceTable()->defaultPerPage()),
		]+self::activeViewParams($resource, $request)+self::activeGroupParams($resource, $request)+self::activeFilterParams($resource, $request)+self::activeColumnParams($request);
		$params=array_filter($params, static fn(mixed $value): bool => (string)$value!=='');
		$html='<nav class="dp-panel-density" aria-label="'.self::e(self::panelText('table.density')).'">';
		foreach(['compact'=>self::panelText('table.density_compact'), 'normal'=>self::panelText('table.density_normal'), 'comfortable'=>self::panelText('table.density_comfortable')] as $density=>$label){
			$params['density']=$density;
			$params['table_prefs']='1';
			$class=$density===$current ? ' class="active"' : '';
			$html.='<a'.$class.' href="'.self::e(PanelConfig::resourceUrl($resource, '', $params)).'">'.self::e($label).'</a>';
		}
		return $html.'</nav>';
	}

	/**
	 * Renders the rows-per-page selector.
	 *
	 * current per-page value is included even when outside the configured options, options are clamped
	 * to the renderer maximum, and state except page is preserved through hidden inputs.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @param bool $compact Whether to omit the explicit apply button.
	 * @return string Per-page form HTML.
	 */
	private static function perPageHtml(Resource $resource, PanelRequest $request, bool $compact=false): string {
		$current=$request->perPage($resource->resourceTable()->defaultPerPage());
		$options=$resource->resourceTable()->perPageOptionsList();
		if(!in_array($current, $options, true)){
			$options[]=$current;
			sort($options, SORT_NUMERIC);
		}
		$params=self::tableRouteParams($resource)+[
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
		]+self::activeViewParams($resource, $request)+self::activeGroupParams($resource, $request)+self::activeFilterParams($resource, $request)+self::activeColumnParams($request)+self::activeDensityParams($request);
		$params=array_filter($params, static fn(mixed $value): bool => (string)$value!=='');
		$hidden='';
		foreach($params as $key=>$value){
			$hidden.='<input type="hidden" name="'.self::e((string)$key).'" value="'.self::e((string)$value).'">';
		}
		$choices='';
		foreach($options as $option){
			$option=max(1, min(250, (int)$option));
			$choices.='<option value="'.$option.'"'.($option===$current ? ' selected' : '').'>'.$option.'</option>';
		}
		$class=$compact ? 'dp-panel-per-page dp-panel-per-page-compact' : 'dp-panel-per-page';
		return '<form class="'.$class.'" method="get" action="'.self::e(PanelConfig::resourceUrl($resource)).'">'
			.$hidden
			.'<label><span>'.self::e(self::panelText('table.rows')).'</span><select name="per_page" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">'.$choices.'</select></label>'
			.($compact ? '' : '<button class="dp-panel-button dp-panel-button-secondary" type="submit">'.self::e(self::panelText('common.apply')).'</button>')
			.'</form>';
	}

	/**
	 * Renders CSV and JSON export links for the current table slice.
	 *
	 * exports require global export enablement and resource export permission, and preserve search,
	 * sort, pagination, view, group, filter, visible-column, and density state.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @return string Export link HTML or empty string.
	 */
	private static function exportButtonHtml(Resource $resource, PanelRequest $request): string {
		if(!PanelConfig::resourceExportsEnabled() || $resource->can('export', null, $request->user())===false){
			return '';
		}
		$params=[
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
			'per_page'=>(string)$request->perPage($resource->resourceTable()->defaultPerPage()),
		]+self::activeViewParams($resource, $request)+self::activeGroupParams($resource, $request)+self::activeFilterParams($resource, $request)+self::activeColumnParams($request)+self::activeDensityParams($request);
		$params=array_filter($params, static fn(mixed $value): bool => (string)$value!=='');
		$csvQuery=$params!==[] ? '?'.http_build_query($params) : '';
		$jsonParams=array_replace($params, ['format'=>'json']);
		$jsonQuery='?'.http_build_query($jsonParams);
		return '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::resourceUrl($resource, 'export', $params)).'">'.self::e(self::panelText('table.export_csv')).'</a>'
			.'<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::resourceUrl($resource, 'export', $jsonParams)).'">'.self::e(self::panelText('table.export_json')).'</a>';
	}

	/**
	 * Renders the CSV import action link when imports are available.
	 *
	 * imports require global enablement, resource import support, and permission. The link preserves
	 * current non-page query state and carries resource modal attributes for the import workflow.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @return string Import link HTML or empty string.
	 */
	private static function importButtonHtml(Resource $resource, PanelRequest $request): string {
		if(!PanelConfig::resourceImportsEnabled() || $resource->canImport()===false || $resource->can('import', null, $request->user())===false){
			return '';
		}
		$query=$request->query();
		unset($query['page']);
		$query=self::filterQueryValues($query);
		$url=PanelConfig::resourceUrl($resource, 'import', $query);
		return '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e($url).'"'.self::resourceModalAttributes('import', self::panelText('table.import_title', ['resource'=>$resource->label()]), self::panelText('table.import_description'), 'xl', 'slide_over', true, self::panelText('import.csv_submit')).'>'.self::e(self::panelText('import.csv_submit')).'</a>';
	}

	/**
	 * Renders pagination controls and record range text.
	 *
	 * visibility follows configured pagination policy, current page is clamped to total pages, and
	 * pagination links preserve active table state except for the page number they intentionally change.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @param int $totalRecords Total records in the current table slice.
	 * @param int $page Current page.
	 * @param int $perPage Current page size.
	 * @param int|null $totalPages Optional precomputed total pages.
	 * @return string Pagination HTML or empty string.
	 */
	private static function paginationHtml(Resource $resource, PanelRequest $request, int $totalRecords, int $page, int $perPage, ?int $totalPages=null): string {
		$totalPages ??=max(1, (int)ceil($totalRecords / max(1, $perPage)));
		$visibility=PanelConfig::tablePaginationVisibility();
		if($visibility==='hide_empty' && $totalRecords===0){
			return '';
		}
		if($visibility==='hide_single' && $totalPages<=1){
			return '';
		}
		if($visibility==='hide_empty_or_single' && ($totalRecords===0 || $totalPages<=1)){
			return '';
		}
		$page=max(1, min($page, $totalPages));
		$start=$totalRecords===0 ? 0 : (($page-1)*$perPage)+1;
		$end=min($totalRecords, $page*$perPage);
		$params=[
			'q'=>trim((string)$request->query('q', '')),
			'sort'=>trim((string)$request->query('sort', '')),
			'dir'=>trim((string)$request->query('dir', '')),
			'per_page'=>$perPage,
		]+self::activeViewParams($resource, $request)+self::activeGroupParams($resource, $request)+self::activeFilterParams($resource, $request)+self::activeColumnParams($request)+self::activeDensityParams($request);
		$params=array_filter($params, static fn(mixed $value): bool => (string)$value!=='');
		$previous=$page>1
			? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::pageUrl($resource, $params, $page-1).'">'.self::e(self::panelText('table.previous')).'</a>'
			: '<span class="dp-panel-page-disabled">'.self::e(self::panelText('table.previous')).'</span>';
		$next=$page<$totalPages
			? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::pageUrl($resource, $params, $page+1).'">'.self::e(self::panelText('table.next')).'</a>'
			: '<span class="dp-panel-page-disabled">'.self::e(self::panelText('table.next')).'</span>';
		return '<nav class="dp-panel-pagination">'
			.'<span>'.self::e(self::panelText('data.showing_records', ['start'=>$start, 'end'=>$end, 'total'=>$totalRecords])).'</span>'
			.'<div>'.$previous.self::paginationWindowHtml($resource, $params, $page, $totalPages).$next.'</div>'
			.'</nav>';
	}

	/**
	 * Renders the compact pagination number window around the current page.
	 *
	 * always includes first, last, current, and nearby pages, inserts visual gaps for skipped ranges,
	 * and marks the current page with aria-current.
	 *
	 * @param Resource $resource Table resource.
	 * @param array<string, mixed> $params Preserved query parameters.
	 * @param int $page Current page.
	 * @param int $totalPages Total pages.
	 * @return string Pagination window HTML.
	 */
	private static function paginationWindowHtml(Resource $resource, array $params, int $page, int $totalPages): string {
		if($totalPages<=1){
			return '<span class="dp-panel-page-current">'.self::e(self::panelText('data.page_count', ['page'=>1, 'pages'=>1])).'</span>';
		}
		$window=[];
		foreach([1, $totalPages, $page-2, $page-1, $page, $page+1, $page+2] as $candidate){
			if($candidate>=1 && $candidate<=$totalPages){
				$window[$candidate]=true;
			}
		}
		ksort($window);
		$html='';
		$previousPage=0;
		foreach(array_keys($window) as $candidate){
			if($previousPage>0 && $candidate>$previousPage+1){
				$html.='<span class="dp-panel-page-gap" aria-hidden="true">...</span>';
			}
			if($candidate===$page){
				$html.='<span class="dp-panel-page-current" aria-current="page">'.self::e((string)$candidate).'</span>';
			}
			else {
				$html.='<a class="dp-panel-page-link" href="'.self::pageUrl($resource, $params, $candidate).'">'.self::e((string)$candidate).'</a>';
			}
			$previousPage=$candidate;
		}
		return '<span class="dp-panel-page-window" aria-label="'.self::e(self::panelText('table.pages')).'">'.$html.'</span>';
	}

	/**
	 * Builds and escapes a table page URL.
	 *
	 * @param Resource $resource Table resource.
	 * @param array<string, mixed> $params Query parameters to preserve.
	 * @param int $page Target page.
	 * @return string Escaped page URL.
	 */
	private static function pageUrl(Resource $resource, array $params, int $page): string {
		$params['page']=$page;
		return self::e(PanelConfig::resourceUrl($resource, '', $params));
	}

	/**
	 * Renders the appropriate empty state for a table.
	 *
	 * resource-provided empty state metadata wins, active table state receives reset affordances, and
	 * truly empty resources show a create action only when create permission allows it.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @return string Empty state HTML.
	 */
	private static function emptyStateHtml(Resource $resource, PanelRequest $request): string {
		$hasState=self::hasActiveTableState($resource, $request);
		$state=$resource->resourceTable()->resolveEmptyState($request, $hasState, $resource);
		if(trim((string)($state['heading'] ?? ''))!=='' || trim((string)($state['description'] ?? ''))!=='' || trim((string)($state['action_label'] ?? ''))!==''){
			return self::tableEmptyStateHtml($state, $hasState ? self::filterResetUrl($resource, $request) : null);
		}
		if($hasState){
			$reset=self::e(PanelConfig::resourceUrl($resource, '', $resource->tableViewsList()!==[] ? ['view'=>'all'] : []));
			return '<div class="dp-panel-empty-state"><strong>'.self::e(self::panelText('table.empty_filtered_title')).'</strong><span>'.self::e(self::panelText('table.empty_filtered_body')).'</span><a class="dp-panel-button dp-panel-button-secondary" href="'.$reset.'">'.self::e(self::panelText('table.reset_view')).'</a></div>';
		}
		$create=$resource->can('create', null, $request->user())!==false ? '<a class="dp-panel-button" href="'.self::e(PanelConfig::resourceUrl($resource, 'create')).'"'.self::resourceModalAttributes('create', self::panelText('table.create_resource_title', ['resource'=>$resource->label()]), self::panelText('table.create_resource_body'), 'xl', 'slide_over', true).'>'.self::e(self::panelText('table.create')).'</a>' : '';
		return '<div class="dp-panel-empty-state"><strong>'.self::e(self::panelText('table.empty_ready_title')).'</strong><span>'.self::e($create!=='' ? self::panelText('table.empty_create_first') : self::panelText('table.empty_records_available')).'</span>'.$create.'</div>';
	}

	/**
	 * Renders an empty state payload supplied by a resource table.
	 *
	 * fallback URLs are used only when the state includes an action label without an action URL, icon
	 * text is compacted, and all visible content is escaped.
	 *
	 * @param array<string, mixed> $state Empty state metadata.
	 * @param string|null $fallbackUrl Optional action URL fallback.
	 * @return string Empty state HTML.
	 */
	private static function tableEmptyStateHtml(array $state, ?string $fallbackUrl=null): string {
		$heading=trim((string)($state['heading'] ?? ''));
		$description=trim((string)($state['description'] ?? ''));
		$icon=trim((string)($state['icon'] ?? ''));
		$actionLabel=trim((string)($state['action_label'] ?? ''));
		$actionUrl=trim((string)($state['action_url'] ?? ''));
		if($actionUrl==='' && $fallbackUrl!==null && $actionLabel!==''){
			$actionUrl=$fallbackUrl;
		}
		$action=$actionLabel!=='' && $actionUrl!=='' ? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e($actionUrl).'">'.self::e($actionLabel).'</a>' : '';
		$iconHtml=$icon!=='' ? '<i class="dp-panel-empty-icon" aria-hidden="true">'.self::e(self::compactNavIcon($icon, $heading)).'</i>' : '';
		return '<div class="dp-panel-empty-state">'.$iconHtml.'<strong>'.self::e($heading!=='' ? $heading : 'Nothing to show yet.').'</strong>'.($description!=='' ? '<span>'.self::e($description).'</span>' : '').$action.'</div>';
	}

	/**
	 * Detects whether the current request has table state that narrows or customizes the default table.
	 *
	 * search, filters, saved views, groups, visible columns, and non-default density all count as
	 * active state for empty-state and reset decisions.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @return bool Whether table state is active.
	 */
	private static function hasActiveTableState(Resource $resource, PanelRequest $request): bool {
		if(trim((string)$request->query('q', ''))!==''){
			return true;
		}
		if(self::activeFilterParams($resource, $request)!==[]){
			return true;
		}
		if(self::activeViewParams($resource, $request)!==[]){
			return true;
		}
		if(self::activeGroupParams($resource, $request)!==[]){
			return true;
		}
		if(self::activeColumnParams($request)!==[]){
			return true;
		}
		return self::activeDensityParams($request)!==[];
	}

	/**
	 * Renders a sortable or static column heading.
	 *
	 * non-sortable headings render text only, sortable headings preserve current table state, toggle
	 * direction when already active, and expose tooltip text through title/aria-label when configured.
	 *
	 * @param Resource $resource Table resource.
	 * @param PanelRequest $request Current panel request.
	 * @param Column $column Column definition.
	 * @return string Column header HTML.
	 */
	private static function columnHeader(Resource $resource, PanelRequest $request, Column $column): string {
		$meta=$column->toArray();
		$tooltip=trim((string)($meta['meta']['tooltip'] ?? ''));
		$tooltipAttr=$tooltip!=='' ? ' title="'.self::e($tooltip).'" aria-label="'.self::e($tooltip).'"' : '';
		if(($meta['sortable'] ?? false)!==true){
			return '<span class="dp-panel-column-heading"'.$tooltipAttr.'>'.self::e((string)$meta['label']).'</span>';
		}
		[$currentSort, $currentDir]=self::sortState($resource, $request);
		$nextDir=$currentSort===$column->name() && $currentDir==='asc' ? 'desc' : 'asc';
		$params=[];
		if(trim((string)$request->query('q', ''))!==''){
			$params['q']=(string)$request->query('q');
		}
		$params+=self::activeViewParams($resource, $request);
		$params+=self::activeGroupParams($resource, $request);
		$params+=self::activeFilterParams($resource, $request);
		$params+=self::activeColumnParams($request);
		$params+=self::activeDensityParams($request);
		$params['per_page']=$request->perPage($resource->resourceTable()->defaultPerPage());
		$params['sort']=$column->name();
		$params['dir']=$nextDir;
		$indicator=$currentSort===$column->name() ? ($currentDir==='asc' ? ' asc' : ' desc') : '';
		return '<a class="dp-panel-sort'.$indicator.'" href="'.self::e(PanelConfig::resourceUrl($resource, '', $params)).'"'.$tooltipAttr.'>'.self::e((string)$meta['label']).'</a>';
	}

	/**
	 * Resolves and serializes custom column header attributes.
	 *
	 * attributes are produced by the Column API, then passed through the shared column class and
	 * attribute allow-lists before being added to header cells.
	 *
	 * @return string Header attribute HTML or empty string.
	 */
	private static function columnHeaderAttributeHtml(Column $column, ?PanelRequest $request=null, ?Resource $resource=null, mixed $table=null): string {
		$attributes=$column->resolveHeaderAttributes($request, $resource, $table);
		if($attributes===[]){
			return '';
		}
		return self::columnExtraClass($attributes).self::columnExtraAttributes($attributes);
	}

	/**
	 * Renders table header rows with optional grouped column headings.
	 *
	 * simple tables produce one row, grouped columns produce a group row and child header row, select
	 * and action columns are accounted for with rowspans, and column attributes flow through allow-listed helpers.
	 *
	 * @param array<int, mixed> $columns Visible columns.
	 * @param callable $headerRenderer Renderer for individual column labels.
	 * @param bool $hasSelect Whether a select-all column is present.
	 * @param bool $hasActions Whether an action column is present.
	 * @return string Header row HTML.
	 */
	private static function tableHeaderRowsHtml(array $columns, callable $headerRenderer, bool $hasSelect=false, bool $hasActions=true, ?PanelRequest $request=null, ?Resource $resource=null, mixed $table=null): string {
		$columns=array_values($columns);
		$hasGroups=false;
		foreach($columns as $column){
			if($column instanceof Column && trim((string)($column->toArray()['group'] ?? ''))!==''){
				$hasGroups=true;
				break;
			}
		}
		$single='';
		if($hasSelect){
			$single.='<th class="dp-panel-select"><input type="checkbox" data-dp-panel-select-all aria-label="'.self::e(self::panelText('table.select_all_visible')).'"></th>';
		}
		foreach($columns as $column){
			if(!$column instanceof Column){
				continue;
			}
			$meta=$column->toArray();
			$single.='<th'.self::alignAttr($meta).self::columnHeaderAttributeHtml($column, $request, $resource, $table).'>'.$headerRenderer($column).'</th>';
		}
		if($hasActions){
			$single.='<th class="dp-panel-actions">'.self::e(self::panelText('table.actions')).'</th>';
		}
		if(!$hasGroups){
			return '<tr>'.$single.'</tr>';
		}
		$top='';
		$bottom='';
		if($hasSelect){
			$top.='<th class="dp-panel-select" rowspan="2"><input type="checkbox" data-dp-panel-select-all aria-label="'.self::e(self::panelText('table.select_all_visible')).'"></th>';
		}
		$count=count($columns);
		for($index=0; $index<$count; $index++){
			$column=$columns[$index] ?? null;
			if(!$column instanceof Column){
				continue;
			}
			$meta=$column->toArray();
			$group=trim((string)($meta['group'] ?? ''));
			if($group===''){
				$top.='<th rowspan="2"'.self::alignAttr($meta).self::columnHeaderAttributeHtml($column, $request, $resource, $table).'>'.$headerRenderer($column).'</th>';
				continue;
			}
			$span=1;
			for($next=$index+1; $next<$count; $next++){
				$nextColumn=$columns[$next] ?? null;
				if(!$nextColumn instanceof Column || trim((string)($nextColumn->toArray()['group'] ?? ''))!==$group){
					break;
				}
				$span++;
			}
			$description=trim((string)($meta['group_description'] ?? ''));
			$top.='<th class="dp-panel-column-group" colspan="'.$span.'"'.($description!=='' ? ' title="'.self::e($description).'"' : '').'><span>'.self::e($group).'</span>'.($description!=='' ? '<small>'.self::e($description).'</small>' : '').'</th>';
			for($groupIndex=$index; $groupIndex<$index+$span; $groupIndex++){
				$groupColumn=$columns[$groupIndex];
				$groupMeta=$groupColumn->toArray();
				$bottom.='<th class="dp-panel-column-group-child"'.self::alignAttr($groupMeta).self::columnHeaderAttributeHtml($groupColumn, $request, $resource, $table).'>'.$headerRenderer($groupColumn).'</th>';
			}
			$index+=$span-1;
		}
		if($hasActions){
			$top.='<th class="dp-panel-column-group dp-panel-actions-group" aria-hidden="true"></th>';
			$bottom.='<th class="dp-panel-actions">'.self::e(self::panelText('table.actions')).'</th>';
		}
		return '<tr class="dp-panel-column-group-row">'.$top.'</tr><tr class="dp-panel-column-header-row">'.$bottom.'</tr>';
	}

	/**
	 * Renders table footer aggregate cells when any column provides footer content.
	 *
	 * footer values are resolved by each Column from the supplied records, empty footer rows are
	 * suppressed, select/action spacer cells preserve table geometry, and all labels/values are escaped.
	 *
	 * @param array<int, mixed> $columns Visible columns.
	 * @param array<int, mixed> $records Records represented by the table.
	 * @param bool $hasSelect Whether a select column is present.
	 * @param bool $hasActions Whether an action column is present.
	 * @return string Footer HTML or empty string.
	 */
	private static function tableFooterRowsHtml(array $columns, array $records, bool $hasSelect=false, bool $hasActions=true, ?PanelRequest $request=null, ?Resource $resource=null, mixed $table=null): string {
		$columns=array_values($columns);
		$footers=[];
		$hasFooter=false;
		foreach($columns as $column){
			if(!$column instanceof Column){
				$footers[]=['label'=>'', 'value'=>'', 'type'=>''];
				continue;
			}
			$footer=$column->resolveFooter($records, $request, $resource, $table);
			$value=trim(self::stringValue($footer['value'] ?? ''));
			$label=trim((string)($footer['label'] ?? ''));
			$type=trim((string)($footer['type'] ?? ''));
			if($value!=='' || $label!==''){
				$hasFooter=true;
			}
			$footers[]=[
				'label'=>$label,
				'value'=>$value,
				'type'=>$type,
			];
		}
		if(!$hasFooter){
			return '';
		}
		$html='<tfoot><tr class="dp-panel-column-footer-row">';
		if($hasSelect){
			$html.='<td class="dp-panel-select" data-label=""></td>';
		}
		foreach($columns as $index=>$column){
			if(!$column instanceof Column){
				continue;
			}
			$meta=$column->toArray();
			$footer=$footers[$index] ?? ['label'=>'', 'value'=>'', 'type'=>''];
			$label=(string)($footer['label'] ?? '');
			$value=(string)($footer['value'] ?? '');
			$type=(string)($footer['type'] ?? '');
			$empty=$label==='' && $value==='';
			$content=$empty
				? '<span aria-hidden="true">&nbsp;</span>'
				: '<div class="dp-panel-table-footer-cell'.($type!=='' ? ' dp-panel-table-footer-cell-'.$type : '').'">'.($label!=='' ? '<small>'.self::e($label).'</small>' : '').'<strong>'.self::e($value).'</strong></div>';
			$html.='<td'.self::alignAttr($meta).' data-label="'.self::e((string)($meta['label'] ?? $column->name())).'">'.$content.'</td>';
		}
		if($hasActions){
			$html.='<td class="dp-panel-actions" data-label=""></td>';
		}
		return $html.'</tr></tfoot>';
	}

	/**
	 * Resolves and serializes custom column cell attributes for one record value.
	 *
	 * value and formatted value are computed once for the Column attribute resolver, and resulting
	 * attributes pass through shared column class and attribute allow-lists.
	 *
	 * @return string Cell attribute HTML or empty string.
	 */
	private static function columnCellAttributeHtml(Column $column, mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null, mixed $table=null): string {
		$value=$column->resolveValue($record);
		$formatted=self::stringValue($column->formatValue($value, $record));
		$attributes=$column->resolveCellAttributes($record, $value, $formatted, $request, $resource, $table);
		if($attributes===[]){
			return '';
		}
		return self::columnExtraClass($attributes).self::columnExtraAttributes($attributes);
	}

	/**
	 * Renders inline-edit controls for editable cells or falls back to read-only cell HTML.
	 *
	 * editability and update permission are both required, records need a stable key, select options
	 * must exist for select inputs, CSRF and return inputs are included, and autosave controls preserve the original value
	 * in data attributes for the client runtime.
	 *
	 * @param Column $column Column definition.
	 * @param mixed $record Source record.
	 * @param PanelRequest $request Current panel request.
	 * @param Resource $resource Resource owning the record.
	 * @return string Editable form or read-only cell HTML.
	 */
	private static function editableCellHtml(Column $column, mixed $record, PanelRequest $request, Resource $resource): string {
		if(!$column->isEditable($record, $request, $resource, $resource->resourceTable()) || $resource->can('update', $record, $request->user())===false){
			return self::cellHtml($column, $record);
		}
		$key=$resource->recordKey($record);
		if($key===''){
			return self::cellHtml($column, $record);
		}
		$meta=$column->toArray();
		$name=$column->name();
		$type=$column->editableInputType();
		$value=$column->resolveValue($record);
		$valueString=self::stringValue($value);
		$label=trim((string)($meta['label'] ?? $name));
		$returnQuery=$request->query();
		unset($returnQuery['operation'], $returnQuery['record']);
		$returnUrl=PanelConfig::resourceUrl($resource, '', self::filterQueryValues($returnQuery));
		$hidden='<input type="hidden" name="field" value="'.self::e($name).'">'
			.'<input type="hidden" name="return" value="'.self::e($returnUrl).'">';
		$control='';
		if($type==='select'){
			$options=$column->resolveEditableOptions($record, $request, $resource, $resource->resourceTable());
			if($options===[]){
				return self::cellHtml($column, $record);
			}
			foreach($options as $optionValue=>$optionLabel){
				$selected=(string)$optionValue===$valueString ? ' selected' : '';
				$control.='<option value="'.self::e((string)$optionValue).'"'.$selected.'>'.self::e((string)$optionLabel).'</option>';
			}
			$control='<select class="dp-panel-inline-edit-control" name="value" aria-label="'.self::e($label).'" data-dp-panel-inline-autosave>'.$control.'</select>';
		}
		elseif($type==='checkbox' || $type==='boolean'){
			$checked=in_array(strtolower(trim($valueString)), ['1', 'true', 'yes', 'on', 'live', 'enabled'], true) ? ' checked' : '';
			$control='<input type="hidden" name="value" value="0"><label class="dp-panel-inline-edit-toggle"><input type="checkbox" name="value" value="1"'.$checked.' aria-label="'.self::e($label).'" data-dp-panel-inline-autosave><span>'.self::e($checked!=='' ? self::panelText('table.inline_live') : self::panelText('table.inline_hidden')).'</span></label>';
		}
		else {
			$inputType=in_array($type, ['number', 'integer', 'int'], true) ? 'number' : 'text';
			$step=$inputType==='number' ? ' step="any"' : '';
			$control='<input class="dp-panel-inline-edit-control" type="'.$inputType.'" name="value" value="'.self::e($valueString).'" aria-label="'.self::e($label).'"'.$step.' data-dp-panel-inline-autosave>';
		}
		$action=PanelConfig::resourceUrl($resource, 'inline_update/'.rawurlencode($key));
		return '<form class="dp-panel-inline-edit" method="post" action="'.self::e($action).'" data-dp-panel-inline-edit data-dp-panel-field="'.self::e($name).'" data-dp-panel-original="'.self::e($valueString).'">'
			.self::csrfInput()
			.$hidden
			.$control
			.'<button class="dp-panel-inline-edit-save" type="submit" title="'.self::e(self::panelText('table.save_field', ['field'=>$label])).'" aria-label="'.self::e(self::panelText('table.save_field', ['field'=>$label])).'">'.self::e(self::panelText('common.save')).'</button>'
			.'<span class="dp-panel-inline-edit-status" aria-live="polite"></span>'
			.'</form>';
	}

	/**
	 * Resolves and serializes custom table row attributes.
	 *
	 * ResourceTable owns row attribute resolution, and emitted attributes pass through the row class and
	 * row attribute allow-lists.
	 *
	 * @return string Row attribute HTML or empty string.
	 */
	private static function tableRowAttributeHtml(ResourceTable $table, mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null): string {
		$attributes=$table->resolveRowAttributes($record, $request, $resource);
		if($attributes===[]){
			return '';
		}
		return self::tableRowExtraClass($attributes).self::tableRowExtraAttributes($attributes);
	}

	/**
	 * Builds client row-click attributes for row navigation or modal-backed row actions.
	 *
	 * row click metadata must provide a URL and resource context, action-target modals resolve through
	 * the named Action, confirmation metadata is escaped, and generic show/edit row modals receive operation-specific
	 * heading, width, and style defaults.
	 *
	 * @return string Row-click data attributes or empty string.
	 */
	private static function tableRowClickAttributeHtml(ResourceTable $table, mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null, string $rowLabel='record'): string {
		if(!$resource instanceof Resource){
			return '';
		}
		$click=$table->resolveRowClick($record, $request, $resource);
		$url=trim((string)($click['url'] ?? ''));
		if($url===''){
			return '';
		}
		$operation=(string)($click['operation'] ?? 'show');
		$attrs=' data-dp-panel-row-url="'.self::e($url).'" data-dp-panel-row-operation="'.self::e($operation).'"';
		if(($click['modal'] ?? false)===true){
			if(($click['target'] ?? '')==='action'){
				$actionName=Resource::normalizeName((string)($click['action'] ?? ''));
				$action=$actionName!=='' ? $resource->actionByName($actionName) : null;
				if($action instanceof Action){
					$meta=$action->resolvedMeta($record, $request, $resource);
					$modalContent=$action->resolveModalContent($record, $request, $resource);
					$attrs.=self::actionModalAttributes($meta, $action->hasFields(), $modalContent);
					if(($meta['has_handler'] ?? false)===true && !$action->hasFields()){
						$attrs.=' data-dp-panel-action-method="POST"';
					}
					if(($meta['requires_confirmation'] ?? false)===true){
						$attrs.=' data-confirm="'.self::e((string)($meta['meta']['confirmation'] ?? $meta['modal_description'] ?? self::panelText('action.run_action_confirm', ['action'=>(string)($meta['label'] ?? self::panelText('common.run'))]))).'"';
					}
					return $attrs;
				}
			}
			$heading=($operation==='edit' ? self::panelText('common.edit') : self::panelText('common.view')).' '.(trim($rowLabel) !== '' ? $rowLabel : self::panelText('common.record'));
			$description=$operation==='edit' ? self::panelText('table.update_record_context') : self::panelText('table.review_record_context');
			$width=$operation==='edit' ? 'xl' : 'lg';
			$style=$operation==='edit' ? 'slide_over' : 'dialog';
			$attrs.=self::resourceModalAttributes($operation, $heading, $description, $width, $style, true);
		}
		return $attrs;
	}

	/**
	 * Builds row preview metadata attributes for the client preview panel.
	 *
	 * previewability is exposed independently from preview field availability, and field metadata is
	 * JSON encoded and escaped only when serialization succeeds.
	 *
	 * @return string Row preview data attributes.
	 */
	private static function tableRowPreviewAttributeHtml(ResourceTable $table, mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null): string {
		$fields=$table->resolveRowPreviewFields($record, $request, $resource);
		$attrs=$table->previewActionEnabled() ? ' data-dp-panel-previewable="1"' : '';
		if($fields===[]){
			return $attrs;
		}
		$json=json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if(!is_string($json) || $json===''){
			return $attrs;
		}
		return $attrs.' data-dp-panel-preview-fields="'.self::e($json).'"';
	}

	/**
	 * Renders the action set available for one table record.
	 *
	 * record keys are required, permissions gate every built-in and custom action, primary view/edit
	 * links can be inline or separated, transition/duplicate/restore/delete actions render as POST forms, and secondary
	 * actions collapse into the row more menu.
	 *
	 * @param Resource $resource Resource owning the record.
	 * @param mixed $record Source record.
	 * @param bool $includePrimaryLinks Whether primary links should render as full buttons.
	 * @param PanelRequest|null $request Current panel request.
	 * @param string|null $returnUrl Explicit return URL for POST actions.
	 * @return string Row actions HTML.
	 */
	private static function rowActions(Resource $resource, mixed $record, bool $includePrimaryLinks=false, ?PanelRequest $request=null, ?string $returnUrl=null): string {
		$key=$resource->recordKey($record);
		if($key===''){
			return '';
		}
		$rowLabel=$resource->recordTitle($record);
		if($rowLabel===''){
			$rowLabel=$key;
		}
		$primary='';
		$secondary='';
		if(!$includePrimaryLinks){
			if($resource->can('view', $record, $request?->user())!==false){
				$primary.='<a class="dp-panel-row-link" href="'.self::e($resource->recordUrl($record, 'show')).'"'.self::resourceModalAttributes('view', self::panelText('data.view_record_title', ['record'=>$rowLabel]), self::panelText('table.review_record_context'), 'lg', 'dialog', true).'>'.self::actionTextHtml(self::panelText('common.view'), 'eye').'</a>';
			}
			if($resource->can('update', $record, $request?->user())!==false){
				$primary.='<a class="dp-panel-row-link" href="'.self::e($resource->recordUrl($record, 'edit')).'"'.self::resourceModalAttributes('edit', self::panelText('common.edit').' '.$rowLabel, self::panelText('table.update_record_same_table'), 'xl', 'slide_over', true).'>'.self::actionTextHtml(self::panelText('common.edit'), 'edit').'</a>';
			}
		}
		else {
			if($resource->can('update', $record, $request?->user())!==false){
				$primary.='<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e($resource->recordUrl($record, 'edit')).'"'.self::resourceModalAttributes('edit', self::panelText('common.edit').' '.$rowLabel, self::panelText('table.update_record_same_view'), 'xl', 'slide_over', true).'>'.self::actionTextHtml(self::panelText('common.edit'), 'edit').'</a>';
			}
		}
		if($resource->resourceTable()->previewActionEnabled()){
			$secondary.='<button class="dp-panel-action dp-panel-action-info" type="button" data-dp-panel-preview-row>'.self::actionTextHtml(self::panelText('table.preview'), 'eye').'</button>';
		}
		if($resource->canTransition()){
			foreach($resource->statusTransitionsList($record) as $transition){
				if(
					$resource->can('transition', $record, $request?->user())===false
					|| $resource->can('transition:'.(string)$transition['name'], $record, $request?->user())===false
				){
					continue;
				}
				$secondary.=self::transitionButton($resource, $record, $transition, $request, $returnUrl);
			}
		}
		if($resource->canDuplicate() && $resource->can('duplicate', $record, $request?->user())!==false){
			$secondary.=self::duplicateButton($resource, $record, $request, $returnUrl);
		}
		if($resource->canRestore() && $resource->can('restore', $record, $request?->user())!==false){
			$secondary.=self::restoreButton($resource, $record, $request, $returnUrl);
		}
		if($resource->canDelete() && $resource->can('delete', $record, $request?->user())!==false){
			$secondary.=self::deleteButton($resource, $record, $request, $returnUrl);
		}
		if($resource->canForceDelete() && $resource->can('force_delete', $record, $request?->user())!==false){
			$secondary.=self::forceDeleteButton($resource, $record, $request, $returnUrl);
		}
		foreach($resource->actionsList() as $action){
			if($action instanceof ActionGroup){
				$secondary.=self::resourceActionGroupButton($resource, $action, $record, false, null, $request, $returnUrl);
				continue;
			}
			$meta=$action->toArray();
			if(($meta['bulk'] ?? false)===true || !$action->isVisible($record, $request?->user(), $resource, $request) || $action->can($record, $request?->user(), $resource)===false){
				continue;
			}
			$secondary.=self::actionButton($resource, $action, $key, false, null, $request, $returnUrl, $record);
		}
		if($includePrimaryLinks){
			return $primary.$secondary;
		}
		return $primary.self::rowMoreActionsHtml($secondary, $rowLabel);
	}

	/**
	 * Wraps secondary row actions in an accessible details menu.
	 *
	 * @param string $actions Trusted action HTML.
	 * @param string $recordLabel Record label for aria text.
	 * @return string Row more menu HTML or empty string.
	 */
	private static function rowMoreActionsHtml(string $actions, string $recordLabel='record'): string {
		if(trim($actions)===''){
			return '';
		}
		$recordLabel=trim($recordLabel) ?: self::panelText('common.record');
		return '<details class="dp-panel-row-more"><summary aria-label="'.self::e(self::panelText('table.more_actions_for', ['record'=>$recordLabel])).'">'.self::actionTextHtml(self::panelText('table.more'), 'more-horizontal').'</summary><div class="dp-panel-row-more-menu" role="menu"><header><span>'.self::e(self::panelText('table.actions')).'</span><strong>'.self::e($recordLabel).'</strong></header><section>'.$actions.'</section></div></details>';
	}

	/**
	 * Renders the POST form button for a record status transition.
	 *
	 * transition and record keys are required, CSRF and return inputs are included, tone is allow-listed,
	 * and confirmation/modal attributes describe the transition before submit.
	 *
	 * @return string Transition form HTML or empty string.
	 */
	private static function transitionButton(Resource $resource, mixed $record, array $transition, ?PanelRequest $request=null, ?string $returnUrl=null): string {
		$key=$resource->recordKey($record);
		if($key===''){
			return '';
		}
		$name=(string)($transition['name'] ?? '');
		if($name===''){
			return '';
		}
		$url=PanelConfig::resourceUrl($resource, 'transition/'.rawurlencode($key), ['transition'=>$name]);
		$title=$resource->recordTitle($record);
		$label=(string)($transition['label'] ?? Resource::normalizeName($name));
		$confirm=trim((string)($transition['confirmation'] ?? ''));
		if($confirm===''){
			$confirm=$label.' '.($title!=='' ? $title : self::panelText('action.this_record')).'?';
		}
		$tone=self::safeTone((string)($transition['tone'] ?? 'primary'));
		return '<form class="dp-panel-inline-action" method="post" action="'.self::e($url).'">'
			.self::csrfInput()
			.($returnUrl!==null ? self::returnInputUrl($returnUrl) : ($request!==null ? self::returnInput($resource, $request) : ''))
			.'<input type="hidden" name="transition" value="'.self::e($name).'">'
			.'<button class="dp-panel-action dp-panel-action-'.$tone.'" type="submit" data-confirm="'.self::e($confirm).'"'.self::resourceModalAttributes($name, $label.' '.$title, $confirm, 'sm', 'dialog', false, $label, self::panelText('common.cancel'), $tone).'>'.self::actionTextHtml($label, $name).'</button>'
			.'</form>';
	}

	/**
	 * Renders the POST form button for duplicating one record.
	 *
	 * @return string Duplicate form HTML or empty string when the record has no key.
	 */
	private static function duplicateButton(Resource $resource, mixed $record, ?PanelRequest $request=null, ?string $returnUrl=null): string {
		$key=$resource->recordKey($record);
		if($key===''){
			return '';
		}
		$url=PanelConfig::resourceUrl($resource, 'duplicate/'.rawurlencode($key));
		$title=$resource->recordTitle($record);
		$label=self::panelText('common.duplicate');
		$confirm=self::panelText('action.duplicate_record_confirm', ['record'=>$title!=='' ? $title : self::panelText('action.this_record')]);
		return '<form class="dp-panel-inline-action" method="post" action="'.self::e($url).'">'
			.self::csrfInput()
			.($returnUrl!==null ? self::returnInputUrl($returnUrl) : ($request!==null ? self::returnInput($resource, $request) : ''))
			.'<button class="dp-panel-action dp-panel-action-neutral" type="submit" data-confirm="'.self::e($confirm).'"'.self::resourceModalAttributes('duplicate', self::panelText('action.duplicate_record'), $confirm, 'sm', 'dialog', false, $label, self::panelText('common.cancel'), 'neutral').'>'.self::actionTextHtml($label, 'copy').'</button>'
			.'</form>';
	}

	/**
	 * Renders the POST form button for restoring one record.
	 *
	 * @return string Restore form HTML or empty string when the record has no key.
	 */
	private static function restoreButton(Resource $resource, mixed $record, ?PanelRequest $request=null, ?string $returnUrl=null): string {
		$key=$resource->recordKey($record);
		if($key===''){
			return '';
		}
		$url=PanelConfig::resourceUrl($resource, 'restore/'.rawurlencode($key));
		$title=$resource->recordTitle($record);
		$label=self::panelText('common.restore');
		$confirm=self::panelText('action.restore_record_confirm', ['record'=>$title!=='' ? $title : self::panelText('action.this_record')]);
		return '<form class="dp-panel-inline-action" method="post" action="'.self::e($url).'">'
			.self::csrfInput()
			.($returnUrl!==null ? self::returnInputUrl($returnUrl) : ($request!==null ? self::returnInput($resource, $request) : ''))
			.'<button class="dp-panel-action dp-panel-action-success" type="submit" data-confirm="'.self::e($confirm).'"'.self::resourceModalAttributes('restore', self::panelText('action.restore_record'), $confirm, 'sm', 'dialog', false, $label, self::panelText('common.cancel'), 'success').'>'.self::actionTextHtml($label, 'rotate-ccw').'</button>'
			.'</form>';
	}

	/**
	 * Renders the POST form button for deleting one record.
	 *
	 * the destructive action uses CSRF, return routing, confirmation text, and danger modal metadata.
	 *
	 * @return string Delete form HTML or empty string when the record has no key.
	 */
	private static function deleteButton(Resource $resource, mixed $record, ?PanelRequest $request=null, ?string $returnUrl=null): string {
		$key=$resource->recordKey($record);
		if($key===''){
			return '';
		}
		$url=PanelConfig::resourceUrl($resource, 'delete/'.rawurlencode($key));
		$title=$resource->recordTitle($record);
		$label=self::panelText('common.delete');
		$confirm=self::panelText('action.delete_record_confirm', ['record'=>$title!=='' ? $title : self::panelText('action.this_record')]);
		return '<form class="dp-panel-inline-action" method="post" action="'.self::e($url).'">'
			.self::csrfInput()
			.($returnUrl!==null ? self::returnInputUrl($returnUrl) : ($request!==null ? self::returnInput($resource, $request) : ''))
			.'<button class="dp-panel-action dp-panel-action-danger" type="submit" data-confirm="'.self::e($confirm).'"'.self::resourceModalAttributes('delete', self::panelText('action.delete_record'), $confirm, 'sm', 'dialog', false, $label, self::panelText('common.cancel'), 'danger').'>'.self::actionTextHtml($label, 'trash').'</button>'
			.'</form>';
	}

	/**
	 * Renders the POST form button for permanently deleting one record.
	 *
	 * force delete keeps the same CSRF, return, confirmation, and danger modal safeguards as soft
	 * delete while targeting the force_delete operation.
	 *
	 * @return string Force-delete form HTML or empty string when the record has no key.
	 */
	private static function forceDeleteButton(Resource $resource, mixed $record, ?PanelRequest $request=null, ?string $returnUrl=null): string {
		$key=$resource->recordKey($record);
		if($key===''){
			return '';
		}
		$url=PanelConfig::resourceUrl($resource, 'force_delete/'.rawurlencode($key));
		$title=$resource->recordTitle($record);
		$label=self::panelText('common.delete_forever');
		$confirm=self::panelText('action.force_delete_record_confirm', ['record'=>$title!=='' ? $title : self::panelText('action.this_record')]);
		return '<form class="dp-panel-inline-action" method="post" action="'.self::e($url).'">'
			.self::csrfInput()
			.($returnUrl!==null ? self::returnInputUrl($returnUrl) : ($request!==null ? self::returnInput($resource, $request) : ''))
			.'<button class="dp-panel-action dp-panel-action-danger" type="submit" data-confirm="'.self::e($confirm).'"'.self::resourceModalAttributes('force_delete', self::panelText('action.force_delete_record'), $confirm, 'sm', 'dialog', false, $label, self::panelText('common.cancel'), 'danger').'>'.self::actionTextHtml(self::panelText('action.force_delete'), 'trash-2').'</button>'
			.'</form>';
	}

	/**
	 * Renders non-bulk resource-level custom actions.
	 *
	 * ActionGroup entries are rendered through the group boundary, bulk actions are excluded, and
	 * visibility plus permission checks gate every action.
	 *
	 * @param Resource $resource Resource owning actions.
	 * @param PanelRequest $request Current panel request.
	 * @return string Resource action HTML.
	 */
	private static function resourceActions(Resource $resource, PanelRequest $request): string {
		$html='';
		foreach($resource->actionsList() as $action){
			if($action instanceof ActionGroup){
				$html.=self::resourceActionGroupButton($resource, $action, null, false, null, $request);
				continue;
			}
			$meta=$action->toArray();
			if(($meta['bulk'] ?? false)===true || !$action->isVisible(null, $request->user(), $resource, $request) || $action->can(null, $request->user(), $resource)===false){
				continue;
			}
			$html.=self::actionButton($resource, $action, null, false, null, $request);
		}
		return $html;
	}

	/**
	 * Renders the status board navigation button when board mode is available.
	 *
	 * board navigation requires transition support, at least one status board view, and board
	 * permission; table-only state is stripped from the board URL.
	 *
	 * @param Resource $resource Resource owning the board.
	 * @param PanelRequest $request Current panel request.
	 * @return string Board button HTML or empty string.
	 */
	private static function statusBoardButtonHtml(Resource $resource, PanelRequest $request): string {
		if($resource->canTransition()===false || self::statusBoardViews($resource)===[] || $resource->can('board', null, $request->user())===false){
			return '';
		}
		$query=self::queryWithoutPage($request);
		unset($query['view'], $query['visible_columns'], $query['density'], $query['per_page']);
		$url=PanelConfig::resourceUrl($resource, 'board', $query);
		return '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e($url).'">'.self::e(self::panelText('table.board')).'</a>';
	}

	/**
	 * Renders all bulk actions available for the current resource and user.
	 *
	 * global export settings, resource capabilities, transition-specific permissions, and custom action
	 * visibility/permission all gate the emitted submit buttons for the shared bulk form.
	 *
	 * @param Resource $resource Resource owning bulk operations.
	 * @param PanelRequest $request Current panel request.
	 * @param string $formId Bulk selection form id.
	 * @return string Bulk action HTML.
	 */
	private static function bulkActions(Resource $resource, PanelRequest $request, string $formId): string {
		$html='';
		if(PanelConfig::resourceExportsEnabled() && $resource->can('export', null, $request->user())!==false && $resource->can('bulk_export', null, $request->user())!==false){
			$html.=self::bulkExportButton($resource, $request, $formId);
		}
		if($resource->canTransition() && $resource->can('transition', null, $request->user())!==false){
			foreach($resource->statusTransitionsList() as $transition){
				if($resource->can('transition:'.(string)$transition['name'], null, $request->user())===false){
					continue;
				}
				$html.=self::bulkTransitionButton($resource, $request, $formId, $transition);
			}
		}
		if($resource->canBulkUpdate() && $resource->can('bulk_update', null, $request->user())!==false){
			$html.=self::bulkUpdateButton($resource, $request, $formId);
		}
		if($resource->canDuplicate() && $resource->can('bulk_duplicate', null, $request->user())!==false){
			$html.=self::bulkDuplicateButton($resource, $request, $formId);
		}
		if($resource->canRestore() && $resource->can('bulk_restore', null, $request->user())!==false){
			$html.=self::bulkRestoreButton($resource, $request, $formId);
		}
		if($resource->canDelete() && $resource->can('bulk_delete', null, $request->user())!==false){
			$html.=self::bulkDeleteButton($resource, $request, $formId);
		}
		if($resource->canForceDelete() && $resource->can('bulk_force_delete', null, $request->user())!==false){
			$html.=self::bulkForceDeleteButton($resource, $request, $formId);
		}
		foreach($resource->actionsList() as $action){
			if($action instanceof ActionGroup){
				$html.=self::resourceActionGroupButton($resource, $action, null, true, $formId, $request);
				continue;
			}
			$meta=$action->toArray();
			if(($meta['bulk'] ?? false)!==true || !$action->isVisible(null, $request->user(), $resource, $request) || $action->can(null, $request->user(), $resource)===false){
				continue;
			}
			$html.=self::actionButton($resource, $action, null, true, $formId, $request);
		}
		return $html;
	}

	/**
	 * Renders CSV and JSON bulk export submit buttons for selected rows.
	 *
	 * @return string Bulk export button HTML.
	 */
	private static function bulkExportButton(Resource $resource, PanelRequest $request, string $formId): string {
		$query=self::queryWithoutPage($request);
		unset($query['format']);
		$csvUrl=PanelConfig::resourceUrl($resource, 'bulk_export', $query);
		$jsonQuery=array_replace($query, ['format'=>'json']);
		$jsonUrl=PanelConfig::resourceUrl($resource, 'bulk_export', $jsonQuery);
		return '<button class="dp-panel-action dp-panel-action-neutral dp-panel-bulk-action" type="submit" form="'.self::e($formId).'" formaction="'.self::e($csvUrl).'">'.self::actionTextHtml(self::panelText('export.csv'), 'file-spreadsheet').'</button>'
			.'<button class="dp-panel-action dp-panel-action-neutral dp-panel-bulk-action" type="submit" form="'.self::e($formId).'" formaction="'.self::e($jsonUrl).'">'.self::actionTextHtml(self::panelText('export.json'), 'braces').'</button>';
	}

	/**
	 * Renders a bulk transition submit button for selected rows.
	 *
	 * transition names are required, tone is allow-listed, current query state is preserved without
	 * page, and modal/confirmation metadata describes the bulk operation.
	 *
	 * @return string Bulk transition button HTML or empty string.
	 */
	private static function bulkTransitionButton(Resource $resource, PanelRequest $request, string $formId, array $transition): string {
		$name=(string)($transition['name'] ?? '');
		if($name===''){
			return '';
		}
		$query=self::queryWithoutPage($request);
		$query['transition']=$name;
		$url=PanelConfig::resourceUrl($resource, 'bulk_transition', $query);
		$label=(string)($transition['label'] ?? Resource::normalizeName($name));
		$tone=self::safeTone((string)($transition['tone'] ?? 'primary'));
		$confirm=self::panelText('action.bulk_confirm', ['action'=>$label]);
		return '<button class="dp-panel-action dp-panel-action-'.$tone.' dp-panel-bulk-action" type="submit" form="'.self::e($formId).'" formaction="'.self::e($url).'" data-confirm="'.self::e($confirm).'"'.self::resourceModalAttributes('bulk_'.$name, self::panelText('action.bulk_title', ['action'=>$label]), $confirm, 'sm', 'dialog', false, $label, self::panelText('common.cancel'), $tone).'>'.self::actionTextHtml(self::panelText('action.bulk_label', ['action'=>$label]), $name).'</button>';
	}

	/**
	 * Renders the bulk update submit button for selected rows.
	 *
	 * @return string Bulk update button HTML.
	 */
	private static function bulkUpdateButton(Resource $resource, PanelRequest $request, string $formId): string {
		$query=self::queryWithoutPage($request);
		$url=PanelConfig::resourceUrl($resource, 'bulk_update', $query);
		$edit=self::panelText('common.edit');
		return '<button class="dp-panel-action dp-panel-action-primary dp-panel-bulk-action" type="submit" form="'.self::e($formId).'" formaction="'.self::e($url).'"'.self::resourceModalAttributes('bulk_update', self::panelText('action.bulk_label', ['action'=>$edit]).' '.$resource->pluralLabel(), self::panelText('action.bulk_update_description'), 'xl', 'slide_over', true, self::panelText('action.update_selected'), self::panelText('common.cancel'), 'primary').'>'.self::actionTextHtml(self::panelText('action.bulk_label', ['action'=>$edit]), 'edit').'</button>';
	}

	/**
	 * Renders the bulk duplicate submit button for selected rows.
	 *
	 * @return string Bulk duplicate button HTML.
	 */
	private static function bulkDuplicateButton(Resource $resource, PanelRequest $request, string $formId): string {
		$query=self::queryWithoutPage($request);
		$url=PanelConfig::resourceUrl($resource, 'bulk_duplicate', $query);
		return '<button class="dp-panel-action dp-panel-action-neutral dp-panel-bulk-action" type="submit" form="'.self::e($formId).'" formaction="'.self::e($url).'" data-confirm="'.self::e(self::panelText('action.duplicate_selected_confirm')).'"'.self::resourceModalAttributes('bulk_duplicate', self::panelText('action.duplicate_selected_confirm', []), self::panelText('action.duplicate_selected_body'), 'sm', 'dialog', false, self::panelText('common.duplicate'), self::panelText('common.cancel'), 'neutral').'>'.self::actionTextHtml(self::panelText('action.duplicate_selected'), 'copy').'</button>';
	}

	/**
	 * Renders the bulk restore submit button for selected rows.
	 *
	 * @return string Bulk restore button HTML.
	 */
	private static function bulkRestoreButton(Resource $resource, PanelRequest $request, string $formId): string {
		$query=self::queryWithoutPage($request);
		$url=PanelConfig::resourceUrl($resource, 'bulk_restore', $query);
		return '<button class="dp-panel-action dp-panel-action-success dp-panel-bulk-action" type="submit" form="'.self::e($formId).'" formaction="'.self::e($url).'" data-confirm="'.self::e(self::panelText('action.restore_selected_confirm')).'"'.self::resourceModalAttributes('bulk_restore', self::panelText('action.restore_selected_confirm'), self::panelText('action.restore_selected_body'), 'sm', 'dialog', false, self::panelText('common.restore'), self::panelText('common.cancel'), 'success').'>'.self::actionTextHtml(self::panelText('action.restore_selected'), 'rotate-ccw').'</button>';
	}

	/**
	 * Renders the bulk delete submit button for selected rows.
	 *
	 * @return string Bulk delete button HTML.
	 */
	private static function bulkDeleteButton(Resource $resource, PanelRequest $request, string $formId): string {
		$query=self::queryWithoutPage($request);
		$url=PanelConfig::resourceUrl($resource, 'bulk_delete', $query);
		return '<button class="dp-panel-action dp-panel-action-danger dp-panel-bulk-action" type="submit" form="'.self::e($formId).'" formaction="'.self::e($url).'" data-confirm="'.self::e(self::panelText('action.delete_selected_confirm')).'"'.self::resourceModalAttributes('bulk_delete', self::panelText('action.delete_selected_confirm'), self::panelText('action.delete_selected_body'), 'sm', 'dialog', false, self::panelText('common.delete'), self::panelText('common.cancel'), 'danger').'>'.self::actionTextHtml(self::panelText('action.delete_selected'), 'trash').'</button>';
	}

	/**
	 * Renders the bulk permanent-delete submit button for selected rows.
	 *
	 * @return string Bulk force-delete button HTML.
	 */
	private static function bulkForceDeleteButton(Resource $resource, PanelRequest $request, string $formId): string {
		$query=self::queryWithoutPage($request);
		$url=PanelConfig::resourceUrl($resource, 'bulk_force_delete', $query);
		return '<button class="dp-panel-action dp-panel-action-danger dp-panel-bulk-action" type="submit" form="'.self::e($formId).'" formaction="'.self::e($url).'" data-confirm="'.self::e(self::panelText('action.force_delete_selected_confirm')).'"'.self::resourceModalAttributes('bulk_force_delete', self::panelText('action.force_delete_selected_confirm'), self::panelText('action.force_delete_selected_body'), 'sm', 'dialog', false, self::panelText('common.delete_forever'), self::panelText('common.cancel'), 'danger').'>'.self::actionTextHtml(self::panelText('action.force_delete_selected'), 'trash-2').'</button>';
	}

	/**
	 * Renders a custom Action as either a standalone POST form or a submit button for an existing form.
	 *
	 * resolved action metadata drives URL, confirmation, modal content, fields, disabled state,
	 * tooltip, key bindings, style, size, and extra attributes. Disabled/content-only actions render as buttons that do
	 * not submit, and POST forms include CSRF and safe return routing.
	 *
	 * @return string Action button or inline form HTML.
	 */
	private static function actionButton(Resource $resource, Action $action, ?string $recordKey=null, bool $bulk=false, ?string $formId=null, ?PanelRequest $request=null, ?string $returnUrl=null, mixed $record=null): string {
		$meta=$action->resolvedMeta($record, $request, $resource);
		$actionUrl=self::actionUrl($resource, (string)$meta['name'], $recordKey, $request);
		$confirm=($meta['requires_confirmation'] ?? false) ? ' data-confirm="'.self::e((string)($meta['meta']['confirmation'] ?? self::panelText('action.run_action_confirm', ['action'=>(string)($meta['label'] ?? self::panelText('common.run'))]))).'"' : '';
		$modalContent=$record!==null ? $action->resolveModalContent($record, $request, $resource) : null;
		$hasFields=$action->hasFields();
		$modal=self::actionModalAttributes($meta, $hasFields, $modalContent);
		$tone=self::e((string)($meta['tone'] ?? 'neutral'));
		$label=self::actionLabelHtml($meta);
		$disabled=$action->isDisabled($record, $request?->user(), $resource, $request);
		$disabledAttr=self::actionDisabledAttributes($action, $record, $request, $resource);
		$tooltipAttr=$disabled ? '' : self::actionTooltipAttributes($meta);
		$keyBindingAttr=$disabled ? '' : self::actionKeyBindingAttributes($meta);
		$extraAttr=self::actionExtraAttributes($meta);
		$style=self::safeActionStyle((string)($meta['style'] ?? 'solid'));
		$size=self::safeActionSize((string)($meta['size'] ?? 'md'));
		$iconOnly=($meta['icon_only'] ?? false)===true;
		$class='dp-panel-action dp-panel-action-'.$tone.' dp-panel-action-style-'.$style.' dp-panel-action-size-'.$size.($iconOnly ? ' dp-panel-action-icon-only' : '').($bulk ? ' dp-panel-bulk-action' : '').($disabled ? ' dp-panel-action-disabled' : '').self::actionExtraClass($meta);
		$formAttr=$formId!==null ? ' form="'.self::e($formId).'"' : '';
		$confirmationAttr=($meta['requires_confirmation'] ?? false) ? ' name="__panel_action_confirm" value="1"' : '';
		$contentOnly=$modalContent!==null && ($meta['has_handler'] ?? false)!==true && !$hasFields && ($meta['requires_confirmation'] ?? false)!==true;
		$ariaLabel=$iconOnly ? ' aria-label="'.self::e((string)($meta['label'] ?? $meta['name'] ?? self::panelText('action.default_label'))).'"' : '';
		$button='<button class="'.$class.'" type="'.($disabled || $contentOnly ? 'button' : 'submit').'"'.$ariaLabel.$extraAttr.($disabled ? '' : $confirmationAttr.$formAttr.($contentOnly ? '' : ' formaction="'.self::e($actionUrl).'"').$confirm.$modal.$tooltipAttr.$keyBindingAttr).$disabledAttr.'>'.$label.'</button>';
		if($formId!==null){
			return $button;
		}
		return '<form class="dp-panel-inline-action" method="post" action="'.self::e($actionUrl).'">'.self::csrfInput().($returnUrl!==null ? self::returnInputUrl($returnUrl) : ($request!==null ? self::returnInput($resource, $request) : '')).$button.'</form>';
	}

	/**
	 * Normalizes action style tokens for CSS class suffixes.
	 *
	 * @param string $style Candidate style.
	 * @return string Safe style token.
	 */
	private static function safeActionStyle(string $style): string {
		$style=Resource::normalizeName($style);
		return in_array($style, ['solid', 'outline', 'ghost', 'link'], true) ? $style : 'solid';
	}

	/**
	 * Normalizes action size tokens for CSS class suffixes.
	 *
	 * @param string $size Candidate size.
	 * @return string Safe size token.
	 */
	private static function safeActionSize(string $size): string {
		$size=Resource::normalizeName($size);
		return in_array($size, ['xs', 'sm', 'md', 'lg', 'xl'], true) ? $size : 'md';
	}

	/**
	 * Builds disabled-state attributes for an unavailable action.
	 *
	 * disabled state is computed by the Action, and the reason is exposed through title and data
	 * attributes only after escaping.
	 *
	 * @return string Disabled attribute HTML or empty string.
	 */
	private static function actionDisabledAttributes(Action $action, mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null): string {
		if(!$action->isDisabled($record, $request?->user(), $resource, $request)){
			return '';
		}
		$reason=$action->disabledReasonFor($record, $request?->user(), $resource, $request) ?? self::panelText('action.not_available_now');
		return ' disabled aria-disabled="true" title="'.self::e($reason).'" data-dp-panel-disabled-reason="'.self::e($reason).'"';
	}

	/**
	 * Renders an ActionGroup dropdown for resource, row, or bulk actions.
	 *
	 * section/divider metadata is preserved only when followed by visible actions, bulk mode filters
	 * actions by metadata, visibility and permission gate every item, and dropdown width/alignment/style/size/tone tokens
	 * are allow-listed.
	 *
	 * @return string Action group dropdown HTML or empty string.
	 */
	private static function resourceActionGroupButton(Resource $resource, ActionGroup $group, mixed $record=null, bool $bulk=false, ?string $formId=null, ?PanelRequest $request=null, ?string $returnUrl=null): string {
		$items='';
		$recordKey=$record!==null ? $resource->recordKey($record) : null;
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
			$meta=$action->resolvedMeta($record, $request, $resource);
			if((($meta['bulk'] ?? false)===true)!==$bulk){
				continue;
			}
			if(!$action->isVisible($record, $request?->user(), $resource, $request) || $action->can($record, $request?->user(), $resource)===false){
				continue;
			}
			if($pending!==''){
				$items.=$pending;
				$pending='';
			}
			$items.=self::actionButton($resource, $action, $recordKey, $bulk, $formId, $request, $returnUrl, $record);
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
	 * Renders a section header inside an action group menu.
	 *
	 * @param string $label Section label.
	 * @param string $description Optional section description.
	 * @return string Section header HTML or empty string.
	 */
	private static function actionGroupSectionHtml(string $label, string $description=''): string {
		$label=trim($label);
		if($label===''){
			return '';
		}
		$description=trim($description);
		return '<header class="dp-panel-action-menu-section"><strong>'.self::e($label).'</strong>'.($description!=='' ? '<small>'.self::e($description).'</small>' : '').'</header>';
	}

	/**
	 * Normalizes action group dropdown width tokens.
	 *
	 * @return string Safe width token.
	 */
	private static function safeActionGroupWidth(string $width): string {
		$width=Resource::normalizeName($width);
		return in_array($width, ['auto', 'xs', 'sm', 'md', 'lg', 'xl'], true) ? $width : 'md';
	}

	/**
	 * Normalizes action group dropdown alignment tokens.
	 *
	 * @return string Safe alignment token.
	 */
	private static function safeActionGroupAlignment(string $alignment): string {
		$alignment=Resource::normalizeName($alignment);
		return match($alignment){
			'left', 'start', 'before' => 'start',
			'center', 'middle' => 'center',
			default => 'end',
		};
	}

	/**
	 * Builds the URL for a custom resource action.
	 *
	 * action names are normalized, record keys are rawurlencoded when present, and current query state
	 * is preserved without page or partial-render parameters.
	 *
	 * @return string Action URL.
	 */
	private static function actionUrl(Resource $resource, string $actionName, ?string $recordKey=null, ?PanelRequest $request=null): string {
		$query=$request!==null ? self::queryWithoutPage($request) : [];
		return PanelConfig::resourceUrl(
			$resource,
			'action/'.Resource::normalizeName($actionName).($recordKey!==null ? '/'.rawurlencode($recordKey) : ''),
			$query
		);
	}

	/**
	 * Returns request query parameters with pagination and partial-render keys removed.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return array<string, mixed> Filtered query parameters.
	 */
	private static function queryWithoutPage(PanelRequest $request): array {
		$query=$request->query();
		unset($query['page'], $query['__panel_partial']);
		return self::filterQueryValues($query);
	}

	/**
	 * Recursively removes empty query values while preserving non-empty nested arrays.
	 *
	 * scalar values are kept when their string form is non-empty, empty nested arrays are dropped, and
	 * key names are preserved for URL generation.
	 *
	 * @param array<string|int, mixed> $query Query parameter tree.
	 * @return array<string|int, mixed> Filtered query tree.
	 */
	private static function filterQueryValues(array $query): array {
		$filtered=[];
		foreach($query as $key=>$value){
			if(is_array($value)){
				$value=self::filterQueryValues($value);
				if($value!==[]){
					$filtered[$key]=$value;
				}
				continue;
			}
			if((string)$value!==''){
				$filtered[$key]=$value;
			}
		}
		return $filtered;
	}

	/**
	 * Renders the hidden return_to input for a resource action form.
	 *
	 * @return string Hidden return input HTML.
	 */
	private static function returnInput(Resource $resource, PanelRequest $request): string {
		return '<input type="hidden" name="return_to" value="'.self::e(self::actionReturnUrl($resource, $request)).'">';
	}

	/**
	 * Renders the MVC CSRF token input when the session component is available.
	 *
	 * missing Session class, token failures, or empty tokens suppress the field; exceptions are caught
	 * so action rendering remains available in non-MVC contexts.
	 *
	 * @return string CSRF hidden input HTML or empty string.
	 */
	private static function csrfInput(): string {
		if(!class_exists('\Dataphyre\Mvc\Session')){
			return '';
		}
		try{
			$token=\Dataphyre\Mvc\Session::token();
		}
		catch(\Throwable){
			return '';
		}
		return is_string($token) && $token!=='' ? '<input type="hidden" name="_token" value="'.self::e($token).'">' : '';
	}

	/**
	 * Renders a hidden return_to input for an explicit URL after panel-path validation.
	 *
	 * @param string $url Candidate return URL.
	 * @return string Hidden return input HTML or empty string.
	 */
	private static function returnInputUrl(string $url): string {
		$url=self::safeReturnUrl($url);
		return $url!==null ? '<input type="hidden" name="return_to" value="'.self::e($url).'">' : '';
	}

	/**
	 * Resolves the return URL used after a custom action form submits.
	 *
	 * validated caller-provided return URLs win, action requests for a record return to show when
	 * possible, and all other actions return to the filtered index table.
	 *
	 * @return string Safe panel return URL.
	 */
	private static function actionReturnUrl(Resource $resource, PanelRequest $request): string {
		$provided=self::requestProvidedReturnUrl($request);
		if($provided!==null){
			return $provided;
		}
		$query=self::queryWithoutPage($request);
		$operation=$request->operation();
		if($operation==='action'){
			unset($query['operation'], $query['action'], $query['record']);
			$recordKey=$request->recordKey();
			if($recordKey!==null && trim($recordKey)!==''){
				return PanelConfig::resourceUrl($resource, 'show/'.rawurlencode($recordKey), $query);
			}
		}
		return PanelConfig::resourceUrl($resource, '', $query);
	}

	/**
	 * Builds the canonical return URL for board operations.
	 *
	 * table-only, action, relation, and record query keys are stripped before routing to board.
	 *
	 * @return string Board return URL.
	 */
	private static function boardReturnUrl(Resource $resource, PanelRequest $request): string {
		$query=self::queryWithoutPage($request);
		unset($query['operation'], $query['record'], $query['relation'], $query['action'], $query['view'], $query['visible_columns'], $query['density'], $query['per_page']);
		return PanelConfig::resourceUrl($resource, 'board', $query);
	}

	/**
	 * Builds the canonical return URL for table operations.
	 *
	 * operation-specific and board/table preference keys that should not survive action completion are
	 * stripped before routing to the resource index.
	 *
	 * @return string Table return URL.
	 */
	private static function tableReturnUrl(Resource $resource, PanelRequest $request): string {
		$query=self::queryWithoutPage($request);
		unset($query['operation'], $query['record'], $query['relation'], $query['action'], $query['view'], $query['visible_columns'], $query['density'], $query['per_page']);
		return PanelConfig::resourceUrl($resource, '', $query);
	}

	/**
	 * Builds the return URL for a record show surface.
	 *
	 * @return string Record show URL when a key exists, otherwise the resource index URL.
	 */
	private static function showReturnUrl(Resource $resource, mixed $record): string {
		$key=$resource->recordKey($record);
		return $key!=='' ? PanelConfig::resourceUrl($resource, 'show/'.rawurlencode($key)) : PanelConfig::resourceUrl($resource);
	}

	/**
	 * Reads a caller-provided return_to URL from input or query after validation.
	 *
	 * input value has precedence over query value, and every candidate must pass safeReturnUrl().
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return string|null Safe return URL or null.
	 */
	private static function requestProvidedReturnUrl(PanelRequest $request): ?string {
		foreach([$request->input('return_to'), $request->query('return_to')] as $candidate){
			if(is_string($candidate)){
				$url=self::safeReturnUrl($candidate);
				if($url!==null){
					return $url;
				}
			}
		}
		return null;
	}

	/**
	 * Validates and normalizes a return URL for panel-local redirects.
	 *
	 * line breaks, external URLs, protocol-relative URLs, non-panel paths, and partial-render markers
	 * are rejected or stripped so action redirects remain inside the panel boundary.
	 *
	 * @param string $url Candidate return URL.
	 * @return string|null Safe panel-local URL or null.
	 */
	private static function safeReturnUrl(string $url): ?string {
		$url=trim(str_replace(["\r", "\n"], '', $url));
		if($url==='' || !PanelConfig::isPanelPath($url)){
			return null;
		}
		if(str_starts_with($url, '//') || str_contains($url, '://')){
			return null;
		}
		$url=preg_replace('/([?&])__panel_partial=[^&#]*/', '$1', $url) ?? $url;
		$url=str_replace(['?&', '&&'], ['?', '&'], $url);
		$url=rtrim($url, '?&');
		return $url;
	}

	/**
	 * Renders the checkbox that selects one record for bulk actions.
	 *
	 * records without stable keys cannot be selected; emitted value, form id, aria label, and title are
	 * escaped before output.
	 *
	 * @param Resource $resource Resource owning the record.
	 * @param mixed $record Source record.
	 * @param string $formId Bulk form id.
	 * @return string Checkbox HTML or empty string.
	 */
	private static function recordCheckbox(Resource $resource, mixed $record, string $formId): string {
		$key=$resource->recordKey($record);
		if($key===''){
			return '';
		}
		return '<input type="checkbox" name="selected[]" value="'.self::e($key).'" form="'.self::e($formId).'" aria-label="'.self::e(self::panelText('table.select_record', ['record'=>$key])).'" title="'.self::e(self::panelText('table.select_this_record')).'">';
	}
}
