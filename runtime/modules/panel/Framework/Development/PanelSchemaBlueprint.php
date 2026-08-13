<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Converts database metadata into deterministic Panel resource blueprints. */
final class PanelSchemaBlueprint implements \JsonSerializable {
	private const MAX_COLUMNS=512;
	private const MAX_METADATA_DEPTH=8;
	private const MAX_METADATA_ITEMS=256;
	private const MAX_METADATA_STRING_BYTES=4096;
	private array $columns=[];
	private array $foreignKeys=[];
	public function __construct(private readonly string $table, array $columns, array $options=[]) {
		if(trim($table)===''){ throw new \InvalidArgumentException('A schema blueprint table name is required.'); }
		if(strlen($table)>255 || preg_match('//u', $table)!==1 || preg_match('/[\x00-\x1f\x7f]/', $table)===1){ throw new \InvalidArgumentException('Panel schema blueprint table names must be bounded printable UTF-8 text.'); }
		if(count($columns)>self::MAX_COLUMNS){ throw new \LengthException('Panel schema blueprints support at most 512 columns.'); }
		foreach($columns as $name=>$definition){
			if(is_int($name) && is_array($definition)){ $name=(string)($definition['name'] ?? ''); }
			if(trim((string)$name)===''){ continue; }
			if(is_array($definition)){ self::assertMetadata($definition); $this->columns[(string)$name]=$definition; }
			elseif(is_scalar($definition) || $definition===null){ $this->columns[(string)$name]=['type'=>(string)$definition]; }
			else{ throw new \InvalidArgumentException('Panel schema blueprint column metadata must be JSON scalar or array data.'); }
		}
		$foreign=$options['foreign_keys'] ?? [];
		if(!is_array($foreign)){ throw new \InvalidArgumentException('Panel schema blueprint foreign_keys must be an array.'); }
		if(count($foreign)>self::MAX_COLUMNS){ throw new \LengthException('Panel schema blueprints support at most 512 foreign keys.'); }
		self::assertMetadata($foreign);
		$this->foreignKeys=$foreign;
	}
	public static function make(string $table, array $columns, array $options=[]): self { return new self($table, $columns, $options); }
	public function resourceName(): string {
		$segments=explode('.', $this->table);
		$table=(string)end($segments);
		$table=trim((string)preg_replace('/[^A-Za-z0-9_]+/', '_', $table), '_');
		return self::studly(self::singular($table));
	}
	public function fieldDefinitions(): array { $fields=[]; foreach($this->columns as $name=>$column){ if(($column['generated'] ?? false)===true || in_array($name, ['created_at', 'updated_at', 'deleted_at'], true)){ continue; } $fields[]=$this->field($name, $column); } return $fields; }
	public function columnDefinitions(): array { $columns=[]; foreach($this->columns as $name=>$definition){ if(($definition['hidden'] ?? false)===true){ continue; } $type=$this->panelType($definition,$name); $columns[]=['name'=>$name, 'label'=>self::label($name), 'type'=>$type, 'sortable'=>true, 'searchable'=>in_array($type, ['text', 'email', 'url', 'badge'], true), 'toggleable'=>!in_array($name, ['id'], true)]; } return $columns; }
	public function relationDefinitions(): array { $relations=[]; foreach($this->foreignKeys as $name=>$foreign){ if(!is_array($foreign)){ continue; } $column=(string)($foreign['column'] ?? $name); $target=(string)($foreign['table'] ?? ''); if($column==='' || $target===''){ continue; } $relations[]=['name'=>preg_replace('/_id$/', '', $column), 'type'=>'belongs_to', 'foreign_key'=>$column, 'related_resource'=>self::studly(self::singular($target))]; } return $relations; }
	public function manifest(): array { return ['type'=>'panel_schema_blueprint', 'table'=>$this->table, 'resource'=>$this->resourceName(), 'fields'=>$this->fieldDefinitions(), 'columns'=>$this->columnDefinitions(), 'relations'=>$this->relationDefinitions(), 'capabilities'=>['generated_resource'=>true, 'generated_form'=>true, 'generated_table'=>true, 'generated_relations'=>$this->foreignKeys!==[]]]; }
	public function php(string $namespace='App\\Panel\\Resources'): string {
		$namespace=trim(trim($namespace), '\\');
		if($namespace==='' || strlen($namespace)>255 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace)!==1){
			throw new \InvalidArgumentException('Panel schema blueprint namespaces must contain safe PHP namespace identifiers.');
		}
		$resource=$this->resourceName();
		if($resource==='' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $resource)!==1){
			throw new \InvalidArgumentException('Panel schema blueprint tables must produce a safe PHP class identifier.');
		}
		$manifest=$this->manifest();
		$export=var_export($manifest, true);
		return "<?php\ndeclare(strict_types=1);\n\nnamespace ".$namespace.";\n\nuse Dataphyre\\Panel\\Resource;\n\nfinal class ".$resource."Resource {\n\tpublic static function definition(): array {\n\t\treturn ".$export.";\n\t}\n}\n";
	}
	public function jsonSerialize(): array { return $this->manifest(); }
	private function field(string $name, array $column): array { $type=$this->panelType($column,$name); $field=['name'=>$name, 'label'=>self::label($name), 'type'=>$type, 'required'=>($column['nullable'] ?? true)===false && ($column['default'] ?? null)===null]; if(isset($column['length'])){ $field['max_length']=max(1, (int)$column['length']); } if(isset($column['default'])){ $field['default']=$column['default']; } if(is_array($column['enum'] ?? null)){ $field['type']='select'; $field['options']=array_combine(array_map('strval', $column['enum']), array_map(static fn(mixed $value): string => self::label((string)$value), $column['enum'])); } return $field; }
	private function panelType(array $column,string $name=''): string { $type=strtolower((string)($column['type'] ?? 'string'));$name=strtolower($name); if(is_array($column['enum'] ?? null)){ return 'badge'; } return match(true){ str_contains($name,'email')=>'email',str_contains($name,'password')=>'password',str_contains($name,'url')||str_contains($name,'website')=>'url',str_contains($name,'phone')=>'tel',str_contains($type, 'bool') || $type==='bit'=>'toggle', str_contains($type, 'int')=>'integer', in_array($type, ['decimal','numeric','float','double','real'], true)=>'decimal', in_array($type, ['date'], true)=>'date', str_contains($type, 'time')=>'datetime', in_array($type, ['json','jsonb'], true)=>'json', in_array($type, ['text','longtext','mediumtext'], true)=>'textarea', default=>'text' }; }
	private static function singular(string $value): string { return str_ends_with($value, 'ies') ? substr($value, 0, -3).'y' : (str_ends_with($value, 's') && !str_ends_with($value, 'ss') ? substr($value, 0, -1) : $value); }
	private static function studly(string $value): string { return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value))); }
	private static function label(string $value): string { return ucwords(str_replace(['-', '_'], ' ', $value)); }
	private static function assertMetadata(mixed $value, int $depth=0): void {
		if($depth>self::MAX_METADATA_DEPTH){ throw new \LengthException('Panel schema blueprint metadata exceeds the nesting budget.'); }
		if(is_array($value)){
			if(count($value)>self::MAX_METADATA_ITEMS){ throw new \LengthException('Panel schema blueprint metadata exceeds the item budget.'); }
			foreach($value as $item){ self::assertMetadata($item, $depth+1); }
			return;
		}
		if(is_float($value) && !is_finite($value)){ throw new \InvalidArgumentException('Panel schema blueprint metadata numbers must be finite.'); }
		if(!is_scalar($value) && $value!==null){ throw new \InvalidArgumentException('Panel schema blueprint metadata must be JSON scalar or array data.'); }
		if(is_string($value) && preg_match('//u', $value)!==1){ throw new \InvalidArgumentException('Panel schema blueprint metadata strings must be valid UTF-8.'); }
		if(is_string($value) && strlen($value)>self::MAX_METADATA_STRING_BYTES){ throw new \LengthException('Panel schema blueprint metadata strings exceed the byte budget.'); }
	}
}
