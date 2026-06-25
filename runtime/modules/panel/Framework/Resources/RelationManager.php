<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Fluent definition for a Panel relation manager.
 *
 * Relation managers describe how a resource renders and mutates related records, including table presentation, attach/detach flows, pivot updates, and authorization.
 */
final class RelationManager {
	use PanelExtensible;

	private string $name;
	private string $label;
	private string|\Closure|null $description=null;
	private string|\Closure|null $parentTitle=null;
	private string|\Closure|null $badge=null;
	private string $emptyHeading='No related records to show.';
	private ?string $emptyDescription=null;
	private ?string $relatedResource=null;
	private ?string $table=null;
	private ?string $foreignKey=null;
	private ?string $localKey=null;
	private ResourceTable $resourceTable;
	/** @var array<string, TableSummary> */
	private array $facts=[];
	private ?\Closure $queryFactory=null;
	private ?\Closure $authorizer=null;
	private ?\Closure $attachableRecordsHandler=null;
	private ?\Closure $attachHandler=null;
	private ?\Closure $detachHandler=null;
	private ?\Closure $associateHandler=null;
	private ?\Closure $dissociateHandler=null;
	private ?\Closure $reorderHandler=null;
	private ?\Closure $pivotUpdateHandler=null;
	private bool $readOnly=false;
	private bool $createEnabled=true;
	private bool $attachEnabled=true;
	private bool $detachEnabled=true;
	private bool $associateEnabled=false;
	private bool $dissociateEnabled=false;
	private bool $reorderEnabled=false;
	private string $attachLabel='Attach record';
	private string $detachLabel='Detach';
	private string $associateLabel='Associate record';
	private string $dissociateLabel='Dissociate';
	private string $reorderLabel='Reorder';
	private ?string $orderColumn=null;
	/** @var array<string,Field> */
	private array $pivotFields=[];
	/** @var array<string,mixed> */
	private array $meta=[];

	/**
	 * Creates a relation manager with a normalized manifest name and default table schema.
	 *
	 * construction establishes the immutable identity used by manifests,
	 * permission bridge relation keys, and renderer DOM/action namespaces. The
	 * default label is derived from the normalized name and can be replaced through
	 * the fluent clone-based configuration API.
	 */
	public function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
		$this->resourceTable=ResourceTable::make();
	}

	/**
	 * Builds a Panel relation definition from fluent input or array configuration.
	 *
	 * The builder normalizes names, labels, callbacks, renderer metadata, and manifest options before export.
	 *
	 * @param string $name Relation name before normalization.
	 * @return self Configured relation manager with normalized name and default table builder.
	 */
	public static function make(string $name): self {
		return self::configured(new self($name));
	}

	/**
	 * Builds a Panel relation definition from fluent input or array configuration.
	 *
	 * The builder normalizes names, labels, callbacks, renderer metadata, and manifest options before export.
	 *
	 * @param array<string,mixed> $definition Array manifest/configuration definition.
	 * @return self Relation manager hydrated from manifest/configuration data.
	 */
	public static function fromArray(array $definition): self {
		$relation=self::make((string)($definition['name'] ?? ''));
		if(isset($definition['label'])){
			$relation=$relation->label((string)$definition['label']);
		}
		if(isset($definition['description']) && (is_string($definition['description']) || is_callable($definition['description']))){
			$relation=$relation->description($definition['description']);
		}
		if(isset($definition['parent_title']) && (is_string($definition['parent_title']) || is_callable($definition['parent_title']))){
			$relation=$relation->parentTitle($definition['parent_title']);
		}
		if(isset($definition['badge']) && (is_scalar($definition['badge']) || is_callable($definition['badge']))){
			$relation=$relation->badge($definition['badge']);
		}
		if(isset($definition['empty_state']) && is_string($definition['empty_state'])){
			$relation=$relation->emptyState($definition['empty_state'], is_string($definition['empty_description'] ?? null) ? $definition['empty_description'] : null);
		}
		foreach(['related_resource', 'table', 'foreign_key', 'local_key'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$method=str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
				$method[0]=strtolower($method[0]);
				$relation=$relation->{$method}($definition[$key]);
			}
		}
		if(isset($definition['columns']) && is_array($definition['columns'])){
			$relation=$relation->columns($definition['columns']);
		}
		if(isset($definition['views']) && is_array($definition['views'])){
			$relation=$relation->views($definition['views']);
		}
		if(isset($definition['filters']) && is_array($definition['filters'])){
			$relation=$relation->filters($definition['filters']);
		}
		if(isset($definition['summaries']) && is_array($definition['summaries'])){
			$relation=$relation->summaries($definition['summaries']);
		}
		if(isset($definition['facts']) && is_array($definition['facts'])){
			$relation=$relation->facts($definition['facts']);
		}
		if(isset($definition['per_page'])){
			$relation=$relation->perPage((int)$definition['per_page']);
		}
		if(isset($definition['per_page_options']) && is_array($definition['per_page_options'])){
			$relation=$relation->perPageOptions($definition['per_page_options']);
		}
		if(isset($definition['default_sort']) && is_array($definition['default_sort'])){
			$relation=$relation->defaultSort((string)($definition['default_sort']['column'] ?? ''), (string)($definition['default_sort']['direction'] ?? 'asc'));
		}
		if(!empty($definition['read_only'])){
			$relation=$relation->readOnly();
		}
		if(array_key_exists('create_enabled', $definition)){
			$relation=$relation->create((bool)$definition['create_enabled']);
		}
		if(array_key_exists('attach_enabled', $definition)){
			$relation=$relation->attach((bool)$definition['attach_enabled']);
		}
		if(array_key_exists('detach_enabled', $definition)){
			$relation=$relation->detach((bool)$definition['detach_enabled']);
		}
		if(isset($definition['attach_label']) && is_string($definition['attach_label'])){
			$relation=$relation->attachLabel($definition['attach_label']);
		}
		if(isset($definition['detach_label']) && is_string($definition['detach_label'])){
			$relation=$relation->detachLabel($definition['detach_label']);
		}
		if(array_key_exists('associate_enabled', $definition)){
			$relation=$relation->associate((bool)$definition['associate_enabled']);
		}
		if(array_key_exists('dissociate_enabled', $definition)){
			$relation=$relation->dissociate((bool)$definition['dissociate_enabled']);
		}
		if(array_key_exists('reorder_enabled', $definition)){
			$relation=$relation->reorderable((bool)$definition['reorder_enabled'], is_string($definition['order_column'] ?? null) ? $definition['order_column'] : null);
		}
		if(isset($definition['associate_label']) && is_string($definition['associate_label'])){
			$relation=$relation->associateLabel($definition['associate_label']);
		}
		if(isset($definition['dissociate_label']) && is_string($definition['dissociate_label'])){
			$relation=$relation->dissociateLabel($definition['dissociate_label']);
		}
		if(isset($definition['reorder_label']) && is_string($definition['reorder_label'])){
			$relation=$relation->reorderLabel($definition['reorder_label']);
		}
		if(isset($definition['pivot_fields']) && is_array($definition['pivot_fields'])){
			$relation=$relation->pivotFields($definition['pivot_fields']);
		}
		if(isset($definition['attachable_records']) && is_callable($definition['attachable_records'])){
			$relation=$relation->attachableRecordsUsing($definition['attachable_records']);
		}
		if(isset($definition['attach']) && is_callable($definition['attach'])){
			$relation=$relation->attachUsing($definition['attach']);
		}
		if(isset($definition['detach']) && is_callable($definition['detach'])){
			$relation=$relation->detachUsing($definition['detach']);
		}
		if(isset($definition['associate']) && is_callable($definition['associate'])){
			$relation=$relation->associateUsing($definition['associate']);
		}
		if(isset($definition['dissociate']) && is_callable($definition['dissociate'])){
			$relation=$relation->dissociateUsing($definition['dissociate']);
		}
		if(isset($definition['reorder']) && is_callable($definition['reorder'])){
			$relation=$relation->reorderUsing($definition['reorder']);
		}
		if(isset($definition['update_pivot']) && is_callable($definition['update_pivot'])){
			$relation=$relation->updatePivotUsing($definition['update_pivot']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$relation=$relation->meta($definition['meta']);
		}
		return $relation;
	}

	/**
	 * Updates the name metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return string Resolved relation string value.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Updates the label metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param ?string $label Optional display label; null reads the current label.
	 * @return string|self Current label when read, or a cloned relation manager with an updated label.
	 */
	public function label(?string $label=null): string|self {
		if($label===null){
			return $this->label;
		}
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Updates the description metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param string|callable $description Static description or callback resolved against parent/relation context.
	 * @return self Cloned relation manager with updated description metadata.
	 */
	public function description(string|callable $description): self {
		$clone=clone $this;
		$clone->description=is_string($description) ? (trim($description) ?: null) : \Closure::fromCallable($description);
		return $clone;
	}

	/**
	 * Updates the parent title metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param string|callable $title Static parent title or callback resolved against relation context.
	 * @return self Cloned relation manager with updated parent title metadata.
	 */
	public function parentTitle(string|callable $title): self {
		$clone=clone $this;
		$clone->parentTitle=is_string($title) ? (trim($title) ?: null) : \Closure::fromCallable($title);
		return $clone;
	}

	/**
	 * Updates the parent title using metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param callable $resolver Relation-aware resolver callback.
	 * @return self Cloned relation manager with updated parent title using metadata.
	 */
	public function parentTitleUsing(callable $resolver): self {
		return $this->parentTitle($resolver);
	}

	/**
	 * Updates the badge metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param string|int|float|callable|null $badge Static badge value, badge callback, or null.
	 * @return self Cloned relation manager with updated badge metadata.
	 */
	public function badge(string|int|float|callable|null $badge): self {
		$clone=clone $this;
		$clone->badge=is_callable($badge) ? \Closure::fromCallable($badge) : ($badge===null ? null : (string)$badge);
		return $clone;
	}

	/**
	 * Updates the badge using metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param callable $resolver Relation-aware resolver callback.
	 * @return self Cloned relation manager with updated badge using metadata.
	 */
	public function badgeUsing(callable $resolver): self {
		return $this->badge($resolver);
	}

	/**
	 * Updates the empty state metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param string $heading Empty-state heading displayed when no related records are shown.
	 * @param ?string $description Optional empty-state description.
	 * @return self Cloned relation manager with updated empty state metadata.
	 */
	public function emptyState(string $heading, ?string $description=null): self {
		$clone=clone $this;
		$clone->emptyHeading=trim($heading) ?: 'No related records to show.';
		$clone->emptyDescription=$description!==null ? (trim($description) ?: null) : null;
		return $clone;
	}

	/**
	 * Updates the related resource metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param string $resource Related resource name before normalization.
	 * @return self Cloned relation manager with updated related resource metadata.
	 */
	public function relatedResource(string $resource): self {
		$clone=clone $this;
		$clone->relatedResource=Resource::normalizeName($resource) ?: null;
		return $clone;
	}

	/**
	 * Configures related-record table presentation.
	 *
	 * Relation table metadata controls columns, filters, views, summaries, facts, empty state, and read-only behavior.
	 *
	 * @param string $table Database table used as the relation source.
	 * @return self Cloned relation manager with updated table metadata.
	 */
	public function table(string $table): self {
		$clone=clone $this;
		$clone->table=trim($table) ?: null;
		return $clone;
	}

	/**
	 * Updates the foreign key metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param string $key Record key column before normalization.
	 * @return self Cloned relation manager with updated foreign key metadata.
	 */
	public function foreignKey(string $key): self {
		$clone=clone $this;
		$clone->foreignKey=Resource::normalizeName($key) ?: null;
		return $clone;
	}

	/**
	 * Updates the local key metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param string $key Record key column before normalization.
	 * @return self Cloned relation manager with updated local key metadata.
	 */
	public function localKey(string $key): self {
		$clone=clone $this;
		$clone->localKey=Resource::normalizeName($key) ?: null;
		return $clone;
	}

	/**
	 * Updates the query using metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param callable $queryFactory Callback that returns the relation query source.
	 * @return self Cloned relation manager with updated query using metadata.
	 */
	public function queryUsing(callable $queryFactory): self {
		$clone=clone $this;
		$clone->queryFactory=\Closure::fromCallable($queryFactory);
		return $clone;
	}

	/**
	 * Updates the authorize metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param callable $authorizer Callback that authorizes relation abilities.
	 * @return self Cloned relation manager with updated authorize metadata.
	 */
	public function authorize(callable $authorizer): self {
		$clone=clone $this;
		$clone->authorizer=\Closure::fromCallable($authorizer);
		return $clone;
	}

	/**
	 * Updates the read only metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param bool $readOnly Whether relation mutations are disabled.
	 * @return self Cloned relation manager with updated read only metadata.
	 */
	public function readOnly(bool $readOnly=true): self {
		$clone=clone $this;
		$clone->readOnly=$readOnly;
		return $clone;
	}

	/**
	 * Updates the create metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param bool $enabled Whether the relation operation is enabled.
	 * @return self Cloned relation manager with updated create metadata.
	 */
	public function create(bool $enabled=true): self {
		$clone=clone $this;
		$clone->createEnabled=$enabled;
		return $clone;
	}

	/**
	 * Updates the without create metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return self Cloned relation manager with updated without create metadata.
	 */
	public function withoutCreate(): self {
		return $this->create(false);
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param bool $enabled Whether the relation operation is enabled.
	 * @return self Cloned relation manager with updated attach metadata.
	 */
	public function attach(bool $enabled=true): self {
		$clone=clone $this;
		$clone->attachEnabled=$enabled;
		return $clone;
	}

	/**
	 * Updates the without attach metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return self Cloned relation manager with updated without attach metadata.
	 */
	public function withoutAttach(): self {
		return $this->attach(false);
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param bool $enabled Whether the relation operation is enabled.
	 * @return self Cloned relation manager with updated detach metadata.
	 */
	public function detach(bool $enabled=true): self {
		$clone=clone $this;
		$clone->detachEnabled=$enabled;
		return $clone;
	}

	/**
	 * Updates the without detach metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return self Cloned relation manager with updated without detach metadata.
	 */
	public function withoutDetach(): self {
		return $this->detach(false);
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param string $label User-facing relation operation label.
	 * @return self Cloned relation manager with updated attach label metadata.
	 */
	public function attachLabel(string $label): self {
		$clone=clone $this;
		$clone->attachLabel=trim($label) ?: 'Attach record';
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param string $label User-facing relation operation label.
	 * @return self Cloned relation manager with updated detach label metadata.
	 */
	public function detachLabel(string $label): self {
		$clone=clone $this;
		$clone->detachLabel=trim($label) ?: 'Detach';
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param bool $enabled Whether the relation operation is enabled.
	 * @return self Cloned relation manager with updated associate metadata.
	 */
	public function associate(bool $enabled=true): self {
		$clone=clone $this;
		$clone->associateEnabled=$enabled;
		return $clone;
	}

	/**
	 * Updates the without associate metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return self Cloned relation manager with updated without associate metadata.
	 */
	public function withoutAssociate(): self {
		return $this->associate(false);
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param bool $enabled Whether the relation operation is enabled.
	 * @return self Cloned relation manager with updated dissociate metadata.
	 */
	public function dissociate(bool $enabled=true): self {
		$clone=clone $this;
		$clone->dissociateEnabled=$enabled;
		return $clone;
	}

	/**
	 * Updates the without dissociate metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return self Cloned relation manager with updated without dissociate metadata.
	 */
	public function withoutDissociate(): self {
		return $this->dissociate(false);
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param bool $enabled Whether the relation operation is enabled.
	 * @param ?string $orderColumn Column storing related-record order.
	 * @return self Cloned relation manager with updated reorderable metadata.
	 */
	public function reorderable(bool $enabled=true, ?string $orderColumn=null): self {
		$clone=clone $this;
		$clone->reorderEnabled=$enabled;
		if($orderColumn!==null){
			$clone->orderColumn=Resource::normalizeName($orderColumn) ?: null;
		}
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param string $label User-facing relation operation label.
	 * @return self Cloned relation manager with updated associate label metadata.
	 */
	public function associateLabel(string $label): self {
		$clone=clone $this;
		$clone->associateLabel=trim($label) ?: 'Associate record';
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param string $label User-facing relation operation label.
	 * @return self Cloned relation manager with updated dissociate label metadata.
	 */
	public function dissociateLabel(string $label): self {
		$clone=clone $this;
		$clone->dissociateLabel=trim($label) ?: 'Dissociate';
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param string $label User-facing relation operation label.
	 * @return self Cloned relation manager with updated reorder label metadata.
	 */
	public function reorderLabel(string $label): self {
		$clone=clone $this;
		$clone->reorderLabel=trim($label) ?: 'Reorder';
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param array<int,Field|array<string,mixed>|string> $fields Pivot fields collected for attach/update-pivot forms.
	 * @return self Cloned relation manager with updated pivot fields metadata.
	 */
	public function pivotFields(array $fields): self {
		$clone=clone $this;
		$clone->pivotFields=[];
		foreach($fields as $field){
			$field=$field instanceof Field ? $field : (is_array($field) ? Field::fromArray($field) : Field::make((string)$field));
			if($field->name()!==''){
				$clone->pivotFields[$field->name()]=$field;
			}
		}
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param Field|array|string $field Pivot field definition, manifest array, or field name.
	 * @param ?string $type Field or summary type used when constructing from a string.
	 * @return self Cloned relation manager with updated pivot field metadata.
	 */
	public function pivotField(Field|array|string $field, ?string $type=null): self {
		$field=$field instanceof Field ? $field : (is_array($field) ? Field::fromArray($field) : Field::make((string)$field, $type ?? 'text'));
		$clone=clone $this;
		if($field->name()!==''){
			$clone->pivotFields[$field->name()]=$field;
		}
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param callable $handler Relation operation handler callback.
	 * @return self Cloned relation manager with updated attachable records using metadata.
	 */
	public function attachableRecordsUsing(callable $handler): self {
		$clone=clone $this;
		$clone->attachableRecordsHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param callable $handler Relation operation handler callback.
	 * @return self Cloned relation manager with updated attach using metadata.
	 */
	public function attachUsing(callable $handler): self {
		$clone=clone $this;
		$clone->attachHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param callable $handler Relation operation handler callback.
	 * @return self Cloned relation manager with updated detach using metadata.
	 */
	public function detachUsing(callable $handler): self {
		$clone=clone $this;
		$clone->detachHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param callable $handler Relation operation handler callback.
	 * @return self Cloned relation manager with updated associate using metadata.
	 */
	public function associateUsing(callable $handler): self {
		$clone=clone $this;
		$clone->associateHandler=\Closure::fromCallable($handler);
		$clone->associateEnabled=true;
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param callable $handler Relation operation handler callback.
	 * @return self Cloned relation manager with updated dissociate using metadata.
	 */
	public function dissociateUsing(callable $handler): self {
		$clone=clone $this;
		$clone->dissociateHandler=\Closure::fromCallable($handler);
		$clone->dissociateEnabled=true;
		return $clone;
	}

	/**
	 * Configures relation mutation behavior.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 *
	 * @param callable $handler Relation operation handler callback.
	 * @param ?string $orderColumn Column storing related-record order.
	 * @return self Cloned relation manager with updated reorder using metadata.
	 */
	public function reorderUsing(callable $handler, ?string $orderColumn=null): self {
		$clone=clone $this;
		$clone->reorderHandler=\Closure::fromCallable($handler);
		$clone->reorderEnabled=true;
		if($orderColumn!==null){
			$clone->orderColumn=Resource::normalizeName($orderColumn) ?: null;
		}
		return $clone;
	}

	/**
	 * Updates the update pivot using metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param callable $handler Relation operation handler callback.
	 * @return self Cloned relation manager with updated update pivot using metadata.
	 */
	public function updatePivotUsing(callable $handler): self {
		$clone=clone $this;
		$clone->pivotUpdateHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Configures related-record table presentation.
	 *
	 * Relation table metadata controls columns, filters, views, summaries, facts, empty state, and read-only behavior.
	 *
	 * @param array<int,Column|array<string,mixed>|string> $columns Related-record table columns.
	 * @return self Cloned relation manager with updated columns metadata.
	 */
	public function columns(array $columns): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->columns($columns);
		return $clone;
	}

	/**
	 * Configures related-record table presentation.
	 *
	 * Relation table metadata controls columns, filters, views, summaries, facts, empty state, and read-only behavior.
	 *
	 * @param Column|array|string $column Column.
	 * @param ?string $type Field or summary type used when constructing from a string.
	 * @return self Cloned relation manager with updated column metadata.
	 */
	public function column(Column|array|string $column, ?string $type=null): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->column($column, $type);
		return $clone;
	}

	/**
	 * Updates the per page metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param int $rows Related-record rows shown per page.
	 * @return self Cloned relation manager with updated per page metadata.
	 */
	public function perPage(int $rows): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->perPage($rows);
		return $clone;
	}

	/**
	 * Updates the per page options metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param array<int,int> $options Allowed related-record page sizes.
	 * @return self Cloned relation manager with updated per page options metadata.
	 */
	public function perPageOptions(array $options): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->perPageOptions($options);
		return $clone;
	}

	/**
	 * Updates the default sort metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param string $column Related-record column used for initial table ordering.
	 * @param string $direction Sort direction passed to the relation table.
	 * @return self Cloned relation manager with updated default sort metadata.
	 */
	public function defaultSort(string $column, string $direction='asc'): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->defaultSort($column, $direction);
		return $clone;
	}

	/**
	 * Configures related-record table presentation.
	 *
	 * Relation table metadata controls columns, filters, views, summaries, facts, empty state, and read-only behavior.
	 *
	 * @param array<int,TableView|array<string,mixed>|string> $views Related-record table views.
	 * @return self Cloned relation manager with updated views metadata.
	 */
	public function views(array $views): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->views($views);
		return $clone;
	}

	/**
	 * Configures related-record table presentation.
	 *
	 * Relation table metadata controls columns, filters, views, summaries, facts, empty state, and read-only behavior.
	 *
	 * @param TableView|array|string $view View.
	 * @return self Cloned relation manager with updated view metadata.
	 */
	public function view(TableView|array|string $view): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->view($view);
		return $clone;
	}

	/**
	 * Configures related-record table presentation.
	 *
	 * Relation table metadata controls columns, filters, views, summaries, facts, empty state, and read-only behavior.
	 *
	 * @param array<int,TableFilter|array<string,mixed>|string> $filters Related-record table filters.
	 * @return self Cloned relation manager with updated filters metadata.
	 */
	public function filters(array $filters): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->filters($filters);
		return $clone;
	}

	/**
	 * Configures related-record table presentation.
	 *
	 * Relation table metadata controls columns, filters, views, summaries, facts, empty state, and read-only behavior.
	 *
	 * @param TableFilter|array|string $filter Filter.
	 * @param ?string $type Field or summary type used when constructing from a string.
	 * @return self Cloned relation manager with updated filter metadata.
	 */
	public function filter(TableFilter|array|string $filter, ?string $type=null): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->filter($filter, $type);
		return $clone;
	}

	/**
	 * Configures related-record table presentation.
	 *
	 * Relation table metadata controls columns, filters, views, summaries, facts, empty state, and read-only behavior.
	 *
	 * @param array<int,TableSummary|array<string,mixed>|string> $summaries Related-record table summaries.
	 * @return self Cloned relation manager with updated summaries metadata.
	 */
	public function summaries(array $summaries): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->summaries($summaries);
		return $clone;
	}

	/**
	 * Configures related-record table presentation.
	 *
	 * Relation table metadata controls columns, filters, views, summaries, facts, empty state, and read-only behavior.
	 *
	 * @param TableSummary|array|string $summary Summary.
	 * @param ?string $type Field or summary type used when constructing from a string.
	 * @return self Cloned relation manager with updated summary metadata.
	 */
	public function summary(TableSummary|array|string $summary, ?string $type=null): self {
		$clone=clone $this;
		$clone->resourceTable=$clone->resourceTable->summary($summary, $type);
		return $clone;
	}

	/**
	 * Updates the facts metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param array<int,TableSummary|array<string,mixed>|string> $facts Relation facts exposed beside the table.
	 * @return self Cloned relation manager with updated facts metadata.
	 */
	public function facts(array $facts): self {
		$clone=clone $this;
		$clone->facts=[];
		foreach($facts as $fact){
			$clone=$clone->fact($fact);
		}
		return $clone;
	}

	/**
	 * Updates the fact metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param TableSummary|array|string $fact Fact.
	 * @param ?string $type Field or summary type used when constructing from a string.
	 * @return self Cloned relation manager with updated fact metadata.
	 */
	public function fact(TableSummary|array|string $fact, ?string $type=null): self {
		$fact=$fact instanceof TableSummary
			? $fact
			: (is_array($fact) ? TableSummary::fromArray($fact) : TableSummary::make((string)$fact, $type ?? 'count'));
		$clone=clone $this;
		$clone->facts[$fact->name()]=$fact;
		return $clone;
	}

	/**
	 * Returns the table builder used by this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return ResourceTable Table builder used to render related records.
	 */
	public function resourceTable(): ResourceTable {
		return $this->resourceTable;
	}

	/**
	 * Returns the normalized related resource name.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return ?string Normalized related resource name, or null when unset.
	 */
	public function relatedResourceName(): ?string {
		return $this->relatedResource;
	}

	/**
	 * Returns the normalized foreign key column.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return ?string Normalized foreign key column, or null when unset.
	 */
	public function foreignKeyName(): ?string {
		return $this->foreignKey;
	}

	/**
	 * Returns the normalized parent local key column.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return ?string Normalized parent local key column, or null when unset.
	 */
	public function localKeyName(): ?string {
		return $this->localKey;
	}

	/**
	 * Reports whether the relation is read-only.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return bool True when all relation mutations are disabled.
	 */
	public function isReadOnly(): bool {
		return $this->readOnly;
	}

	/**
	 * Evaluates whether the relation can create in the current state.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return bool True when create is enabled and resource/key metadata can build related records.
	 */
	public function canCreate(): bool {
		return $this->createEnabled && !$this->readOnly && $this->relatedResource!==null && $this->foreignKey!==null && $this->localKey!==null;
	}

	/**
	 * Evaluates whether the relation can attach in the current state.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return bool True when attach is enabled, writable, and a handler is registered.
	 */
	public function canAttach(): bool {
		return $this->attachEnabled && !$this->readOnly && $this->attachHandler!==null;
	}

	/**
	 * Evaluates whether the relation can detach in the current state.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return bool True when detach is enabled, writable, and a handler is registered.
	 */
	public function canDetach(): bool {
		return $this->detachEnabled && !$this->readOnly && $this->detachHandler!==null;
	}

	/**
	 * Evaluates whether the relation can associate in the current state.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return bool True when associate is enabled, writable, and a handler is registered.
	 */
	public function canAssociate(): bool {
		return $this->associateEnabled && !$this->readOnly && $this->associateHandler!==null;
	}

	/**
	 * Evaluates whether the relation can dissociate in the current state.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return bool True when dissociate is enabled, writable, and a handler is registered.
	 */
	public function canDissociate(): bool {
		return $this->dissociateEnabled && !$this->readOnly && $this->dissociateHandler!==null;
	}

	/**
	 * Evaluates whether the relation can reorder in the current state.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return bool True when reorder is enabled, writable, and a handler is registered.
	 */
	public function canReorder(): bool {
		return $this->reorderEnabled && !$this->readOnly && $this->reorderHandler!==null;
	}

	/**
	 * Evaluates whether the relation can update pivot in the current state.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return bool True when pivot update is writable, handled, and has pivot fields.
	 */
	public function canUpdatePivot(): bool {
		return !$this->readOnly && $this->pivotUpdateHandler!==null && $this->pivotFields!==[];
	}

	/**
	 * Returns the label used for the attach action.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 * @return string Attach action label.
	 */
	public function attachLabelText(): string {
		return $this->attachLabel;
	}

	/**
	 * Returns the label used for the detach action.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 * @return string Detach action label.
	 */
	public function detachLabelText(): string {
		return $this->detachLabel;
	}

	/**
	 * Returns the label used for the associate action.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 * @return string Associate action label.
	 */
	public function associateLabelText(): string {
		return $this->associateLabel;
	}

	/**
	 * Returns the label used for the dissociate action.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 * @return string Dissociate action label.
	 */
	public function dissociateLabelText(): string {
		return $this->dissociateLabel;
	}

	/**
	 * Returns the label used for the reorder action.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 * @return string Reorder action label.
	 */
	public function reorderLabelText(): string {
		return $this->reorderLabel;
	}

	/**
	 * Returns pivot field definitions keyed by field name.
	 *
	 * Mutation metadata controls attach, detach, associate, dissociate, reorder, and pivot update workflows for related records.
	 * @return array<string,Field> Pivot field definitions keyed by field name.
	 */
	public function pivotFieldDefinitions(): array {
		return $this->pivotFields;
	}

	/**
	 * Merges additional relation manifest metadata.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param array<string,mixed> $meta Relation metadata merged into the serialized manifest.
	 * @return self Cloned relation manager with updated manifest metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Resolves the description for the current parent/relation context.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param ?PanelRequest $request Panel request for user, pagination, and table context.
	 * @param ?Resource $parentResource Resource that owns the parent record.
	 * @param array<int,mixed> $records Related records used to resolve dynamic text.
	 * @return ?string Static or callback-resolved relation description.
	 */
	public function resolveDescription(mixed $parentRecord=null, ?PanelRequest $request=null, ?Resource $parentResource=null, array $records=[]): ?string {
		return $this->resolveText($this->description, $parentRecord, $request, $parentResource, $records);
	}

	/**
	 * Resolves the parent title for the current parent/relation context.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param ?PanelRequest $request Panel request for user, pagination, and table context.
	 * @param ?Resource $parentResource Resource that owns the parent record.
	 * @param array<int,mixed> $records Related records used to resolve the parent title.
	 * @return ?string Static or callback-resolved parent title.
	 */
	public function resolveParentTitle(mixed $parentRecord=null, ?PanelRequest $request=null, ?Resource $parentResource=null, array $records=[]): ?string {
		return $this->resolveText($this->parentTitle, $parentRecord, $request, $parentResource, $records);
	}

	/**
	 * Resolves the badge for the current parent/relation context.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param array<int,mixed> $records Related records used to resolve the badge.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param ?PanelRequest $request Panel request for user, pagination, and table context.
	 * @param ?Resource $parentResource Resource that owns the parent record.
	 * @return ?string Static or callback-resolved badge text.
	 */
	public function resolveBadge(array $records=[], mixed $parentRecord=null, ?PanelRequest $request=null, ?Resource $parentResource=null): ?string {
		if($this->badge===null){
			return null;
		}
		if($this->badge instanceof \Closure){
			return $this->normalizeNullableString(($this->badge)($records, $parentRecord, $request, $parentResource, $this));
		}
		return $this->normalizeNullableString($this->badge);
	}

	/**
	 * Resolves the facts for the current parent/relation context.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param array<int,mixed> $records Related records used to resolve fact values.
	 * @param Resource $parentResource Resource that owns the parent record.
	 * @param PanelRequest $request Panel request for user, pagination, and table context.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @return array<int,array<string,mixed>> Resolved fact payloads.
	 */
	public function resolveFacts(array $records, Resource $parentResource, PanelRequest $request, mixed $parentRecord=null): array {
		$facts=[];
		foreach($this->facts as $fact){
			$facts[]=$fact->resolve($records, $parentResource, $request);
		}
		return $facts;
	}

	/**
	 * Resolves the empty state for the current parent/relation context.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param PanelRequest $request Panel request for user, pagination, and table context.
	 * @param bool $hasConstraints Whether search, filters, or views currently constrain the relation.
	 * @return array{heading:string,description:string} Empty-state copy for the current relation constraints.
	 */
	public function resolveEmptyState(PanelRequest $request, bool $hasConstraints=false): array {
		if($hasConstraints){
			return [
				'heading'=>'No related records match this view.',
				'description'=>'Clear the search, filters, or selected view to see more of this relationship.',
			];
		}
		return [
			'heading'=>$this->emptyHeading,
			'description'=>$this->emptyDescription ?? 'When this record gains related activity, it will appear here with the same search and table controls.',
		];
	}

	/**
	 * Authorizes a relation ability for the current parent/user context.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param string $ability Relation ability being authorized.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param mixed $user Current user or actor.
	 * @param ?Resource $parentResource Resource that owns the parent record.
	 * @return bool True when permission bridge and custom authorizer allow the ability.
	 */
	public function can(string $ability, mixed $parentRecord=null, mixed $user=null, ?Resource $parentResource=null): bool {
		if(self::permissionAllows($this->name, $ability, $parentResource, $user, $parentRecord)===false){
			return false;
		}
		if($this->authorizer!==null){
			return (bool)($this->authorizer)($ability, $parentRecord, $user, $parentResource, $this);
		}
		return true;
	}

	/**
	 * Checks the permission bridge for a relation ability.
	 *
	 * relation abilities are collapsed to the bridge's relation operation
	 * vocabulary before authorization. If no parent resource or configured
	 * permission bridge exists, this helper allows the action so local authorizers
	 * and relation flags remain the only enforcement layer.
	 */
	private static function permissionAllows(string $relation, string $ability, ?Resource $resource=null, mixed $user=null, mixed $record=null): bool {
		if(!$resource instanceof Resource || !PanelPermissionBridge::configured()){
			return true;
		}
		$operation=self::permissionRelationOperation($ability);
		$relation=Resource::normalizeName($relation);
		return PanelPermissionBridge::allows(PanelPermissionBridge::relationName($resource->name(), $relation, $operation), $user, [
			'resource'=>$resource->name(),
			'relation'=>$relation,
			'operation'=>$operation,
			'record'=>$record,
		]);
	}

	/**
	 * Maps relation abilities to permission-bridge operations.
	 *
	 * read-oriented aliases resolve to view while all mutation and custom
	 * abilities resolve to update, matching the bridge's coarse relation permission
	 * model and avoiding per-action permission name drift.
	 */
	private static function permissionRelationOperation(string $ability): string {
		$ability=Resource::normalizeName($ability);
		return in_array($ability, ['view', 'index', 'list'], true) ? 'view' : 'update';
	}

	/**
	 * Runs the records mutation workflow for related records.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param Resource $parentResource Resource that owns the parent record.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param ?PanelRequest $request Panel request for user, pagination, and table context.
	 * @param bool $preferAll Whether full-record getters are preferred over paginated getters.
	 * @return array<int,mixed> Related records after query resolution and parent-key filtering.
	 */
	public function records(Resource $parentResource, mixed $parentRecord, ?PanelRequest $request=null, bool $preferAll=false): array {
		$query=$this->makeQuery($parentResource, $parentRecord, $request);
		if($query===null){
			return [];
		}
		if(is_array($query)){
			return $this->filterRecordsByKeys($query, $parentRecord);
		}
		$methods=$preferAll
			? ['getRecords', 'get', 'paginateRecords', 'paginate']
			: ['paginateRecords', 'paginate', 'getRecords', 'get'];
		foreach($methods as $method){
			if(!method_exists($query, $method)){
				continue;
			}
			$result=$method==='paginateRecords' || $method==='paginate'
				? $query->{$method}($request?->page() ?? 1, $request?->perPage() ?? 25)
				: $query->{$method}();
			if(is_object($result) && method_exists($result, 'items')){
				$result=$result->items();
			}
			return is_array($result) ? $this->filterRecordsByKeys($result, $parentRecord) : [];
		}
		return [];
	}

	/**
	 * Resolves attachable related records for the current parent record.
	 *
	 * Custom resolvers receive the parent record, parent resource, current request, and relation manager; fallback discovery excludes already attached records.
	 *
	 * @param Resource $parentResource Resource that owns the parent record.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param ?PanelRequest $request Panel request for user, pagination, and table context.
	 * @return array<int,mixed> Candidate related records excluding records already attached to the parent.
	 */
	public function attachableRecords(Resource $parentResource, mixed $parentRecord, ?PanelRequest $request=null): array {
		if($this->attachableRecordsHandler!==null){
			$records=($this->attachableRecordsHandler)($parentRecord, $parentResource, $request, $this);
			return is_array($records) ? array_values($records) : [];
		}
		if($this->relatedResource===null || !($resource=Panel::get($this->relatedResource)) instanceof Resource){
			return [];
		}
		$query=$resource->makeQuery($request);
		$records=[];
		if(is_array($query)){
			$records=$query;
		}
		elseif(is_object($query)){
			foreach(['getRecords', 'get', 'paginateRecords', 'paginate'] as $method){
				if(!method_exists($query, $method)){
					continue;
				}
				$result=($method==='paginateRecords' || $method==='paginate') ? $query->{$method}(1, 250) : $query->{$method}();
				if(is_object($result) && method_exists($result, 'items')){
					$result=$result->items();
				}
				$records=is_array($result) ? $result : [];
				break;
			}
		}
		$attached=[];
		foreach($this->records($parentResource, $parentRecord, $request, true) as $record){
			$key=$resource->recordKey($record);
			if($key!==''){
				$attached[$key]=true;
			}
		}
		return array_values(array_filter($records, static function(mixed $record) use ($resource, $attached): bool {
			$key=$resource->recordKey($record);
			return $key==='' || !isset($attached[$key]);
		}));
	}

	/**
	 * Runs the attach mutation workflow for a related record.
	 *
	 * Mutation handlers receive the parent record, selected related key or keys, current request, parent resource, and relation manager.
	 *
	 * @param Resource $parentResource Resource that owns the parent record.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param mixed $relatedKey Key of the related record being attached or associated.
	 * @param PanelRequest $request Panel request for user, pagination, and table context.
	 * @return mixed Attach handler result, or a failure payload when no handler is registered.
	 */
	public function attachRecord(Resource $parentResource, mixed $parentRecord, mixed $relatedKey, PanelRequest $request): mixed {
		if($this->attachHandler===null){
			return ['attached'=>false, 'message'=>'No attach handler is registered for this relation.'];
		}
		return ($this->attachHandler)($parentRecord, $relatedKey, $request, $parentResource, $this);
	}

	/**
	 * Runs the detach mutation workflow for a related record.
	 *
	 * Mutation handlers receive the parent record, selected related key or keys, current request, parent resource, and relation manager.
	 *
	 * @param Resource $parentResource Resource that owns the parent record.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param mixed $childKey Key of the related child record being mutated.
	 * @param PanelRequest $request Panel request for user, pagination, and table context.
	 * @return mixed Detach handler result, or a failure payload when no handler is registered.
	 */
	public function detachRecord(Resource $parentResource, mixed $parentRecord, mixed $childKey, PanelRequest $request): mixed {
		if($this->detachHandler===null){
			return ['detached'=>false, 'message'=>'No detach handler is registered for this relation.'];
		}
		return ($this->detachHandler)($parentRecord, $childKey, $request, $parentResource, $this);
	}

	/**
	 * Runs the associate mutation workflow for a related record.
	 *
	 * Mutation handlers receive the parent record, selected related key or keys, current request, parent resource, and relation manager.
	 *
	 * @param Resource $parentResource Resource that owns the parent record.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param mixed $relatedKey Key of the related record being attached or associated.
	 * @param PanelRequest $request Panel request for user, pagination, and table context.
	 * @return mixed Associate handler result, or a failure payload when no handler is registered.
	 */
	public function associateRecord(Resource $parentResource, mixed $parentRecord, mixed $relatedKey, PanelRequest $request): mixed {
		if($this->associateHandler===null){
			return ['associated'=>false, 'message'=>'No associate handler is registered for this relation.'];
		}
		return ($this->associateHandler)($parentRecord, $relatedKey, $request, $parentResource, $this);
	}

	/**
	 * Runs the dissociate mutation workflow for a related record.
	 *
	 * Mutation handlers receive the parent record, selected related key or keys, current request, parent resource, and relation manager.
	 *
	 * @param Resource $parentResource Resource that owns the parent record.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param mixed $childKey Key of the related child record being mutated.
	 * @param PanelRequest $request Panel request for user, pagination, and table context.
	 * @return mixed Dissociate handler result, or a failure payload when no handler is registered.
	 */
	public function dissociateRecord(Resource $parentResource, mixed $parentRecord, mixed $childKey, PanelRequest $request): mixed {
		if($this->dissociateHandler===null){
			return ['dissociated'=>false, 'message'=>'No dissociate handler is registered for this relation.'];
		}
		return ($this->dissociateHandler)($parentRecord, $childKey, $request, $parentResource, $this);
	}

	/**
	 * Runs the reorder mutation workflow for related records.
	 *
	 * Mutation handlers receive the parent record, selected related key or keys, current request, parent resource, and relation manager.
	 *
	 * @param Resource $parentResource Resource that owns the parent record.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param array<int,mixed> $orderedKeys Related record keys in their desired order.
	 * @param PanelRequest $request Panel request for user, pagination, and table context.
	 * @return mixed Reorder handler result, or a failure payload when no handler is registered.
	 */
	public function reorderRecords(Resource $parentResource, mixed $parentRecord, array $orderedKeys, PanelRequest $request): mixed {
		if($this->reorderHandler===null){
			return ['reordered'=>false, 'message'=>'No reorder handler is registered for this relation.'];
		}
		return ($this->reorderHandler)($parentRecord, array_values($orderedKeys), $request, $parentResource, $this);
	}

	/**
	 * Runs the update pivot record mutation workflow for related records.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param Resource $parentResource Resource that owns the parent record.
	 * @param mixed $parentRecord Parent record that owns the relation.
	 * @param mixed $childKey Key of the related child record being mutated.
	 * @param array<string,mixed> $values Pivot field values to persist for the related record.
	 * @param PanelRequest $request Panel request for user, pagination, and table context.
	 * @return mixed Pivot-update handler result, or a failure payload when no handler is registered.
	 */
	public function updatePivotRecord(Resource $parentResource, mixed $parentRecord, mixed $childKey, array $values, PanelRequest $request): mixed {
		if($this->pivotUpdateHandler===null){
			return ['updated'=>false, 'message'=>'No pivot update handler is registered for this relation.'];
		}
		return ($this->pivotUpdateHandler)($parentRecord, $childKey, $values, $request, $parentResource, $this);
	}

	/**
	 * Serializes this relation manager for renderer manifests.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 * @return array{name:string,label:string,description:?string,description_dynamic:bool,parent_title:?string,parent_title_dynamic:bool,badge:?string,badge_dynamic:bool,empty_state:string,empty_description:?string,related_resource:?string,table:?string,foreign_key:?string,local_key:?string,read_only:bool,create_enabled:bool,attach_enabled:bool,detach_enabled:bool,associate_enabled:bool,dissociate_enabled:bool,reorder_enabled:bool,attach_label:string,detach_label:string,associate_label:string,dissociate_label:string,reorder_label:string,order_column:?string,pivot_fields:array<int,array<string,mixed>>,table_schema:array<string,mixed>,facts:array<int,array<string,mixed>>,queryable:bool,authorizes:bool,attaches:bool,detaches:bool,associates:bool,dissociates:bool,reorders:bool,updates_pivot:bool,operations:array<string,array<string,mixed>>,meta:array<string,mixed>} Serialized relation manager definition.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'description'=>is_string($this->description) ? $this->description : null,
			'description_dynamic'=>$this->description instanceof \Closure,
			'parent_title'=>is_string($this->parentTitle) ? $this->parentTitle : null,
			'parent_title_dynamic'=>$this->parentTitle instanceof \Closure,
			'badge'=>is_string($this->badge) ? $this->badge : null,
			'badge_dynamic'=>$this->badge instanceof \Closure,
			'empty_state'=>$this->emptyHeading,
			'empty_description'=>$this->emptyDescription,
			'related_resource'=>$this->relatedResource,
			'table'=>$this->table,
			'foreign_key'=>$this->foreignKey,
			'local_key'=>$this->localKey,
			'read_only'=>$this->readOnly,
			'create_enabled'=>$this->createEnabled,
			'attach_enabled'=>$this->attachEnabled,
			'detach_enabled'=>$this->detachEnabled,
			'associate_enabled'=>$this->associateEnabled,
			'dissociate_enabled'=>$this->dissociateEnabled,
			'reorder_enabled'=>$this->reorderEnabled,
			'attach_label'=>$this->attachLabel,
			'detach_label'=>$this->detachLabel,
			'associate_label'=>$this->associateLabel,
			'dissociate_label'=>$this->dissociateLabel,
			'reorder_label'=>$this->reorderLabel,
			'order_column'=>$this->orderColumn,
			'pivot_fields'=>array_map(static fn(Field $field): array => $field->toArray(), array_values($this->pivotFields)),
			'table_schema'=>$this->resourceTable->toArray(),
			'facts'=>array_map(static fn(TableSummary $fact): array => $fact->toArray(), array_values($this->facts)),
			'queryable'=>$this->queryFactory!==null || $this->table!==null || $this->relatedResource!==null,
			'authorizes'=>$this->authorizer!==null,
			'attaches'=>$this->attachHandler!==null,
			'detaches'=>$this->detachHandler!==null,
			'associates'=>$this->associateHandler!==null,
			'dissociates'=>$this->dissociateHandler!==null,
			'reorders'=>$this->reorderHandler!==null,
			'updates_pivot'=>$this->pivotUpdateHandler!==null,
			'operations'=>$this->operationDefinitions(),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Updates the manifest metadata for this relation.
	 *
	 * Relation managers describe how a parent resource discovers, renders, and mutates connected records.
	 *
	 * @param ?PanelRequest $request Panel request for user, pagination, and table context.
	 * @param array<string,mixed> $meta Manifest metadata merged into the relation payload.
	 * @return array<string,mixed> Relation manifest payload.
	 */
	public function manifest(?PanelRequest $request=null, array $meta=[]): array {
		return RelationManifest::from($this, $request, $meta)->toArray();
	}

	/**
	 * Builds the backing record source for this relation.
	 *
	 * query resolution prefers the user-supplied query factory, then a
	 * database table query constrained by foreign/local keys, and finally the
	 * related Resource query. Returning null means the relation has no discoverable
	 * source and should render as empty rather than throwing from the renderer.
	 */
	private function makeQuery(Resource $parentResource, mixed $parentRecord, ?PanelRequest $request=null): mixed {
		if($this->queryFactory!==null){
			return ($this->queryFactory)($parentRecord, $parentResource, $request, $this);
		}
		if($this->table!==null && class_exists('\Dataphyre\Database\DB')){
			$query=\Dataphyre\Database\DB::table($this->table);
			$foreign=$this->foreignKey;
			$local=$this->localKey;
			if($foreign!==null && $local!==null && method_exists($query, 'where')){
				$query=$query->where($foreign, '=', self::recordValue($parentRecord, $local));
			}
			return $query;
		}
		if($this->relatedResource!==null && ($resource=Panel::get($this->relatedResource)) instanceof Resource){
			return $resource->makeQuery($request);
		}
		return null;
	}

	/**
	 * Applies in-memory foreign/local key filtering to relation records.
	 *
	 * array-backed or resource-backed queries can return broader record
	 * sets than the current parent record. When both key names are configured and
	 * the local value is comparable, this helper narrows records to the matching
	 * relation while preserving the original records when filtering would be unsafe.
	 */
	private function filterRecordsByKeys(array $records, mixed $parentRecord): array {
		if($this->foreignKey===null || $this->localKey===null){
			return $records;
		}
		$localValue=self::recordValue($parentRecord, $this->localKey, null);
		if(!is_scalar($localValue) && $localValue!==null){
			return $records;
		}
		return array_values(array_filter($records, fn(mixed $record): bool => (string)self::recordValue($record, $this->foreignKey, '')===(string)$localValue));
	}

	/**
	 * Extracts a field value from an array or object record.
	 *
	 * relation logic accepts plain arrays, public object properties, and
	 * getter-style objects. This helper keeps key lookup consistent for query
	 * constraints, in-memory filtering, attach exclusion, and renderer metadata.
	 */
	private static function recordValue(mixed $record, string $key, mixed $default=null): mixed {
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
	 * Resolves static or dynamic text used by relation presentation.
	 *
	 * descriptions, parent titles, and similar display strings may be
	 * closures that receive parent record, request, resource, manager, and current
	 * records. All outputs are normalized through the same nullable-string rules
	 * before reaching manifests.
	 */
	private function resolveText(string|\Closure|null $value, mixed $parentRecord=null, ?PanelRequest $request=null, ?Resource $parentResource=null, array $records=[]): ?string {
		if($value===null){
			return null;
		}
		if($value instanceof \Closure){
			return $this->normalizeNullableString($value($parentRecord, $request, $parentResource, $this, $records));
		}
		return $this->normalizeNullableString($value);
	}

	/**
	 * Converts scalar-ish display values into manifest-safe nullable strings.
	 *
	 * booleans become stable numeric strings, scalar values are trimmed,
	 * blank values become null, and structured values are JSON encoded so dynamic
	 * callbacks cannot leak arrays or objects into string-only manifest fields.
	 */
	private function normalizeNullableString(mixed $value): ?string {
		if(is_bool($value)){
			$value=$value ? '1' : '0';
		}
		if(is_scalar($value) || $value===null){
			$value=trim((string)$value);
			return $value==='' ? null : $value;
		}
		$value=json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($value) && trim($value)!=='' ? $value : null;
	}

	/**
	 * Builds renderer metadata for all relation mutation operations.
	 *
	 * operation definitions are the manifest contract between relation
	 * configuration and the panel UI. Each operation reports enabled state,
	 * handler presence, labels, disabled reason, and extra operation-specific
	 * payload such as reorder columns or pivot fields.
	 */
	private function operationDefinitions(): array {
		return [
			'attach'=>$this->operationDefinition('attach', $this->canAttach(), $this->attachEnabled, $this->attachHandler!==null, $this->attachLabel, 'Attach record'),
			'detach'=>$this->operationDefinition('detach', $this->canDetach(), $this->detachEnabled, $this->detachHandler!==null, $this->detachLabel, 'Detach record'),
			'associate'=>$this->operationDefinition('associate', $this->canAssociate(), $this->associateEnabled, $this->associateHandler!==null, $this->associateLabel, 'Associate record'),
			'dissociate'=>$this->operationDefinition('dissociate', $this->canDissociate(), $this->dissociateEnabled, $this->dissociateHandler!==null, $this->dissociateLabel, 'Dissociate record'),
			'reorder'=>array_replace($this->operationDefinition('reorder', $this->canReorder(), $this->reorderEnabled, $this->reorderHandler!==null, $this->reorderLabel, 'Reorder records'), [
				'order_column'=>$this->orderColumn,
			]),
			'update_pivot'=>array_replace($this->operationDefinition('update_pivot', $this->canUpdatePivot(), $this->pivotUpdateHandler!==null && $this->pivotFields!==[], $this->pivotUpdateHandler!==null, 'Update pivot', 'Update pivot fields'), [
				'pivot_fields'=>array_map(static fn(Field $field): array => $field->toArray(), array_values($this->pivotFields)),
			]),
		];
	}

	/**
	 * Creates one manifest operation descriptor.
	 *
	 * this helper centralizes disabled-reason precedence so read-only
	 * state wins over per-operation flags, and missing handlers are explained only
	 * after the operation has been explicitly enabled.
	 */
	private function operationDefinition(string $name, bool $available, bool $configured, bool $hasHandler, string $label, string $modalLabel): array {
		$disabledReason=null;
		if($this->readOnly){
			$disabledReason='Relation is read-only.';
		}
		elseif(!$configured){
			$disabledReason='Operation is not enabled for this relation.';
		}
		elseif(!$hasHandler){
			$disabledReason='Operation handler is not registered.';
		}
		return [
			'name'=>$name,
			'label'=>$label,
			'enabled'=>$available,
			'authorized'=>true,
			'modal_label'=>$modalLabel,
			'disabled_reason'=>$disabledReason,
			'handler'=>$hasHandler,
		];
	}

	/**
	 * Converts normalized relation keys into human-facing default labels.
	 *
	 * relation names use machine-safe separators in manifests and route
	 * surfaces; this helper produces a readable fallback label while preserving an
	 * empty string for intentionally empty or invalid names.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
