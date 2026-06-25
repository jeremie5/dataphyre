<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Fluent definition for a Panel resource.
 *
 * A resource gathers navigation metadata, record access, forms, tables, actions, relations, tenant scoping, lifecycle hooks, and mutation handlers into the manifest used by Panel pages.
 */
final class Resource {
	use PanelExtensible;

	private string $name='';
	private string $label='';
	private string $pluralLabel='';
	private ?string $model=null;
	private ?string $repository=null;
	private ?string $table=null;
	private ?string $url=null;
	private ?string $group=null;
	private ?string $navigationParent=null;
	private ?string $icon=null;
	private int $sort=100;
	private bool $hiddenFromNavigation=false;
	private ?string $navigationDescription=null;
	private mixed $navigationBadge=null;
	private ?\Closure $navigationBadgeResolver=null;
	private string $navigationBadgeTone='neutral';
	private bool $globalSearchable=false;
	/** @var array<int,string> */
	private array $globalSearchColumns=[];
	private ?\Closure $authorizer=null;
	private ?string $tenantField=null;
	private ?\Closure $tenantResolver=null;
	private ?\Closure $tenantScope=null;
	private bool $tenantRequired=true;
	private ?\Closure $queryFactory=null;
	private ?\Closure $saveHandler=null;
	private ?\Closure $formDataMutator=null;
	private ?\Closure $createDataMutator=null;
	private ?\Closure $updateDataMutator=null;
	private bool $formDataMutationActive=false;
	private ?\Closure $fillDataMutator=null;
	private ?\Closure $createFillDataMutator=null;
	private ?\Closure $editFillDataMutator=null;
	private ?\Closure $beforeFillHandler=null;
	private ?\Closure $afterFillHandler=null;
	private ?\Closure $beforeValidateHandler=null;
	private ?\Closure $afterValidateHandler=null;
	private ?\Closure $beforeSaveHandler=null;
	private ?\Closure $afterSaveHandler=null;
	private ?\Closure $importHandler=null;
	private ?\Closure $transitionHandler=null;
	private ?\Closure $bulkUpdateHandler=null;
	private ?\Closure $duplicateHandler=null;
	private ?\Closure $restoreHandler=null;
	private ?\Closure $deleteHandler=null;
	private ?\Closure $forceDeleteHandler=null;
	private ?\Closure $recordKeyResolver=null;
	private ?\Closure $recordTitleResolver=null;
	private ?\Closure $recordSubtitleResolver=null;
	private ?\Closure $recordUrlResolver=null;
	private ?\Closure $globalSearchHandler=null;
	private ?\Closure $globalSearchTitleResolver=null;
	private ?\Closure $globalSearchSubtitleResolver=null;
	private ?\Closure $insightsHandler=null;
	private ?\Closure $alertsHandler=null;
	private ?\Closure $linksHandler=null;
	private ?\Closure $contactsHandler=null;
	private ?\Closure $locationsHandler=null;
	private ?\Closure $changesHandler=null;
	private ?\Closure $tagsHandler=null;
	private ?\Closure $tagHandler=null;
	private ?\Closure $itemsHandler=null;
	private ?\Closure $totalsHandler=null;
	private ?\Closure $approvalsHandler=null;
	private ?\Closure $approvalHandler=null;
	private ?\Closure $activityHandler=null;
	private ?\Closure $notesHandler=null;
	private ?\Closure $noteHandler=null;
	private ?\Closure $messagesHandler=null;
	private ?\Closure $messageHandler=null;
	private ?\Closure $shipmentsHandler=null;
	private ?\Closure $paymentsHandler=null;
	private ?\Closure $attachmentsHandler=null;
	private ?\Closure $attachHandler=null;
	private ?\Closure $tasksHandler=null;
	private ?\Closure $taskHandler=null;
	private ?\Closure $createTaskHandler=null;
	private ResourceForm $form;
	private ResourceForm $bulkUpdateForm;
	private Schema|Infolist|null $infolistSchema=null;
	private ResourceTable $resourceTable;
	private string $statusField='status';
	/** @var array<string,array<string,mixed>> */
	private array $statusTransitions=[];
	private bool $statusWidgetsEnabled=false;
	private string $actionFit='stretch';
	/** @var array<string, Action|ActionGroup> */
	private array $actions=[];
	/** @var array<string, RelationManager> */
	private array $relations=[];

	/**
	 * Creates a resource definition with empty form, bulk form, and table builders.
	 *
	 * The resource name is normalized immediately so later registry, routing, and
	 * navigation lookups share one stable key even before labels or URLs are set.
	 *
	 * @param ?string $name Raw resource name.
	 */
	public function __construct(?string $name=null) {
		$this->name=self::normalizeName((string)($name ?? ''));
		$this->form=ResourceForm::make();
		$this->bulkUpdateForm=ResourceForm::make();
		$this->resourceTable=ResourceTable::make();
	}

	/**
	 * Builds a Panel resource definition from fluent input or array configuration.
	 *
	 * Resource definitions gather navigation, forms, tables, actions, relations, authorization, tenants, lifecycle hooks, and record handlers into one manifest.
	 *
	 * @param ?string $name Normalized field, resource, surface, or helper name.
	 * @return self Configured resource definition with default form, bulk form, and table builders.
	 */
	public static function make(?string $name=null): self {
		return self::configured(new self($name));
	}

	/**
	 * Builds a Panel resource definition from fluent input or array configuration.
	 *
	 * Resource definitions gather navigation, forms, tables, actions, relations, authorization, tenants, lifecycle hooks, and record handlers into one manifest.
	 *
	 * @param array<string,mixed> $definition Array definition imported from configuration or a manifest.
	 * @return self Resource definition hydrated from the supplied manifest array.
	 */
	public static function fromArray(array $definition): self {
		$resource=self::make((string)($definition['name'] ?? ''));
		if(isset($definition['label'])){
			$resource=$resource->label((string)$definition['label']);
		}
		if(isset($definition['plural_label'])){
			$resource=$resource->pluralLabel((string)$definition['plural_label']);
		}
		foreach(['model', 'repository', 'table', 'url', 'group', 'icon'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$resource=$resource->{$key}($definition[$key]);
			}
		}
		if(isset($definition['navigation_parent']) && is_string($definition['navigation_parent'])){
			$resource=$resource->navigationParent($definition['navigation_parent']);
		}
		if(isset($definition['folder']) && is_string($definition['folder'])){
			$resource=$resource->navigationParent($definition['folder']);
		}
		if(isset($definition['navigation_description']) && is_string($definition['navigation_description'])){
			$resource=$resource->navigationDescription($definition['navigation_description']);
		}
		if(array_key_exists('navigation_badge', $definition)){
			$resource=$resource->navigationBadge($definition['navigation_badge']);
		}
		if(isset($definition['navigation_badge_tone']) && is_string($definition['navigation_badge_tone'])){
			$resource=$resource->navigationBadgeTone($definition['navigation_badge_tone']);
		}
		if(isset($definition['sort'])){
			$resource=$resource->sort((int)$definition['sort']);
		}
		if(isset($definition['per_page'])){
			$resource=$resource->perPage((int)$definition['per_page']);
		}
		if(isset($definition['action_fit']) && is_string($definition['action_fit'])){
			$resource=$resource->actionFit($definition['action_fit']);
		}
		if(!empty($definition['hidden_from_navigation'])){
			$resource=$resource->hideFromNavigation();
		}
		if(array_key_exists('policy', $definition)){
			$resource=$resource->policy($definition['policy']);
		}
		elseif(isset($definition['authorize']) && is_callable($definition['authorize'])){
			$resource=$resource->authorize($definition['authorize']);
		}
		if(isset($definition['tenant_field']) && is_string($definition['tenant_field'])){
			$resource=$resource->tenantScoped($definition['tenant_field'], (bool)($definition['tenant_required'] ?? true));
		}
		elseif(!empty($definition['tenant_scoped'])){
			$resource=$resource->tenantScoped('tenant_id', (bool)($definition['tenant_required'] ?? true));
		}
		if(isset($definition['tenant_resolver']) && is_callable($definition['tenant_resolver'])){
			$resource=$resource->tenantUsing($definition['tenant_resolver']);
		}
		if(isset($definition['tenant_scope']) && is_callable($definition['tenant_scope'])){
			$resource=$resource->tenantScopeUsing($definition['tenant_scope']);
		}
		if(array_key_exists('global_searchable', $definition)){
			$resource=$resource->globalSearchable((bool)$definition['global_searchable']);
		}
		if(isset($definition['global_search_columns']) && is_array($definition['global_search_columns'])){
			$resource=$resource->globalSearchColumns($definition['global_search_columns']);
		}
		if(isset($definition['activity']) && is_callable($definition['activity'])){
			$resource=$resource->activityUsing($definition['activity']);
		}
		if(isset($definition['insights']) && is_callable($definition['insights'])){
			$resource=$resource->insightsUsing($definition['insights']);
		}
		if(isset($definition['alerts']) && is_callable($definition['alerts'])){
			$resource=$resource->alertsUsing($definition['alerts']);
		}
		if(isset($definition['links']) && is_callable($definition['links'])){
			$resource=$resource->linksUsing($definition['links']);
		}
		if(isset($definition['contacts']) && is_callable($definition['contacts'])){
			$resource=$resource->contactsUsing($definition['contacts']);
		}
		if(isset($definition['locations']) && is_callable($definition['locations'])){
			$resource=$resource->locationsUsing($definition['locations']);
		}
		if(isset($definition['changes']) && is_callable($definition['changes'])){
			$resource=$resource->changesUsing($definition['changes']);
		}
		if(isset($definition['tags']) && is_callable($definition['tags'])){
			$resource=$resource->tagsUsing($definition['tags']);
		}
		if(isset($definition['tag']) && is_callable($definition['tag'])){
			$resource=$resource->tagUsing($definition['tag']);
		}
		if(isset($definition['items']) && is_callable($definition['items'])){
			$resource=$resource->itemsUsing($definition['items']);
		}
		if(isset($definition['totals']) && is_callable($definition['totals'])){
			$resource=$resource->totalsUsing($definition['totals']);
		}
		if(isset($definition['approvals']) && is_callable($definition['approvals'])){
			$resource=$resource->approvalsUsing($definition['approvals']);
		}
		if(isset($definition['approval']) && is_callable($definition['approval'])){
			$resource=$resource->approvalUsing($definition['approval']);
		}
		if(isset($definition['notes']) && is_callable($definition['notes'])){
			$resource=$resource->notesUsing($definition['notes']);
		}
		if(isset($definition['note']) && is_callable($definition['note'])){
			$resource=$resource->noteUsing($definition['note']);
		}
		if(isset($definition['messages']) && is_callable($definition['messages'])){
			$resource=$resource->messagesUsing($definition['messages']);
		}
		if(isset($definition['message']) && is_callable($definition['message'])){
			$resource=$resource->messageUsing($definition['message']);
		}
		if(isset($definition['shipments']) && is_callable($definition['shipments'])){
			$resource=$resource->shipmentsUsing($definition['shipments']);
		}
		if(isset($definition['payments']) && is_callable($definition['payments'])){
			$resource=$resource->paymentsUsing($definition['payments']);
		}
		if(isset($definition['attachments']) && is_callable($definition['attachments'])){
			$resource=$resource->attachmentsUsing($definition['attachments']);
		}
		if(isset($definition['attach']) && is_callable($definition['attach'])){
			$resource=$resource->attachUsing($definition['attach']);
		}
		if(isset($definition['tasks']) && is_callable($definition['tasks'])){
			$resource=$resource->tasksUsing($definition['tasks']);
		}
		if(isset($definition['task']) && is_callable($definition['task'])){
			$resource=$resource->taskUsing($definition['task']);
		}
		if(isset($definition['create_task']) && is_callable($definition['create_task'])){
			$resource=$resource->createTaskUsing($definition['create_task']);
		}
		if(isset($definition['mutate_form_data']) && is_callable($definition['mutate_form_data'])){
			$resource=$resource->mutateFormDataUsing($definition['mutate_form_data']);
		}
		if(isset($definition['mutate_create_data']) && is_callable($definition['mutate_create_data'])){
			$resource=$resource->mutateCreateDataUsing($definition['mutate_create_data']);
		}
		if(isset($definition['mutate_update_data']) && is_callable($definition['mutate_update_data'])){
			$resource=$resource->mutateUpdateDataUsing($definition['mutate_update_data']);
		}
		if(isset($definition['mutate_fill_data']) && is_callable($definition['mutate_fill_data'])){
			$resource=$resource->mutateFillDataUsing($definition['mutate_fill_data']);
		}
		if(isset($definition['mutate_create_fill_data']) && is_callable($definition['mutate_create_fill_data'])){
			$resource=$resource->mutateCreateFillDataUsing($definition['mutate_create_fill_data']);
		}
		if(isset($definition['mutate_edit_fill_data']) && is_callable($definition['mutate_edit_fill_data'])){
			$resource=$resource->mutateEditFillDataUsing($definition['mutate_edit_fill_data']);
		}
		if(isset($definition['before_fill']) && is_callable($definition['before_fill'])){
			$resource=$resource->beforeFillUsing($definition['before_fill']);
		}
		if(isset($definition['after_fill']) && is_callable($definition['after_fill'])){
			$resource=$resource->afterFillUsing($definition['after_fill']);
		}
		if(isset($definition['before_validate']) && is_callable($definition['before_validate'])){
			$resource=$resource->beforeValidateUsing($definition['before_validate']);
		}
		if(isset($definition['after_validate']) && is_callable($definition['after_validate'])){
			$resource=$resource->afterValidateUsing($definition['after_validate']);
		}
		if(isset($definition['before_save']) && is_callable($definition['before_save'])){
			$resource=$resource->beforeSaveUsing($definition['before_save']);
		}
		if(isset($definition['after_save']) && is_callable($definition['after_save'])){
			$resource=$resource->afterSaveUsing($definition['after_save']);
		}
		if(isset($definition['record_key']) && is_string($definition['record_key'])){
			$resource=$resource->recordKeyUsing($definition['record_key']);
		}
		if(isset($definition['record_title']) && is_string($definition['record_title'])){
			$resource=$resource->recordTitleUsing($definition['record_title']);
		}
		if(isset($definition['record_subtitle']) && is_string($definition['record_subtitle'])){
			$resource=$resource->recordSubtitleUsing($definition['record_subtitle']);
		}
		if(isset($definition['form']) && is_array($definition['form'])){
			$schema=Schema::from($definition['form']['schema'] ?? $definition['form']);
			if($schema instanceof Schema){
				$resource=$resource->schema($schema);
			}
		}
		if(isset($definition['bulk_form']) && is_array($definition['bulk_form'])){
			$schema=Schema::from($definition['bulk_form']['schema'] ?? $definition['bulk_form']);
			if($schema instanceof Schema){
				$resource=$resource->bulkSchema($schema);
			}
		}
		if(isset($definition['infolist'])){
			$infolist=Infolist::from($definition['infolist']);
			if($infolist instanceof Infolist){
				$resource=$resource->infolist($infolist);
			}
		}
		if(isset($definition['infolist_schema'])){
			$infolist=Infolist::from($definition['infolist_schema']);
			if($infolist instanceof Infolist){
				$resource=$resource->infolist($infolist);
			}
		}
		if(isset($definition['fields']) && is_array($definition['fields'])){
			$resource=$resource->fields($definition['fields']);
		}
		if(isset($definition['schema'])){
			$schema=Schema::from($definition['schema']);
			if($schema instanceof Schema){
				$resource=$resource->schema($schema);
			}
		}
		if(isset($definition['bulk_fields']) && is_array($definition['bulk_fields'])){
			$resource=$resource->bulkFields($definition['bulk_fields']);
		}
		if(isset($definition['bulk_schema'])){
			$schema=Schema::from($definition['bulk_schema']);
			if($schema instanceof Schema){
				$resource=$resource->bulkSchema($schema);
			}
		}
		if(isset($definition['status_field']) && is_string($definition['status_field'])){
			$resource=$resource->statusField($definition['status_field']);
		}
		if(isset($definition['transitions']) && is_array($definition['transitions'])){
			$resource=$resource->statusTransitions($definition['transitions']);
		}
		if(array_key_exists('status_widgets', $definition)){
			$resource=$resource->statusWidgets((bool)$definition['status_widgets']);
		}
		if(isset($definition['form_sections']) && is_array($definition['form_sections'])){
			$resource=$resource->formSections($definition['form_sections']);
		}
		if(isset($definition['columns']) && is_array($definition['columns'])){
			$resource=$resource->columns($definition['columns']);
		}
		if(isset($definition['views']) && is_array($definition['views'])){
			$resource=$resource->views($definition['views']);
		}
		if(isset($definition['filters']) && is_array($definition['filters'])){
			$resource=$resource->filters($definition['filters']);
		}
		if(isset($definition['summaries']) && is_array($definition['summaries'])){
			$resource=$resource->summaries($definition['summaries']);
		}
		if(isset($definition['actions']) && is_array($definition['actions'])){
			$resource=$resource->actions($definition['actions']);
		}
		if(isset($definition['relations']) && is_array($definition['relations'])){
			$resource=$resource->relations($definition['relations']);
		}
		return $resource;
	}

	/**
	 * Normalizes a resource-compatible identifier.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return string Lowercase identifier containing only letters, digits, underscores, dots, and dashes.
	 */
	public static function normalizeName(string $name): string {
		$name=strtolower(trim($name));
		$name=preg_replace('/[^a-z0-9_.-]+/', '_', $name) ?? '';
		return trim($name, '_.-');
	}

	/**
	 * Converts an array manifest entry into an action or action group object.
	 *
	 * Definitions with nested actions, or explicit group/action_group types, are
	 * treated as grouped actions; every other definition becomes a single action.
	 *
	 * @param array<string,mixed> $definition Action manifest entry.
	 * @return Action|ActionGroup Normalized action object for this resource.
	 */
	private static function actionDefinition(array $definition): Action|ActionGroup {
		$type=self::normalizeName((string)($definition['type'] ?? $definition['kind'] ?? ''));
		if((isset($definition['actions']) && is_array($definition['actions'])) || in_array($type, ['group', 'action_group'], true)){
			return ActionGroup::fromArray($definition);
		}
		return Action::fromArray($definition);
	}

	/**
	 * Returns the normalized resource name.
	 *
	 * @return string Normalized resource registry key.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Gets or replaces the singular label shown for this resource.
	 *
	 * When the plural label still matches the previous generated default, changing the singular label also refreshes the plural default.
	 *
	 * @param ?string $label Replacement label, or null to read the current value.
	 * @return string|self Current label when read, or the resource after update.
	 */
	public function label(?string $label=null): string|self {
		if($label===null){
			return $this->label;
		}
		$clone=clone $this;
		$clone->label=trim($label);
		if($clone->pluralLabel==='' || $clone->pluralLabel===$this->label.'s'){
			$clone->pluralLabel=$clone->label!=='' ? $clone->label.'s' : '';
		}
		return $clone;
	}

	/**
	 * Gets or replaces the plural label shown for resource collections.
	 *
	 * A blank plural label falls back to the singular label when read, keeping navigation and page titles usable before explicit pluralization is provided.
	 *
	 * @param ?string $label Replacement plural label, or null to read the effective value.
	 * @return string|self Current label when read, or the resource after update.
	 */
	public function pluralLabel(?string $label=null): string|self {
		if($label===null){
			return $this->pluralLabel ?: $this->label;
		}
		$clone=clone $this;
		$clone->pluralLabel=trim($label);
		return $clone;
	}

	/**
	 * Updates the model setting for this resource.
	 *
	 * The model class is metadata for resource consumers; it is trimmed here but not autoload-validated so optional domain classes can be registered lazily.
	 *
	 * @param string $class Model class name stored in resource metadata.
	 * @return self Cloned resource definition with updated model metadata.
	 */
	public function model(string $class): self {
		$clone=clone $this;
		$clone->model=trim($class) ?: null;
		return $clone;
	}

	/**
	 * Updates the repository setting for this resource.
	 *
	 * The repository class is metadata for query factories and save handlers; it is trimmed here but not autoload-validated during manifest construction.
	 *
	 * @param string $class Repository class name stored in resource metadata.
	 * @return self Cloned resource definition with updated repository metadata.
	 */
	public function repository(string $class): self {
		$clone=clone $this;
		$clone->repository=trim($class) ?: null;
		return $clone;
	}

	/**
	 * Sets the backing table name advertised by this resource.
	 *
	 * The table string is metadata for default query factories and generated manifests; blank input clears the table binding.
	 *
	 * @param string $table Table name stored after trimming.
	 * @return self Cloned resource definition with updated table metadata.
	 */
	public function table(string $table): self {
		$clone=clone $this;
		$clone->table=trim($table) ?: null;
		return $clone;
	}

	/**
	 * Updates the url setting for this resource.
	 *
	 * URLs are normalized to an absolute Panel path with one leading slash and no trailing slash noise from caller input.
	 *
	 * @param string $url Resource URL path relative to the Panel root.
	 * @return self Cloned resource definition with updated url metadata.
	 */
	public function url(string $url): self {
		$clone=clone $this;
		$url='/'.trim($url, '/');
		$clone->url=$url;
		return $clone;
	}

	/**
	 * Assigns the resource to a navigation group.
	 *
	 * Blank input clears the group so the resource appears at the root level for renderers that group navigation.
	 *
	 * @param string $group Navigation group label or key.
	 * @return self Cloned resource definition with updated group metadata.
	 */
	public function group(string $group): self {
		$clone=clone $this;
		$clone->group=trim($group) ?: null;
		return $clone;
	}

	/**
	 * Assigns the resource to a parent navigation item.
	 *
	 * String parents are normalized as navigation keys; NavigationItem parents contribute their own normalized name. Empty values clear the parent.
	 *
	 * @param string|NavigationItem|null $parent Parent navigation key, item object, or null to clear.
	 * @return self Cloned resource definition with updated navigation parent metadata.
	 */
	public function navigationParent(string|NavigationItem|null $parent): self {
		$clone=clone $this;
		$clone->navigationParent=$parent instanceof NavigationItem ? $parent->name() : (is_string($parent) ? self::normalizeName($parent) : null);
		$clone->navigationParent=$clone->navigationParent!=='' ? $clone->navigationParent : null;
		return $clone;
	}

	/**
	 * Updates the folder setting for this resource.
	 *
	 * This is a semantic alias for navigationParent().
	 *
	 * @param string|NavigationItem|null $parent Parent navigation key, item object, or null to clear.
	 * @return self Cloned resource definition with updated folder metadata.
	 */
	public function folder(string|NavigationItem|null $parent): self {
		return $this->navigationParent($parent);
	}

	/**
	 * Sets the icon token shown beside the resource in navigation.
	 *
	 * Blank input clears the icon so renderers can fall back to their default resource symbol.
	 *
	 * @param string $icon Icon token stored after trimming.
	 * @return self Cloned resource definition with updated icon metadata.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Updates the sort setting for this resource.
	 *
	 * Lower sort values appear earlier in navigation lists that honor resource ordering.
	 *
	 * @param int $sort Navigation sort weight.
	 * @return self Cloned resource definition with updated sort metadata.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Toggles whether this resource appears in Panel navigation.
	 *
	 * Hidden resources can still be routed, authorized, and rendered directly; this flag only affects navigation manifest output.
	 *
	 * @param bool $hidden Whether the resource should be omitted from navigation.
	 * @return self Cloned resource definition with updated hide from navigation metadata.
	 */
	public function hideFromNavigation(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hiddenFromNavigation=$hidden;
		return $clone;
	}

	/**
	 * Sets descriptive text for navigation or resource pickers.
	 *
	 * Blank input clears the description so renderers do not emit empty helper text.
	 *
	 * @param string $description Description text stored after trimming.
	 * @return self Cloned resource definition with updated navigation description metadata.
	 */
	public function navigationDescription(string $description): self {
		$clone=clone $this;
		$clone->navigationDescription=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Sets a static or callback-resolved navigation badge.
	 *
	 * Callable badges are evaluated later by navigation builders; static badges are stored as-is so numeric counts, labels, or null can be represented.
	 *
	 * @param mixed $badge Static badge value or callable badge resolver.
	 * @return self Cloned resource definition with updated navigation badge metadata.
	 */
	public function navigationBadge(mixed $badge): self {
		$clone=clone $this;
		if(is_callable($badge)){
			$clone->navigationBadgeResolver=\Closure::fromCallable($badge);
			$clone->navigationBadge=null;
			return $clone;
		}
		$clone->navigationBadge=$badge;
		$clone->navigationBadgeResolver=null;
		return $clone;
	}

	/**
	 * Registers a callback for resolving navigation badge values.
	 *
	 * The callback is stored until navigation manifests are built, keeping potentially expensive counts out of resource construction.
	 *
	 * @param callable $resolver Callback that returns the badge value for navigation.
	 * @return self Cloned resource definition with the navigation badge using callback registered.
	 */
	public function navigationBadgeUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->navigationBadgeResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Sets the tone used to render the navigation badge.
	 *
	 * Unsupported tones fall back to neutral so renderer manifests always contain a known tone token.
	 *
	 * @param string $tone Badge tone token.
	 * @return self Cloned resource definition with updated navigation badge tone metadata.
	 */
	public function navigationBadgeTone(string $tone): self {
		$tone=self::normalizeName($tone);
		$clone=clone $this;
		$clone->navigationBadgeTone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Toggles whether this resource participates in Panel global search.
	 *
	 * Enabling search only exposes the resource to Panel search orchestration; actual query behavior still depends on configured columns or a custom search handler.
	 *
	 * @param bool $searchable Whether global search should include this resource.
	 * @return self Cloned resource definition with updated global searchable metadata.
	 */
	public function globalSearchable(bool $searchable=true): self {
		$clone=clone $this;
		$clone->globalSearchable=$searchable;
		return $clone;
	}

	/**
	 * Alias for globalSearchable().
	 *
	 * @param bool $searchable Whether global search should include this resource.
	 * @return self Cloned resource definition with updated global search metadata.
	 */
	public function globalSearch(bool $searchable=true): self {
		return $this->globalSearchable($searchable);
	}

	/**
	 * Sets the resource columns searched by default global search.
	 *
	 * Column names are normalized, blanks are removed, and duplicates are collapsed before the search manifest is emitted.
	 *
	 * @param array<int,string> $columns Searchable column names.
	 * @return self Cloned resource definition with updated global search columns metadata.
	 */
	public function globalSearchColumns(array $columns): self {
		$columns=array_values(array_filter(array_map(
			static fn(mixed $column): string => self::normalizeName((string)$column),
			$columns
		), static fn(string $column): bool => $column!==''));
		$clone=clone $this;
		$clone->globalSearchColumns=array_values(array_unique($columns));
		return $clone;
	}

	/**
	 * Registers a custom global search handler for this resource.
	 *
	 * Installing a handler also enables global search. The callback is invoked by Panel search execution with the request/query context instead of relying on column metadata alone.
	 *
	 * @param callable $handler Callback that returns global search result entries.
	 * @return self Cloned resource definition with the global search using callback registered.
	 */
	public function globalSearchUsing(callable $handler): self {
		$clone=clone $this;
		$clone->globalSearchHandler=\Closure::fromCallable($handler);
		$clone->globalSearchable=true;
		return $clone;
	}

	/**
	 * Registers a callback for deriving global search result titles.
	 *
	 * The resolver receives record context during search result formatting and lets resources avoid exposing raw model fields as titles.
	 *
	 * @param callable $resolver Callback that returns a result title for a record.
	 * @return self Cloned resource definition with the global search title using callback registered.
	 */
	public function globalSearchTitleUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->globalSearchTitleResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Registers a callback for deriving global search result subtitles.
	 *
	 * The resolver receives record context during search result formatting and can add secondary text without changing stored record data.
	 *
	 * @param callable $resolver Callback that returns a result subtitle for a record.
	 * @return self Cloned resource definition with the global search subtitle using callback registered.
	 */
	public function globalSearchSubtitleUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->globalSearchSubtitleResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Registers the activity callback for this resource.
	 *
	 * @param callable $handler Callback that returns record activity entries for detail and audit surfaces.
	 * @return self Cloned resource definition with the activity using callback registered.
	 */
	public function activityUsing(callable $handler): self {
		$clone=clone $this;
		$clone->activityHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the insights callback for this resource.
	 *
	 * @param callable $handler Callback that returns insight cards or metrics for the resource surface.
	 * @return self Cloned resource definition with the insights using callback registered.
	 */
	public function insightsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->insightsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record insights callback for this resource.
	 *
	 * @param callable $handler Callback that returns insight cards or metrics for a record surface.
	 * @return self Cloned resource definition with the record insights using callback registered.
	 */
	public function recordInsightsUsing(callable $handler): self {
		return $this->insightsUsing($handler);
	}

	/**
	 * Registers the alerts callback for this resource.
	 *
	 * @param callable $handler Callback that returns alert rows for the resource surface.
	 * @return self Cloned resource definition with the alerts using callback registered.
	 */
	public function alertsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->alertsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record alerts callback for this resource.
	 *
	 * @param callable $handler Callback that returns alert rows for a record surface.
	 * @return self Cloned resource definition with the record alerts using callback registered.
	 */
	public function recordAlertsUsing(callable $handler): self {
		return $this->alertsUsing($handler);
	}

	/**
	 * Registers the links callback for this resource.
	 *
	 * @param callable $handler Callback that returns related links for the resource or record.
	 * @return self Cloned resource definition with the links using callback registered.
	 */
	public function linksUsing(callable $handler): self {
		$clone=clone $this;
		$clone->linksHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record links callback for this resource.
	 *
	 * @param callable $handler Callback that returns related links for a record.
	 * @return self Cloned resource definition with the record links using callback registered.
	 */
	public function recordLinksUsing(callable $handler): self {
		return $this->linksUsing($handler);
	}

	/**
	 * Registers the contacts callback for this resource.
	 *
	 * @param callable $handler Callback that returns contact rows for the resource or record.
	 * @return self Cloned resource definition with the contacts using callback registered.
	 */
	public function contactsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->contactsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record contacts callback for this resource.
	 *
	 * @param callable $handler Callback that returns contact rows for a record.
	 * @return self Cloned resource definition with the record contacts using callback registered.
	 */
	public function recordContactsUsing(callable $handler): self {
		return $this->contactsUsing($handler);
	}

	/**
	 * Registers the locations callback for this resource.
	 *
	 * @param callable $handler Callback that returns location rows or map data for the resource.
	 * @return self Cloned resource definition with the locations using callback registered.
	 */
	public function locationsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->locationsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record locations callback for this resource.
	 *
	 * @param callable $handler Callback that returns location rows or map data for a record.
	 * @return self Cloned resource definition with the record locations using callback registered.
	 */
	public function recordLocationsUsing(callable $handler): self {
		return $this->locationsUsing($handler);
	}

	/**
	 * Registers the changes callback for this resource.
	 *
	 * @param callable $handler Callback that returns change-history rows for the resource.
	 * @return self Cloned resource definition with the changes using callback registered.
	 */
	public function changesUsing(callable $handler): self {
		$clone=clone $this;
		$clone->changesHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record changes callback for this resource.
	 *
	 * @param callable $handler Callback that returns change-history rows for a record.
	 * @return self Cloned resource definition with the record changes using callback registered.
	 */
	public function recordChangesUsing(callable $handler): self {
		return $this->changesUsing($handler);
	}

	/**
	 * Registers the tags callback for this resource.
	 *
	 * @param callable $handler Callback that returns tag values or tag metadata for the resource.
	 * @return self Cloned resource definition with the tags using callback registered.
	 */
	public function tagsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->tagsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record tags callback for this resource.
	 *
	 * @param callable $handler Callback that returns tag values or tag metadata for a record.
	 * @return self Cloned resource definition with the record tags using callback registered.
	 */
	public function recordTagsUsing(callable $handler): self {
		return $this->tagsUsing($handler);
	}

	/**
	 * Registers the tag callback for this resource.
	 *
	 * @param callable $handler Callback that applies a tag mutation to a record.
	 * @return self Cloned resource definition with the tag using callback registered.
	 */
	public function tagUsing(callable $handler): self {
		$clone=clone $this;
		$clone->tagHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the update tag callback for this resource.
	 *
	 * @param callable $handler Callback that applies a tag update to a record.
	 * @return self Cloned resource definition with the update tag using callback registered.
	 */
	public function updateTagUsing(callable $handler): self {
		return $this->tagUsing($handler);
	}

	/**
	 * Registers the items callback for this resource.
	 *
	 * @param callable $handler Callback that returns item rows for the resource or record.
	 * @return self Cloned resource definition with the items using callback registered.
	 */
	public function itemsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->itemsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record items callback for this resource.
	 *
	 * @param callable $handler Callback that returns item rows for a record.
	 * @return self Cloned resource definition with the record items using callback registered.
	 */
	public function recordItemsUsing(callable $handler): self {
		return $this->itemsUsing($handler);
	}

	/**
	 * Registers the totals callback for this resource.
	 *
	 * @param callable $handler Callback that returns aggregate totals for the resource.
	 * @return self Cloned resource definition with the totals using callback registered.
	 */
	public function totalsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->totalsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record totals callback for this resource.
	 *
	 * @param callable $handler Callback that returns aggregate totals for a record.
	 * @return self Cloned resource definition with the record totals using callback registered.
	 */
	public function recordTotalsUsing(callable $handler): self {
		return $this->totalsUsing($handler);
	}

	/**
	 * Registers the approvals callback for this resource.
	 *
	 * @param callable $handler Callback that returns approval rows for the resource or record.
	 * @return self Cloned resource definition with the approvals using callback registered.
	 */
	public function approvalsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->approvalsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record approvals callback for this resource.
	 *
	 * @param callable $handler Callback that returns approval rows for a record.
	 * @return self Cloned resource definition with the record approvals using callback registered.
	 */
	public function recordApprovalsUsing(callable $handler): self {
		return $this->approvalsUsing($handler);
	}

	/**
	 * Registers the approval callback for this resource.
	 *
	 * @param callable $handler Callback that resolves or mutates one approval request.
	 * @return self Cloned resource definition with the approval using callback registered.
	 */
	public function approvalUsing(callable $handler): self {
		$clone=clone $this;
		$clone->approvalHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the resolve approval callback for this resource.
	 *
	 * @param callable $handler Callback that resolves one approval request.
	 * @return self Cloned resource definition with the resolve approval using callback registered.
	 */
	public function resolveApprovalUsing(callable $handler): self {
		return $this->approvalUsing($handler);
	}

	/**
	 * Registers the notes callback for this resource.
	 *
	 * @param callable $handler Callback that returns note rows for the resource or record.
	 * @return self Cloned resource definition with the notes using callback registered.
	 */
	public function notesUsing(callable $handler): self {
		$clone=clone $this;
		$clone->notesHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the note callback for this resource.
	 *
	 * @param callable $handler Callback that creates or updates one note.
	 * @return self Cloned resource definition with the note using callback registered.
	 */
	public function noteUsing(callable $handler): self {
		$clone=clone $this;
		$clone->noteHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the add note callback for this resource.
	 *
	 * @param callable $handler Callback that creates one note for a record.
	 * @return self Cloned resource definition with the add note using callback registered.
	 */
	public function addNoteUsing(callable $handler): self {
		return $this->noteUsing($handler);
	}

	/**
	 * Registers the messages callback for this resource.
	 *
	 * @param callable $handler Callback that returns message rows for the resource or record.
	 * @return self Cloned resource definition with the messages using callback registered.
	 */
	public function messagesUsing(callable $handler): self {
		$clone=clone $this;
		$clone->messagesHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record messages callback for this resource.
	 *
	 * @param callable $handler Callback that returns message rows for a record.
	 * @return self Cloned resource definition with the record messages using callback registered.
	 */
	public function recordMessagesUsing(callable $handler): self {
		return $this->messagesUsing($handler);
	}

	/**
	 * Registers the message callback for this resource.
	 *
	 * @param callable $handler Callback that creates or updates one message.
	 * @return self Cloned resource definition with the message using callback registered.
	 */
	public function messageUsing(callable $handler): self {
		$clone=clone $this;
		$clone->messageHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the send message callback for this resource.
	 *
	 * @param callable $handler Callback that sends one message for a record.
	 * @return self Cloned resource definition with the send message using callback registered.
	 */
	public function sendMessageUsing(callable $handler): self {
		return $this->messageUsing($handler);
	}

	/**
	 * Registers the shipments callback for this resource.
	 *
	 * @param callable $handler Callback that returns shipment rows for the resource or record.
	 * @return self Cloned resource definition with the shipments using callback registered.
	 */
	public function shipmentsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->shipmentsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record shipments callback for this resource.
	 *
	 * @param callable $handler Callback that returns shipment rows for a record.
	 * @return self Cloned resource definition with the record shipments using callback registered.
	 */
	public function recordShipmentsUsing(callable $handler): self {
		return $this->shipmentsUsing($handler);
	}

	/**
	 * Registers the payments callback for this resource.
	 *
	 * @param callable $handler Callback that returns payment rows for the resource or record.
	 * @return self Cloned resource definition with the payments using callback registered.
	 */
	public function paymentsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->paymentsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the record payments callback for this resource.
	 *
	 * @param callable $handler Callback that returns payment rows for a record.
	 * @return self Cloned resource definition with the record payments using callback registered.
	 */
	public function recordPaymentsUsing(callable $handler): self {
		return $this->paymentsUsing($handler);
	}

	/**
	 * Registers the attachments callback for this resource.
	 *
	 * @param callable $handler Callback that returns attachment rows for the resource or record.
	 * @return self Cloned resource definition with the attachments using callback registered.
	 */
	public function attachmentsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->attachmentsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the attach callback for this resource.
	 *
	 * @param callable $handler Callback that attaches an uploaded file or attachment record.
	 * @return self Cloned resource definition with the attach using callback registered.
	 */
	public function attachUsing(callable $handler): self {
		$clone=clone $this;
		$clone->attachHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the upload attachment callback for this resource.
	 *
	 * @param callable $handler Callback that persists an uploaded attachment.
	 * @return self Cloned resource definition with the upload attachment using callback registered.
	 */
	public function uploadAttachmentUsing(callable $handler): self {
		return $this->attachUsing($handler);
	}

	/**
	 * Registers the tasks callback for this resource.
	 *
	 * @param callable $handler Callback that returns task rows for the resource or record.
	 * @return self Cloned resource definition with the tasks using callback registered.
	 */
	public function tasksUsing(callable $handler): self {
		$clone=clone $this;
		$clone->tasksHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the task callback for this resource.
	 *
	 * @param callable $handler Callback that updates one task.
	 * @return self Cloned resource definition with the task using callback registered.
	 */
	public function taskUsing(callable $handler): self {
		$clone=clone $this;
		$clone->taskHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the update task callback for this resource.
	 *
	 * @param callable $handler Callback that updates one task for a record.
	 * @return self Cloned resource definition with the update task using callback registered.
	 */
	public function updateTaskUsing(callable $handler): self {
		return $this->taskUsing($handler);
	}

	/**
	 * Registers the create task callback for this resource.
	 *
	 * @param callable $handler Callback that creates one task for a record.
	 * @return self Cloned resource definition with the create task using callback registered.
	 */
	public function createTaskUsing(callable $handler): self {
		$clone=clone $this;
		$clone->createTaskHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the add task callback for this resource.
	 *
	 * @param callable $handler Callback that creates one task for a record.
	 * @return self Cloned resource definition with the add task using callback registered.
	 */
	public function addTaskUsing(callable $handler): self {
		return $this->createTaskUsing($handler);
	}

	/**
	 * Updates the authorize setting for this resource.
	 *
	 * The callback becomes the resource authorization boundary for abilities, records, users, and the resource instance.
	 *
	 * @param callable $authorizer Callback that returns whether an ability is allowed.
	 * @return self Cloned resource definition with updated authorize metadata.
	 */
	public function authorize(callable $authorizer): self {
		$clone=clone $this;
		$clone->authorizer=\Closure::fromCallable($authorizer);
		return $clone;
	}

	/**
	 * Enables tenant scoping on a normalized record field.
	 *
	 * Tenant metadata is used by Panel pages and actions to filter queries and validate tenant context before records are shown or mutated.
	 *
	 * @param string $field Tenant key field on resource records.
	 * @param bool $required Whether missing tenant context should block scoped operations.
	 * @return self Cloned resource definition with updated tenant scoped metadata.
	 */
	public function tenantScoped(string $field='tenant_id', bool $required=true): self {
		$clone=clone $this;
		$field=self::normalizeName($field);
		$clone->tenantField=$field!=='' ? $field : 'tenant_id';
		$clone->tenantRequired=$required;
		return $clone;
	}

	/**
	 * Disables tenant scoping and clears tenant callbacks.
	 *
	 * This removes the field, resolver, and custom scope callback so later manifests and query builders treat the resource as unscoped.
	 * @return self Cloned resource definition with updated without tenant scope metadata.
	 */
	public function withoutTenantScope(): self {
		$clone=clone $this;
		$clone->tenantField=null;
		$clone->tenantResolver=null;
		$clone->tenantScope=null;
		return $clone;
	}

	/**
	 * Registers a callback for resolving the current tenant value.
	 *
	 * The resolver is invoked by Panel runtime code when it needs tenant context for query filtering or mutation authorization.
	 *
	 * @param callable $resolver Callback that returns the current tenant identifier.
	 * @return self Cloned resource definition with the tenant using callback registered.
	 */
	public function tenantUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->tenantResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Registers a callback for applying tenant constraints to a query.
	 *
	 * Custom scope callbacks let resources apply tenant filtering to non-standard query builders while keeping tenant enforcement centralized.
	 *
	 * @param callable $scope Callback that receives query and tenant context.
	 * @return self Cloned resource definition with the tenant scope using callback registered.
	 */
	public function tenantScopeUsing(callable $scope): self {
		$clone=clone $this;
		$clone->tenantScope=\Closure::fromCallable($scope);
		return $clone;
	}

	/**
	 * Toggles whether tenant context is mandatory for scoped operations.
	 *
	 * Required tenant scopes can fail closed before records are queried or mutated when no tenant can be resolved.
	 *
	 * @param bool $required Whether tenant context is mandatory.
	 * @return self Cloned resource definition with updated tenant required metadata.
	 */
	public function tenantRequired(bool $required=true): self {
		$clone=clone $this;
		$clone->tenantRequired=$required;
		return $clone;
	}

	/**
	 * Updates the policy setting for this resource.
	 *
	 * String policies are validated as loadable classes immediately. Arrays and objects are deferred to authorizePolicy(), which resolves ability-specific methods at authorization time.
	 *
	 * @param array|object|string $policy Policy class, instance, or ability map.
	 * @return self Cloned resource definition with updated policy metadata.
	 */
	public function policy(array|object|string $policy): self {
		if(is_string($policy)){
			$policy=trim($policy);
			if($policy==='' || !class_exists($policy)){
				throw new \InvalidArgumentException('Panel resource policy class not found.');
			}
		}
		$clone=clone $this;
		$clone->authorizer=function(string $ability, mixed $record, mixed $user, Resource $resource) use ($policy): bool {
			return self::authorizePolicy($policy, $ability, $record, $user, $resource);
		};
		return $clone;
	}

	/**
	 * Checks the configured authorizer for a resource ability.
	 *
	 * @param string $ability Ability name requested by a Panel page, action, or relation manager.
	 * @param mixed $record Optional record involved in the authorization decision.
	 * @param mixed $user Optional current user or actor context.
	 * @return bool True when no authorizer is configured or an ability candidate is allowed.
	 */
	public function can(string $ability, mixed $record=null, mixed $user=null): bool {
		if($this->authorizer===null){
			return true;
		}
		foreach(self::abilityCandidates($ability) as $candidate){
			try{
				if((bool)($this->authorizer)($candidate, $record, $user, $this)){
					PanelTrace::record('resource.authorized', [
						'resource'=>$this->name,
						'ability'=>$ability,
						'matched_ability'=>$candidate,
						'allowed'=>true,
					]);
					return true;
				}
			}
			catch(\Throwable $exception){
				PanelTrace::record('resource.authorizer_error', [
					'resource'=>$this->name,
					'ability'=>$candidate,
					'message'=>$exception->getMessage(),
				]);
				return false;
			}
		}
		PanelTrace::record('resource.authorized', [
			'resource'=>$this->name,
			'ability'=>$ability,
			'allowed'=>false,
		]);
		return false;
	}

	/**
	 * Evaluates a policy definition for one resource ability.
	 *
	 * Policies may be class names, policy objects, or arrays keyed by ability
	 * aliases. Callable array entries receive record, user, resource, and matched
	 * ability; object policies use camel-cased method names before falling back to
	 * a user can() method when available.
	 *
	 * @param array<string,mixed>|object|string $policy Policy class, object, or ability map.
	 * @param string $ability Requested resource ability.
	 * @param mixed $record Record under authorization, when applicable.
	 * @param mixed $user Current user or actor.
	 * @param Resource $resource Resource being checked.
	 * @return bool Whether the policy allows the ability.
	 */
	private static function authorizePolicy(array|object|string $policy, string $ability, mixed $record, mixed $user, Resource $resource): bool {
		if(is_string($policy)){
			$policy=new $policy();
		}
		foreach(self::abilityCandidates($ability) as $candidate){
			if(is_array($policy)){
				foreach(self::policyArrayKeys($candidate) as $key){
					if(!array_key_exists($key, $policy)){
						continue;
					}
					$value=$policy[$key];
					return is_callable($value) ? (bool)$value($record, $user, $resource, $candidate) : (bool)$value;
				}
				continue;
			}
			foreach(self::policyMethodNames($candidate) as $method){
				if(method_exists($policy, $method)){
					return (bool)$policy->{$method}($record, $user, $resource, $candidate);
				}
			}
		}
		if(is_array($policy)){
			return true;
		}
		if(is_object($user) && method_exists($user, 'can')){
			foreach(self::abilityCandidates($ability) as $candidate){
				if((bool)$user->can($candidate, $record, $resource)){
					return true;
				}
			}
			return false;
		}
		return true;
	}

	/**
	 * Expands an ability name into equivalent policy lookup candidates.
	 *
	 * Panel accepts route-style, framework-style, and bulk-operation ability names;
	 * this helper normalizes punctuation and adds common aliases such as index,
	 * view_any, update, edit, delete, and destroy.
	 *
	 * @param string $ability Requested ability name.
	 * @return array<int,string> Candidate ability keys in lookup order.
	 */
	private static function abilityCandidates(string $ability): array {
		$ability=self::normalizeName(str_replace(':', '.', $ability));
		$aliases=[
			'index'=>['view_any', 'viewAny', 'list'],
			'view_any'=>['index', 'viewAny', 'list'],
			'viewany'=>['view_any', 'index', 'list'],
			'list'=>['index', 'view_any', 'viewAny'],
			'show'=>['view'],
			'view'=>['show'],
			'create'=>['store'],
			'store'=>['create'],
			'edit'=>['update'],
			'update'=>['edit'],
			'delete'=>['destroy'],
			'destroy'=>['delete'],
			'force_delete'=>['forceDelete', 'force-delete'],
			'forcedelete'=>['force_delete', 'forceDelete'],
			'bulk_export'=>['export_any', 'exportAny', 'export'],
			'export_any'=>['bulk_export', 'exportAny', 'export'],
			'bulk_update'=>['update_any', 'updateAny', 'update'],
			'bulk_delete'=>['delete_any', 'deleteAny', 'delete'],
			'bulk_duplicate'=>['duplicate_any', 'duplicateAny', 'duplicate'],
			'bulk_restore'=>['restore_any', 'restoreAny', 'restore'],
			'bulk_force_delete'=>['force_delete_any', 'forceDeleteAny', 'force_delete'],
		];
		$candidates=[$ability];
		$key=str_replace('.', '_', $ability);
		foreach($aliases[$ability] ?? $aliases[$key] ?? [] as $alias){
			$candidates[]=self::normalizeName(str_replace(':', '.', $alias));
		}
		if(str_contains($ability, '.')){
			$candidates[]=strtok($ability, '.');
		}
		return array_values(array_unique(array_filter($candidates, static fn(string $candidate): bool => $candidate!=='')));
	}

	/**
	 * Builds possible array keys for a policy ability.
	 *
	 * Array policies may use dot, colon, snake, camel, or base wildcard keys, so
	 * this preserves compatibility with compact configuration manifests.
	 *
	 * @param string $ability Normalized ability candidate.
	 * @return array<int,string> Candidate array keys.
	 */
	private static function policyArrayKeys(string $ability): array {
		$keys=[$ability, str_replace('.', ':', $ability), str_replace('.', '_', $ability), self::camelAbility($ability)];
		if(str_contains($ability, '.')){
			$base=strtok($ability, '.');
			$keys[]=$base.':*';
			$keys[]=$base.'.*';
		}
		return array_values(array_unique($keys));
	}

	/**
	 * Builds possible object method names for a policy ability.
	 *
	 * Dot-scoped abilities check both the full camel-cased method and the base
	 * ability method, letting policies authorize whole ability groups.
	 *
	 * @param string $ability Normalized ability candidate.
	 * @return array<int,string> Candidate policy method names.
	 */
	private static function policyMethodNames(string $ability): array {
		$camel=self::camelAbility($ability);
		$methods=[$camel];
		if(str_contains($ability, '.')){
			$methods[]=self::camelAbility(strtok($ability, '.'));
		}
		return array_values(array_unique($methods));
	}

	/**
	 * Converts an ability token into lower camelCase method form.
	 *
	 * Dots, underscores, colons, and hyphens are treated as word separators so
	 * policy names remain stable across route and manifest naming styles.
	 *
	 * @param string $ability Ability key.
	 * @return string Camel-cased ability method name.
	 */
	private static function camelAbility(string $ability): string {
		$parts=preg_split('/[._:-]+/', $ability) ?: [];
		$first=array_shift($parts);
		return (string)$first.implode('', array_map(static fn(string $part): string => ucfirst($part), $parts));
	}

	/**
	 * Registers the query callback for this resource.
	 *
	 * @param callable $queryFactory Callback that returns or customizes the query source used for resource records.
	 * @return self Cloned resource definition with the query using callback registered.
	 */
	public function queryUsing(callable $queryFactory): self {
		$clone=clone $this;
		$clone->queryFactory=\Closure::fromCallable($queryFactory);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $saveHandler Callback that persists validated resource form data.
	 * @return self Cloned resource definition with the save using callback registered.
	 */
	public function saveUsing(callable $saveHandler): self {
		$clone=clone $this;
		$clone->saveHandler=\Closure::fromCallable($saveHandler);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $mutator Callback that normalizes submitted form data before validation or persistence.
	 * @return self Cloned resource definition with the mutate form data using callback registered.
	 */
	public function mutateFormDataUsing(callable $mutator): self {
		$clone=clone $this;
		$clone->formDataMutator=\Closure::fromCallable($mutator);
		return $clone;
	}

	/**
	 * Registers the mutate create data callback for this resource.
	 *
	 * @param callable $mutator Callback that normalizes create data before persistence.
	 * @return self Cloned resource definition with the mutate create data using callback registered.
	 */
	public function mutateCreateDataUsing(callable $mutator): self {
		$clone=clone $this;
		$clone->createDataMutator=\Closure::fromCallable($mutator);
		return $clone;
	}

	/**
	 * Registers the mutate update data callback for this resource.
	 *
	 * @param callable $mutator Callback that normalizes update data before persistence.
	 * @return self Cloned resource definition with the mutate update data using callback registered.
	 */
	public function mutateUpdateDataUsing(callable $mutator): self {
		$clone=clone $this;
		$clone->updateDataMutator=\Closure::fromCallable($mutator);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $mutator Callback that normalizes record data before filling the form.
	 * @return self Cloned resource definition with the mutate fill data using callback registered.
	 */
	public function mutateFillDataUsing(callable $mutator): self {
		$clone=clone $this;
		$clone->fillDataMutator=\Closure::fromCallable($mutator);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $mutator Callback that normalizes record data before filling the form.
	 * @return self Cloned resource definition with the mutate form data before fill using callback registered.
	 */
	public function mutateFormDataBeforeFillUsing(callable $mutator): self {
		return $this->mutateFillDataUsing($mutator);
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $mutator Callback that normalizes create-form fill data.
	 * @return self Cloned resource definition with the mutate create fill data using callback registered.
	 */
	public function mutateCreateFillDataUsing(callable $mutator): self {
		$clone=clone $this;
		$clone->createFillDataMutator=\Closure::fromCallable($mutator);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $mutator Callback that normalizes create-form fill data before controls are populated.
	 * @return self Cloned resource definition with the mutate create form data before fill using callback registered.
	 */
	public function mutateCreateFormDataBeforeFillUsing(callable $mutator): self {
		return $this->mutateCreateFillDataUsing($mutator);
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $mutator Callback that normalizes edit-form fill data.
	 * @return self Cloned resource definition with the mutate edit fill data using callback registered.
	 */
	public function mutateEditFillDataUsing(callable $mutator): self {
		$clone=clone $this;
		$clone->editFillDataMutator=\Closure::fromCallable($mutator);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $mutator Callback that normalizes edit-form fill data before controls are populated.
	 * @return self Cloned resource definition with the mutate edit form data before fill using callback registered.
	 */
	public function mutateEditFormDataBeforeFillUsing(callable $mutator): self {
		return $this->mutateEditFillDataUsing($mutator);
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $handler Lifecycle callback invoked before form fill data is applied.
	 * @return self Cloned resource definition with the before fill using callback registered.
	 */
	public function beforeFillUsing(callable $handler): self {
		$clone=clone $this;
		$clone->beforeFillHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $handler Lifecycle callback invoked after form fill data is prepared.
	 * @return self Cloned resource definition with the after fill using callback registered.
	 */
	public function afterFillUsing(callable $handler): self {
		$clone=clone $this;
		$clone->afterFillHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the before validate callback for this resource.
	 *
	 * @param callable $handler Lifecycle callback invoked before form data validation.
	 * @return self Cloned resource definition with the before validate using callback registered.
	 */
	public function beforeValidateUsing(callable $handler): self {
		$clone=clone $this;
		$clone->beforeValidateHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the after validate callback for this resource.
	 *
	 * @param callable $handler Lifecycle callback invoked after form data validation.
	 * @return self Cloned resource definition with the after validate using callback registered.
	 */
	public function afterValidateUsing(callable $handler): self {
		$clone=clone $this;
		$clone->afterValidateHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $handler Lifecycle callback invoked before record persistence.
	 * @return self Cloned resource definition with the before save using callback registered.
	 */
	public function beforeSaveUsing(callable $handler): self {
		$clone=clone $this;
		$clone->beforeSaveHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param callable $handler Lifecycle callback invoked after record persistence.
	 * @return self Cloned resource definition with the after save using callback registered.
	 */
	public function afterSaveUsing(callable $handler): self {
		$clone=clone $this;
		$clone->afterSaveHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Registers the import callback for this resource.
	 *
	 * @param callable $importHandler Callback that imports resource rows from uploaded or parsed input.
	 * @return self Cloned resource definition with the import using callback registered.
	 */
	public function importUsing(callable $importHandler): self {
		$clone=clone $this;
		$clone->importHandler=\Closure::fromCallable($importHandler);
		return $clone;
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 *
	 * @param callable $transitionHandler Callback that applies a status transition to a record.
	 * @return self Cloned resource definition with the transition using callback registered.
	 */
	public function transitionUsing(callable $transitionHandler): self {
		$clone=clone $this;
		$clone->transitionHandler=\Closure::fromCallable($transitionHandler);
		return $clone;
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param callable $bulkUpdateHandler Callback that applies bulk updates to selected records.
	 * @return self Cloned resource definition with the bulk update using callback registered.
	 */
	public function bulkUpdateUsing(callable $bulkUpdateHandler): self {
		$clone=clone $this;
		$clone->bulkUpdateHandler=\Closure::fromCallable($bulkUpdateHandler);
		return $clone;
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 *
	 * @param callable $duplicateHandler Callback that duplicates one resource record.
	 * @return self Cloned resource definition with the duplicate using callback registered.
	 */
	public function duplicateUsing(callable $duplicateHandler): self {
		$clone=clone $this;
		$clone->duplicateHandler=\Closure::fromCallable($duplicateHandler);
		return $clone;
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 *
	 * @param callable $restoreHandler Callback that restores one soft-deleted resource record.
	 * @return self Cloned resource definition with the restore using callback registered.
	 */
	public function restoreUsing(callable $restoreHandler): self {
		$clone=clone $this;
		$clone->restoreHandler=\Closure::fromCallable($restoreHandler);
		return $clone;
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 *
	 * @param callable $deleteHandler Callback that deletes one resource record.
	 * @return self Cloned resource definition with the delete using callback registered.
	 */
	public function deleteUsing(callable $deleteHandler): self {
		$clone=clone $this;
		$clone->deleteHandler=\Closure::fromCallable($deleteHandler);
		return $clone;
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 *
	 * @param callable $forceDeleteHandler Callback that permanently deletes one resource record.
	 * @return self Cloned resource definition with the force delete using callback registered.
	 */
	public function forceDeleteUsing(callable $forceDeleteHandler): self {
		$clone=clone $this;
		$clone->forceDeleteHandler=\Closure::fromCallable($forceDeleteHandler);
		return $clone;
	}

	/**
	 * Registers the record key callback for this resource.
	 *
	 * @param callable|string $resolver Callback or field name used to derive the stable record key.
	 * @return self Cloned resource definition with the record key using callback registered.
	 */
	public function recordKeyUsing(callable|string $resolver): self {
		$clone=clone $this;
		$clone->recordKeyResolver=is_string($resolver)
			? static fn(mixed $record): string => (string)self::recordValue($record, self::normalizeName($resolver), '')
			: \Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Registers the record title callback for this resource.
	 *
	 * @param callable|string $resolver Callback or field name used to derive the record subtitle.
	 * @return self Cloned resource definition with the record title using callback registered.
	 */
	public function recordTitleUsing(callable|string $resolver): self {
		$clone=clone $this;
		$clone->recordTitleResolver=is_string($resolver)
			? static fn(mixed $record): string => (string)self::recordValue($record, self::normalizeName($resolver), '')
			: \Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Registers the record subtitle callback for this resource.
	 *
	 * @param callable|string $resolver Resolver.
	 * @return self Cloned resource definition with the record subtitle using callback registered.
	 */
	public function recordSubtitleUsing(callable|string $resolver): self {
		$clone=clone $this;
		$clone->recordSubtitleResolver=is_string($resolver)
			? static fn(mixed $record): string => (string)self::recordValue($record, self::normalizeName($resolver), '')
			: \Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Registers the record url callback for this resource.
	 *
	 * @param callable $resolver Callback that returns the record detail URL.
	 * @return self Cloned resource definition with the record url using callback registered.
	 */
	public function recordUrlUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->recordUrlResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Reports whether this resource has activity.
	 *
	 * @return bool True when activity handlers or state are configured.
	 */
	public function hasActivity(): bool {
		return $this->activityHandler!==null;
	}

	/**
	 * Reports whether this resource has insights.
	 *
	 * @return bool True when insights handlers or state are configured.
	 */
	public function hasInsights(): bool {
		return $this->insightsHandler!==null;
	}

	/**
	 * Reports whether this resource has alerts.
	 *
	 * @return bool True when alerts handlers or state are configured.
	 */
	public function hasAlerts(): bool {
		return $this->alertsHandler!==null;
	}

	/**
	 * Reports whether this resource has links.
	 *
	 * @return bool True when links handlers or state are configured.
	 */
	public function hasLinks(): bool {
		return $this->linksHandler!==null;
	}

	/**
	 * Reports whether this resource has contacts.
	 *
	 * @return bool True when contacts handlers or state are configured.
	 */
	public function hasContacts(): bool {
		return $this->contactsHandler!==null;
	}

	/**
	 * Reports whether this resource has locations.
	 *
	 * @return bool True when locations handlers or state are configured.
	 */
	public function hasLocations(): bool {
		return $this->locationsHandler!==null;
	}

	/**
	 * Reports whether this resource has changes.
	 *
	 * @return bool True when changes handlers or state are configured.
	 */
	public function hasChanges(): bool {
		return $this->changesHandler!==null;
	}

	/**
	 * Reports whether this resource has tags.
	 *
	 * @return bool True when tags handlers or state are configured.
	 */
	public function hasTags(): bool {
		return $this->tagsHandler!==null || $this->tagHandler!==null;
	}

	/**
	 * Reports whether this resource can update tag.
	 *
	 * @return bool True when the update tag handler or permission path is available.
	 */
	public function canUpdateTag(): bool {
		return $this->tagHandler!==null;
	}

	/**
	 * Reports whether this resource has items.
	 *
	 * @return bool True when items handlers or state are configured.
	 */
	public function hasItems(): bool {
		return $this->itemsHandler!==null;
	}

	/**
	 * Reports whether this resource has totals.
	 *
	 * @return bool True when totals handlers or state are configured.
	 */
	public function hasTotals(): bool {
		return $this->totalsHandler!==null;
	}

	/**
	 * Reports whether this resource has approvals.
	 *
	 * @return bool True when approvals handlers or state are configured.
	 */
	public function hasApprovals(): bool {
		return $this->approvalsHandler!==null || $this->approvalHandler!==null;
	}

	/**
	 * Reports whether this resource can resolve approval.
	 *
	 * @return bool True when the resolve approval handler or permission path is available.
	 */
	public function canResolveApproval(): bool {
		return $this->approvalHandler!==null;
	}

	/**
	 * Reports whether this resource has notes.
	 *
	 * @return bool True when notes handlers or state are configured.
	 */
	public function hasNotes(): bool {
		return $this->notesHandler!==null || $this->noteHandler!==null;
	}

	/**
	 * Reports whether this resource can add note.
	 *
	 * @return bool True when the add note handler or permission path is available.
	 */
	public function canAddNote(): bool {
		return $this->noteHandler!==null;
	}

	/**
	 * Reports whether this resource has messages.
	 *
	 * @return bool True when messages handlers or state are configured.
	 */
	public function hasMessages(): bool {
		return $this->messagesHandler!==null || $this->messageHandler!==null;
	}

	/**
	 * Reports whether this resource can send message.
	 *
	 * @return bool True when the send message handler or permission path is available.
	 */
	public function canSendMessage(): bool {
		return $this->messageHandler!==null;
	}

	/**
	 * Reports whether this resource has shipments.
	 *
	 * @return bool True when shipments handlers or state are configured.
	 */
	public function hasShipments(): bool {
		return $this->shipmentsHandler!==null;
	}

	/**
	 * Reports whether this resource has payments.
	 *
	 * @return bool True when payments handlers or state are configured.
	 */
	public function hasPayments(): bool {
		return $this->paymentsHandler!==null;
	}

	/**
	 * Reports whether this resource has attachments.
	 *
	 * @return bool True when attachments handlers or state are configured.
	 */
	public function hasAttachments(): bool {
		return $this->attachmentsHandler!==null || $this->attachHandler!==null;
	}

	/**
	 * Reports whether this resource can attach.
	 *
	 * @return bool True when the attach handler or permission path is available.
	 */
	public function canAttach(): bool {
		return $this->attachHandler!==null;
	}

	/**
	 * Reports whether this resource has tasks.
	 *
	 * @return bool True when tasks handlers or state are configured.
	 */
	public function hasTasks(): bool {
		return $this->tasksHandler!==null || $this->taskHandler!==null || $this->createTaskHandler!==null;
	}

	/**
	 * Reports whether this resource can update task.
	 *
	 * @return bool True when the update task handler or permission path is available.
	 */
	public function canUpdateTask(): bool {
		return $this->taskHandler!==null;
	}

	/**
	 * Reports whether this resource can create task.
	 *
	 * @return bool True when the create task handler or permission path is available.
	 */
	public function canCreateTask(): bool {
		return $this->createTaskHandler!==null;
	}

	/**
	 * Updates the make query setting for this resource.
	 *
	 * @param mixed ... $arguments Arguments.
	 * @return mixed Query source from the custom factory, repository, table builder, or empty fallback.
	 */
	public function makeQuery(mixed ...$arguments): mixed {
		$source=null;
		if($this->queryFactory!==null){
			$source=($this->queryFactory)(...$arguments);
		}
		elseif($this->repository!==null && class_exists($this->repository) && method_exists($this->repository, 'query')){
			$source=$this->repository::query();
		}
		elseif($this->table!==null && class_exists('\Dataphyre\Database\DB')){
			$source=\Dataphyre\Database\DB::table($this->table);
		}
		return $this->applyTenantScope($source, $arguments[0] ?? null);
	}

	/**
	 * Applies this resource's tenant boundary to a query source or record list.
	 *
	 * Custom tenant scopes receive the original source, resolved tenant key, tenant
	 * field, request, and resource. Array sources are filtered in memory, query
	 * objects with where() are constrained, and missing required tenants collapse
	 * array sources to an empty result set.
	 *
	 * @param mixed $source Query builder, repository result, array records, or null.
	 * @param mixed $request Optional Panel request or caller context.
	 * @return mixed Source constrained to the resolved tenant, an empty array for missing required tenants, or the original source when no tenant boundary applies.
	 */
	private function applyTenantScope(mixed $source, mixed $request=null): mixed {
		if($this->tenantField===null){
			return $source;
		}
		$tenant=$this->resolveTenantKey($request);
		if($tenant===null || $tenant===''){
			PanelTrace::record('resource.tenant_missing', [
				'resource'=>$this->name,
				'field'=>$this->tenantField,
				'required'=>$this->tenantRequired,
			]);
			return $this->tenantRequired && is_array($source) ? [] : $source;
		}
		if($this->tenantScope!==null){
			return ($this->tenantScope)($source, $tenant, $this->tenantField, $request, $this);
		}
		if(is_array($source)){
			$field=$this->tenantField;
			return array_values(array_filter($source, static fn(mixed $record): bool => (string)self::recordValue($record, $field, '')===(string)$tenant));
		}
		if(is_object($source) && method_exists($source, 'where')){
			$result=$source->where($this->tenantField, $tenant);
			return $result ?? $source;
		}
		return $source;
	}

	/**
	 * Resolves the tenant key for resource scoping.
	 *
	 * Resource-specific resolvers take precedence, then PanelRequest tenant state,
	 * then global Panel configuration. Blank or non-scalar resolver results are
	 * treated as no tenant.
	 *
	 * @param mixed $request Optional Panel request or caller context.
	 * @return ?string Tenant key, or null when unavailable.
	 */
	private function resolveTenantKey(mixed $request=null): ?string {
		if($this->tenantResolver!==null){
			$value=($this->tenantResolver)($request, $this);
			return is_scalar($value) && trim((string)$value)!=='' ? trim((string)$value) : null;
		}
		if($request instanceof PanelRequest && $request->tenantKey()!==null){
			return $request->tenantKey();
		}
		return PanelConfig::currentTenantKey();
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param array<string,mixed> $data Form data prepared for persistence.
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $mode Resource operation mode such as create, edit, view, or index.
	 * @param mixed $request Panel request payload or framework request object.
	 * @param bool $alreadyMutated Whether form data has already passed mutation hooks.
	 * @param bool $skipLifecycle Whether resource lifecycle hooks should be skipped.
	 * @return mixed Custom save result, updated record, created record, or validated data payload.
	 */
	public function saveRecord(array $data, mixed $record=null, string $mode='store', mixed $request=null, bool $alreadyMutated=false, bool $skipLifecycle=false): mixed {
		if(!$alreadyMutated && !$this->formDataMutationActive){
			$data=$this->mutateFormData($data, $record, $mode, $request);
		}
		if(!$skipLifecycle){
			$data=$this->runBeforeSave($data, $record, $mode, $request);
		}
		if($this->saveHandler!==null){
			$result=($this->saveHandler)($data, $record, $mode, $request, $this);
			return $skipLifecycle ? $result : $this->runAfterSave($result, $data, $record, $mode, $request);
		}
		$result=[
			'saved'=>false,
			'data'=>$data,
			'message'=>'No save handler is registered for this resource.',
		];
		return $skipLifecycle ? $result : $this->runAfterSave($result, $data, $record, $mode, $request);
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param array<string,mixed> $data Form data before resource mutators run.
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $mode Resource operation mode such as create, edit, view, or index.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return array<string,mixed> Mutated form data.
	 */
	public function mutateFormData(array $data, mixed $record=null, string $mode='store', mixed $request=null): array {
		if($this->formDataMutationActive){
			return $data;
		}
		$this->formDataMutationActive=true;
		$mutators=[];
		try {
			if($this->formDataMutator!==null){
				$mutators[]=$this->formDataMutator;
			}
			if(in_array($mode, ['store', 'create', 'import'], true) && $this->createDataMutator!==null){
				$mutators[]=$this->createDataMutator;
			}
			if(in_array($mode, ['update', 'edit', 'bulk_update', 'transition'], true) && $this->updateDataMutator!==null){
				$mutators[]=$this->updateDataMutator;
			}
			foreach($mutators as $mutator){
				$result=$mutator($data, $record, $mode, $request, $this);
				if(is_array($result)){
					$data=$result;
				}
			}
		}
		finally {
			$this->formDataMutationActive=false;
		}
		return $data;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param array<string,mixed> $data Fill data before resource mutators run.
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $mode Resource operation mode such as create, edit, view, or index.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return array<string,mixed> Mutated fill data.
	 */
	public function mutateFillData(array $data, mixed $record=null, string $mode='create', mixed $request=null): array {
		$mutators=[];
		if($this->fillDataMutator!==null){
			$mutators[]=$this->fillDataMutator;
		}
		if(in_array($mode, ['create', 'store'], true) && $this->createFillDataMutator!==null){
			$mutators[]=$this->createFillDataMutator;
		}
		if(in_array($mode, ['edit', 'update'], true) && $this->editFillDataMutator!==null){
			$mutators[]=$this->editFillDataMutator;
		}
		foreach($mutators as $mutator){
			$result=$mutator($data, $record, $mode, $request, $this);
			if(is_array($result)){
				$data=$result;
			}
		}
		return $data;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param PanelFormState $state State.
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $mode Resource operation mode such as create, edit, view, or index.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return PanelFormState Mutated form state.
	 */
	public function mutateFormStateBeforeFill(PanelFormState $state, mixed $record=null, string $mode='create', mixed $request=null): PanelFormState {
		$values=$this->mutateFillData($state->values(), $record, $mode, $request);
		if($values===$state->values()){
			return $state;
		}
		return PanelFormState::make($values, $state->errors(), array_replace($state->meta(), [
			'fill_mutated'=>true,
		]));
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $mode Resource operation mode such as create, edit, view, or index.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return void
	 */
	public function runBeforeFill(mixed $record=null, string $mode='create', mixed $request=null): void {
		if($this->beforeFillHandler!==null){
			($this->beforeFillHandler)($record, $mode, $request, $this);
		}
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param PanelFormState $state State.
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $mode Resource operation mode such as create, edit, view, or index.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return PanelFormState Mutated form state.
	 */
	public function runAfterFill(PanelFormState $state, mixed $record=null, string $mode='create', mixed $request=null): PanelFormState {
		if($this->afterFillHandler===null){
			return $state;
		}
		$result=($this->afterFillHandler)($state, $record, $mode, $request, $this);
		return $result instanceof PanelFormState ? $result : $state;
	}

	/**
	 * Runs the run before validate workflow for this resource.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $mode Resource operation mode such as create, edit, view, or index.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return ?PanelLifecycleResult Lifecycle short-circuit result, or null to continue.
	 */
	public function runBeforeValidate(mixed $record=null, string $mode='store', mixed $request=null): ?PanelLifecycleResult {
		if($this->beforeValidateHandler!==null){
			$result=($this->beforeValidateHandler)($record, $mode, $request, $this);
			return $this->normalizeLifecycleResult($result);
		}
		return null;
	}

	/**
	 * Runs the run after validate workflow for this resource.
	 *
	 * @param PanelFormState $state State.
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $mode Resource operation mode such as create, edit, view, or index.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return PanelFormState|PanelLifecycleResult Mutated form state or lifecycle short-circuit result.
	 */
	public function runAfterValidate(PanelFormState $state, mixed $record=null, string $mode='store', mixed $request=null): PanelFormState|PanelLifecycleResult {
		if($this->afterValidateHandler===null){
			return $state;
		}
		$result=($this->afterValidateHandler)($state, $record, $mode, $request, $this);
		return $result instanceof PanelFormState ? $result : ($this->normalizeLifecycleResult($result) ?? $state);
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param array<string,mixed> $data Form data before save lifecycle hooks.
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $mode Resource operation mode such as create, edit, view, or index.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return array<string,mixed>|PanelLifecycleResult Data to save, or a lifecycle halt.
	 */
	public function runBeforeSave(array $data, mixed $record=null, string $mode='store', mixed $request=null): array|PanelLifecycleResult {
		if($this->beforeSaveHandler===null){
			return $data;
		}
		$result=($this->beforeSaveHandler)($data, $record, $mode, $request, $this);
		if(is_array($result)){
			return $result;
		}
		return $this->normalizeLifecycleResult($result) ?? $data;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param mixed $result Result payload returned by the resource operation.
	 * @param array<string,mixed> $data Form data passed to the save handler.
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $mode Resource operation mode such as create, edit, view, or index.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return mixed After-save callback result, or the persisted record when no result is returned.
	 */
	public function runAfterSave(mixed $result, array $data, mixed $record=null, string $mode='store', mixed $request=null): mixed {
		if($this->afterSaveHandler===null){
			return $result;
		}
		$hookResult=($this->afterSaveHandler)($result, $data, $record, $mode, $request, $this);
		return $this->normalizeLifecycleResult($hookResult) ?? $hookResult ?? $result;
	}

	/**
	 * Converts lifecycle hook return values into a halt result when applicable.
	 *
	 * Hooks can return an existing PanelLifecycleResult, false, or an array with
	 * halt/halted metadata. Other values are left to the caller as ordinary hook
	 * results.
	 *
	 * @param mixed $result Raw lifecycle hook return value.
	 * @return ?PanelLifecycleResult Normalized halt result, or null for non-halt values.
	 */
	private function normalizeLifecycleResult(mixed $result): ?PanelLifecycleResult {
		if($result instanceof PanelLifecycleResult){
			return $result;
		}
		if($result===false){
			return PanelLifecycleResult::halt('The operation was stopped by the resource lifecycle.');
		}
		if(is_array($result) && (($result['halt'] ?? false)===true || ($result['halted'] ?? false)===true)){
			$notifications=[];
			foreach(['notification', 'notifications'] as $key){
				if(!array_key_exists($key, $result)){
					continue;
				}
				$items=is_array($result[$key]) && array_is_list($result[$key]) ? $result[$key] : [$result[$key]];
				$notifications=array_merge($notifications, $items);
			}
			return PanelLifecycleResult::halt((string)($result['message'] ?? 'The operation was stopped.'), $notifications, (int)($result['status'] ?? 422), $result);
		}
		return null;
	}

	/**
	 * Runs the import records workflow for this resource.
	 *
	 * @param array<int,array<string,mixed>|mixed> $rows Import rows before per-row validation and persistence.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return mixed Import callback result, or null when imports are not configured.
	 */
	public function importRecords(array $rows, mixed $request=null): mixed {
		if($this->importHandler!==null){
			return ($this->importHandler)($rows, $request, $this);
		}
		$imported=[];
		$failed=[];
		$results=[];
		foreach($rows as $index=>$row){
			if(!is_array($row)){
				$failed[]=$index;
				continue;
			}
			$result=$this->saveRecord($row, null, 'import', $request);
			$results[$index]=$result;
			if(self::saveOutcomeSucceeded($result)){
				$imported[]=$index;
			}
			else {
				$failed[]=$index;
			}
		}
		return [
			'imported'=>$imported,
			'failed'=>$failed,
			'results'=>$results,
		];
	}

	/**
	 * Reports whether this resource can import.
	 *
	 * @return bool True when the import handler or permission path is available.
	 */
	public function canImport(): bool {
		return $this->importHandler!==null || ($this->saveHandler!==null && $this->form->fieldsList()!==[]);
	}

	/**
	 * Runs the apply transition workflow for this resource.
	 *
	 * @param string $transitionName Workflow transition name requested for the record.
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return mixed Transition callback result, or null when the transition has no handler.
	 */
	public function applyTransition(string $transitionName, mixed $record=null, mixed $request=null): mixed {
		$transition=$this->statusTransition($transitionName);
		if($transition===null){
			return [
				'transitioned'=>false,
				'message'=>'Transition is not registered for this resource.',
			];
		}
		if($this->transitionHandler!==null){
			return ($this->transitionHandler)($transition, $record, $request, $this);
		}
		if($this->saveHandler!==null){
			return $this->saveRecord([$this->statusField=>$transition['to']], $record, 'transition', $request);
		}
		return [
			'transitioned'=>false,
			'message'=>'No transition or save handler is registered for this resource.',
		];
	}

	/**
	 * Reports whether this resource can transition.
	 *
	 * @return bool True when the transition handler or permission path is available.
	 */
	public function canTransition(): bool {
		return $this->statusTransitions!==[] && ($this->transitionHandler!==null || $this->saveHandler!==null);
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param array<string,mixed> $data Bulk update form data.
	 * @param array<int,mixed> $records Records selected for bulk update.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return mixed Bulk update callback result, or null when bulk updates are not configured.
	 */
	public function bulkUpdateRecords(array $data, array $records, mixed $request=null): mixed {
		if($this->bulkUpdateHandler!==null){
			return ($this->bulkUpdateHandler)($data, $records, $request, $this);
		}
		$results=[];
		foreach($records as $record){
			$results[]=$this->saveRecord($data, $record, 'bulk_update', $request);
		}
		return [
			'updated'=>count($records),
			'results'=>$results,
		];
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 * @return bool True when the bulk update handler or permission path is available.
	 */
	public function canBulkUpdate(): bool {
		return $this->bulkUpdateForm->fieldsList()!==[] && ($this->bulkUpdateHandler!==null || $this->saveHandler!==null);
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return mixed Duplicate callback result, or null when duplication is unavailable.
	 */
	public function duplicateRecord(mixed $record=null, mixed $request=null): mixed {
		if($this->duplicateHandler!==null){
			return ($this->duplicateHandler)($record, $request, $this);
		}
		return [
			'duplicated'=>false,
			'message'=>'No duplicate handler is registered for this resource.',
		];
	}

	/**
	 * Reports whether this resource can duplicate.
	 *
	 * @return bool True when the duplicate handler or permission path is available.
	 */
	public function canDuplicate(): bool {
		return $this->duplicateHandler!==null;
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return mixed Restore callback result, or null when restore is unavailable.
	 */
	public function restoreRecord(mixed $record=null, mixed $request=null): mixed {
		if($this->restoreHandler!==null){
			return ($this->restoreHandler)($record, $request, $this);
		}
		return [
			'restored'=>false,
			'message'=>'No restore handler is registered for this resource.',
		];
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 * @return bool True when the restore handler or permission path is available.
	 */
	public function canRestore(): bool {
		return $this->restoreHandler!==null;
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return mixed Delete callback result, or null when delete is unavailable.
	 */
	public function deleteRecord(mixed $record=null, mixed $request=null): mixed {
		if($this->deleteHandler!==null){
			return ($this->deleteHandler)($record, $request, $this);
		}
		return [
			'deleted'=>false,
			'message'=>'No delete handler is registered for this resource.',
		];
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 * @return bool True when the delete handler or permission path is available.
	 */
	public function canDelete(): bool {
		return $this->deleteHandler!==null;
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param mixed $request Panel request payload or framework request object.
	 * @return mixed Force-delete callback result, or null when force delete is unavailable.
	 */
	public function forceDeleteRecord(mixed $record=null, mixed $request=null): mixed {
		if($this->forceDeleteHandler!==null){
			return ($this->forceDeleteHandler)($record, $request, $this);
		}
		return [
			'force_deleted'=>false,
			'message'=>'No force delete handler is registered for this resource.',
		];
	}

	/**
	 * Configures record mutation handlers for this resource.
	 *
	 * Mutation callbacks centralize duplicate, transition, restore, delete, force-delete, and bulk update workflows.
	 * @return bool True when the force delete handler or permission path is available.
	 */
	public function canForceDelete(): bool {
		return $this->forceDeleteHandler!==null;
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param array<int,Field|array<string,mixed>|string> $fields Bulk update form fields.
	 * @return self Cloned resource definition with updated bulk fields metadata.
	 */
	public function bulkFields(array $fields): self {
		$clone=clone $this;
		$clone->bulkUpdateForm=$clone->bulkUpdateForm->fields($fields);
		return $clone;
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param Field|array|string $field Field.
	 * @param ?string $type Type.
	 * @return self Cloned resource definition with updated bulk field metadata.
	 */
	public function bulkField(Field|array|string $field, ?string $type=null): self {
		$clone=clone $this;
		$clone->bulkUpdateForm=$clone->bulkUpdateForm->field($field, $type);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param ?ResourceForm $form Form.
	 * @return ResourceForm|self Resource form when read, or the resource after update.
	 */
	public function bulkForm(?ResourceForm $form=null): ResourceForm|self {
		if($form===null){
			return $this->bulkUpdateForm;
		}
		$clone=clone $this;
		$clone->bulkUpdateForm=$form;
		return $clone;
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param Schema $schema Schema.
	 * @return self Cloned resource definition with updated bulk schema metadata.
	 */
	public function bulkSchema(Schema $schema): self {
		$clone=clone $this;
		$clone->bulkUpdateForm=$clone->bulkUpdateForm->schema($schema);
		return $clone;
	}

	/**
	 * Updates the fields setting for this resource.
	 *
	 * @param array<int,Field|array<string,mixed>|string> $fields Resource form fields.
	 * @return self Cloned resource definition with updated fields metadata.
	 */
	public function fields(array $fields): self {
		$clone=clone $this;
		$clone->form=$clone->form->fields($fields);
		return $clone;
	}

	/**
	 * Updates the field setting for this resource.
	 *
	 * @param Field|array|string $field Field.
	 * @param ?string $type Type.
	 * @return self Cloned resource definition with updated field metadata.
	 */
	public function field(Field|array|string $field, ?string $type=null): self {
		$clone=clone $this;
		$clone->form=$clone->form->field($field, $type);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param array<int,FormSection|array<string,mixed>|string> $sections Resource form sections.
	 * @return self Cloned resource definition with updated form sections metadata.
	 */
	public function formSections(array $sections): self {
		$clone=clone $this;
		$clone->form=$clone->form->sections($sections);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param FormSection|array|string $section Section.
	 * @param ?array<int,Field|array<string,mixed>|string> $fields Optional fields for the section.
	 * @return self Cloned resource definition with updated form section metadata.
	 */
	public function formSection(FormSection|array|string $section, ?array $fields=null): self {
		$clone=clone $this;
		$clone->form=$clone->form->section($section, $fields);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param int $columns Requested table column count for resource layout.
	 * @return self Cloned resource definition with updated form columns metadata.
	 */
	public function formColumns(int $columns): self {
		$clone=clone $this;
		$clone->form=$clone->form->columns($columns);
		return $clone;
	}

	/**
	 * Configures form, fill, validation, or save behavior for resource records.
	 *
	 * Lifecycle callbacks let Panel transform record data before display, before validation, before persistence, and after persistence.
	 *
	 * @param ?ResourceForm $form Form.
	 * @return ResourceForm|self Resource form when read, or the resource after update.
	 */
	public function form(?ResourceForm $form=null): ResourceForm|self {
		if($form===null){
			return $this->form;
		}
		$clone=clone $this;
		$clone->form=$form;
		return $clone;
	}

	/**
	 * Updates the schema setting for this resource.
	 *
	 * @param Schema $schema Schema.
	 * @return self Cloned resource definition with updated schema metadata.
	 */
	public function schema(Schema $schema): self {
		$clone=clone $this;
		$clone->form=$clone->form->schema($schema);
		return $clone;
	}

	/**
	 * Updates the infolist setting for this resource.
	 *
	 * @param Schema|Infolist|array|null $schema Schema.
	 * @return Schema|Infolist|self Infolist schema when read, or the resource after update.
	 */
	public function infolist(Schema|Infolist|array|null $schema=null): Schema|Infolist|self {
		if($schema===null){
			return $this->infolistSchema ?? Infolist::fromSchema($this->form->schema());
		}
		if(is_array($schema)){
			$schema=Infolist::from($schema) ?? Infolist::make();
		}
		$clone=clone $this;
		$clone->infolistSchema=$schema;
		return $clone;
	}

	/**
	 * Updates the infolist entries setting for this resource.
	 *
	 * @param array<int,Field|InfolistEntry|array<string,mixed>|string> $entries Infolist entries.
	 * @return self Cloned resource definition with updated infolist entries metadata.
	 */
	public function infolistEntries(array $entries): self {
		return $this->infolist(Infolist::make($entries));
	}

	/**
	 * Updates the infolist entry setting for this resource.
	 *
	 * @param Field|InfolistEntry|array|string $entry Entry.
	 * @param ?string $type Type.
	 * @return self Cloned resource definition with updated infolist entry metadata.
	 */
	public function infolistEntry(Field|InfolistEntry|array|string $entry, ?string $type=null): self {
		$infolist=$this->infolist();
		$infolist=$infolist instanceof Infolist ? $infolist : Infolist::fromSchema($infolist);
		return $this->infolist($infolist->entry($entry, $type));
	}

	/**
	 * Updates the infolist state setting for this resource.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param ?PanelRequest $request Request.
	 * @return PanelInfolistState Resolved infolist state.
	 */
	public function infolistState(mixed $record=null, ?PanelRequest $request=null): PanelInfolistState {
		$infolist=$this->infolist();
		$schema=$infolist instanceof Infolist ? $infolist->schema() : $infolist;
		$schemaMeta=$schema->toArray();
		$entries=[];
		$sections=[];
		foreach($schema->fieldsList() as $field){
			$fieldMeta=$field->toArray();
			$name=(string)($fieldMeta['name'] ?? '');
			if($name===''){
				continue;
			}
			$fieldMeta['options']=$field->optionsFor($record, $request, 'show');
			$visible=($fieldMeta['hidden'] ?? false)!==true && $field->isVisible('show', $record, $request)!==false;
			$raw=self::recordValue($record, $name, $fieldMeta['default'] ?? null);
			$display=self::displayEntryValue($field, $fieldMeta, $raw, $record, $request);
			$section=trim((string)($fieldMeta['meta']['section'] ?? ''));
			$section=$section!=='' ? $section : Panel::trans('record.details', [], null, 'Details');
			$entry=[
				'name'=>$name,
				'label'=>(string)($fieldMeta['label'] ?? $name),
				'type'=>(string)($fieldMeta['type'] ?? 'text'),
				'section'=>$section,
				'raw'=>$raw,
				'display'=>$display,
				'visible'=>$visible,
				'copyable'=>($fieldMeta['meta']['copyable'] ?? false)===true,
				'meta'=>is_array($fieldMeta['meta'] ?? null) ? $fieldMeta['meta'] : [],
				'field'=>$fieldMeta,
			];
			$entries[]=$entry;
			if($visible){
				$sections[$section] ??=[];
				$sections[$section][]=$entry;
			}
		}
		$state=PanelInfolistState::make($entries, $sections, $schemaMeta, [
			'resource'=>$this->name,
			'record'=>[
				'key'=>$record!==null ? $this->recordKey($record) : '',
				'title'=>$record!==null ? $this->recordTitle($record) : '',
				'subtitle'=>$record!==null ? $this->recordSubtitle($record) : '',
				'url'=>$record!==null ? $this->recordUrl($record) : '',
			],
		]);
		PanelTrace::record('infolist.state', [
			'resource'=>$this,
			'request'=>$request,
			'state'=>$state,
		]);
		return $state;
	}

	/**
	 * Replaces the table columns shown for this resource.
	 *
	 * Column declarations are delegated to ResourceTable so arrays, strings, and Column objects normalize into the renderer manifest consistently.
	 *
	 * @param array<int,Column|array<string,mixed>|string> $columns Table columns.
	 * @return self Cloned resource definition with updated columns metadata.
	 */
	public function columns(array $columns): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->columns($columns);
		return $clone;
	}

	/**
	 * Adds one table column to this resource.
	 *
	 * The column declaration is normalized by ResourceTable and appended without changing existing columns.
	 *
	 * @param Column|array|string $column Column declaration, name, or Column instance.
	 * @param ?string $type Optional column type when the declaration is a string.
	 * @return self Cloned resource definition with updated column metadata.
	 */
	public function column(Column|array|string $column, ?string $type=null): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->column($column, $type);
		return $clone;
	}

	/**
	 * Updates the per page setting for this resource.
	 *
	 * @param int $rows Default row count requested by the resource index table.
	 * @return self Cloned resource definition with updated per page metadata.
	 */
	public function perPage(int $rows): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->perPage($rows);
		return $clone;
	}

	/**
	 * Updates the per page options setting for this resource.
	 *
	 * @param array<int,int> $options Allowed page sizes.
	 * @return self Cloned resource definition with updated per page options metadata.
	 */
	public function perPageOptions(array $options): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->perPageOptions($options);
		return $clone;
	}

	/**
	 * Updates the default sort setting for this resource.
	 *
	 * @param string $column Column used for the default table sort.
	 * @param string $direction Sort direction token normalized by ResourceTable.
	 * @return self Cloned resource definition with updated default sort metadata.
	 */
	public function defaultSort(string $column, string $direction='asc'): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->defaultSort($column, $direction);
		return $clone;
	}

	/**
	 * Updates the filters setting for this resource.
	 *
	 * @param array<int,TableFilter|array<string,mixed>|string> $filters Table filters.
	 * @return self Cloned resource definition with updated filters metadata.
	 */
	public function filters(array $filters): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->filters($filters);
		return $clone;
	}

	/**
	 * Updates the filter setting for this resource.
	 *
	 * @param TableFilter|array|string $filter Filter declaration, name, or TableFilter instance.
	 * @param ?string $type Optional filter type when the declaration is a string.
	 * @return self Cloned resource definition with updated filter metadata.
	 */
	public function filter(TableFilter|array|string $filter, ?string $type=null): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->filter($filter, $type);
		return $clone;
	}

	/**
	 * Updates the empty state setting for this resource.
	 *
	 * Empty state metadata is rendered when the unfiltered resource table has no rows.
	 *
	 * @param string|array|callable $heading Heading text, full empty-state config, or resolver callback.
	 * @param ?string $description Optional supporting text.
	 * @param ?string $actionLabel Optional call-to-action label.
	 * @param string|callable|null $actionUrl Optional static URL or URL resolver.
	 * @param ?string $icon Optional icon token.
	 * @return self Cloned resource definition with updated empty state metadata.
	 */
	public function emptyState(string|array|callable $heading, ?string $description=null, ?string $actionLabel=null, string|callable|null $actionUrl=null, ?string $icon=null): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->emptyState($heading, $description, $actionLabel, $actionUrl, $icon);
		return $clone;
	}

	/**
	 * Updates the filtered empty state setting for this resource.
	 *
	 * Filtered empty state metadata is rendered when filters/search produce no matching rows while the resource may still contain records.
	 *
	 * @param string|array|callable $heading Heading text, full empty-state config, or resolver callback.
	 * @param ?string $description Optional supporting text.
	 * @param ?string $actionLabel Optional call-to-action label.
	 * @param string|callable|null $actionUrl Optional static URL or URL resolver.
	 * @param ?string $icon Optional icon token.
	 * @return self Cloned resource definition with updated filtered empty state metadata.
	 */
	public function filteredEmptyState(string|array|callable $heading, ?string $description=null, ?string $actionLabel=null, string|callable|null $actionUrl=null, ?string $icon=null): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->filteredEmptyState($heading, $description, $actionLabel, $actionUrl, $icon);
		return $clone;
	}

	/**
	 * Sets the call-to-action shown in the unfiltered empty state.
	 *
	 * The URL can be static or resolved at render time by ResourceTable.
	 *
	 * @param string $label Action label shown in the empty state.
	 * @param string|callable $url Static URL or URL resolver for the action.
	 * @return self Cloned resource definition with updated empty state action metadata.
	 */
	public function emptyStateAction(string $label, string|callable $url): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->emptyStateAction($label, $url);
		return $clone;
	}

	/**
	 * Sets the call-to-action shown in the filtered empty state.
	 *
	 * The URL can be static or resolved at render time by ResourceTable.
	 *
	 * @param string $label Action label shown in the filtered empty state.
	 * @param string|callable $url Static URL or URL resolver for the action.
	 * @return self Cloned resource definition with updated filtered empty state action metadata.
	 */
	public function filteredEmptyStateAction(string $label, string|callable $url): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->filteredEmptyStateAction($label, $url);
		return $clone;
	}

	/**
	 * Updates the views setting for this resource.
	 *
	 * @param array<int,TableView|array<string,mixed>|string> $views Table views.
	 * @return self Cloned resource definition with updated views metadata.
	 */
	public function views(array $views): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->views($views);
		return $clone;
	}

	/**
	 * Updates the view setting for this resource.
	 *
	 * @param TableView|array|string $view View declaration, name, or TableView instance.
	 * @return self Cloned resource definition with updated view metadata.
	 */
	public function view(TableView|array|string $view): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->view($view);
		return $clone;
	}

	/**
	 * Updates the summaries setting for this resource.
	 *
	 * @param array<int,TableSummary|array<string,mixed>|string> $summaries Table summaries.
	 * @return self Cloned resource definition with updated summaries metadata.
	 */
	public function summaries(array $summaries): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->summaries($summaries);
		return $clone;
	}

	/**
	 * Updates the summary setting for this resource.
	 *
	 * @param TableSummary|array|string $summary Summary declaration, name, or TableSummary instance.
	 * @param ?string $type Optional summary type when the declaration is a string.
	 * @return self Cloned resource definition with updated summary metadata.
	 */
	public function summary(TableSummary|array|string $summary, ?string $type=null): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->summary($summary, $type);
		return $clone;
	}

	/**
	 * Replaces table group definitions for this resource.
	 *
	 * Group declarations are normalized by ResourceTable before becoming renderer manifest metadata.
	 *
	 * @param array<int,TableGroup|array<string,mixed>|string> $groups Table groups.
	 * @return self Cloned resource definition with updated table groups metadata.
	 */
	public function tableGroups(array $groups): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->groups($groups);
		return $clone;
	}

	/**
	 * Adds one table group definition to this resource.
	 *
	 * The group declaration is normalized by ResourceTable and appended without changing existing groups.
	 *
	 * @param TableGroup|array|string $group Group declaration, name, or TableGroup instance.
	 * @return self Cloned resource definition with updated table group metadata.
	 */
	public function tableGroup(TableGroup|array|string $group): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->group($group);
		return $clone;
	}

	/**
	 * Updates the row attributes setting for this resource.
	 *
	 * Attributes may be static or resolved per record. Merge mode preserves existing row attributes while replacement mode lets callers define the full row attribute set.
	 *
	 * @param array|callable $attributes Static row attributes or resolver callback.
	 * @param bool $merge Whether to merge with existing row attributes.
	 * @return self Cloned resource definition with updated row attributes metadata.
	 */
	public function rowAttributes(array|callable $attributes, bool $merge=true): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->rowAttributes($attributes, $merge);
		return $clone;
	}

	/**
	 * Resolves the record attributes value for Panel rendering.
	 *
	 * This is a semantic alias for rowAttributes().
	 *
	 * @param array|callable $attributes Static row attributes or resolver callback.
	 * @param bool $merge Whether to merge with existing row attributes.
	 * @return self Cloned resource definition with updated record attributes metadata.
	 */
	public function recordAttributes(array|callable $attributes, bool $merge=true): self {
		return $this->rowAttributes($attributes, $merge);
	}

	/**
	 * Updates the row attribute setting for this resource.
	 *
	 * @param string $name HTML attribute name stored for each table row.
	 * @param mixed $value Static value or renderer-resolved value for the attribute.
	 * @return self Cloned resource definition with updated row attribute metadata.
	 */
	public function rowAttribute(string $name, mixed $value=true): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->rowAttribute($name, $value);
		return $clone;
	}

	/**
	 * Updates the row data setting for this resource.
	 *
	 * @param string $name Data attribute suffix stored for each table row.
	 * @param mixed $value Static value or renderer-resolved value for the data attribute.
	 * @return self Cloned resource definition with updated row data metadata.
	 */
	public function rowData(string $name, mixed $value=true): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->rowData($name, $value);
		return $clone;
	}

	/**
	 * Updates the row aria setting for this resource.
	 *
	 * @param string $name ARIA attribute suffix stored for each table row.
	 * @param mixed $value Static value or renderer-resolved value for the ARIA attribute.
	 * @return self Cloned resource definition with updated row aria metadata.
	 */
	public function rowAria(string $name, mixed $value=true): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->rowAria($name, $value);
		return $clone;
	}

	/**
	 * Updates the row click setting for this resource.
	 *
	 * Row clicks can be disabled, routed to a static target, or resolved per record. Modal mode tells renderers whether to open the target in a Panel modal flow.
	 *
	 * @param bool|string|callable $target Click target flag, URL/action name, or resolver callback.
	 * @param bool $modal Whether the click target should open in a modal flow.
	 * @return self Cloned resource definition with updated row click metadata.
	 */
	public function rowClick(bool|string|callable $target=true, bool $modal=true): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->rowClick($target, $modal);
		return $clone;
	}

	/**
	 * Updates the clickable rows setting for this resource.
	 *
	 * This is a semantic alias for rowClick().
	 *
	 * @param bool|string|callable $target Click target flag, URL/action name, or resolver callback.
	 * @param bool $modal Whether the click target should open in a modal flow.
	 * @return self Cloned resource definition with updated clickable rows metadata.
	 */
	public function clickableRows(bool|string|callable $target=true, bool $modal=true): self {
		return $this->rowClick($target, $modal);
	}

	/**
	 * Sets the default row action dispatched when a table row is activated.
	 *
	 * The operation name is passed through ResourceTable so renderers can wire record-level actions consistently with modal preference.
	 *
	 * @param string $operation Resource operation or action name.
	 * @param bool $modal Whether the row action should open in a modal flow.
	 * @return self Cloned resource definition with updated row action metadata.
	 */
	public function rowAction(string $operation='show', bool $modal=true): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->rowAction($operation, $modal);
		return $clone;
	}

	/**
	 * Sets the named record action dispatched from row interaction.
	 *
	 * This is the action-oriented counterpart to rowAction() for resources that expose custom action names.
	 *
	 * @param string $actionName Action registered on this resource.
	 * @param bool $modal Whether the action should open in a modal flow.
	 * @return self Cloned resource definition with updated record action metadata.
	 */
	public function recordAction(string $actionName, bool $modal=true): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->recordAction($actionName, $modal);
		return $clone;
	}

	/**
	 * Updates the row url setting for this resource.
	 *
	 * @param callable $resolver Callback that returns a row URL for the current record.
	 * @param bool $modal Whether the URL should open in a modal flow.
	 * @return self Cloned resource definition with updated row url metadata.
	 */
	public function rowUrl(callable $resolver, bool $modal=true): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->rowUrl($resolver, $modal);
		return $clone;
	}

	/**
	 * Updates the previewable setting for this resource.
	 *
	 * @param bool $enabled Whether rows can expose preview affordances.
	 * @return self Cloned resource definition with updated previewable metadata.
	 */
	public function previewable(bool $enabled=true): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->previewable($enabled);
		return $clone;
	}

	/**
	 * Updates the row preview setting for this resource.
	 *
	 * This is a semantic alias for previewable().
	 *
	 * @param bool $enabled Whether rows can expose preview affordances.
	 * @return self Cloned resource definition with updated row preview metadata.
	 */
	public function rowPreview(bool $enabled=true): self {
		return $this->previewable($enabled);
	}

	/**
	 * Toggles the preview action exposed by table renderers.
	 *
	 * @param bool $enabled Whether preview action UI should be available.
	 * @return self Cloned resource definition with updated preview action metadata.
	 */
	public function previewAction(bool $enabled=true): self {
		return $this->previewable($enabled);
	}

	/**
	 * Updates the preview fields setting for this resource.
	 *
	 * Preview field declarations can be static or resolved per record; showAction controls whether the table also surfaces an explicit preview command.
	 *
	 * @param array|callable $fields Static preview fields or resolver callback.
	 * @param bool $showAction Whether renderers should show a preview action.
	 * @return self Cloned resource definition with updated preview fields metadata.
	 */
	public function previewFields(array|callable $fields, bool $showAction=true): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->previewFields($fields, $showAction);
		return $clone;
	}

	/**
	 * Configures table presentation for resource records.
	 *
	 * Table metadata defines columns, filters, grouping, pagination, summaries, and row actions used by the resource index view.
	 *
	 * @param ?ResourceTable $table Table.
	 * @return ResourceTable|self Resource table when read, or the resource after update.
	 */
	public function resourceTable(?ResourceTable $table=null): ResourceTable|self {
		if($table===null){
			return $this->resourceTable;
		}
		$clone=clone $this;
		$clone->resourceTable=$table;
		return $clone;
	}

	/**
	 * Configures table presentation for resource records.
	 *
	 * Table metadata defines columns, filters, grouping, pagination, summaries, and row actions used by the resource index view.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param array<string,mixed> $meta Metadata merged into the manifest payload.
	 * @return array<string,mixed> Table manifest payload.
	 */
	public function tableManifest(?PanelRequest $request=null, array $meta=[]): array {
		return TableManifest::from($this->resourceTable, $this, $request, $meta)->toArray();
	}

	/**
	 * Updates the resource manifest setting for this resource.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param array<string,mixed> $meta Metadata merged into the manifest payload.
	 * @return array<string,mixed> Resource manifest payload.
	 */
	public function resourceManifest(?PanelRequest $request=null, array $meta=[]): array {
		return ResourceManifest::from($this, $request, $meta)->toArray();
	}

	/**
	 * Configures table presentation for resource records.
	 *
	 * Table metadata defines columns, filters, grouping, pagination, summaries, and row actions used by the resource index view.
	 *
	 * @param PanelRequest $request Request.
	 * @param array<int,mixed> $records Records loaded for table state.
	 * @param bool $alreadyPaginated Whether the record set has already been paginated upstream.
	 * @param array<string,mixed> $preferences Table preference state.
	 * @return PanelTableState Resolved table state.
	 */
	public function tableState(PanelRequest $request, array $records=[], bool $alreadyPaginated=false, array $preferences=[]): PanelTableState {
		return $this->resourceTable->state($request, $records, $this, $alreadyPaginated, $preferences);
	}

	/**
	 * Configures table presentation for resource records.
	 *
	 * Table metadata defines columns, filters, grouping, pagination, summaries, and row actions used by the resource index view.
	 * @return array<string,TableView> Table views keyed by view name.
	 */
	public function tableViewsList(): array {
		$views=$this->resourceTable->viewsList();
		foreach($this->statusTableViews() as $name=>$view){
			if(!isset($views[$name])){
				$views[$name]=$view;
			}
		}
		return $views;
	}

	/**
	 * Configures table presentation for resource records.
	 *
	 * Table metadata defines columns, filters, grouping, pagination, summaries, and row actions used by the resource index view.
	 * @return array<string,TableGroup> Table groups keyed by group name.
	 */
	public function tableGroupsList(): array {
		return $this->resourceTable->groupsList();
	}

	/**
	 * Configures table presentation for resource records.
	 *
	 * Table metadata defines columns, filters, grouping, pagination, summaries, and row actions used by the resource index view.
	 *
	 * @param PanelRequest $request Request.
	 * @return string Selected table group name, or the default group when the request is empty or invalid.
	 */
	public function activeTableGroupName(PanelRequest $request): string {
		return $this->resourceTable->activeGroupName($request);
	}

	/**
	 * Configures table presentation for resource records.
	 *
	 * Table metadata defines columns, filters, grouping, pagination, summaries, and row actions used by the resource index view.
	 *
	 * @param PanelRequest $request Request.
	 * @return string Selected table view name, or the default view when the request is empty or invalid.
	 */
	public function activeTableViewName(PanelRequest $request): string {
		$views=$this->tableViewsList();
		if($views===[]){
			return '';
		}
		$requested=self::normalizeName((string)$request->query('view', ''));
		if($requested==='all'){
			return '';
		}
		if($requested!=='' && isset($views[$requested])){
			return $requested;
		}
		foreach($views as $view){
			if($view instanceof TableView && ($view->toArray()['default'] ?? false)===true){
				return $view->name();
			}
		}
		return '';
	}

	/**
	 * Updates the request with resolved view setting for this resource.
	 *
	 * @param PanelRequest $request Request.
	 * @return PanelRequest Request carrying the resolved table view.
	 */
	public function requestWithResolvedView(PanelRequest $request): PanelRequest {
		$views=$this->tableViewsList();
		if($views===[]){
			return $request;
		}
		$requested=self::normalizeName((string)$request->query('view', ''));
		if($requested==='all'){
			return $request->withQueryValue('view', 'all');
		}
		if($requested!=='' && isset($views[$requested])){
			return $this->requestWithViewDefaults($request->withQueryValue('view', $requested), $views[$requested]);
		}
		$active=$this->activeTableViewName($request);
		return $active!=='' && isset($views[$active]) ? $this->requestWithViewDefaults($request->withQueryValue('view', $active), $views[$active]) : $request;
	}

	/**
	 * Updates the status field setting for this resource.
	 *
	 * @param string $field Resource field name used by metadata or lookup helpers.
	 * @return self Cloned resource definition with updated status field metadata.
	 */
	public function statusField(string $field): self {
		$field=self::normalizeName($field);
		$clone=clone $this;
		$clone->statusField=$field!=='' ? $field : 'status';
		return $clone;
	}

	/**
	 * Updates the status widgets setting for this resource.
	 *
	 * @param bool $enabled Whether this resource feature should be enabled.
	 * @return self Cloned resource definition with updated status widgets metadata.
	 */
	public function statusWidgets(bool $enabled=true): self {
		$clone=clone $this;
		$clone->statusWidgetsEnabled=$enabled;
		return $clone;
	}

	/**
	 * Updates the status transitions setting for this resource.
	 *
	 * @param array<string,array<string,mixed>|string>|array<int,array<string,mixed>|string> $transitions Status transition definitions.
	 * @return self Cloned resource definition with updated status transitions metadata.
	 */
	public function statusTransitions(array $transitions): self {
		$clone=clone $this;
		$clone->statusTransitions=[];
		foreach($transitions as $name=>$transition){
			if(is_array($transition)){
				$transition['name']=$transition['name'] ?? (is_string($name) ? $name : null);
				$clone=$clone->statusTransition($transition);
			}
			elseif(is_string($transition)){
				$clone=$clone->statusTransition($transition, $transition);
			}
		}
		return $clone;
	}

	/**
	 * Updates the status transition setting for this resource.
	 *
	 * @param array|string $transition Transition.
	 * @param ?string $to To.
	 * @param ?string $label Label.
	 * @param ?string $from From.
	 * @param string $tone Visual tone token exposed in resource metadata.
	 * @return self|array|null Current resource, transition map, or null when absent.
	 */
	public function statusTransition(array|string $transition, ?string $to=null, ?string $label=null, ?string $from=null, string $tone='primary'): self|array|null {
		if($to===null && is_string($transition)){
			return $this->statusTransitions[self::normalizeName($transition)] ?? null;
		}
		$definition=is_array($transition) ? $transition : [
			'name'=>$transition,
			'to'=>$to,
			'label'=>$label,
			'from'=>$from,
			'tone'=>$tone,
		];
		$name=self::normalizeName((string)($definition['name'] ?? $definition['to'] ?? ''));
		$toValue=trim((string)($definition['to'] ?? ''));
		if($name==='' || $toValue===''){
			return clone $this;
		}
		$fromValue=$definition['from'] ?? null;
		$fromValues=is_array($fromValue) ? $fromValue : ($fromValue===null || $fromValue==='' ? [] : [$fromValue]);
		$fromValues=array_values(array_filter(array_map(static fn(mixed $value): string => trim((string)$value), $fromValues), static fn(string $value): bool => $value!==''));
		$toneValue=strtolower(trim((string)($definition['tone'] ?? $tone)));
		$clone=clone $this;
		$clone->statusTransitions[$name]=[
			'name'=>$name,
			'label'=>trim((string)($definition['label'] ?? '')) ?: self::humanize($name),
			'from'=>$fromValues,
			'to'=>$toValue,
			'tone'=>in_array($toneValue, ['neutral', 'primary', 'success', 'warning', 'danger'], true) ? $toneValue : 'primary',
			'confirmation'=>trim((string)($definition['confirmation'] ?? '')),
		];
		return $clone;
	}

	/**
	 * Updates the status transitions list setting for this resource.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @return array<string,array<string,mixed>> Status transitions keyed by normalized transition name.
	 */
	public function statusTransitionsList(mixed $record=null): array {
		if($record===null){
			return $this->statusTransitions;
		}
		return array_filter($this->statusTransitions, fn(array $transition): bool => $this->statusTransitionApplies($transition, $record));
	}

	/**
	 * Updates the status view names setting for this resource.
	 *
	 * @return array<int,string> Status-backed table view names.
	 */
	public function statusViewNames(): array {
		return array_keys($this->statusTableViews());
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param array<int,Action|ActionGroup|array<string,mixed>|string> $actions Action definitions.
	 * @return self Cloned resource definition with updated actions metadata.
	 */
	public function actions(array $actions): self {
		$clone=clone $this;
		foreach($actions as $action){
			$clone=$clone->action($action);
		}
		return $clone;
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param string $fit Image or media fit mode requested by the renderer.
	 * @return self Cloned resource definition with updated action fit metadata.
	 */
	public function actionFit(string $fit): self {
		$fit=self::normalizeName($fit);
		$clone=clone $this;
		$clone->actionFit=in_array($fit, ['stretch', 'content'], true) ? $fit : 'stretch';
		return $clone;
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param bool $enabled Whether this resource feature should be enabled.
	 * @return self Cloned resource definition with updated stretch actions metadata.
	 */
	public function stretchActions(bool $enabled=true): self {
		return $this->actionFit($enabled ? 'stretch' : 'content');
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 * @return string Normalized action layout mode.
	 */
	public function actionFitMode(): string {
		return $this->actionFit;
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param Action|ActionGroup|array|string $action Action.
	 * @return self Cloned resource definition with updated action metadata.
	 */
	public function action(Action|ActionGroup|array|string $action): self {
		$action=$action instanceof Action || $action instanceof ActionGroup
			? $action
			: (is_array($action) ? self::actionDefinition($action) : Action::make((string)$action));
		$clone=clone $this;
		$clone->actions[$action->name()]=$action;
		return $clone;
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param ActionGroup|array|string $group Group.
	 * @param array<int,Action|array<string,mixed>|string> $actions Nested actions for the group.
	 * @return self Cloned resource definition with updated action group metadata.
	 */
	public function actionGroup(ActionGroup|array|string $group, array $actions=[]): self {
		$group=$group instanceof ActionGroup ? $group : (is_array($group) ? ActionGroup::fromArray($group) : ActionGroup::make((string)$group)->actions($actions));
		return $this->action($group);
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 * @return array<string,Action|ActionGroup> Actions keyed by action name.
	 */
	public function actionsList(): array {
		return $this->actions;
	}

	/**
	 * Configures resource actions and bulk operations.
	 *
	 * Action definitions describe command availability, forms, handlers, confirmation state, and renderer placement.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return ?Action Matching action, or null when absent.
	 */
	public function actionByName(string $name): ?Action {
		$name=self::normalizeName($name);
		foreach($this->actions as $action){
			if($action instanceof Action && $action->name()===$name){
				return $action;
			}
			if($action instanceof ActionGroup && ($nested=$action->actionByName($name)) instanceof Action){
				return $nested;
			}
		}
		return null;
	}

	/**
	 * Configures related resource managers.
	 *
	 * Relation metadata lets Panel render nested tables, forms, and counts for records connected to this resource.
	 *
	 * @param array<int,RelationManager|array<string,mixed>|string> $relations Relation manager definitions.
	 * @return self Cloned resource definition with updated relations metadata.
	 */
	public function relations(array $relations): self {
		$clone=clone $this;
		foreach($relations as $relation){
			$clone=$clone->relation($relation);
		}
		return $clone;
	}

	/**
	 * Configures related resource managers.
	 *
	 * Relation metadata lets Panel render nested tables, forms, and counts for records connected to this resource.
	 *
	 * @param RelationManager|array|string $relation Relation.
	 * @return self Cloned resource definition with updated relation metadata.
	 */
	public function relation(RelationManager|array|string $relation): self {
		$relation=$relation instanceof RelationManager
			? $relation
			: (is_array($relation) ? RelationManager::fromArray($relation) : RelationManager::make((string)$relation));
		$clone=clone $this;
		$clone->relations[$relation->name()]=$relation;
		return $clone;
	}

	/**
	 * Configures related resource managers.
	 *
	 * Relation metadata lets Panel render nested tables, forms, and counts for records connected to this resource.
	 * @return array<string,RelationManager> Relation managers keyed by relation name.
	 */
	public function relationManagers(): array {
		return $this->relations;
	}

	/**
	 * Configures related resource managers.
	 *
	 * Relation metadata lets Panel render nested tables, forms, and counts for records connected to this resource.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return ?RelationManager Matching relation manager, or null when absent.
	 */
	public function relationManager(string $name): ?RelationManager {
		$name=self::normalizeName($name);
		return $this->relations[$name] ?? null;
	}

	/**
	 * Configures how this resource appears in Panel navigation.
	 *
	 * Navigation metadata controls labels, grouping, ordering, badges, icons, parent folders, and visibility.
	 * @return bool True when the resource is hidden from navigation.
	 */
	public function isHiddenFromNavigation(): bool {
		return $this->hiddenFromNavigation;
	}

	/**
	 * Configures global search behavior for this resource.
	 *
	 * Search metadata tells Panel which columns and callbacks can produce navigation-level result entries.
	 * @return bool True when the resource is global searchable.
	 */
	public function isGlobalSearchable(): bool {
		return $this->globalSearchable;
	}

	/**
	 * Resolves the record key value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @return string Stable record key, or an empty string when the record cannot be keyed.
	 */
	public function recordKey(mixed $record): string {
		if($this->recordKeyResolver!==null){
			try{
				$value=($this->recordKeyResolver)($record, $this);
				return is_scalar($value) && trim((string)$value)!=='' ? (string)$value : '';
			}
			catch(\Throwable $exception){
				PanelTrace::record('record.key_error', [
					'resource'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				return '';
			}
		}
		return self::recordKeyDefault($record);
	}

	/**
	 * Resolves the record title value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @return string Display title resolved from the callback, record fields, or fallback key.
	 */
	public function recordTitle(mixed $record): string {
		if($this->recordTitleResolver!==null){
			try{
				$value=($this->recordTitleResolver)($record, $this);
				if(is_scalar($value) && trim((string)$value)!==''){
					return (string)$value;
				}
			}
			catch(\Throwable $exception){
				PanelTrace::record('record.title_error', [
					'resource'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
			}
		}
		return $this->defaultRecordTitle($record);
	}

	/**
	 * Resolves the record subtitle value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @return string Optional display subtitle, or an empty string when unavailable.
	 */
	public function recordSubtitle(mixed $record): string {
		if($this->recordSubtitleResolver!==null){
			try{
				$value=($this->recordSubtitleResolver)($record, $this);
				return is_scalar($value) ? trim((string)$value) : '';
			}
			catch(\Throwable $exception){
				PanelTrace::record('record.subtitle_error', [
					'resource'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				return '';
			}
		}
		return $this->defaultRecordSubtitle($record);
	}

	/**
	 * Resolves the record url value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $operation Resource operation name such as create, edit, view, or delete.
	 * @return string Record URL for the requested operation, or an empty string when routing cannot be built.
	 */
	public function recordUrl(mixed $record, string $operation='show'): string {
		if($this->recordUrlResolver!==null){
			try{
				$value=($this->recordUrlResolver)($record, $operation, $this);
				if(is_string($value) && trim($value)!==''){
					return trim($value);
				}
			}
			catch(\Throwable $exception){
				PanelTrace::record('record.url_error', [
					'resource'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
			}
		}
		$key=$this->recordKey($record);
		if($key===''){
			return $this->url ?? PanelConfig::resourceUrl($this);
		}
		$operation=Resource::normalizeName($operation);
		if($this->url!==null && trim($this->url)!==''){
			return rtrim($this->url, '/').'/'.$operation.'/'.rawurlencode($key);
		}
		return PanelConfig::resourceUrl($this, $operation.'/'.rawurlencode($key));
	}

	/**
	 * Configures global search behavior for this resource.
	 *
	 * Search metadata tells Panel which columns and callbacks can produce navigation-level result entries.
	 *
	 * @param string $query Search query text supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @param int $limit Maximum number of resource results to return.
	 * @return array<int,array<string,mixed>> Message payloads for the record.
	 */
	public function globalSearchResults(string $query, PanelRequest $request, int $limit=5): array {
		$query=trim($query);
		$limit=max(1, min(50, $limit));
		if($query==='' || $this->globalSearchable===false){
			return [];
		}
		if($this->globalSearchHandler!==null){
			$results=($this->globalSearchHandler)($query, $request, $this, $limit);
			return $this->normalizeGlobalSearchResults(is_array($results) ? $results : [], $limit);
		}
		$records=$this->globalSearchRecords($request, $query, $limit);
		if($records===[]){
			return [];
		}
		$columns=$this->resolvedGlobalSearchColumns();
		$matches=[];
		foreach($records as $record){
			if(!$this->recordMatchesGlobalSearch($record, $columns, $query)){
				continue;
			}
			$matches[]=$this->globalSearchResultForRecord($record);
			if(count($matches)>=$limit){
				break;
			}
		}
		return $matches;
	}

	/**
	 * Resolves the record activity value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved activity entries, or an empty list when no handler is configured.
	 */
	public function recordActivity(mixed $record, PanelRequest $request): array {
		if($this->activityHandler===null){
			return [];
		}
		try{
			$activity=($this->activityHandler)($record, $request, $this);
			return is_array($activity) ? $activity : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.activity_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record insights value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved insights entries, or an empty list when no handler is configured.
	 */
	public function recordInsights(mixed $record, PanelRequest $request): array {
		if($this->insightsHandler===null){
			return [];
		}
		try{
			$insights=($this->insightsHandler)($record, $request, $this);
			return is_array($insights) ? $insights : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.insights_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record alerts value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved alerts entries, or an empty list when no handler is configured.
	 */
	public function recordAlerts(mixed $record, PanelRequest $request): array {
		if($this->alertsHandler===null){
			return [];
		}
		try{
			$alerts=($this->alertsHandler)($record, $request, $this);
			return is_array($alerts) ? $alerts : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.alerts_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record links value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved links entries, or an empty list when no handler is configured.
	 */
	public function recordLinks(mixed $record, PanelRequest $request): array {
		if($this->linksHandler===null){
			return [];
		}
		try{
			$links=($this->linksHandler)($record, $request, $this);
			return is_array($links) ? $links : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.links_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record contacts value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved contacts entries, or an empty list when no handler is configured.
	 */
	public function recordContacts(mixed $record, PanelRequest $request): array {
		if($this->contactsHandler===null){
			return [];
		}
		try{
			$contacts=($this->contactsHandler)($record, $request, $this);
			return is_array($contacts) ? $contacts : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.contacts_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record locations value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved locations entries, or an empty list when no handler is configured.
	 */
	public function recordLocations(mixed $record, PanelRequest $request): array {
		if($this->locationsHandler===null){
			return [];
		}
		try{
			$locations=($this->locationsHandler)($record, $request, $this);
			return is_array($locations) ? $locations : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.locations_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record changes value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved changes entries, or an empty list when no handler is configured.
	 */
	public function recordChanges(mixed $record, PanelRequest $request): array {
		if($this->changesHandler===null){
			return [];
		}
		try{
			$changes=($this->changesHandler)($record, $request, $this);
			return is_array($changes) ? $changes : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.changes_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record tags value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved tags entries, or an empty list when no handler is configured.
	 */
	public function recordTags(mixed $record, PanelRequest $request): array {
		if($this->tagsHandler===null){
			return [];
		}
		try{
			$tags=($this->tagsHandler)($record, $request, $this);
			return is_array($tags) ? $tags : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.tags_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Runs the update tag workflow for this resource.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $tag Tag value attached to the resource record.
	 * @param string $action Action name recorded for the resource record.
	 * @param PanelRequest $request Request.
	 * @return mixed Tag update callback result, or null when tag updates are unavailable.
	 */
	public function updateTag(mixed $record, string $tag, string $action, PanelRequest $request): mixed {
		if($this->tagHandler===null){
			return [
				'tag_updated'=>false,
				'message'=>'No tag handler is registered for this resource.',
			];
		}
		return ($this->tagHandler)($record, $tag, $action, $request, $this);
	}

	/**
	 * Resolves the record items value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved items entries, or an empty list when no handler is configured.
	 */
	public function recordItems(mixed $record, PanelRequest $request): array {
		if($this->itemsHandler===null){
			return [];
		}
		try{
			$items=($this->itemsHandler)($record, $request, $this);
			return is_array($items) ? $items : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.items_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record totals value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved totals entries, or an empty list when no handler is configured.
	 */
	public function recordTotals(mixed $record, PanelRequest $request): array {
		if($this->totalsHandler===null){
			return [];
		}
		try{
			$totals=($this->totalsHandler)($record, $request, $this);
			return is_array($totals) ? $totals : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.totals_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record approvals value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved approvals entries, or an empty list when no handler is configured.
	 */
	public function recordApprovals(mixed $record, PanelRequest $request): array {
		if($this->approvalsHandler===null){
			return [];
		}
		try{
			$approvals=($this->approvalsHandler)($record, $request, $this);
			return is_array($approvals) ? $approvals : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.approvals_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Runs the resolve approval workflow for this resource.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $approval Approval step or status name.
	 * @param string $decision Approval decision recorded for the resource record.
	 * @param PanelRequest $request Request.
	 * @return mixed Approval resolution callback result, or null when approvals are unavailable.
	 */
	public function resolveApproval(mixed $record, string $approval, string $decision, PanelRequest $request): mixed {
		if($this->approvalHandler===null){
			return [
				'approval_resolved'=>false,
				'message'=>'No approval handler is registered for this resource.',
			];
		}
		return ($this->approvalHandler)($record, $approval, $decision, $request, $this);
	}

	/**
	 * Resolves the record notes value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved notes entries, or an empty list when no handler is configured.
	 */
	public function recordNotes(mixed $record, PanelRequest $request): array {
		if($this->notesHandler===null){
			return [];
		}
		try{
			$notes=($this->notesHandler)($record, $request, $this);
			return is_array($notes) ? $notes : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.notes_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Runs the add note workflow for this resource.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $note Note text attached to the resource record.
	 * @param PanelRequest $request Request.
	 * @return mixed Note creation callback result, or null when notes are unavailable.
	 */
	public function addNote(mixed $record, string $note, PanelRequest $request): mixed {
		if($this->noteHandler===null){
			return [
				'noted'=>false,
				'message'=>'No note handler is registered for this resource.',
			];
		}
		return ($this->noteHandler)($record, $note, $request, $this);
	}

	/**
	 * Resolves the record messages value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array Resolved messages entries, or an empty list when no handler is configured.
	 */
	public function recordMessages(mixed $record, PanelRequest $request): array {
		if($this->messagesHandler===null){
			return [];
		}
		try{
			$messages=($this->messagesHandler)($record, $request, $this);
			return is_array($messages) ? $messages : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.messages_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Runs the send message workflow for this resource.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param array<string,mixed> $message Message payload to send for the record.
	 * @param PanelRequest $request Request.
	 * @return mixed Message send callback result, or null when messages are unavailable.
	 */
	public function sendMessage(mixed $record, array $message, PanelRequest $request): mixed {
		if($this->messageHandler===null){
			return [
				'message_sent'=>false,
				'message'=>'No message handler is registered for this resource.',
			];
		}
		return ($this->messageHandler)($record, $message, $request, $this);
	}

	/**
	 * Resolves the record shipments value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array<int,array<string,mixed>> Shipment payloads for the record.
	 */
	public function recordShipments(mixed $record, PanelRequest $request): array {
		if($this->shipmentsHandler===null){
			return [];
		}
		try{
			$shipments=($this->shipmentsHandler)($record, $request, $this);
			return is_array($shipments) ? $shipments : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.shipments_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record payments value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array<int,array<string,mixed>> Payment payloads for the record.
	 */
	public function recordPayments(mixed $record, PanelRequest $request): array {
		if($this->paymentsHandler===null){
			return [];
		}
		try{
			$payments=($this->paymentsHandler)($record, $request, $this);
			return is_array($payments) ? $payments : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.payments_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Resolves the record attachments value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array<int,array<string,mixed>> Attachment payloads for the record.
	 */
	public function recordAttachments(mixed $record, PanelRequest $request): array {
		if($this->attachmentsHandler===null){
			return [];
		}
		try{
			$attachments=($this->attachmentsHandler)($record, $request, $this);
			return is_array($attachments) ? $attachments : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.attachments_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Runs the attach file workflow for this resource.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param array<string,mixed> $file File payload passed to the attachment handler.
	 * @param PanelRequest $request Request.
	 * @return mixed Attachment callback result, or null when attachments are unavailable.
	 */
	public function attachFile(mixed $record, array $file, PanelRequest $request): mixed {
		if($this->attachHandler===null){
			return [
				'attached'=>false,
				'message'=>'No attachment handler is registered for this resource.',
			];
		}
		return ($this->attachHandler)($record, $file, $request, $this);
	}

	/**
	 * Resolves the record tasks value for Panel rendering.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param PanelRequest $request Request.
	 * @return array<int,array<string,mixed>> Task payloads for the record.
	 */
	public function recordTasks(mixed $record, PanelRequest $request): array {
		if($this->tasksHandler===null){
			return [];
		}
		try{
			$tasks=($this->tasksHandler)($record, $request, $this);
			return is_array($tasks) ? $tasks : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('record.tasks_error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Runs the update task workflow for this resource.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param string $task Task label or identifier attached to the resource record.
	 * @param bool $completed Whether the task should be marked complete.
	 * @param PanelRequest $request Request.
	 * @return mixed Task update callback result, or null when task updates are unavailable.
	 */
	public function updateTask(mixed $record, string $task, bool $completed, PanelRequest $request): mixed {
		if($this->taskHandler===null){
			return [
				'task_updated'=>false,
				'message'=>'No task handler is registered for this resource.',
			];
		}
		return ($this->taskHandler)($record, $task, $completed, $request, $this);
	}

	/**
	 * Runs the create task workflow for this resource.
	 *
	 * @param mixed $record Resource record or row payload supplied by Panel.
	 * @param array<string,mixed> $task Task payload passed to the creation handler.
	 * @param PanelRequest $request Request.
	 * @return mixed Task creation callback result, or null when task creation is unavailable.
	 */
	public function createTask(mixed $record, array $task, PanelRequest $request): mixed {
		if($this->createTaskHandler===null){
			return [
				'task_created'=>false,
				'message'=>'No task creation handler is registered for this resource.',
			];
		}
		return ($this->createTaskHandler)($record, $task, $request, $this);
	}

	/**
	 * Configures how this resource appears in Panel navigation.
	 *
	 * Navigation metadata controls labels, grouping, ordering, badges, icons, parent folders, and visibility.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param ?PanelManager $manager Manager.
	 * @return array Navigation manifest entry with route, label, icon, badge, group, and visibility metadata.
	 */
	public function navigationEntry(?PanelRequest $request=null, ?PanelManager $manager=null): array {
		$badge=$this->navigationBadge;
		if($this->navigationBadgeResolver!==null){
			try{
				$badge=($this->navigationBadgeResolver)($request, $this, $manager);
			}
			catch(\Throwable $exception){
				PanelTrace::record('navigation.badge_error', [
					'resource'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$badge=null;
			}
		}
		return [
			'name'=>$this->name,
			'label'=>$this->pluralLabel ?: $this->label,
			'group'=>$this->group,
			'parent'=>$this->navigationParent,
			'icon'=>$this->icon ?? self::defaultIcon(),
			'url'=>$this->url ?? PanelConfig::resourceUrl($this),
			'sort'=>$this->sort,
			'description'=>$this->navigationDescription,
			'badge'=>$badge,
			'badge_tone'=>$this->navigationBadgeTone,
		];
	}

	/**
	 * Resolves the dashboard status widgets value for Panel rendering.
	 *
	 * @param ?PanelRequest $request Request.
	 * @return array Status widget descriptors keyed by configured transition status.
	 */
	public function dashboardStatusWidgets(?PanelRequest $request=null): array {
		if($this->statusWidgetsEnabled===false || $this->statusTransitions===[]){
			return [];
		}
		$records=$this->dashboardWidgetRecords($request);
		if($records===[]){
			return [];
		}
		$widgets=[];
		foreach($this->statusTableViews() as $name=>$view){
			$count=0;
			foreach($records as $record){
				if($view->matches($record, $request ?? PanelRequest::fromArray([]), $this)){
					$count++;
				}
			}
			$meta=$view->toArray();
			$widgets[]=[
				'name'=>$this->name.'_status_'.$name,
				'type'=>'stat',
				'label'=>(string)($meta['label'] ?? self::humanize($name)),
				'value'=>$count,
				'description'=>($this->pluralLabel ?: $this->label).' / '.$this->statusField,
				'tone'=>(string)($meta['tone'] ?? 'neutral'),
				'icon'=>$this->icon ?? self::defaultIcon(),
				'url'=>PanelConfig::resourceUrl($this, '', ['view'=>$name]),
				'sort'=>$this->sort,
			];
		}
		return $widgets;
	}

	/**
	 * Reports whether this resource has status widgets.
	 *
	 * @return bool True when status widgets handlers or state are configured.
	 */
	public function hasStatusWidgets(): bool {
		return $this->statusWidgetsEnabled;
	}

	/**
	 * Exports this resource as a Panel manifest payload.
	 *
	 * The payload is the renderer-facing contract for navigation, forms, tables, actions, relations, authorization, and record lifecycle behavior.
	 * @return array Renderer-facing resource manifest with navigation, forms, tables, actions, relations, authorization, and lifecycle metadata.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'plural_label'=>$this->pluralLabel,
			'model'=>$this->model,
			'repository'=>$this->repository,
			'table'=>$this->table,
			'url'=>$this->url ?? PanelConfig::resourceUrl($this),
			'group'=>$this->group,
			'icon'=>$this->icon ?? self::defaultIcon(),
			'sort'=>$this->sort,
			'per_page'=>$this->resourceTable->defaultPerPage(),
			'hidden_from_navigation'=>$this->hiddenFromNavigation,
			'navigation_description'=>$this->navigationDescription,
			'navigation_badge'=>$this->navigationBadgeResolver===null ? $this->navigationBadge : null,
			'navigation_badge_lazy'=>$this->navigationBadgeResolver!==null,
			'navigation_badge_tone'=>$this->navigationBadgeTone,
			'record_key_custom'=>$this->recordKeyResolver!==null,
			'record_title_custom'=>$this->recordTitleResolver!==null,
			'record_subtitle_custom'=>$this->recordSubtitleResolver!==null,
			'record_url_custom'=>$this->recordUrlResolver!==null,
			'tenant_scoped'=>$this->tenantField!==null,
			'tenant_field'=>$this->tenantField,
			'tenant_required'=>$this->tenantRequired,
			'tenant_resolves'=>$this->tenantResolver!==null,
			'tenant_scope_custom'=>$this->tenantScope!==null,
			'insights'=>$this->insightsHandler!==null,
			'alerts'=>$this->alertsHandler!==null,
			'links'=>$this->linksHandler!==null,
			'contacts'=>$this->contactsHandler!==null,
			'locations'=>$this->locationsHandler!==null,
			'changes'=>$this->changesHandler!==null,
			'tags'=>$this->tagsHandler!==null,
			'updates_tags'=>$this->tagHandler!==null,
			'items'=>$this->itemsHandler!==null,
			'totals'=>$this->totalsHandler!==null,
			'approvals'=>$this->approvalsHandler!==null,
			'resolves_approvals'=>$this->approvalHandler!==null,
			'activity'=>$this->activityHandler!==null,
			'notes'=>$this->notesHandler!==null,
			'adds_notes'=>$this->noteHandler!==null,
			'messages'=>$this->messagesHandler!==null,
			'sends_messages'=>$this->messageHandler!==null,
			'shipments'=>$this->shipmentsHandler!==null,
			'payments'=>$this->paymentsHandler!==null,
			'attachments'=>$this->attachmentsHandler!==null,
			'attaches_files'=>$this->attachHandler!==null,
			'tasks'=>$this->tasksHandler!==null,
			'updates_tasks'=>$this->taskHandler!==null,
			'creates_tasks'=>$this->createTaskHandler!==null,
			'global_searchable'=>$this->globalSearchable,
			'global_search_columns'=>$this->globalSearchColumns,
			'form'=>$this->form->toArray(),
			'bulk_form'=>$this->bulkUpdateForm->toArray(),
			'infolist'=>$this->infolistSchema instanceof Infolist ? $this->infolistSchema->toArray() : $this->infolistSchema?->toArray(),
			'table_schema'=>$this->resourceTable->toArray(),
			'resolved_views'=>array_map(static fn(TableView $view): array => $view->toArray(), array_values($this->tableViewsList())),
			'actions'=>array_map(static fn(Action|ActionGroup $action): array => $action->toArray(), array_values($this->actions)),
			'action_fit'=>$this->actionFit,
			'relations'=>array_map(static fn(RelationManager $relation): array => $relation->toArray(), array_values($this->relations)),
			'authorizes'=>$this->authorizer!==null,
			'saves'=>$this->saveHandler!==null,
			'mutates_form_data'=>$this->formDataMutator!==null || $this->createDataMutator!==null || $this->updateDataMutator!==null,
			'mutates_fill_data'=>$this->fillDataMutator!==null || $this->createFillDataMutator!==null || $this->editFillDataMutator!==null,
			'form_lifecycle'=>[
				'before_fill'=>$this->beforeFillHandler!==null,
				'after_fill'=>$this->afterFillHandler!==null,
				'before_validate'=>$this->beforeValidateHandler!==null,
				'after_validate'=>$this->afterValidateHandler!==null,
				'before_save'=>$this->beforeSaveHandler!==null,
				'after_save'=>$this->afterSaveHandler!==null,
			],
			'imports'=>$this->canImport(),
			'transitions'=>$this->statusTransitions,
			'status_field'=>$this->statusField,
			'status_widgets'=>$this->statusWidgetsEnabled,
			'bulk_updates'=>$this->canBulkUpdate(),
			'duplicates'=>$this->duplicateHandler!==null,
			'restores'=>$this->restoreHandler!==null,
			'deletes'=>$this->deleteHandler!==null,
			'force_deletes'=>$this->forceDeleteHandler!==null,
			'queryable'=>$this->queryFactory!==null || $this->repository!==null || $this->table!==null,
		];
	}

	/**
	 * Loads candidate records for resource global search.
	 *
	 * Query sources may be in-memory arrays, custom objects exposing globalSearch()
	 * or search(), or collection-like objects exposing getRecords() or get().
	 *
	 * @param PanelRequest $request Current Panel request.
	 * @param string $query Search text.
	 * @param int $limit Maximum records requested from capable sources.
	 * @return array<int,mixed> Candidate records or custom search rows.
	 */
	private function globalSearchRecords(PanelRequest $request, string $query, int $limit): array {
		$source=$this->makeQuery($request);
		if(is_array($source)){
			return $source;
		}
		if(!is_object($source)){
			return [];
		}
		foreach(['globalSearch', 'search'] as $method){
			if(method_exists($source, $method)){
				$result=$source->{$method}($query, $limit);
				return is_array($result) ? $result : [];
			}
		}
		foreach(['getRecords', 'get'] as $method){
			if(method_exists($source, $method)){
				$result=$source->{$method}();
				return is_array($result) ? $result : [];
			}
		}
		return [];
	}

	/**
	 * Resolves the fields used by fallback global-search matching.
	 *
	 * Explicit resource columns win, followed by searchable table columns, then
	 * common identity columns so resources can participate in search with minimal
	 * configuration.
	 *
	 * @return array<int,string> Searchable record keys.
	 */
	private function resolvedGlobalSearchColumns(): array {
		if($this->globalSearchColumns!==[]){
			return $this->globalSearchColumns;
		}
		$columns=$this->resourceTable->columnsList();
		$searchable=[];
		foreach($columns as $column){
			if($column instanceof Column && ($column->toArray()['searchable'] ?? false)===true){
				$searchable[]=$column->name();
			}
		}
		if($searchable!==[]){
			return $searchable;
		}
		foreach(['title', 'name', 'email', 'slug', 'id'] as $fallback){
			if(isset($columns[$fallback])){
				$searchable[]=$fallback;
			}
		}
		return $searchable!==[] ? $searchable : ['title', 'name', 'email', 'slug', 'id'];
	}

	/**
	 * Tests one record against fallback global-search columns.
	 *
	 * Matching is case-insensitive and uses recordValue() so arrays, public object
	 * properties, and getter methods share the same lookup behavior.
	 *
	 * @param mixed $record Record being tested.
	 * @param array<int,string> $columns Candidate record keys.
	 * @param string $query Search text.
	 * @return bool Whether any column contains the query.
	 */
	private function recordMatchesGlobalSearch(mixed $record, array $columns, string $query): bool {
		foreach($columns as $column){
			if(stripos((string)self::recordValue($record, $column, ''), $query)!==false){
				return true;
			}
		}
		return false;
	}

	/**
	 * Converts one record into Panel's global-search result shape.
	 *
	 * Title and subtitle resolvers override the default record labels, while the
	 * record URL is built through the resource's record URL contract.
	 *
	 * @param mixed $record Source record.
	 * @return array<string,mixed> Global-search result entry.
	 */
	private function globalSearchResultForRecord(mixed $record): array {
		$key=$this->recordKey($record);
		$title=$this->globalSearchTitleResolver!==null
			? (string)($this->globalSearchTitleResolver)($record, $this)
			: $this->recordTitle($record);
		$subtitle=$this->globalSearchSubtitleResolver!==null
			? (string)($this->globalSearchSubtitleResolver)($record, $this)
			: $this->recordSubtitle($record);
		return [
			'resource'=>$this->name,
			'resource_label'=>$this->pluralLabel ?: $this->label,
			'title'=>$title,
			'subtitle'=>$subtitle,
			'record_key'=>$key,
			'url'=>$this->recordUrl($record),
		];
	}

	/**
	 * Normalizes custom or record-based search results into Panel result entries.
	 *
	 * Array rows with a title are treated as already projected and completed with
	 * resource metadata and URLs. Other rows are considered records and projected
	 * through globalSearchResultForRecord().
	 *
	 * @param array<int,mixed> $results Raw handler results or records.
	 * @param int $limit Maximum entries to return.
	 * @return array<int,array<string,mixed>> Normalized global-search entries.
	 */
	private function normalizeGlobalSearchResults(array $results, int $limit): array {
		$normalized=[];
		foreach($results as $result){
			if(is_array($result) && isset($result['title'])){
				$key=trim((string)($result['record_key'] ?? $result['key'] ?? ''));
				$url=trim((string)($result['url'] ?? ''));
				$normalized[]=[
					'resource'=>(string)($result['resource'] ?? $this->name),
					'resource_label'=>(string)($result['resource_label'] ?? ($this->pluralLabel ?: $this->label)),
					'title'=>(string)$result['title'],
					'subtitle'=>(string)($result['subtitle'] ?? ''),
					'record_key'=>$key,
					'url'=>$url!=='' ? $url : ($key!=='' ? PanelConfig::resourceUrl($this, 'show/'.$key) : PanelConfig::resourceUrl($this)),
				];
			}
			else {
				$normalized[]=$this->globalSearchResultForRecord($result);
			}
			if(count($normalized)>=$limit){
				break;
			}
		}
		return $normalized;
	}

	/**
	 * Builds a fallback record title from common identity fields.
	 *
	 * The first non-empty title, name, email, slug, or id value wins; otherwise the
	 * resource label is used so search and relation displays never render blank.
	 *
	 * @param mixed $record Source record.
	 * @return string Display title.
	 */
	private function defaultRecordTitle(mixed $record): string {
		foreach(['title', 'name', 'email', 'slug', 'id'] as $column){
			$value=trim((string)self::recordValue($record, $column, ''));
			if($value!==''){
				return $value;
			}
		}
		return $this->label;
	}

	/**
	 * Builds a fallback subtitle from the first distinct searchable values.
	 *
	 * At most two values are joined to keep search rows compact while still
	 * exposing useful context such as name plus email.
	 *
	 * @param mixed $record Source record.
	 * @return string Display subtitle.
	 */
	private function defaultRecordSubtitle(mixed $record): string {
		$parts=[];
		foreach($this->resolvedGlobalSearchColumns() as $column){
			$value=trim((string)self::recordValue($record, $column, ''));
			if($value!=='' && !in_array($value, $parts, true)){
				$parts[]=$value;
			}
			if(count($parts)>=2){
				break;
			}
		}
		return implode(' / ', $parts);
	}

	/**
	 * Reads a value from an array record, public object property, or getter method.
	 *
	 * Getter names are derived from normalized keys by converting separators to
	 * words and prefixing get, matching the common PHP model convention.
	 *
	 * @param mixed $record Source record.
	 * @param string $key Record key or property name.
	 * @param mixed $default Value used when the key is unavailable.
	 * @return mixed Array value, public property, getter result, or the supplied default when the key is unavailable.
	 */
	private static function recordValue(mixed $record, string $key, mixed $default=''): mixed {
		if(is_array($record)){
			return $record[$key] ?? $default;
		}
		if(is_object($record)){
			if(isset($record->{$key})){
				return $record->{$key};
			}
			$method='get'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
			if(method_exists($record, $method)){
				return $record->{$method}();
			}
		}
		return $default;
	}

	/**
	 * Converts a form field value into display text for infolists and summaries.
	 *
	 * Field-level display callbacks run first. The fallback handles empty values,
	 * booleans, file metadata, option labels, repeaters, and scalar/stringable
	 * values without exposing raw PHP structures to the UI.
	 *
	 * @param Field $field Field definition.
	 * @param array<string,mixed> $meta Field manifest metadata.
	 * @param mixed $value Raw field value.
	 * @param mixed $record Source record, when available.
	 * @param ?PanelRequest $request Current Panel request.
	 * @return string Display-safe text value.
	 */
	private static function displayEntryValue(Field $field, array $meta, mixed $value, mixed $record=null, ?PanelRequest $request=null): string {
		$display=$field->displayValue($value, $record, $request);
		if($display!==$value){
			return self::entryStringValue($display);
		}
		if($value===null || $value===''){
			return (string)($meta['meta']['empty'] ?? 'Not set');
		}
		$type=(string)($meta['type'] ?? 'text');
		if(in_array($type, ['boolean', 'bool', 'checkbox', 'toggle'], true)){
			return self::truthyEntryValue($value) ? 'Yes' : 'No';
		}
		if(in_array($type, ['file', 'file_upload', 'upload', 'image'], true)){
			if(is_array($value) && isset($value['name']) && !is_array($value['name'])){
				return (string)$value['name'];
			}
			if(is_array($value)){
				$names=[];
				foreach($value as $item){
					if(is_array($item) && isset($item['name']) && !is_array($item['name'])){
						$names[]=(string)$item['name'];
					}
					elseif(is_scalar($item)){
						$names[]=(string)$item;
					}
				}
				return implode(', ', array_filter($names)) ?: 'Uploaded file';
			}
		}
		$options=is_array($meta['options'] ?? null) ? $meta['options'] : [];
		if($options!==[]){
			$labels=[];
			$values=is_array($value) ? $value : [$value];
			foreach($values as $item){
				$label=self::entryOptionLabel($options, (string)$item);
				$labels[]=$label ?? self::entryStringValue($item);
			}
			return implode(', ', array_filter($labels, static fn(string $label): bool => $label!==''));
		}
		if($type==='repeater' && is_array($value)){
			return self::entryRepeaterDisplayValue($value, $meta);
		}
		return self::entryStringValue($value);
	}

	/**
	 * Resolves an option label from flat or grouped option definitions.
	 *
	 * Supported option shapes include key/value maps, grouped options, and arrays
	 * carrying value and label keys.
	 *
	 * @param array<string|int,mixed> $options Field options.
	 * @param string $key Submitted option value.
	 * @return ?string Matching label, or null when not found.
	 */
	private static function entryOptionLabel(array $options, string $key): ?string {
		if(array_key_exists($key, $options) && !is_array($options[$key])){
			return (string)$options[$key];
		}
		foreach($options as $optionValue=>$label){
			if(is_array($label) && (isset($label['options']) || isset($label['label']))){
				$groupOptions=is_array($label['options'] ?? null) ? $label['options'] : $label;
				unset($groupOptions['label'], $groupOptions['options']);
				$found=self::entryOptionLabel($groupOptions, $key);
				if($found!==null){
					return $found;
				}
				continue;
			}
			if(is_array($label)){
				$optionValue=(string)($label['value'] ?? $optionValue);
				if($optionValue===$key){
					return (string)($label['label'] ?? $key);
				}
			}
			elseif((string)$optionValue===$key){
				return (string)$label;
			}
		}
		return null;
	}

	/**
	 * Formats repeater rows for compact read-only display.
	 *
	 * Repeater field metadata determines which nested values appear; each row is
	 * rendered as a numbered line with label/value pairs.
	 *
	 * @param array<int,mixed> $rows Repeater row values.
	 * @param array<string,mixed> $meta Field manifest metadata.
	 * @return string Multiline repeater summary or empty-state text.
	 */
	private static function entryRepeaterDisplayValue(array $rows, array $meta): string {
		$fields=is_array($meta['repeater_fields'] ?? null) ? $meta['repeater_fields'] : (is_array($meta['meta']['repeater_fields'] ?? null) ? $meta['meta']['repeater_fields'] : []);
		$lines=[];
		foreach($rows as $index=>$row){
			if(!is_array($row)){
				continue;
			}
			$parts=[];
			foreach($fields as $field){
				if(!is_array($field)){
					continue;
				}
				$name=self::normalizeName((string)($field['name'] ?? ''));
				$value=self::entryStringValue($row[$name] ?? '');
				if($name!=='' && $value!==''){
					$parts[]=((string)($field['label'] ?? $name)).': '.$value;
				}
			}
			if($parts!==[]){
				$lines[]='#'.($index+1).' '.implode(', ', $parts);
			}
		}
		return $lines!==[] ? implode("\n", $lines) : (string)($meta['meta']['empty'] ?? 'No items');
	}

	/**
	 * Converts arbitrary entry values into stable display strings.
	 *
	 * Scalars and Stringable objects are preserved, booleans become 1/0 tokens, and
	 * arrays/objects fall back to JSON for readable inspection.
	 *
	 * @param mixed $value Entry value.
	 * @return string String representation for UI display.
	 */
	private static function entryStringValue(mixed $value): string {
		if($value===null){
			return '';
		}
		if(is_bool($value)){
			return $value ? '1' : '0';
		}
		if(is_scalar($value)){
			return (string)$value;
		}
		if($value instanceof \Stringable){
			return (string)$value;
		}
		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
	}

	/**
	 * Interprets common submitted values as truthy booleans.
	 *
	 * The accepted string tokens match HTML form and settings conventions used by
	 * Panel controls.
	 *
	 * @param mixed $value Raw entry value.
	 * @return bool Whether the value represents enabled/true.
	 */
	private static function truthyEntryValue(mixed $value): bool {
		if(is_bool($value)){
			return $value;
		}
		$value=strtolower(trim((string)$value));
		return in_array($value, ['1', 'true', 'yes', 'on', 'enabled'], true);
	}

	/**
	 * Finds a stable fallback key for a record.
	 *
	 * Common identity fields are checked in priority order so record URLs and
	 * global-search results can still be generated without a custom key resolver.
	 *
	 * @param mixed $record Source record.
	 * @return string Record key, or an empty string.
	 */
	private static function recordKeyDefault(mixed $record): string {
		foreach(['id', 'key', 'uuid', 'name'] as $key){
			$value=self::recordValue($record, $key, null);
			if($value!==null && $value!==''){
				return (string)$value;
			}
		}
		return '';
	}

	/**
	 * Checks whether a status transition can be shown for a record.
	 *
	 * Transitions without a from list are always available; otherwise the record's
	 * current status field must match one of the configured source statuses.
	 *
	 * @param array<string,mixed> $transition Status transition definition.
	 * @param mixed $record Source record.
	 * @return bool Whether the transition applies.
	 */
	private function statusTransitionApplies(array $transition, mixed $record): bool {
		$from=$transition['from'] ?? [];
		if(!is_array($from) || $from===[]){
			return true;
		}
		$current=(string)self::recordValue($record, $this->statusField, '');
		return in_array($current, array_map('strval', $from), true);
	}

	/**
	 * Generates table views from configured status transitions.
	 *
	 * Every discovered source and target status becomes a TableView with a status
	 * filter closure, allowing transition metadata to automatically seed index
	 * view tabs.
	 *
	 * @return array<string,TableView> Generated status table views keyed by normalized status.
	 */
	private function statusTableViews(): array {
		if($this->statusTransitions===[]){
			return [];
		}
		$statuses=[];
		foreach($this->statusTransitions as $transition){
			foreach((array)($transition['from'] ?? []) as $from){
				$from=trim((string)$from);
				if($from!=='' && !isset($statuses[$from])){
					$statuses[$from]=['label'=>self::humanize($from), 'tone'=>'neutral'];
				}
			}
			$to=trim((string)($transition['to'] ?? ''));
			if($to!==''){
				$statuses[$to]=[
					'label'=>self::humanize($to),
					'tone'=>(string)($transition['tone'] ?? 'neutral'),
				];
			}
		}
		$views=[];
		foreach($statuses as $status=>$meta){
			$name=self::normalizeName($status);
			if($name===''){
				continue;
			}
			$statusField=$this->statusField;
			$views[$name]=TableView::make($name)
				->label((string)($meta['label'] ?? self::humanize($status)))
				->tone((string)($meta['tone'] ?? 'neutral'))
				->meta(['generated_status_view'=>true])
				->where(static fn(mixed $record): bool => (string)self::recordValue($record, $statusField, '')===$status);
		}
		return $views;
	}

	/**
	 * Loads records used by generated status dashboard widgets.
	 *
	 * The helper reuses the resource query source and accepts array or
	 * collection-like results. Query failures are traced and converted to an empty
	 * set so dashboard widgets cannot break the surrounding page.
	 *
	 * @param ?PanelRequest $request Current Panel request.
	 * @return array<int,mixed> Records available to status widgets.
	 */
	private function dashboardWidgetRecords(?PanelRequest $request=null): array {
		try{
			$source=$this->makeQuery($request);
			if(is_array($source)){
				return $source;
			}
			if(!is_object($source)){
				return [];
			}
			foreach(['getRecords', 'get'] as $method){
				if(method_exists($source, $method)){
					$result=$source->{$method}();
					return is_array($result) ? $result : [];
				}
			}
		}
		catch(\Throwable $exception){
			PanelTrace::record('status_widgets.error', [
				'resource'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
		}
		return [];
	}

	/**
	 * Applies missing table-view query defaults to a Panel request.
	 *
	 * Existing non-empty query values are preserved, while blank or absent keys are
	 * filled from the view defaults and returned as a cloned request.
	 *
	 * @param PanelRequest $request Current Panel request.
	 * @param TableView $view Table view providing query defaults.
	 * @return PanelRequest Request carrying merged query defaults.
	 */
	private function requestWithViewDefaults(PanelRequest $request, TableView $view): PanelRequest {
		$query=$request->query();
		foreach($view->queryDefaults() as $key=>$value){
			if(array_key_exists($key, $query) && (is_array($query[$key]) ? $query[$key]!==[] : (string)$query[$key]!=='')){
				continue;
			}
			$query[$key]=$value;
		}
		return $request->withQuery($query, true);
	}

	/**
	 * Interprets a save/import handler result as success or failure.
	 *
	 * Array results may expose saved, success, ok, created, updated, or imported
	 * flags. Non-array results are considered successful unless they are false.
	 *
	 * @param mixed $result Save/import handler result.
	 * @return bool Whether the outcome should be counted as successful.
	 */
	private static function saveOutcomeSucceeded(mixed $result): bool {
		if(is_array($result)){
			foreach(['saved', 'success', 'ok', 'created', 'updated', 'imported'] as $key){
				if(array_key_exists($key, $result)){
					return (bool)$result[$key];
				}
			}
		}
		return $result!==false;
	}

	/**
	 * Resolves the default Panel resource icon.
	 *
	 * Runtime panel configuration wins when the legacy panel facade is loaded;
	 * otherwise the settings icon is used as the deterministic fallback.
	 *
	 * @return string Icon token.
	 */
	private static function defaultIcon(): string {
		if(class_exists('\dataphyre\panel', false)){
			$value=\dataphyre\panel::config('default_icon', 'settings');
			return is_string($value) && trim($value)!=='' ? trim($value) : 'settings';
		}
		return 'settings';
	}

	/**
	 * Converts a machine status or key into a display label.
	 *
	 * Common separators are replaced with spaces and words are title-cased for
	 * generated status views, labels, and fallback text.
	 *
	 * @param string $value Machine key or status token.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
