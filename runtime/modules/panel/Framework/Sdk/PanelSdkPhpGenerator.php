<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Emits a framework-neutral PHP client with an injected HTTP transport. */
final class PanelSdkPhpGenerator {
	/** @return array<string,string> */
	public function files(PanelSdkContract $contract,string $namespace,string $class,string $package):array {
		$namespace=PanelSdkGuard::phpNamespace($namespace);$class=PanelSdkGuard::phpClass($class);$package=PanelSdkGuard::packageId($package);if(str_starts_with($package,'@')||substr_count($package,'/')!==1){throw new \InvalidArgumentException('Panel PHP SDK package must use a Composer vendor/package id.');}
		$prefix=$namespace.'\\';
		return[
			'composer.json'=>PanelSdkGuard::canonicalJson(['name'=>$package,'description'=>'Generated '.$contract->label().' Panel SDK','type'=>'library','license'=>'MIT','require'=>['php'=>'^8.2'],'autoload'=>['psr-4'=>[$prefix=>'src/']]]),
			'src/PanelTransport.php'=>$this->transport($namespace),
			'src/PanelTransportResponse.php'=>$this->response($namespace),
			'src/PanelSdkException.php'=>$this->exception($namespace),
			'src/'.$class.'.php'=>$this->client($contract,$namespace,$class),
			'README.md'=>$this->readme($contract,$namespace,$class),
		];
	}

	private function transport(string $namespace):string {return "<?php\n".str_replace('__NAMESPACE__',$namespace,<<<'PHP'
declare(strict_types=1);

namespace __NAMESPACE__;

/** Host-supplied HTTP transport. Authentication, CSRF, retries, and telemetry remain host-owned. */
interface PanelTransport {
	/** @param array<string,string> $headers */
	public function send(string $method,string $url,array $headers,?string $jsonBody=null):PanelTransportResponse;
}
PHP
)."\n";}

	private function response(string $namespace):string {return "<?php\n".str_replace('__NAMESPACE__',$namespace,<<<'PHP'
declare(strict_types=1);

namespace __NAMESPACE__;

final class PanelTransportResponse {
	/** @var array<string,string> */private readonly array $headers;
	/** @param array<string,string> $headers */
	public function __construct(private readonly int $status,private readonly mixed $body,array $headers=[]){
		if($status<100||$status>599){throw new \InvalidArgumentException('Panel SDK transport status is invalid.');}
		$clean=[];foreach($headers as$name=>$value){if(!is_string($name)||!is_string($value)||preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D",$name)!==1||preg_match('/[\r\n]/',$value)===1){throw new \InvalidArgumentException('Panel SDK response header is invalid.');}$clean[strtolower($name)]=$value;}$this->headers=$clean;
	}
	public function status():int{return$this->status;}public function body():mixed{return$this->body;}/** @return array<string,string> */public function headers():array{return$this->headers;}public function header(string $name):?string{return$this->headers[strtolower($name)]??null;}
}
PHP
)."\n";}

	private function exception(string $namespace):string {return "<?php\n".str_replace('__NAMESPACE__',$namespace,<<<'PHP'
declare(strict_types=1);

namespace __NAMESPACE__;

final class PanelSdkException extends \RuntimeException {
	/** @param list<string> $validationErrors */
	public function __construct(string $message,public readonly string $publicCode,public readonly int $httpStatus,public readonly ?string $correlationId,public readonly mixed $responseBody=null,public readonly array $validationErrors=[]){parent::__construct($message,$httpStatus);}
}
PHP
)."\n";}

	private function client(PanelSdkContract $contract,string $namespace,string $class):string {
		$methods=[];foreach($contract->operations()as$operation){$methods[]=$this->method($operation);}$methods=implode("\n\n",$methods);$export=var_export($contract->jsonSerialize(),true);$version=var_export($contract->version(),true);$fingerprint=var_export($contract->fingerprint(),true);
		$template=<<<'PHP'
<?php
declare(strict_types=1);

namespace __NAMESPACE__;

/** Generated Panel SDK client. */
final class __CLASS__ {
	public const VERSION=__VERSION__;
	public const CONTRACT_FINGERPRINT=__FINGERPRINT__;
	private const CONTRACT=__CONTRACT__;
	private readonly string $baseUrl;
	private $headerProvider;

	/** @param array<string,string>|callable():array<string,string> $headers */
	public function __construct(private readonly PanelTransport $transport,string $baseUrl='',array|callable $headers=[],private readonly bool $validateResponses=true){$this->baseUrl=self::baseUrl($baseUrl);$this->headerProvider=$headers;}

__METHODS__

	/** @param array<string,mixed> $arguments */
	private function request(string $name,array $arguments):mixed {
		$operation=self::CONTRACT['operations'][$name]??null;if(!is_array($operation)){throw new PanelSdkException('SDK operation is unavailable.','operation_unavailable',0,null);}
		$path=is_array($arguments['path']??null)?$arguments['path']:[];$query=is_array($arguments['query']??null)?$arguments['query']:[];$body=$arguments['body']??null;$hasBody=array_key_exists('body',$arguments);
		$errors=array_merge(self::validate($path,$operation['request']['path'],'$.path','request'),self::validate($query,$operation['request']['query'],'$.query','request'));
		if($operation['request']['body']===null){if($hasBody){$errors[]='$.body: body is not accepted';}}else{$errors=array_merge($errors,self::validate($body,$operation['request']['body'],'$.body','request'));}
		if($errors!==[]){throw new PanelSdkException('SDK request does not match its contract.','request_contract_invalid',0,null,null,array_slice($errors,0,100));}
		$route=self::path($operation['path'],$path).self::query($query);$headers=$this->headers(is_array($arguments['headers']??null)?$arguments['headers']:[],$operation['request']['body']!==null);$json=null;
		if($operation['request']['body']!==null){try{$json=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}catch(\JsonException $exception){throw new PanelSdkException('SDK request body cannot be encoded.','request_json_invalid',0,null,null,[$exception->getMessage()]);}}
		$response=$this->transport->send($operation['method'],$this->baseUrl.$route,$headers,$json);$payload=self::payload($response);
		if($response->status()<200||$response->status()>=300){$schema=$operation['errors'][(string)$response->status()]??$operation['errors']['default']??null;$validation=is_array($schema)?self::validate($payload,$schema,'$.error','response'):[];$public=self::publicError($payload,$response->header('x-correlation-id'));throw new PanelSdkException($public['message'],$public['code'],$response->status(),$public['correlation_id'],$payload,$validation);}
		if($this->validateResponses){$validation=self::validate($payload,$operation['response'],'$.response','response');if($validation!==[]){$public=self::publicError($payload,$response->header('x-correlation-id'));throw new PanelSdkException('SDK response does not match its contract.','response_contract_invalid',$response->status(),$public['correlation_id'],$payload,$validation);}}
		return$payload;
	}

	/** @param array<string,string> $request @return array<string,string> */
	private function headers(array $request,bool $body):array {$provided=is_callable($this->headerProvider)?($this->headerProvider)():$this->headerProvider;if(!is_array($provided)){throw new PanelSdkException('SDK header provider returned an invalid value.','headers_invalid',0,null);}$headers=array_replace(['Accept'=>'application/json'],$body?['Content-Type'=>'application/json']:[],$provided,$request);foreach($headers as$name=>$value){if(!is_string($name)||!is_string($value)||preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D",$name)!==1||preg_match('/[\r\n]/',$value)===1){throw new PanelSdkException('SDK request header is invalid.','headers_invalid',0,null);}}return$headers;}

	private static function baseUrl(string $value):string {$value=rtrim(trim($value),'/');if($value===''){return'';}$parts=parse_url($value);if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||trim((string)($parts['host']??''))===''||isset($parts['user'],$parts['pass'])||isset($parts['query'])||isset($parts['fragment'])){throw new \InvalidArgumentException('Panel SDK base URL must be an absolute HTTP(S) URL without credentials, query, or fragment data.');}return$value;}

	/** @param array<string,mixed> $values */
	private static function path(string $template,array $values):string {$path=preg_replace_callback('/\{([A-Za-z][A-Za-z0-9_]*)\}/',static function(array $match)use($values):string{$value=$values[$match[1]]??null;if(!is_string($value)&&!is_int($value)){throw new PanelSdkException('SDK path parameter is missing.','path_parameter_missing',0,null);}return rawurlencode((string)$value);},$template);if(!is_string($path)){throw new PanelSdkException('SDK route could not be built.','path_invalid',0,null);}return$path;}

	/** @param array<string,mixed> $values */
	private static function query(array $values):string {$pairs=[];foreach($values as$name=>$value){if($value===null||$value===''){continue;}$items=is_array($value)&&array_is_list($value)?$value:[$value];foreach($items as$item){if(is_array($item)){$item=json_encode($item,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}$pairs[]=rawurlencode((string)$name).'='.rawurlencode(is_bool($item)?($item?'true':'false'):(string)$item);}}return$pairs===[]?'':'?'.implode('&',$pairs);}

	private static function payload(PanelTransportResponse $response):mixed {$body=$response->body();if(!is_string($body)){return$body;}if(strlen($body)>4194304){throw new PanelSdkException('SDK response exceeds 4 MiB.','response_too_large',$response->status(),$response->header('x-correlation-id'));}if($body===''){return null;}try{return json_decode($body,true,25,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new PanelSdkException('Transport returned malformed JSON.','response_json_invalid',$response->status(),$response->header('x-correlation-id'),$body);}}

	/** @return array{code:string,message:string,correlation_id:?string} */
	private static function publicError(mixed $payload,?string $header):array {$object=is_array($payload)&&(!array_is_list($payload)||$payload===[])?$payload:[];$nested=is_array($object['error']??null)?$object['error']:[];$code=is_string($nested['code']??null)?$nested['code']:(is_string($object['code']??null)?$object['code']:'http_error');$message=is_string($nested['message']??null)?$nested['message']:(is_string($object['message']??null)?$object['message']:'Panel request failed.');$correlation=is_string($nested['correlation_id']??null)?$nested['correlation_id']:(is_string($object['correlation_id']??null)?$object['correlation_id']:$header);return['code'=>$code,'message'=>$message,'correlation_id'=>$correlation];}

	/** @param array<string,mixed> $schema @return list<string> */
	private static function validate(mixed $value,array $schema,string $path,string $mode,int $depth=0,?int &$nodes=null):array {
		$nodes??=0;if($depth>24||++$nodes>20000){return[$path.': value exceeds validation bounds'];}if(isset($schema['anyOf'])&&is_array($schema['anyOf'])){foreach($schema['anyOf']as$member){if(is_array($member)&&self::validate($value,$member,$path,$mode,$depth+1,$nodes)===[]){return[];}}return[$path.': union mismatch'];}
		$type=$schema['type']??null;if(!is_string($type)){return self::jsonValue($value,0,$nodes)?[]:[$path.': value is not bounded JSON'];}if(!self::matches($value,$type)){return[$path.': expected '.$type];}
		if(isset($schema['enum'])&&is_array($schema['enum'])&&!in_array($value,$schema['enum'],true)){return[$path.': enum mismatch'];}$errors=[];
		if($type==='string'){$length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);if(isset($schema['minLength'])&&$length<$schema['minLength']){$errors[]=$path.': shorter than minLength';}if(isset($schema['maxLength'])&&$length>$schema['maxLength']){$errors[]=$path.': longer than maxLength';}if(isset($schema['pattern'])&&preg_match('~'.$schema['pattern'].'~u',$value)!==1){$errors[]=$path.': pattern mismatch';}if(isset($schema['format'])&&!self::format($value,(string)$schema['format'])){$errors[]=$path.': format mismatch';}}
		elseif($type==='number'||$type==='integer'){if(isset($schema['minimum'])&&$value<$schema['minimum']){$errors[]=$path.': below minimum';}if(isset($schema['maximum'])&&$value>$schema['maximum']){$errors[]=$path.': above maximum';}}
		elseif($type==='array'){if(isset($schema['minItems'])&&count($value)<$schema['minItems']){$errors[]=$path.': fewer than minItems';}if(isset($schema['maxItems'])&&count($value)>$schema['maxItems']){$errors[]=$path.': more than maxItems';}if(($schema['uniqueItems']??false)===true){$seen=[];foreach($value as$item){$key=hash('sha256',json_encode(self::canonical($item),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));if(isset($seen[$key])){$errors[]=$path.': duplicate items rejected';break;}$seen[$key]=true;}}foreach($value as$index=>$item){$errors=array_merge($errors,self::validate($item,$schema['items'],$path.'['.$index.']',$mode,$depth+1,$nodes));}}
		elseif($type==='object'){$properties=is_array($schema['properties']??null)?$schema['properties']:[];foreach((array)($schema['required']??[])as$name){if(!array_key_exists($name,$value)){$errors[]=$path.'.'.$name.': required property missing';}}foreach($value as$name=>$item){if(isset($properties[$name])&&is_array($properties[$name])){$errors=array_merge($errors,self::validate($item,$properties[$name],$path.'.'.$name,$mode,$depth+1,$nodes));}elseif($mode==='request'&&($schema['additionalProperties']??false)===false){$errors[]=$path.'.'.$name.': additional property rejected';}elseif(is_array($schema['additionalProperties']??null)){$errors=array_merge($errors,self::validate($item,$schema['additionalProperties'],$path.'.'.$name,$mode,$depth+1,$nodes));}}}
		return array_slice($errors,0,100);
	}

	private static function matches(mixed $value,string $type):bool {return match($type){'null'=>$value===null,'boolean'=>is_bool($value),'integer'=>is_int($value),'number'=>(is_int($value)||is_float($value))&&(!is_float($value)||is_finite($value)),'string'=>is_string($value),'array'=>is_array($value)&&array_is_list($value),'object'=>is_array($value)&&(!array_is_list($value)||$value===[]),default=>false};}
	private static function jsonValue(mixed $value,int $depth,int &$nodes):bool {if($depth>24||++$nodes>20000){return false;}if($value===null||is_bool($value)||is_int($value)||is_string($value)){return true;}if(is_float($value)){return is_finite($value);}if(!is_array($value)){return false;}foreach($value as$item){if(!self::jsonValue($item,$depth+1,$nodes)){return false;}}return true;}
	private static function format(string $value,string $format):bool {if($format==='email'){return filter_var($value,FILTER_VALIDATE_EMAIL)!==false;}if($format==='uri'){return filter_var($value,FILTER_VALIDATE_URL)!==false;}if($format==='uuid'){return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',$value)===1;}if($format==='date'){if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$match)!==1){return false;}return checkdate((int)$match[2],(int)$match[3],(int)$match[1]);}if($format==='date-time'){if(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D',$value)!==1){return false;}try{new \DateTimeImmutable($value);return true;}catch(\Throwable){return false;}}return true;}
	private static function canonical(mixed $value):mixed {if(!is_array($value)){return$value;}if(!array_is_list($value)){ksort($value,SORT_STRING);}foreach($value as$key=>$item){$value[$key]=self::canonical($item);}return$value;}
}
PHP;
		return str_replace(['__NAMESPACE__','__CLASS__','__VERSION__','__FINGERPRINT__','__CONTRACT__','__METHODS__'],[$namespace,$class,$version,$fingerprint,$export,$methods],$template)."\n";
	}

	private function method(PanelSdkOperation $operation):string {
		$name=self::methodName($operation->name());$parameters=[];$path=[];foreach($operation->pathParameters()as$parameter){$variable=self::variable($parameter);$parameters[]='string|int $'.$variable;$path[]=var_export($parameter,true).'=>$'.$variable;}
		$arguments=["'path'=>[".implode(',',$path)."]"];
		if($operation->bodySchema()!==null){$parameters[]='mixed $body';$arguments[]="'body'=>\$body";}$parameters[]='array $query=[]';$arguments[]="'query'=>\$query";$parameters[]='array $options=[]';
		$doc="\t/**\n\t * ".$operation->summary()."\n\t * @param array<string,mixed> \$query @param array{headers?:array<string,string>} \$options\n\t */";
		return$doc."\n\tpublic function {$name}(".implode(',',$parameters)."):mixed {\$unknown=array_diff(array_keys(\$options),['headers']);if(\$unknown!==[]){throw new \\InvalidArgumentException('Panel SDK call options contain unsupported keys.');}return\$this->request(".var_export($operation->name(),true).",[".implode(',',$arguments).",'headers'=>is_array(\$options['headers']??null)?\$options['headers']:[]]);}";
	}

	private function readme(PanelSdkContract $contract,string $namespace,string $class):string {return'# '.$contract->label()." PHP SDK\n\nGenerated from Panel SDK contract `".$contract->fingerprint()."`. Implement `".$namespace."\\PanelTransport` with the host HTTP stack, then inject it into `".$namespace."\\".$class."`. Authentication, CSRF, retries, and telemetry remain host-owned. No credentials are generated or embedded.\n\nRun `composer dump-autoload` after installing the generated package.\n";}
	private static function methodName(string $value):string {$parts=preg_split('/[^A-Za-z0-9]+/',$value,-1,PREG_SPLIT_NO_EMPTY)?:[];$name=lcfirst(implode('',array_map(static fn(string $part):string=>ucfirst($part),$parts)));$reserved=['__construct','clone','match','new','class','function','trait','interface','enum','readonly','static','echo','print','unset','isset','empty','include','require','yield','fn'];return$name===''||in_array(strtolower($name),$reserved,true)?'call'.ucfirst($name!==''?$name:'Operation'):$name;}
	private static function variable(string $value):string {$value=preg_replace('/[^A-Za-z0-9_]/','_',$value)??'parameter';if($value===''||ctype_digit($value[0])){$value='parameter_'.$value;}return$value;}
}
