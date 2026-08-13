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
 * Non-executing, root-confined source analyzer for Filament 3, 4, and 5 apps.
 *
 * The analyzer tokenizes PHP and extracts only declarations and literal builder
 * metadata. It never requires application files, resolves a service container,
 * connects to a database, or serializes source contents.
 */
final class PanelFilamentSourceAnalyzer {
	private string $root;
	private int $maximumFiles;
	private int $maximumFileBytes;
	private int $maximumTotalBytes;

	public function __construct(string $root,array $options=[]){
		$root=trim($root);
		if($root===''||!is_dir($root)||is_link($root)||($resolved=realpath($root))===false){throw new \InvalidArgumentException('Filament source root must be an existing non-symlink directory.');}
		$this->root=rtrim($resolved,'/\\');
		$this->maximumFiles=max(1,min(50000,(int)($options['maximum_files']??5000)));
		$this->maximumFileBytes=max(1024,min(16777216,(int)($options['maximum_file_bytes']??2097152)));
		$this->maximumTotalBytes=max($this->maximumFileBytes,min(1073741824,(int)($options['maximum_total_bytes']??67108864)));
	}

	public static function make(string $root,array $options=[]):self{return new self($root,$options);}
	public function root():string{return$this->root;}

	/** @param list<string> $paths */
	public function analyze(array $paths=[]):PanelFilamentMigrationInventory{
		$findings=[];$files=[];$totalBytes=0;
		foreach($this->discover($paths,$findings)as$relative=>$absolute){
			$bytes=filesize($absolute);
			if(!is_int($bytes)||$bytes<0){$findings[]=$this->finding('blocker','source_unreadable',$relative,1,'Source size could not be read.');continue;}
			if($bytes>$this->maximumFileBytes){$findings[]=$this->finding('blocker','source_file_too_large',$relative,1,'Source file exceeds the configured analysis bound.');continue;}
			$totalBytes+=$bytes;
			if($totalBytes>$this->maximumTotalBytes){$findings[]=$this->finding('blocker','source_inventory_too_large',$relative,1,'Source inventory exceeds the configured byte bound.');break;}
			$contents=file_get_contents($absolute);
			if(!is_string($contents)){$findings[]=$this->finding('blocker','source_unreadable',$relative,1,'Source file could not be read.');continue;}
			try{$parsed=$this->parse($relative,$contents,$findings);}
			catch(\ParseError){$findings[]=$this->finding('blocker','php_parse_failed',$relative,1,'PHP source could not be tokenized safely.');continue;}
			if($parsed!==null){$files[]=$parsed;}
		}
		if($files===[]){$findings[]=$this->finding('blocker','no_filament_sources','app/Filament',1,'No analyzable Filament PHP sources were found.');}
		return new PanelFilamentMigrationInventory($this->composer(),$files,$findings);
	}

	/** @param list<string> $paths @param list<array<string,mixed>> $findings @return array<string,string> */
	private function discover(array $paths,array &$findings):array{
		if($paths===[]){
			foreach(['app/Filament','app/Providers/Filament','src/Filament']as$default){if(file_exists($this->root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$default))){$paths[]=$default;}}
			if($paths===[]&&is_dir($this->root.DIRECTORY_SEPARATOR.'app')){$paths[]='app';}
		}
		$result=[];
		foreach($paths as$path){
			$relative=$this->relative((string)$path);$absolute=$this->root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
			if(is_link($absolute)){$findings[]=$this->finding('blocker','source_symlink_rejected',$relative,1,'Filament source paths cannot be symbolic links.');continue;}
			$resolved=realpath($absolute);
			if($resolved===false||!$this->withinRoot($resolved)){throw new \InvalidArgumentException('Filament source path does not exist inside the project root: '.$relative);}
			if(is_file($resolved)){$this->appendFile($resolved,$result,$findings);continue;}
			$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved,\FilesystemIterator::SKIP_DOTS));
			foreach($iterator as$item){
				if($item->isLink()){$findings[]=$this->finding('blocker','source_symlink_rejected',$this->relativeFromAbsolute($item->getPathname()),1,'Filament source trees cannot traverse symbolic links.');continue;}
				if(!$item->isFile()||strtolower($item->getExtension())!=='php')continue;
				$this->appendFile($item->getPathname(),$result,$findings);
				if(count($result)>=$this->maximumFiles){$findings[]=$this->finding('blocker','source_file_limit_reached',$relative,1,'Filament source inventory reached the configured file limit.');break 2;}
			}
		}
		ksort($result,SORT_STRING);return$result;
	}

	/** @param array<string,string> $result @param list<array<string,mixed>> $findings */
	private function appendFile(string $absolute,array &$result,array &$findings):void{
		$real=realpath($absolute);if($real===false||!$this->withinRoot($real)||is_link($absolute)){$findings[]=$this->finding('blocker','source_path_escape','unknown.php',1,'A source path escaped or traversed a symbolic link.');return;}
		$relative=$this->relativeFromAbsolute($real);if(strtolower(pathinfo($relative,PATHINFO_EXTENSION))!=='php')return;
		$result[$relative]=$real;
	}

	/** @param list<array<string,mixed>> $findings @return array<string,mixed>|null */
	private function parse(string $path,string $source,array &$findings):?array{
		if(!str_contains($source,'Filament\\')&&!str_contains($path,'Filament'))return null;
		$tokens=$this->tokens($source);$namespace=$this->namespace($tokens);$imports=$this->imports($tokens,$namespace);
		$classes=$this->classes($tokens,$namespace,$imports);$metadata=$this->metadata($source,$namespace,$imports);
		foreach($classes as&$class){$class['metadata']=$metadata;}unset($class);
		$components=$this->components($tokens,$namespace,$imports,$path,$findings);
		$references=$this->references($tokens,$namespace,$imports);
		if($classes===[]&&$components===[]){$findings[]=$this->finding('warning','filament_source_unclassified',$path,1,'Filament-referencing source has no recognized class or component declaration.');}
		foreach($classes as$class){
			if(in_array($class['kind'],['page','widget','relation_manager','panel_provider','plugin','cluster','importer','exporter','support'],true)){
				$findings[]=$this->finding('warning','manual_'.$class['kind'],$path,(int)$class['line'],'This Filament class requires an explicit Panel migration adapter.');
			}
		}
		return[
			'path'=>$path,'sha256'=>hash('sha256',$source),'bytes'=>strlen($source),
			'namespace'=>$namespace,'classes'=>$classes,'components'=>$components,'references'=>$references,
			'import_count'=>count($imports),'source_serialized'=>false,
		];
	}

	/**
	 * Captures static class dependencies without resolving or loading them. This
	 * links Filament 5 resource shells to generated Schema and Table companions.
	 *
	 * @param list<array{id:int|null,text:string,line:int}> $tokens
	 * @param array<string,string> $imports
	 * @return list<string>
	 */
	private function references(array $tokens,string $namespace,array $imports):array{
		$references=[];$count=count($tokens);
		for($index=0;$index<$count;$index++){
			if(!$this->nameToken($tokens[$index]))continue;
			$double=$this->nextSignificant($tokens,$index+1);
			if($double===null||$tokens[$double]['id']!==T_DOUBLE_COLON)continue;
			$raw=trim($tokens[$index]['text']);$lower=strtolower($raw);
			if(in_array($lower,['self','static','parent'],true))continue;
			$resolved=$this->resolveClass($raw,$namespace,$imports);
			if($resolved!==''&&!str_starts_with($resolved,'Filament\\'))$references[$resolved]=$resolved;
		}
		ksort($references,SORT_STRING);return array_values($references);
	}

	/** @return list<array{id:int|null,text:string,line:int}> */
	private function tokens(string $source):array{
		$raw=token_get_all($source,TOKEN_PARSE);$tokens=[];$line=1;
		foreach($raw as$token){if(is_array($token)){$line=$token[2];$tokens[]=['id'=>$token[0],'text'=>$token[1],'line'=>$line];$line+=substr_count($token[1],"\n");}else{$tokens[]=['id'=>null,'text'=>$token,'line'=>$line];$line+=substr_count($token,"\n");}}
		return$tokens;
	}

	/** @param list<array{id:int|null,text:string,line:int}> $tokens */
	private function namespace(array $tokens):string{
		$depth=0;foreach($tokens as$index=>$token){$text=$token['text'];if($text==='{')$depth++;elseif($text==='}')$depth=max(0,$depth-1);if($token['id']!==T_NAMESPACE||$depth!==0)continue;$name='';for($i=$index+1,$count=count($tokens);$i<$count;$i++){if(in_array($tokens[$i]['text'],[';','{'],true))break;if($this->nameToken($tokens[$i]))$name.=$tokens[$i]['text'];}return trim($name,'\\');}return'';
	}

	/** @param list<array{id:int|null,text:string,line:int}> $tokens @return array<string,string> */
	private function imports(array $tokens,string $namespace):array{
		$imports=[];$depth=0;$count=count($tokens);
		for($index=0;$index<$count;$index++){
			$token=$tokens[$index];$text=$token['text'];if($text==='{'){$depth++;continue;}if($text==='}'){$depth=max(0,$depth-1);continue;}if($token['id']!==T_USE||$depth!==0)continue;
			$statement='';for($i=$index+1;$i<$count&&$tokens[$i]['text']!==';';$i++){$statement.=$tokens[$i]['text'];}
			if(str_contains($statement,'{')||preg_match('/^\s*(?:function|const)\s+/i',$statement)===1)continue;
			foreach(explode(',',$statement)as$part){$part=trim($part);if($part==='')continue;if(preg_match('/^([^\s]+)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$/i',$part,$matches)!==1)continue;$class=trim($matches[1],'\\');$alias=$matches[2]??substr($class,(int)strrpos('\\'.$class,'\\'));$imports[$alias]=$class;}
		}
		ksort($imports,SORT_STRING);return$imports;
	}

	/** @param list<array{id:int|null,text:string,line:int}> $tokens @param array<string,string> $imports @return list<array<string,mixed>> */
	private function classes(array $tokens,string $namespace,array $imports):array{
		$result=[];$count=count($tokens);
		for($index=0;$index<$count;$index++){
			if($tokens[$index]['id']!==T_CLASS)continue;$previous=$this->previousSignificant($tokens,$index-1);if($previous!==null&&$tokens[$previous]['id']===T_NEW)continue;
			$nameIndex=$this->nextSignificant($tokens,$index+1);if($nameIndex===null||$tokens[$nameIndex]['id']!==T_STRING)continue;$name=$tokens[$nameIndex]['text'];$extends='';
			for($i=$nameIndex+1;$i<$count&&$tokens[$i]['text']!=='{';$i++){if($tokens[$i]['id']===T_EXTENDS){$extends=$this->nameFollowing($tokens,$i+1);break;}}
			$resolved=$extends!==''?$this->resolveClass($extends,$namespace,$imports):'';
			$result[]=['class'=>$name,'fqcn'=>trim($namespace.'\\'.$name,'\\'),'extends'=>$resolved,'kind'=>$this->classKind($resolved,$name),'line'=>$tokens[$index]['line']];
		}
		return$result;
	}

	/** @param list<array{id:int|null,text:string,line:int}> $tokens @param array<string,string> $imports @param list<array<string,mixed>> $findings @return list<array<string,mixed>> */
	private function components(array $tokens,string $namespace,array $imports,string $path,array &$findings):array{
		$result=[];$count=count($tokens);
		for($index=0;$index<$count;$index++){
			if(!$this->nameToken($tokens[$index]))continue;$double=$this->nextSignificant($tokens,$index+1);if($double===null||$tokens[$double]['id']!==T_DOUBLE_COLON)continue;$method=$this->nextSignificant($tokens,$double+1);if($method===null||strtolower($tokens[$method]['text'])!=='make')continue;$open=$this->nextSignificant($tokens,$method+1);if($open===null||$tokens[$open]['text']!=='(')continue;$close=$this->matching($tokens,$open,'(',')');if($close===null)continue;
			$class=$this->resolveClass($tokens[$index]['text'],$namespace,$imports);$short=substr($class,(int)strrpos('\\'.$class,'\\'));$mapping=$this->componentMapping($short,$class);if($mapping===null)continue;
			[$makeLiteral,$makeValue]=$this->argumentValue(array_slice($tokens,$open+1,$close-$open-1),$namespace,$imports);
			$name=is_string($makeValue)?$makeValue:'';$methods=[];$dynamic=!$makeLiteral;$cursor=$close+1;
			while(($arrow=$this->nextSignificant($tokens,$cursor))!==null&&in_array($tokens[$arrow]['text'],['->','?->'],true)){
				$methodIndex=$this->nextSignificant($tokens,$arrow+1);$methodOpen=$methodIndex!==null?$this->nextSignificant($tokens,$methodIndex+1):null;if($methodIndex===null||$methodOpen===null||$tokens[$methodIndex]['id']!==T_STRING||$tokens[$methodOpen]['text']!=='(')break;$methodClose=$this->matching($tokens,$methodOpen,'(',')');if($methodClose===null)break;
				[$literal,$value]=$this->argumentValue(array_slice($tokens,$methodOpen+1,$methodClose-$methodOpen-1),$namespace,$imports);$methodName=$tokens[$methodIndex]['text'];$methods[]=['method'=>$methodName,'literal'=>$literal,'value'=>$literal?$value:null];if(!$literal&&!in_array(strtolower($methodName),['schema','components','tabs'],true))$dynamic=true;$cursor=$methodClose+1;
			}
			$row=['family'=>$mapping['family'],'source_type'=>$short,'panel_type'=>$mapping['panel_type'],'class'=>$class,'name'=>$name,'line'=>$tokens[$index]['line'],'dynamic'=>$dynamic,'methods'=>$methods];$result[]=$row;
			if($mapping['family']==='unsupported'){$findings[]=$this->finding('warning','unsupported_filament_component',$path,$tokens[$index]['line'],'A Filament component has no automatic Panel mapping.');}
			if($dynamic){$findings[]=$this->finding('warning','dynamic_component_configuration',$path,$tokens[$index]['line'],'Dynamic component configuration requires manual semantic migration.');}
			$index=$close;
		}
		usort($result,static fn(array $left,array $right):int=>[$left['line'],$left['family'],$left['source_type'],$left['name']]<=>[$right['line'],$right['family'],$right['source_type'],$right['name']]);return$result;
	}

	/** @param array<string,string> $imports @return array<string,mixed> */
	private function metadata(string $source,string $namespace,array $imports):array{
		$result=[];$names='model|navigationLabel|pluralModelLabel|modelLabel|navigationGroup|navigationIcon|navigationSort|slug|recordTitleAttribute|navigationParentItem|shouldRegisterNavigation';
		if(preg_match_all('/(?:public|protected|private)\s+static\s+(?:[?\\\\A-Za-z0-9_|]+\s+)?\$(' . $names . ')\s*=\s*([^;]+);/m',$source,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE)!==false){
			foreach($matches as$match){$name=$match[1][0];$expression=$match[2][0];try{$tokens=$this->tokens('<?php '.$expression.';');}catch(\ParseError){continue;}$tokens=array_values(array_filter($tokens,static fn(array $token):bool=>$token['id']!==T_OPEN_TAG&&$token['text']!==';'));[$literal,$value]=$this->argumentValue($tokens,$namespace,$imports);if($literal){$result[$name]=$value;}}
		}
		ksort($result,SORT_STRING);return$result;
	}

	/** @param list<array{id:int|null,text:string,line:int}> $tokens @param array<string,string> $imports @return array{bool,mixed} */
	private function argumentValue(array $tokens,string $namespace,array $imports):array{
		$tokens=$this->significant($tokens);if($tokens===[])return[true,true];$parts=$this->splitTopLevel($tokens,',');$values=[];foreach($parts as$part){[$ok,$value]=$this->literal($part,$namespace,$imports);if(!$ok)return[false,null];$values[]=$value;}return[true,count($values)===1?$values[0]:$values];
	}

	/** @param list<array{id:int|null,text:string,line:int}> $tokens @param array<string,string> $imports @return array{bool,mixed} */
	private function literal(array $tokens,string $namespace,array $imports):array{
		$tokens=$this->significant($tokens);$count=count($tokens);if($count===0)return[true,null];
		if($count===1){$token=$tokens[0];if($token['id']===T_CONSTANT_ENCAPSED_STRING)return[true,$this->phpString($token['text'])];if($token['id']===T_LNUMBER)return[true,(int)str_replace('_','',$token['text'])];if($token['id']===T_DNUMBER)return[true,(float)str_replace('_','',$token['text'])];$lower=strtolower($token['text']);if($lower==='true')return[true,true];if($lower==='false')return[true,false];if($lower==='null')return[true,null];}
		if($count===2&&$tokens[0]['text']==='-'&&in_array($tokens[1]['id'],[T_LNUMBER,T_DNUMBER],true)){return$tokens[1]['id']===T_LNUMBER?[true,-(int)str_replace('_','',$tokens[1]['text'])]:[true,-(float)str_replace('_','',$tokens[1]['text'])];}
		if($count===3&&$this->nameToken($tokens[0])&&$tokens[1]['id']===T_DOUBLE_COLON&&strtolower($tokens[2]['text'])==='class'){return[true,$this->resolveClass($tokens[0]['text'],$namespace,$imports)];}
		if(($tokens[0]['text']==='['&&$tokens[$count-1]['text']===']')||($tokens[0]['id']===T_ARRAY&&isset($tokens[1],$tokens[$count-1])&&$tokens[1]['text']==='('&&$tokens[$count-1]['text']===')')){
			$inside=$tokens[0]['text']==='['?array_slice($tokens,1,-1):array_slice($tokens,2,-1);if($inside===[])return[true,[]];$result=[];$list=true;$next=0;
			foreach($this->splitTopLevel($inside,',')as$entry){$arrows=$this->topLevelPositions($entry,'=>');if(count($arrows)>1)return[false,null];if($arrows===[]){[$ok,$value]=$this->literal($entry,$namespace,$imports);if(!$ok)return[false,null];$result[]=$value;$next++;continue;}$position=$arrows[0];[$keyOk,$key]=$this->literal(array_slice($entry,0,$position),$namespace,$imports);[$valueOk,$value]=$this->literal(array_slice($entry,$position+1),$namespace,$imports);if(!$keyOk||!$valueOk||(!is_int($key)&&!is_string($key)))return[false,null];$result[$key]=$value;$list=false;}
			return[true,$list?array_values($result):$result];
		}
		return[false,null];
	}

	/** @return array{family:string,panel_type:?string}|null */
	private function componentMapping(string $short,string $class):?array{
		$fields=['TextInput'=>'text','Textarea'=>'textarea','Select'=>'select','Checkbox'=>'checkbox','Toggle'=>'toggle','CheckboxList'=>'checkbox_list','Radio'=>'radio','DatePicker'=>'date','DateTimePicker'=>'datetime','TimePicker'=>'time','FileUpload'=>'file','RichEditor'=>'rich_editor','MarkdownEditor'=>'markdown','Repeater'=>'repeater','Builder'=>'builder','TagsInput'=>'tags','KeyValue'=>'key_value','ColorPicker'=>'color','ToggleButtons'=>'toggle_buttons','Slider'=>'range','CodeEditor'=>'code','Hidden'=>'hidden'];
		$columns=['TextColumn'=>'text','IconColumn'=>'icon','ImageColumn'=>'image','ColorColumn'=>'color','SelectColumn'=>'select','ToggleColumn'=>'toggle','TextInputColumn'=>'text','CheckboxColumn'=>'checkbox'];
		$filters=['Filter'=>'custom','SelectFilter'=>'select','TernaryFilter'=>'ternary','TrashedFilter'=>'trashed'];
		$layouts=['Section'=>'section','Grid'=>'grid','Flex'=>'flex','Fieldset'=>'fieldset','Tabs'=>'tabs','Tab'=>'tab','Wizard'=>'wizard','Step'=>'step','Callout'=>'callout','Split'=>'split','Stack'=>'stack'];
		$entries=['TextEntry'=>'text','IconEntry'=>'icon','ImageEntry'=>'image','ColorEntry'=>'color','CodeEntry'=>'code','KeyValueEntry'=>'key_value','RepeatableEntry'=>'repeatable'];
		if(isset($fields[$short]))return['family'=>'field','panel_type'=>$fields[$short]];if(isset($columns[$short]))return['family'=>'column','panel_type'=>$columns[$short]];if(isset($filters[$short]))return['family'=>'filter','panel_type'=>$filters[$short]];if(isset($layouts[$short]))return['family'=>'layout','panel_type'=>$layouts[$short]];if(isset($entries[$short]))return['family'=>'infolist','panel_type'=>$entries[$short]];
		if(str_ends_with($short,'Action')||$short==='ActionGroup'||$short==='BulkActionGroup')return['family'=>'action','panel_type'=>Resource::normalizeName(preg_replace('/Action$/','',$short)??$short)];
		if(str_starts_with($class,'Filament\\')&&(str_contains($class,'\\Components\\')||str_contains($class,'\\Columns\\')||str_contains($class,'\\Filters\\')||str_contains($class,'\\Actions\\')))return['family'=>'unsupported','panel_type'=>null];
		return null;
	}

	private function classKind(string $extends,string $name):string{
		$short=substr($extends,(int)strrpos('\\'.$extends,'\\'));
		return match(true){$short==='RelationManager'=>'relation_manager',$short==='PanelProvider'=>'panel_provider',$short==='Resource'||str_ends_with($short,'Resource')=>'resource',in_array($short,['Page','ListRecords','CreateRecord','EditRecord','ViewRecord','ManageRecords'],true)||str_ends_with($short,'Page')=>'page',str_contains($short,'Widget')=>'widget',$short==='Importer'||str_ends_with($name,'Importer')=>'importer',$short==='Exporter'||str_ends_with($name,'Exporter')=>'exporter',$short==='Cluster'=>'cluster',$short==='Plugin'||str_ends_with($name,'Plugin')=>'plugin',default=>'support'};
	}

	/** @return array<string,mixed> */
	private function composer():array{
		$version='';$constraint='';$plugins=[];$lock=$this->root.DIRECTORY_SEPARATOR.'composer.lock';
		if(is_file($lock)&&!is_link($lock))try{$data=json_decode((string)file_get_contents($lock),true,512,JSON_THROW_ON_ERROR);foreach([...(array)($data['packages']??[]),...(array)($data['packages-dev']??[])]as$package){if(!is_array($package)||!is_string($package['name']??null))continue;$name=strtolower($package['name']);if($name==='filament/filament')$version=(string)($package['version']??'');elseif(str_contains($name,'filament'))$plugins[$name]=(string)($package['version']??'');}}catch(\Throwable){}
		$json=$this->root.DIRECTORY_SEPARATOR.'composer.json';if(is_file($json)&&!is_link($json))try{$data=json_decode((string)file_get_contents($json),true,512,JSON_THROW_ON_ERROR);$constraint=(string)($data['require']['filament/filament']??$data['require-dev']['filament/filament']??'');}catch(\Throwable){}
		ksort($plugins,SORT_STRING);$major=null;if(preg_match('/(?:^|[^0-9])v?([0-9]+)\./',$version,$matches)===1)$major=(int)$matches[1];
		return['package'=>'filament/filament','version'=>$version,'constraint'=>$constraint,'detected_major'=>$major,'supported_majors'=>[3,4,5],'plugins'=>$plugins];
	}

	/** @param list<array{id:int|null,text:string,line:int}> $tokens */
	private function nameFollowing(array $tokens,int $start):string{$name='';for($i=$start,$count=count($tokens);$i<$count;$i++){if($this->ignored($tokens[$i]))continue;if($this->nameToken($tokens[$i])){$name.=$tokens[$i]['text'];continue;}break;}return$name;}
	private function resolveClass(string $class,string $namespace,array $imports):string{$class=trim($class);if($class==='')return'';$absolute=str_starts_with($class,'\\');$class=ltrim($class,'\\');$parts=explode('\\',$class);$first=$parts[0];if(isset($imports[$first])){array_shift($parts);return trim($imports[$first].($parts!==[]?'\\'.implode('\\',$parts):''),'\\');}return$absolute?$class:trim($namespace.'\\'.$class,'\\');}
	private function nameToken(array $token):bool{return in_array($token['id'],array_filter([T_STRING,defined('T_NAME_QUALIFIED')?T_NAME_QUALIFIED:null,defined('T_NAME_FULLY_QUALIFIED')?T_NAME_FULLY_QUALIFIED:null,defined('T_NAME_RELATIVE')?T_NAME_RELATIVE:null,defined('T_NS_SEPARATOR')?T_NS_SEPARATOR:null]),true);}
	private function ignored(array $token):bool{return in_array($token['id'],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true);}
	private function nextSignificant(array $tokens,int $start):?int{for($i=$start,$count=count($tokens);$i<$count;$i++){if(!$this->ignored($tokens[$i]))return$i;}return null;}
	private function previousSignificant(array $tokens,int $start):?int{for($i=$start;$i>=0;$i--){if(!$this->ignored($tokens[$i]))return$i;}return null;}
	private function matching(array $tokens,int $open,string $left,string $right):?int{$depth=0;for($i=$open,$count=count($tokens);$i<$count;$i++){if($tokens[$i]['text']===$left)$depth++;elseif($tokens[$i]['text']===$right&&--$depth===0)return$i;}return null;}
	/** @param list<array{id:int|null,text:string,line:int}> $tokens @return list<array{id:int|null,text:string,line:int}> */
	private function significant(array $tokens):array{return array_values(array_filter($tokens,fn(array $token):bool=>!$this->ignored($token)));}
	/** @param list<array{id:int|null,text:string,line:int}> $tokens @return list<list<array{id:int|null,text:string,line:int}>> */
	private function splitTopLevel(array $tokens,string $delimiter):array{$parts=[];$part=[];$round=0;$square=0;$curly=0;foreach($tokens as$token){$text=$token['text'];if($text==='(')$round++;elseif($text===')')$round--;elseif($text==='[')$square++;elseif($text===']')$square--;elseif($text==='{')$curly++;elseif($text==='}')$curly--;if($text===$delimiter&&$round===0&&$square===0&&$curly===0){if($part!==[])$parts[]=$part;$part=[];continue;}$part[]=$token;}if($part!==[])$parts[]=$part;return$parts;}
	/** @param list<array{id:int|null,text:string,line:int}> $tokens @return list<int> */
	private function topLevelPositions(array $tokens,string $needle):array{$positions=[];$round=0;$square=0;$curly=0;foreach($tokens as$index=>$token){$text=$token['text'];if($text==='(')$round++;elseif($text===')')$round--;elseif($text==='[')$square++;elseif($text===']')$square--;elseif($text==='{')$curly++;elseif($text==='}')$curly--;if($text===$needle&&$round===0&&$square===0&&$curly===0)$positions[]=$index;}return$positions;}
	private function phpString(string $literal):string{$quote=$literal[0]??"'";$value=substr($literal,1,-1);return$quote==="'"?str_replace(["\\'","\\\\"],["'","\\"],$value):stripcslashes($value);}
	private function relative(string $path):string{$path=str_replace('\\','/',trim($path));if($path===''||str_starts_with($path,'/')||preg_match('/^[A-Za-z]:\//',$path)===1||str_contains($path,"\0"))throw new \InvalidArgumentException('Filament source paths must be project-relative.');$parts=[];foreach(explode('/',$path)as$part){if($part===''||$part==='.')continue;if($part==='..'||preg_match('/[\x00-\x1F\x7F]/',$part)===1)throw new \InvalidArgumentException('Filament source path escapes the project root.');$parts[]=$part;}if($parts===[])throw new \InvalidArgumentException('Filament source path is empty.');return implode('/',$parts);}
	private function relativeFromAbsolute(string $absolute):string{$absolute=str_replace('\\','/',$absolute);$root=str_replace('\\','/',$this->root);if(!$this->withinRoot($absolute))throw new \InvalidArgumentException('Filament source path escapes the project root.');return ltrim(substr($absolute,strlen($root)),'/');}
	private function withinRoot(string $path):bool{$root=str_replace('\\','/',$this->root);$path=str_replace('\\','/',$path);if(PanelFilesystemPath::usesWindowsSemantics($root)||PanelFilesystemPath::usesWindowsSemantics($path)){$root=strtolower($root);$path=strtolower($path);}return$path===$root||str_starts_with($path.'/',$root.'/');}
	private function finding(string $severity,string $code,string $path,int $line,string $message):array{return['severity'=>$severity,'code'=>$code,'path'=>$path,'line'=>$line,'message'=>$message];}
}
