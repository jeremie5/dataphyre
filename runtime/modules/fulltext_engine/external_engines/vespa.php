<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\fulltext_engine;

/**
 * Implements the Dataphyre fulltext adapter for a local or configured Vespa deployment.
 *
 * The adapter owns Vespa application packaging, schema activation, document CRUD,
 * and simple YQL search projection. Configuration is read from
 * DP_FULLTEXT_ENGINE_CFG, deployment artifacts are staged under Dataphyre cache,
 * and network operations are performed through cURL against Vespa config and
 * query endpoints.
 */
class vespa {

	private const DEFAULT_QUERY_URL='http://127.0.0.1:8080';
	private const DEFAULT_CONFIG_URL='http://127.0.0.1:19071';

	/**
	 * Resolves the Vespa query/document API base URL.
	 *
	 * The external_engines.vespa key takes precedence over the legacy vespa key.
	 * Blank configuration falls back to the local Vespa query endpoint and the
	 * trailing slash is removed for endpoint composition.
	 *
	 * @return string Normalized Vespa query API base URL.
	 */
	private static function query_base_url(): string {
		$url=(string)(DP_FULLTEXT_ENGINE_CFG['external_engines']['vespa']['query_url']
			?? DP_FULLTEXT_ENGINE_CFG['vespa']['query_url']
			?? self::DEFAULT_QUERY_URL);
		$url=trim($url);
		return rtrim($url!=='' ? $url : self::DEFAULT_QUERY_URL, '/');
	}

	/**
	 * Resolves the Vespa config server base URL.
	 *
	 * The URL is used for prepare, activate, and delete operations on application
	 * packages. Blank configuration falls back to the local config server port.
	 *
	 * @return string Normalized Vespa config API base URL.
	 */
	private static function config_base_url(): string {
		$url=(string)(DP_FULLTEXT_ENGINE_CFG['external_engines']['vespa']['config_url']
			?? DP_FULLTEXT_ENGINE_CFG['vespa']['config_url']
			?? self::DEFAULT_CONFIG_URL);
		$url=trim($url);
		return rtrim($url!=='' ? $url : self::DEFAULT_CONFIG_URL, '/');
	}

	/**
	 * Builds the cache directory used to stage one Vespa application package.
	 *
	 * The path is deterministic per application name so subsequent create_index()
	 * calls replace the same schema and services files before packaging.
	 *
	 * @param string $application_name Vespa application and document type name.
	 * @return string Absolute staging directory under Dataphyre cache.
	 */
	private static function application_directory(string $application_name): string {
		return ROOTPATH['dataphyre'].'cache/fulltext_engine/vespa/'.$application_name;
	}

	/**
	 * Reads the maximum retry count for Vespa application prepare requests.
	 *
	 * Values below one are clamped so index creation always performs at least one
	 * prepare attempt before reporting failure.
	 *
	 * @return int Number of prepare attempts.
	 */
	private static function prepare_max_attempts(): int {
		return max(1, (int)(DP_FULLTEXT_ENGINE_CFG['external_engines']['vespa']['prepare_max_attempts']
			?? DP_FULLTEXT_ENGINE_CFG['vespa']['prepare_max_attempts']
			?? 10));
	}

	/**
	 * Reads the delay between Vespa prepare retries.
	 *
	 * Negative configuration is clamped to zero, allowing deployments to disable
	 * sleep while keeping retry attempts active.
	 *
	 * @return int Delay in seconds between prepare attempts.
	 */
	private static function prepare_retry_delay_seconds(): int {
		return max(0, (int)(DP_FULLTEXT_ENGINE_CFG['external_engines']['vespa']['prepare_retry_delay_seconds']
			?? DP_FULLTEXT_ENGINE_CFG['vespa']['prepare_retry_delay_seconds']
			?? 3));
	}

	/**
	 * Reads the cURL timeout used for Vespa network calls.
	 *
	 * The timeout applies to config, search, and document endpoints. Values below
	 * one are clamped to one second to avoid accidental infinite waits.
	 *
	 * @return int cURL timeout in seconds.
	 */
	private static function http_timeout_seconds(): int {
		return max(1, (int)(DP_FULLTEXT_ENGINE_CFG['external_engines']['vespa']['http_timeout_seconds']
			?? DP_FULLTEXT_ENGINE_CFG['vespa']['http_timeout_seconds']
			?? 30));
	}

	/**
	 * Builds the Vespa document API endpoint for one Dataphyre record.
	 *
	 * The adapter uses the application name as both Vespa namespace and document
	 * type, with the Dataphyre primary key encoded as the docid segment.
	 *
	 * @param string $application_name Vespa namespace and document type.
	 * @param string $document_id Dataphyre primary key value.
	 * @return string Fully-qualified Vespa document endpoint.
	 */
	private static function document_endpoint(string $application_name, string $document_id): string {
		return self::query_base_url().'/document/v1/'.$application_name.'/'.$application_name.'/docid/'.rawurlencode($document_id);
	}

	/**
	 * Packages a staged Vespa application directory into a deployment archive.
	 *
	 * ZipArchive is required by Vespa's prepare endpoint. Existing archives are
	 * replaced, paths inside the archive are normalized to forward slashes, and a
	 * partially-written archive is removed if any file cannot be added.
	 *
	 * @param string $source_directory Staged Vespa application directory.
	 * @param string $archive_path Destination zip path.
	 * @return bool Whether the archive was written and closed successfully.
	 */
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

	/**
	 * Flattens Dataphyre record values into the Vespa fulltext content field.
	 *
	 * Scalar values are trimmed directly, arrays and objects are JSON encoded, and
	 * empty values are skipped. The resulting string is the only indexed text field
	 * generated by this adapter.
	 *
	 * @param array<string|int,mixed> $values Source record values.
	 * @return string Space-joined searchable content.
	 */
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

	/**
	 * Normalizes Dataphyre boolean search mode into Vespa YQL glue.
	 *
	 * Boolean true and the string "and" require all terms. Every other value uses
	 * "or", matching the looser search behavior used by legacy adapters.
	 *
	 * @param bool|string $boolean_mode Dataphyre boolean mode flag.
	 * @return string Either "and" or "or".
	 */
	private static function normalize_boolean_mode(bool|string $boolean_mode): string {
		return match(true){
			is_bool($boolean_mode)=>($boolean_mode ? 'and' : 'or'),
			default=>strtolower((string)$boolean_mode)==='and' ? 'and' : 'or',
		};
	}

	/**
	 * Builds the Vespa document fields map for one Dataphyre record.
	 *
	 * The primary key is stored as an attribute/summary field for result mapping,
	 * and all supplied values are folded into the fulltext content field.
	 *
	 * @param array<string|int,mixed> $values Source record values.
	 * @param string $primary_column_name Dataphyre primary key column.
	 * @param string $primary_key_value Primary key value for the document.
	 * @return array<string,string> Vespa document field map.
	 */
	private static function build_document_fields(array $values, string $primary_column_name, string $primary_key_value): array {
		return [
			$primary_column_name=>$primary_key_value,
			'content'=>self::flatten_content($values),
		];
	}

	/**
	 * Deletes the deployed Vespa application for a Dataphyre fulltext index.
	 *
	 * Vespa reports asynchronous deletes with 202 and missing applications with
	 * 404; both are treated as successful end states for Dataphyre index removal.
	 *
	 * @param string $application_name Vespa application name.
	 * @return bool Whether Vespa accepted deletion or the application was absent.
	 */
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

	/**
	 * Creates or replaces a Vespa application for one Dataphyre fulltext index.
	 *
	 * The method writes schema and services files, builds a zip archive, submits
	 * it to the Vespa prepare endpoint with configured retries, then activates the
	 * returned session. Temporary zip artifacts are removed after prepare.
	 *
	 * @param string $application_name Vespa application and document type name.
	 * @param string $primary_key Primary key field exposed in summaries.
	 * @return bool Whether the application package was prepared and activated.
	 */
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

	/**
	 * Searches a Vespa application and maps hits back to Dataphyre primary keys.
	 *
	 * Search terms are converted into a simple content contains YQL expression.
	 * The language argument is accepted for adapter interface compatibility; this
	 * implementation relies on the Vespa schema analyzer instead of per-call
	 * language selection.
	 *
	 * @param string $application_name Vespa application to search.
	 * @param array<int|string,mixed> $search_data Search terms or values to flatten into query clauses.
	 * @param string $primary_column_name Primary key field expected in result summaries.
	 * @param bool|string $boolean_mode Whether terms should be joined with and or or.
	 * @param string $language Adapter interface language hint.
	 * @param int $max_results Maximum number of Vespa hits requested.
	 * @param float $threshold Minimum relevance score accepted.
	 * @return array<int,array<string,float>> Result maps of primary key value to relevance score.
	 */
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

	/**
	 * Adds a Dataphyre record document to Vespa.
	 *
	 * The document API receives a fields map built from the primary key and
	 * flattened content. The language argument is accepted for adapter interface
	 * compatibility and is not sent to Vespa.
	 *
	 * @param string $application_name Vespa application and document type name.
	 * @param array<string|int,mixed> $values Source record values.
	 * @param string $primary_column_name Primary key field name.
	 * @param string $primary_key_value Primary key value used as Vespa docid.
	 * @param string $language Adapter interface language hint.
	 * @return bool Whether Vespa accepted document creation.
	 */
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

	/**
	 * Replaces a Dataphyre record document in Vespa.
	 *
	 * The operation uses PUT against the deterministic document endpoint, sending
	 * the same field map as add(). Vespa 200 and 201 responses are accepted.
	 *
	 * @param string $application_name Vespa application and document type name.
	 * @param array<string|int,mixed> $values Source record values.
	 * @param string $primary_column_name Primary key field name.
	 * @param string $primary_key_value Primary key value used as Vespa docid.
	 * @param string $language Adapter interface language hint.
	 * @return bool Whether Vespa accepted the document replacement.
	 */
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

	/**
	 * Removes a Dataphyre record document from Vespa.
	 *
	 * Missing documents are treated as removed because the desired external state
	 * has already been reached. The primary column name is part of the adapter
	 * interface but the Vespa docid is sufficient for deletion.
	 *
	 * @param string $application_name Vespa application and document type name.
	 * @param string $primary_column_name Adapter interface primary key field.
	 * @param string $primary_key_value Primary key value used as Vespa docid.
	 * @return bool Whether Vespa accepted deletion or the document was absent.
	 */
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

	/**
	 * Builds the simple Vespa YQL query used by the adapter.
	 *
	 * Each non-empty search value becomes a content contains clause, single quotes
	 * are escaped for the YQL string literal, and an empty search set falls back to
	 * a true predicate capped by the requested limit.
	 *
	 * @param array<int|string,mixed> $search_data Search terms or values.
	 * @param bool|string $boolean_mode Dataphyre boolean mode flag.
	 * @param int $max_results Maximum Vespa hits requested.
	 * @return string Vespa YQL query.
	 */
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

	/**
	 * Projects Vespa search response children into Dataphyre result rows.
	 *
	 * Children below the relevance threshold or missing the configured primary
	 * field are skipped. Accepted rows preserve Vespa relevance as the Dataphyre
	 * match score.
	 *
	 * @param array<string,mixed> $response_data Decoded Vespa search response.
	 * @param string $primary_column_name Primary key field expected in child fields.
	 * @param float $threshold Minimum relevance score accepted.
	 * @return array<int,array<string,float>> Result maps of primary key value to relevance score.
	 */
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
