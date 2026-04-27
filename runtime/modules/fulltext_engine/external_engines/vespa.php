<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\fulltext_engine;

class vespa {

	private const DEFAULT_QUERY_URL='http://127.0.0.1:8080';
	private const DEFAULT_CONFIG_URL='http://127.0.0.1:19071';

	private static function query_base_url(): string {
		$url=(string)(DP_FULLTEXT_ENGINE_CFG['external_engines']['vespa']['query_url']
			?? DP_FULLTEXT_ENGINE_CFG['vespa']['query_url']
			?? self::DEFAULT_QUERY_URL);
		$url=trim($url);
		return rtrim($url!=='' ? $url : self::DEFAULT_QUERY_URL, '/');
	}

	private static function config_base_url(): string {
		$url=(string)(DP_FULLTEXT_ENGINE_CFG['external_engines']['vespa']['config_url']
			?? DP_FULLTEXT_ENGINE_CFG['vespa']['config_url']
			?? self::DEFAULT_CONFIG_URL);
		$url=trim($url);
		return rtrim($url!=='' ? $url : self::DEFAULT_CONFIG_URL, '/');
	}

	private static function application_directory(string $application_name): string {
		return ROOTPATH['dataphyre'].'cache/fulltext_engine/vespa/'.$application_name;
	}

	private static function prepare_max_attempts(): int {
		return max(1, (int)(DP_FULLTEXT_ENGINE_CFG['external_engines']['vespa']['prepare_max_attempts']
			?? DP_FULLTEXT_ENGINE_CFG['vespa']['prepare_max_attempts']
			?? 10));
	}

	private static function prepare_retry_delay_seconds(): int {
		return max(0, (int)(DP_FULLTEXT_ENGINE_CFG['external_engines']['vespa']['prepare_retry_delay_seconds']
			?? DP_FULLTEXT_ENGINE_CFG['vespa']['prepare_retry_delay_seconds']
			?? 3));
	}

	private static function http_timeout_seconds(): int {
		return max(1, (int)(DP_FULLTEXT_ENGINE_CFG['external_engines']['vespa']['http_timeout_seconds']
			?? DP_FULLTEXT_ENGINE_CFG['vespa']['http_timeout_seconds']
			?? 30));
	}

	private static function document_endpoint(string $application_name, string $document_id): string {
		return self::query_base_url().'/document/v1/'.$application_name.'/'.$application_name.'/docid/'.rawurlencode($document_id);
	}

	private static function build_deployment_archive(string $source_directory, string $archive_path): bool {
		if(!class_exists('\ZipArchive')){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='ZipArchive extension is required for Vespa deployment packaging', $S='warning');
			return false;
		}
		$source_directory=rtrim($source_directory, '/\\');
		if(!is_dir($source_directory)){
			return false;
		}
		if(file_exists($archive_path)){
			@unlink($archive_path);
		}
		$zip=new \ZipArchive();
		if($zip->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)!==true){
			return false;
		}
		$iterator=new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source_directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach($iterator as $item){
			$full_path=$item->getPathname();
			$relative_path=substr($full_path, strlen($source_directory)+1);
			$relative_path=str_replace('\\', '/', $relative_path);
			if($relative_path===''){
				continue;
			}
			if($item->isDir()){
				$zip->addEmptyDir($relative_path);
				continue;
			}
			if($item->isFile() && $zip->addFile($full_path, $relative_path)===false){
				$zip->close();
				@unlink($archive_path);
				return false;
			}
		}
		return $zip->close();
	}

	private static function flatten_content(array $values): string {
		$parts=[];
		foreach($values as $value){
			if(is_array($value)){
				$value=json_encode($value);
			}
			elseif(is_object($value)){
				$value=json_encode($value);
			}
			$value=trim((string)$value);
			if($value!==''){
				$parts[]=$value;
			}
		}
		return implode(' ', $parts);
	}

	private static function normalize_boolean_mode(bool|string $boolean_mode): string {
		return match(true){
			is_bool($boolean_mode)=>($boolean_mode ? 'and' : 'or'),
			default=>strtolower((string)$boolean_mode)==='and' ? 'and' : 'or',
		};
	}

	private static function build_document_fields(array $values, string $primary_column_name, string $primary_key_value): array {
		return [
			$primary_column_name=>$primary_key_value,
			'content'=>self::flatten_content($values),
		];
	}

	public static function delete_index(string $application_name): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = null, $S = 'function_call', $A = func_get_args());
		$url=self::config_base_url().'/application/v2/tenant/default/application/'.
			$application_name.'/environment/dev/region/default/instance/default';
		$ch=curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::http_timeout_seconds());
		curl_exec($ch);
		$http_code=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return in_array($http_code, [200, 202, 404], true);
	}

	public static function create_index(string $application_name, string $primary_key): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = null, $S = 'function_call', $A = func_get_args());
		$app_dir=self::application_directory($application_name);
		$schema_dir=$app_dir.'/schemas';
		$zip_path=$app_dir.'.zip';
		$schema_name=$application_name;
		$schema_def=<<<SD
schema $schema_name {
	document $schema_name {
		field $primary_key type string {
			indexing: summary | attribute
		}
		field content type string {
			indexing: index | summary
		}
	}
	document-summary default {
		summary $primary_key type string
		summary content type string
	}
}
SD;
		$services_xml=<<<XML
<services version="1.0">
  <container id="default" version="1.0">
	<document-processing/>
	<search/>
	<document-api/>
	<http>
	  <server id="default" port="8080"/>
	</http>
  </container>
  <content id="default" version="1.0">
	<redundancy>1</redundancy>
	<documents>
	  <document type="$schema_name" mode="index"/>
	</documents>
	<nodes>
	  <node hostalias="localhost"/>
	</nodes>
  </content>
</services>
XML;
		if(\dataphyre\core::file_put_contents_forced($schema_dir.'/'.$schema_name.'.sd', $schema_def)===false){
			return false;
		}
		if(\dataphyre\core::file_put_contents_forced($app_dir.'/services.xml', $services_xml)===false){
			return false;
		}
		if(!is_dir($app_dir)){
			return false;
		}
		if(self::build_deployment_archive($app_dir, $zip_path)!==true || !file_exists($zip_path)){
			return false;
		}
		$zip_contents=file_get_contents($zip_path);
		if($zip_contents===false){
			@unlink($zip_path);
			return false;
		}
		$prepare_url=self::config_base_url().'/application/v2/tenant/default/prepare';
		$max_attempts=self::prepare_max_attempts();
		$retry_delay=self::prepare_retry_delay_seconds();
		$session_data=null;
		while($max_attempts-- > 0){
			$ch=curl_init($prepare_url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $zip_contents);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/zip']);
			curl_setopt($ch, CURLOPT_TIMEOUT, self::http_timeout_seconds());
			$response=curl_exec($ch);
			curl_close($ch);
			if($response!==false){
				$session_data=json_decode($response, true);
				if(is_array($session_data) && isset($session_data['session'])){
					break;
				}
			}
			if($max_attempts>0 && $retry_delay>0){
				sleep($retry_delay);
			}
		}
		@unlink($zip_path);
		if(!isset($session_data['session'])){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = 'Vespa prepare failed after retries', $S = 'fatal');
			return false;
		}
		$activate_url=self::config_base_url().$session_data['session'].'/active';
		$ch=curl_init($activate_url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::http_timeout_seconds());
		curl_exec($ch);
		$http_code=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return in_array($http_code, [200, 202], true);
	}

    public static function find(string $application_name, array $search_data, string $primary_column_name, bool|string $boolean_mode, string $language, int $max_results, float $threshold): array {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		if($primary_column_name===''){
			return [];
		}
        $yql_query=self::buildVespaQuery($search_data, $boolean_mode, $max_results);
        $url=self::query_base_url().'/search/?yql='.urlencode($yql_query);
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::http_timeout_seconds());
        $response=curl_exec($ch);
        curl_close($ch);
        if($response===false) {
            return [];
        }
        $response_data=json_decode($response, true);
        return self::processVespaResults(is_array($response_data) ? $response_data : [], $primary_column_name, $threshold);
    }

	public static function add(string $application_name, array $values, string $primary_column_name, string $primary_key_value, string $language): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
		$url=self::document_endpoint($application_name, $primary_key_value);
		$json_data=json_encode(['fields'=>self::build_document_fields($values, $primary_column_name, $primary_key_value)]);
		$ch=curl_init($url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::http_timeout_seconds());
		$response=curl_exec($ch);
		$http_code=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return $response!==false && in_array($http_code, [200, 201], true);
	}

	public static function update(string $application_name, array $values, string $primary_column_name, string $primary_key_value, string $language): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
		$url=self::document_endpoint($application_name, $primary_key_value);
		$json_data=json_encode(['fields'=>self::build_document_fields($values, $primary_column_name, $primary_key_value)]);
		$ch=curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::http_timeout_seconds());
		$response=curl_exec($ch);
		$http_code=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return $response!==false && in_array($http_code, [200, 201], true);
	}

	public static function remove(string $application_name, string $primary_column_name, string $primary_key_value): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
		$url=self::document_endpoint($application_name, $primary_key_value);
		$ch=curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::http_timeout_seconds());
		$response=curl_exec($ch);
		$http_code=(int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return $response!==false && in_array($http_code, [200, 202, 404], true);
	}

	private static function buildVespaQuery(array $search_data, bool|string $boolean_mode, int $max_results): string {
		$conditions=[];
		foreach($search_data as $value){
			$value=trim((string)$value);
			if($value===''){
				continue;
			}
			$value=str_replace("'", "\\'", $value);
			$conditions[]="content contains '".$value."'";
		}
		if(empty($conditions)){
			return "select * from sources * where true limit $max_results;";
		}
		$glue=' '.self::normalize_boolean_mode($boolean_mode).' ';
		return 'select * from sources * where '.implode($glue, $conditions)." limit $max_results;";
	}

    private static function processVespaResults(array $response_data, string $primary_column_name, float $threshold): array {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
        $results=[];
        if(isset($response_data['root']['children']) && is_array($response_data['root']['children'])) {
            foreach($response_data['root']['children'] as $child) {
                $fields=is_array($child['fields'] ?? null) ? $child['fields'] : [];
                $score=(float)($child['relevance'] ?? 0);
                if($score>=$threshold && array_key_exists($primary_column_name, $fields)) {
                    $results[]=array((string)$fields[$primary_column_name]=>$score);
                }
            }
        }
        return $results;
    }
}
