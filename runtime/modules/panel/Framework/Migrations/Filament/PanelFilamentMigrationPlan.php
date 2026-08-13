<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Deterministic, fail-safe conversion plan for statically analyzed Filament
 * resources. Generated resources preserve declarative presentation metadata,
 * but deliberately omit data access, mutation, authorization, and tenancy
 * callbacks until the host supplies and reviews native Panel adapters.
 */
final class PanelFilamentMigrationPlan implements \JsonSerializable {
	private readonly string $targetNamespace;
	private readonly string $targetDirectory;
	/** @var list<PanelScaffoldResult> */
	private array $artifacts=[];
	/** @var list<array<string,mixed>> */
	private array $findings=[];
	/** @var array<string,int> */
	private array $coverage=[];
	private string $digest;

	public function __construct(private readonly PanelFilamentMigrationInventory $inventory,array $options=[]){
		$this->targetNamespace=self::namespace((string)($options['target_namespace']??'App\\Panel\\Resources'));
		$this->targetDirectory=self::relativeDirectory((string)($options['target_directory']??'app/Panel/Resources'));
		$this->build();
	}

	public static function make(PanelFilamentMigrationInventory $inventory,array $options=[]):self{return new self($inventory,$options);}
	public function inventory():PanelFilamentMigrationInventory{return$this->inventory;}
	public function targetNamespace():string{return$this->targetNamespace;}
	public function targetDirectory():string{return$this->targetDirectory;}
	/** @return list<PanelScaffoldResult> */
	public function artifacts():array{return$this->artifacts;}
	/** @return list<array<string,mixed>> */
	public function findings():array{return$this->findings;}
	/** @return array<string,int> */
	public function coverage():array{return$this->coverage;}
	public function digest():string{return$this->digest;}
	public function readyToWrite():bool{return$this->artifacts!==[]&&!$this->hasBlockers();}
	public function readyToActivate():bool{return false;}

	public function write(string $root,string $policy='error',bool $dryRun=true):PanelScaffoldWriteResult{
		if(!$this->readyToWrite()){throw new \LogicException('Filament migration has blocking findings or no generated resources.');}
		return PanelScaffoldWriter::make($root)->apply($this->artifacts,$policy,$dryRun);
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{
		return PanelManifestContract::stamp([
			'type'=>'panel_filament_migration_plan','version'=>1,'digest'=>$this->digest,
			'inventory_digest'=>$this->inventory->digest(),'target_namespace'=>$this->targetNamespace,
			'target_directory'=>$this->targetDirectory,'artifact_count'=>count($this->artifacts),
			'artifacts'=>array_map(static fn(PanelScaffoldResult $artifact):array=>$artifact->jsonSerialize(),$this->artifacts),
			'coverage'=>$this->coverage,'findings'=>$this->findings,
			'ready_to_write'=>$this->readyToWrite(),'ready_to_activate'=>false,
			'activation_requirements'=>self::activationRequirements(),
			'security'=>[
				'source_executed'=>false,'source_contents_serialized'=>false,'source_root_serialized'=>false,
				'generated_callbacks'=>false,'query_adapter_generated'=>false,'mutation_adapter_generated'=>false,
				'authorization_policy_generated'=>false,'tenancy_policy_generated'=>false,
			],
		]);
	}

	private function build():void{
		$this->findings=$this->inventory->findings();
		$this->coverage=['resources_discovered'=>0,'resources_generated'=>0,'fields_discovered'=>0,'fields_mapped'=>0,'columns_discovered'=>0,'columns_mapped'=>0,'manual_components'=>0,'literal_methods_mapped'=>0,'literal_methods_skipped'=>0,'dynamic_methods_skipped'=>0];
		$files=$this->inventory->files();$classFiles=[];$resources=[];
		foreach($files as$fileIndex=>$file){
			foreach((array)($file['classes']??[])as$class){
				if(!is_array($class)||!is_string($class['fqcn']??null))continue;
				$classFiles[$class['fqcn']]=$fileIndex;
				if(($class['kind']??null)==='resource')$resources[]=['file'=>$fileIndex,'class'=>$class];
			}
		}
		usort($resources,static fn(array $left,array $right):int=>strcmp((string)$left['class']['fqcn'],(string)$right['class']['fqcn']));
		$this->coverage['resources_discovered']=count($resources);
		if($resources===[]){$this->findings[]=$this->finding('blocker','no_filament_resources','app/Filament',1,'No Filament resource classes were discovered for generation.');}
		foreach($resources as$resource){
			$file=$files[$resource['file']];$class=$resource['class'];$components=$this->resourceComponents($resource['file'],$files,$classFiles);
			if(!self::className((string)($class['fqcn']??''))||!self::shortClass((string)($class['class']??''))){
				$this->findings[]=$this->finding('blocker','invalid_resource_class',(string)($file['path']??'app/Filament'),(int)($class['line']??1),'A Filament resource class cannot be represented as safe generated PHP.');
				continue;
			}
			$this->artifacts[]=$this->resourceArtifact($file,$class,$components);
			$this->coverage['resources_generated']++;
		}
		usort($this->artifacts,static fn(PanelScaffoldResult $left,PanelScaffoldResult $right):int=>strcmp($left->path(),$right->path()));
		$rank=['blocker'=>0,'warning'=>1,'info'=>2];
		usort($this->findings,static fn(array $left,array $right):int=>[
			$rank[$left['severity']]??9,$left['path']??'',$left['line']??0,$left['code'],
		]<=>[
			$rank[$right['severity']]??9,$right['path']??'',$right['line']??0,$right['code'],
		]);
		ksort($this->coverage,SORT_STRING);
		$this->digest=PanelOperationsGuard::digest([
			'inventory_digest'=>$this->inventory->digest(),'target_namespace'=>$this->targetNamespace,
			'target_directory'=>$this->targetDirectory,'artifacts'=>array_map(static fn(PanelScaffoldResult $artifact):array=>[
				'kind'=>$artifact->kind(),'name'=>$artifact->name(),'class'=>$artifact->class(),'path'=>$artifact->path(),
				'digest'=>hash('sha256',$artifact->contents()),'metadata'=>$artifact->metadata(),
			],$this->artifacts),'coverage'=>$this->coverage,'findings'=>$this->findings,
		]);
	}

	/**
	 * @param list<array<string,mixed>> $files
	 * @param array<string,int> $classFiles
	 * @return list<array<string,mixed>>
	 */
	private function resourceComponents(int $root,array $files,array $classFiles):array{
		$queue=[$root];$seen=[];$components=[];
		while($queue!==[]){
			$index=array_shift($queue);if(!is_int($index)||isset($seen[$index]))continue;$seen[$index]=true;$file=$files[$index]??null;if(!is_array($file))continue;
			foreach((array)($file['components']??[])as$component){if(is_array($component))$components[]=$component+['source_path'=>(string)($file['path']??'unknown.php')];}
			foreach((array)($file['references']??[])as$reference){if(is_string($reference)&&isset($classFiles[$reference])&&!isset($seen[$classFiles[$reference]]))$queue[]=$classFiles[$reference];}
		}
		usort($components,static fn(array $left,array $right):int=>[
			$left['source_path']??'',$left['line']??0,$left['family']??'',$left['name']??'',
		]<=>[
			$right['source_path']??'',$right['line']??0,$right['family']??'',$right['name']??'',
		]);return$components;
	}

	/** @param array<string,mixed> $file @param array<string,mixed> $class @param list<array<string,mixed>> $components */
	private function resourceArtifact(array $file,array $class,array $components):PanelScaffoldResult{
		$sourceClass=(string)$class['fqcn'];$sourcePath=(string)$file['path'];$short=(string)$class['class'];
		[$namespace,$path]=$this->target($sourceClass,$short);$metadata=is_array($class['metadata']??null)?$class['metadata']:[];
		$name=Resource::normalizeName((string)($metadata['slug']??preg_replace('/Resource$/','',$short)))?:'resource';
		$label=(string)($metadata['modelLabel']??$metadata['navigationLabel']??self::headline(preg_replace('/Resource$/','',$short)??$short));
		$plural=(string)($metadata['pluralModelLabel']??self::pluralize($label));
		$fieldLines=[];$columnLines=[];$seen=[];
		foreach($components as$component){
			$family=(string)($component['family']??'unsupported');$componentPath=(string)($component['source_path']??$sourcePath);$line=max(1,(int)($component['line']??1));
			if($family==='field')$this->coverage['fields_discovered']++;elseif($family==='column')$this->coverage['columns_discovered']++;
			if(!in_array($family,['field','column'],true)){
				$this->coverage['manual_components']++;
				$this->findings[]=$this->finding('warning','manual_'.$family.'_migration',$componentPath,$line,'This Filament component requires an explicit native Panel mapping.');continue;
			}
			$componentName=Resource::normalizeName((string)($component['name']??''));
			if($componentName===''){
				$this->coverage['manual_components']++;
				$this->findings[]=$this->finding('warning','dynamic_component_identity',$componentPath,$line,'A field or column with a dynamic identity was not generated.');continue;
			}
			$key=$family.':'.$componentName;if(isset($seen[$key])){
				$this->findings[]=$this->finding('warning','duplicate_component_identity',$componentPath,$line,'A duplicate field or column identity was omitted from generated source.');continue;
			}$seen[$key]=true;
			$chain=$this->componentChain($component,$componentPath);
			$type=Resource::normalizeName((string)($component['panel_type']??'text'))?:'text';
			$code="\t\t\t\$panel->{$family}('".self::quote($componentName)."', '".self::quote($type)."')".$chain.',';
			if($family==='field'){$fieldLines[]=$code;$this->coverage['fields_mapped']++;}else{$columnLines[]=$code;$this->coverage['columns_mapped']++;}
		}
		$resourceLines=["\t\treturn \$panel->resource('".self::quote($name)."')","\t\t\t->label('".self::quote($label)."')","\t\t\t->pluralLabel('".self::quote($plural)."')"];
		if(is_string($metadata['model']??null)&&trim($metadata['model'])!==''){
			if(self::className(ltrim(trim($metadata['model']),'\\')))$resourceLines[]="\t\t\t->model(\\".ltrim(trim($metadata['model']),'\\')."::class)";
			else$this->findings[]=$this->finding('warning','invalid_model_metadata',$sourcePath,(int)($class['line']??1),'Filament model metadata was omitted because it is not a valid class name.');
		}
		if(is_string($metadata['navigationGroup']??null))$resourceLines[]="\t\t\t->group('".self::quote($metadata['navigationGroup'])."')";
		if(is_string($metadata['navigationParentItem']??null))$resourceLines[]="\t\t\t->navigationParent('".self::quote($metadata['navigationParentItem'])."')";
		if(is_string($metadata['navigationIcon']??null))$resourceLines[]="\t\t\t->icon('".self::quote($metadata['navigationIcon'])."')";
		if(is_int($metadata['navigationSort']??null))$resourceLines[]="\t\t\t->sort(".$metadata['navigationSort'].")";
		if(($metadata['shouldRegisterNavigation']??true)===false)$resourceLines[]="\t\t\t->hideFromNavigation()";
		if(is_string($metadata['slug']??null)&&trim($metadata['slug'])!=='')$resourceLines[]="\t\t\t->url('/".self::quote(trim($metadata['slug'],'/'))."')";
		if(is_string($metadata['recordTitleAttribute']??null)&&Resource::normalizeName($metadata['recordTitleAttribute'])!=='')$resourceLines[]="\t\t\t->recordTitleUsing('".self::quote(Resource::normalizeName($metadata['recordTitleAttribute']))."')";
		$resourceLines[]="\t\t\t->columns([\n".implode("\n",$columnLines)."\n\t\t\t])";
		$resourceLines[]="\t\t\t->fields([\n".implode("\n",$fieldLines)."\n\t\t\t]);";
		$contents="<?php\n/*************************************************************************\n * Generated by Dataphyre Panel's Filament migration planner.\n * Review data, policy, authorization, and tenancy adapters before use.\n *************************************************************************/\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nuse Dataphyre\\Panel\\PanelInstance;\nuse Dataphyre\\Panel\\Resource;\n\nfinal class {$short} {\n\tpublic static function make(PanelInstance \$panel): Resource {\n".implode("\n",$resourceLines)."\n\t}\n}\n";
		$this->findings[]=$this->finding('warning','host_data_adapter_required',$sourcePath,(int)($class['line']??1),'Generated resources require a reviewed host query adapter before activation.');
		$this->findings[]=$this->finding('warning','host_mutation_policy_required',$sourcePath,(int)($class['line']??1),'Generated resources require reviewed mutation, authorization, and tenancy policies before activation.');
		return PanelScaffoldResult::make('resource',$name,$namespace.'\\'.$short,$path,$contents,[
			'source_class'=>$sourceClass,'source_path'=>$sourcePath,'source_sha256'=>(string)($file['sha256']??''),
			'generated_sha256'=>hash('sha256',$contents),'fields'=>count($fieldLines),'columns'=>count($columnLines),
			'companion_sources_followed'=>true,'source_executed'=>false,'callbacks_generated'=>false,
			'query_adapter_generated'=>false,'mutation_adapter_generated'=>false,'activation_ready'=>false,
			'register'=>'$panel->register('.$short.'::make($panel));',
		]);
	}

	/** @param array<string,mixed> $component */
	private function componentChain(array $component,string $path):string{
		$family=(string)$component['family'];$line=max(1,(int)($component['line']??1));$chain='';
		$allowed=$family==='field'?
			['label'=>'string','required'=>'bool','readonly'=>'bool','disabled'=>'bool','hidden'=>'bool','placeholder'=>'string','helpertext'=>'string','default'=>'value','options'=>'array','multiple'=>'bool','searchable'=>'bool','live'=>'bool','reactive'=>'bool','minlength'=>'int','maxlength'=>'int','minvalue'=>'value','maxvalue'=>'value','step'=>'value','prefix'=>'string','suffix'=>'string','numeric'=>'trigger','email'=>'trigger','url'=>'trigger','password'=>'bool']:
			['label'=>'string','sortable'=>'bool','searchable'=>'bool','toggleable'=>'bool','hidden'=>'bool','align'=>'string','copyable'=>'bool','limit'=>'int','icon'=>'string','color'=>'string','description'=>'string','tooltip'=>'string','money'=>'optional_string','datetime'=>'optional_string','date'=>'optional_string','badge'=>'optional_value','url'=>'optional_value'];
		foreach((array)($component['methods']??[])as$method){
			if(!is_array($method))continue;$sourceMethod=(string)($method['method']??'');$name=strtolower($sourceMethod);
			if(!isset($allowed[$name])){
				if(!in_array($name,['make','schema','components'],true)){$this->coverage['literal_methods_skipped']++;$this->findings[]=$this->finding('warning','manual_component_method',$path,$line,'Filament method '.$sourceMethod.' requires manual semantic migration.');}continue;
			}
			if(($method['literal']??false)!==true){$this->coverage['dynamic_methods_skipped']++;$this->findings[]=$this->finding('warning','dynamic_component_method',$path,$line,'Dynamic Filament method '.$sourceMethod.' was not generated.');continue;}
			$value=$method['value']??null;$kind=$allowed[$name];$target=match($name){'helpertext'=>'helperText','minlength'=>'minLength','maxlength'=>'maxLength','minvalue'=>'minValue','maxvalue'=>'maxValue',default=>$name};
			$valid=match($kind){'string'=>is_string($value),'int'=>is_int($value),'array'=>is_array($value),'bool'=>is_bool($value),'trigger'=>$value===true,'value'=>self::literalValue($value),'optional_string'=>$value===true||is_string($value),'optional_value'=>$value===true||self::literalValue($value),default=>false};
			if(!$valid){$this->coverage['literal_methods_skipped']++;$this->findings[]=$this->finding('warning','incompatible_component_method',$path,$line,'Literal Filament method '.$sourceMethod.' could not be represented safely.');continue;}
			if($kind==='bool'){$chain.=$value?"->{$target}()":"->{$target}(false)";}
			elseif(in_array($kind,['trigger','optional_string','optional_value'],true)&&$value===true){$chain.="->{$target}()";}
			else{$chain.="->{$target}(".self::export($value).")";}
			$this->coverage['literal_methods_mapped']++;
		}
		if(($component['dynamic']??false)===true)$this->findings[]=$this->finding('warning','partial_dynamic_component',$path,$line,'Only verified literal configuration was generated for this dynamic component.');
		return$chain;
	}

	/** @return array{0:string,1:string} */
	private function target(string $sourceClass,string $short):array{
		$suffix='';$marker='\\Filament\\Resources\\';$position=strpos($sourceClass,$marker);
		if($position!==false){$tail=substr($sourceClass,$position+strlen($marker));$parts=explode('\\',$tail);array_pop($parts);if($parts!==[])$suffix=implode('\\',$parts);}
		$namespace=$this->targetNamespace.($suffix!==''?'\\'.$suffix:'');
		$path=$this->targetDirectory.($suffix!==''?'/'.str_replace('\\','/',$suffix):'').'/'.$short.'.php';
		return[$namespace,$path];
	}

	private function hasBlockers():bool{foreach($this->findings as$finding){if(($finding['severity']??null)==='blocker')return true;}return false;}
	/** @return list<string> */
	private static function activationRequirements():array{return['Bind an allowlisted query or repository adapter.','Bind create, update, delete, and bulk mutation handlers explicitly.','Map and enforce every Filament authorization policy and action guard.','Verify tenant scoping, record ownership, and cross-tenant denial.','Review dynamic schemas, filters, actions, relation managers, pages, widgets, imports, and exports.','Run Panel unit, browser, responsive, theme, accessibility, and rollback certification.'];}
	private function finding(string $severity,string $code,string $path,int $line,string $message):array{return['severity'=>$severity,'code'=>Resource::normalizeName($code),'path'=>self::relativePath($path),'line'=>max(1,$line),'message'=>substr(trim($message),0,512)];}
	private static function quote(string $value):string{return str_replace(['\\',"'"],["\\\\","\\'"],$value);}
	private static function export(mixed $value):string{return var_export($value,true);}
	private static function literalValue(mixed $value):bool{if(is_null($value)||is_scalar($value))return true;if(!is_array($value))return false;foreach($value as$key=>$item){if((!is_int($key)&&!is_string($key))||!self::literalValue($item))return false;}return true;}
	private static function headline(string $value):string{$value=preg_replace('/(?<!^)[A-Z]/',' $0',str_replace(['_','-'],' ',$value))??$value;return ucwords(trim(preg_replace('/\s+/',' ',$value)??$value));}
	private static function pluralize(string $value):string{return preg_match('/(?:s|x|z|ch|sh)$/i',$value)===1?$value.'es':(preg_match('/[^aeiou]y$/i',$value)===1?substr($value,0,-1).'ies':$value.'s');}
	private static function className(string $class):bool{return$class!==''&&preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*)(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D',$class)===1;}
	private static function shortClass(string $class):bool{return$class!==''&&preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$class)===1;}
	private static function namespace(string $namespace):string{$namespace=trim(str_replace('/','\\',$namespace),'\\ ');if($namespace===''||preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*)(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D',$namespace)!==1)throw new \InvalidArgumentException('Filament migration target namespace is invalid.');return$namespace;}
	private static function relativeDirectory(string $path):string{$path=self::relativePath($path);if(strtolower(pathinfo($path,PATHINFO_EXTENSION))==='php')throw new \InvalidArgumentException('Filament migration target directory must be a directory path.');return rtrim($path,'/');}
	private static function relativePath(string $path):string{$path=str_replace('\\','/',trim($path));if($path===''||str_starts_with($path,'/')||preg_match('/^[A-Za-z]:\//',$path)===1||str_contains($path,"\0"))throw new \InvalidArgumentException('Filament migration paths must be project-relative.');$parts=[];foreach(explode('/',$path)as$part){if($part===''||$part==='.')continue;if($part==='..'||preg_match('/[\x00-\x1F\x7F]/',$part)===1)throw new \InvalidArgumentException('Filament migration path escapes the project root.');$parts[]=$part;}if($parts===[])throw new \InvalidArgumentException('Filament migration path is empty.');return implode('/',$parts);}
}
