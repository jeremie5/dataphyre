<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable table, column, tenant, search, and relation allowlist for SQL adapters. */
final class PanelSqlSchema implements \JsonSerializable {
	/** @param array<string,string> $fields @param list<string> $searchFields @param array<string,PanelSqlRelation> $relations */
	private function __construct(
		private readonly string $name,
		private readonly string $table,
		private readonly array $fields,
		private readonly string $primaryKey,
		private readonly ?string $tenantField,
		private readonly bool $requireTenant,
		private readonly array $searchFields,
		private readonly array $relations,
		private readonly int $maxLimit
	){}

	/** @param array<int|string,mixed> $fields @param array<string,mixed> $options */
	public static function make(string $table, array $fields, string $primaryKey='id', array $options=[]): self {
		self::assertOptions($options);
		if(array_key_exists('name', $options) && !is_string($options['name'])){ throw new \InvalidArgumentException('Panel SQL schema name must be a string.'); }
		if(array_key_exists('tenant_field', $options) && $options['tenant_field']!==null && !is_string($options['tenant_field'])){ throw new \InvalidArgumentException('Panel SQL tenant_field must be a string or null.'); }
		if(array_key_exists('require_tenant', $options) && !is_bool($options['require_tenant'])){ throw new \InvalidArgumentException('Panel SQL require_tenant must be boolean.'); }
		if(array_key_exists('search_fields', $options) && !is_array($options['search_fields'])){ throw new \InvalidArgumentException('Panel SQL search_fields must be a list.'); }
		if(array_key_exists('relations', $options) && !is_array($options['relations'])){ throw new \InvalidArgumentException('Panel SQL relations must be a list or map.'); }
		if(array_key_exists('max_limit', $options) && !is_int($options['max_limit'])){ throw new \InvalidArgumentException('Panel SQL max_limit must be an integer.'); }
		$table=self::identifierPath($table, 'table', 3);
		$normalized=[]; $columns=[];
		foreach($fields as $public=>$column){
			if(!is_string($column)){ throw new \InvalidArgumentException('Panel SQL field mappings must contain column-name strings.'); }
			if(is_int($public)){ $public=(string)$column; }
			$public=self::publicField((string)$public);
			$column=self::identifier((string)$column, 'column');
			if(str_starts_with(strtolower($public), '__dp_')){ throw new \InvalidArgumentException("Panel SQL field '{$public}' uses a reserved prefix."); }
			if(isset($normalized[$public])){ throw new \InvalidArgumentException("Panel SQL field '{$public}' is duplicated."); }
			if(isset($columns[strtolower($column)])){ throw new \InvalidArgumentException("Panel SQL column '{$column}' is mapped more than once."); }
			$normalized[$public]=$column; $columns[strtolower($column)]=true;
		}
		if($normalized===[]){ throw new \InvalidArgumentException('Panel SQL schemas require at least one field.'); }
		$primaryKey=self::publicField($primaryKey);
		if(!isset($normalized[$primaryKey])){ throw new \InvalidArgumentException("Panel SQL primary key '{$primaryKey}' is not allowlisted."); }

		$tenantField=null;
		if(array_key_exists('tenant_field', $options) && $options['tenant_field']!==null){
			$tenantField=self::publicField((string)$options['tenant_field']);
			if(!isset($normalized[$tenantField])){ throw new \InvalidArgumentException("Panel SQL tenant field '{$tenantField}' is not allowlisted."); }
		}
		$requireTenant=($options['require_tenant'] ?? ($tenantField!==null))===true;
		if($requireTenant && $tenantField===null){ throw new \InvalidArgumentException('Panel SQL require_tenant needs an allowlisted tenant_field.'); }

		$search=[];
		foreach(is_array($options['search_fields'] ?? null) ? $options['search_fields'] : [] as $field){
			$field=self::publicField((string)$field);
			if(!isset($normalized[$field])){ throw new \InvalidArgumentException("Panel SQL search field '{$field}' is not allowlisted."); }
			$search[]=$field;
		}
		$search=array_values(array_unique($search));
		if(count($search)>50){ throw new \LengthException('Panel SQL schemas support at most 50 search fields.'); }

		$relations=[];
		foreach(is_array($options['relations'] ?? null) ? $options['relations'] : [] as $key=>$relation){
			if(!$relation instanceof PanelSqlRelation){ throw new \InvalidArgumentException('Panel SQL schema relations must be PanelSqlRelation objects.'); }
			if(is_string($key) && trim($key)!=='' && self::publicField($key)!==$relation->name()){
				throw new \InvalidArgumentException("Panel SQL relation key '{$key}' does not match its definition.");
			}
			if(!isset($normalized[$relation->localField()])){
				throw new \InvalidArgumentException("Panel SQL relation '{$relation->name()}' references unknown local field '{$relation->localField()}'.");
			}
			if($relation->schema()->relationDepth()>=16){ throw new \LengthException('Panel SQL relation schemas support at most 16 nested levels.'); }
			if(isset($relations[$relation->name()])){ throw new \InvalidArgumentException("Panel SQL relation '{$relation->name()}' is duplicated."); }
			$relations[$relation->name()]=$relation;
		}
		if(count($relations)>32){ throw new \LengthException('Panel SQL schemas support at most 32 relations.'); }

		$maxLimit=(int)($options['max_limit'] ?? 1000);
		if($maxLimit<1 || $maxLimit>10000){ throw new \InvalidArgumentException('Panel SQL max_limit must be between 1 and 10000.'); }
		$name=self::safeName((string)($options['name'] ?? basename(str_replace('.', '/', $table))));
		return new self($name, $table, $normalized, $primaryKey, $tenantField, $requireTenant, $search, $relations, $maxLimit);
	}

	public function name(): string { return $this->name; }
	public function table(): string { return $this->table; }
	/** @return array<string,string> */ public function fields(): array { return $this->fields; }
	/** @return list<string> */ public function fieldNames(): array { return array_keys($this->fields); }
	public function primaryKey(): string { return $this->primaryKey; }
	public function tenantField(): ?string { return $this->tenantField; }
	public function requiresTenant(): bool { return $this->requireTenant; }
	/** @return list<string> */ public function searchFields(): array { return $this->searchFields; }
	/** @return array<string,PanelSqlRelation> */ public function relations(): array { return $this->relations; }
	public function maxLimit(): int { return $this->maxLimit; }
	public function hasField(string $field): bool { return isset($this->fields[$field]); }
	public function hasRelations(): bool { return $this->relations!==[]; }

	public function column(string $field): string {
		$field=self::publicField($field);
		return $this->fields[$field] ?? throw new \OutOfBoundsException("Panel SQL field '{$field}' is not allowlisted by schema '{$this->name}'.");
	}

	public function relation(string $name): PanelSqlRelation {
		$name=self::publicField($name);
		return $this->relations[$name] ?? throw new \OutOfBoundsException("Panel SQL relation '{$name}' is not allowlisted by schema '{$this->name}'.");
	}

	public function relationDepth(): int {
		$maximum=0;
		foreach($this->relations as $relation){ $maximum=max($maximum, 1+$relation->schema()->relationDepth()); }
		return min(16, $maximum);
	}

	public function fingerprint(): string {
		return hash('sha256', json_encode($this->manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}

	/** @return array<string,mixed> */
	public function manifest(): array {
		$relations=[];
		foreach($this->relations as $name=>$relation){
			$relations[$name]=[
				'local_field'=>$relation->localField(), 'foreign_field'=>$relation->foreignField(),
				'schema_name'=>$relation->schema()->name(), 'schema_fingerprint'=>$relation->schema()->fingerprint(),
			];
		}
		return [
			'type'=>'panel_sql_schema', 'version'=>1, 'name'=>$this->name, 'table'=>$this->table,
			'fields'=>$this->fields, 'primary_key'=>$this->primaryKey,
			'tenant_field'=>$this->tenantField, 'tenant_required'=>$this->requireTenant,
			'search_fields'=>$this->searchFields, 'relations'=>$relations,
			'relation_depth'=>$this->relationDepth(), 'max_limit'=>$this->maxLimit,
		];
	}

	/** @return array<string,mixed> */ public function jsonSerialize(): array { return $this->manifest(); }

	/** @param array<string,mixed> $options */
	private static function assertOptions(array $options): void {
		$unknown=array_diff(array_keys($options), ['name','tenant_field','require_tenant','search_fields','relations','max_limit']);
		if($unknown!==[]){ throw new \InvalidArgumentException('Unknown Panel SQL schema option: '.(string)array_values($unknown)[0]); }
	}

	private static function publicField(string $field): string {
		$path=PanelQueryPath::make($field);
		if(count($path->segments())!==1){ throw new \InvalidArgumentException("Panel SQL field '{$field}' must be a single allowlisted name."); }
		return $path->value();
	}

	private static function identifierPath(string $identifier, string $label, int $maxParts): string {
		$identifier=trim($identifier); $parts=explode('.', $identifier);
		if(count($parts)>$maxParts){ throw new \InvalidArgumentException("Panel SQL {$label} contains too many identifier parts."); }
		return implode('.', array_map(static fn(string $part): string=>self::identifier($part, $label), $parts));
	}

	private static function identifier(string $identifier, string $label): string {
		$identifier=trim($identifier);
		if($identifier==='' || strlen($identifier)>63 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier)!==1){
			throw new \InvalidArgumentException("Invalid Panel SQL {$label} identifier '{$identifier}'.");
		}
		return $identifier;
	}

	private static function safeName(string $name): string {
		$name=strtolower(trim($name)); $name=preg_replace('/[^a-z0-9]+/', '_', $name) ?? '';
		$name=trim($name, '_');
		if($name==='' || strlen($name)>64){ throw new \InvalidArgumentException('Panel SQL schema name must contain between 1 and 64 normalized bytes.'); }
		return $name;
	}
}
