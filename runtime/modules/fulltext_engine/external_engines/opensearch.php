<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\fulltext_engine;

/**
 * Bridges Dataphyre fulltext operations to an OpenSearch-compatible HTTP API.
 *
 * This external engine keeps Dataphyre's fulltext contract stable while
 * delegating indexing, searching, scoring, and analyzer behavior to an
 * Elasticsearch/OpenSearch-compatible service. It owns URL normalization, query
 * request construction, result shaping, and transport-level failure mapping.
 */
class elasticsearch {

	private const DEFAULT_URL='http://127.0.0.1:9200';

	/**
	 * Resolves the configured OpenSearch endpoint root.
	 *
	 * This adapter accepts the historical elastic/elasticsearch config
	 * keys used by the shared compatibility layer, trims blank configuration, and
	 * falls back to the local OpenSearch-style default endpoint.
	 */
	private static function base_url(): string {
		$url=(string)(DP_FULLTEXT_ENGINE_CFG['external_engines']['elastic']['url']
			?? DP_FULLTEXT_ENGINE_CFG['elastic']['url']
			?? DP_FULLTEXT_ENGINE_CFG['external_engines']['elasticsearch']['url']
			?? DP_FULLTEXT_ENGINE_CFG['elasticsearch']['url']
			?? self::DEFAULT_URL);
		$url=trim($url);
		return rtrim($url!=='' ? $url : self::DEFAULT_URL, '/');
	}

	/**
	 * Builds an OpenSearch endpoint URL for an index and optional API suffix.
	 *
	 * Index names are lowercased before use, suffixes are normalized
	 * without leading slashes, and callers receive a complete URL for index,
	 * _search, _doc, or nested document update/delete routes.
	 */
	private static function index_url(string $index_name, string $suffix=''): string {
		$index_name=strtolower($index_name);
		$suffix=ltrim($suffix, '/');
		return self::base_url().'/'.$index_name.($suffix!=='' ? '/'.$suffix : '');
	}

	/**
	 * Searches an OpenSearch index and returns Dataphyre score matches.
	 *
	 * Input fields are converted into a bool/match query, submitted to
	 * the index _search endpoint, and decoded hits are reduced to Dataphyre's
	 * list of [primary-key-value => score] rows above the requested threshold.
	 */
    public static function find(string $index_name, array $search_data, string $primary_column_name, bool|string $boolean_mode, string $language, int $max_results, float $threshold){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		$language=explode('-', $language)[0];
		$index_name=strtolower($index_name);
        $query=self::buildElasticsearchQuery($search_data, $boolean_mode, $language, $max_results);
        $url=self::index_url($index_name, '_search');
        $json_data=json_encode($query);
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $response=curl_exec($ch);
        curl_close($ch);
        if($response===false){
            return [];
        }
        $response_data=json_decode($response, true);
        return self::processElasticsearchResults(is_array($response_data) ? $response_data : [], $primary_column_name, $threshold);
    }

	/**
	 * Creates an OpenSearch index with Dataphyre's text-field mapping defaults.
	 *
	 * The primary key is declared as keyword, dynamic string fields are
	 * analyzed text, and the analyzer is selected from the first language segment
	 * before the PUT request is sent to the normalized index endpoint.
	 */
	public static function create_index(string $index_name, string $primary_key, string $language): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = null, $S = 'function_call', $A=null);
		$language=explode('-', $language)[0];
		$index_name=strtolower($index_name);
		$analyzer = self::mapLanguageToAnalyzer($language);
		$index_definition = [
			'settings' => [
				'analysis' => [
					'analyzer' => [
						'default' => [
							'type' => $analyzer
						]
					]
				]
			],
			'mappings' => [
				'dynamic_templates' => [
					[
						'string_fields' => [
							'match_mapping_type' => 'string',
							'mapping' => [
								'type' => 'text',
								'analyzer' => $analyzer,
							],
						],
					],
				],
				'properties' => [
					$primary_key => ['type' => 'keyword'],
				]
			]
		];
		$url = self::index_url($index_name);
		$json = json_encode($index_definition);
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_CUSTOMREQUEST => 'PUT',
			CURLOPT_POSTFIELDS => $json,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
		]);
		$response = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		if ($code >= 200 && $code < 300) {
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = "Index [$index_name] created successfully", $S = 'info');
			return true;
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = "Failed to create index [$index_name]: HTTP $code | $error | response_bytes=".(is_string($response) ? strlen($response) : 0), $S = 'warning');
		return false;
	}


	/**
	 * Deletes an OpenSearch index by normalized name.
	 *
	 * The adapter issues a DELETE request against the index endpoint and
	 * maps cURL transport failure to false. HTTP error-code interpretation remains
	 * outside this legacy boundary because existing callers only consume the
	 * transport success boolean.
	 */
    public static function delete_index(string $index_name): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		$index_name=strtolower($index_name);
        $url=self::index_url($index_name);
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response=curl_exec($ch);
        curl_close($ch);
        if($response===false){
            return false;
        }
        return true;
	}

	/**
	 * Updates an indexed OpenSearch document identified by primary key.
	 *
	 * Dataphyre primary keys are not used as OpenSearch document IDs here,
	 * so the adapter first performs a term lookup, then patches the first matching
	 * internal _id through _update. Missing documents and cURL failures return
	 * false without mutating upstream state.
	 */
    public static function update(string $index_name, array $values, string $primary_column_name, string $primary_key_value, string $language): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		$language=explode('-', $language)[0];
		$index_name=strtolower($index_name);
        $url=self::index_url($index_name, '_search');
        $query=array(
            'query'=>array(
                'term'=>array(
                    $primary_column_name=>$primary_key_value
                )
            )
        );
        $json_data=json_encode($query);
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $response=curl_exec($ch);
        curl_close($ch);
        if($response===false){
            return false;
        }
        $response_data=json_decode($response, true);
        if(isset($response_data['hits']['hits']) && count($response_data['hits']['hits'])>0){
            $document_id=$response_data['hits']['hits'][0]['_id'];
            $url=self::index_url($index_name, '_doc/'.$document_id.'/_update');
            $update_data=array(
                'doc'=>$values
            );
            $json_data=json_encode($update_data);
            $ch=curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            $response=curl_exec($ch);
            curl_close($ch);
            if($response===false){
                return false;
            }
        }
		else
		{
            return false;
        }
        return true;
    }

	/**
	 * Adds a document to an OpenSearch index.
	 *
	 * The caller's values are merged with the primary key field, empty
	 * field names are filtered out, and the document is posted to _doc. HTTP status
	 * outside 2xx is treated as a failed mutation and logged with response context.
	 */
	public static function add(string $index_name, array $values, string $primary_column_name, string $primary_key_value, string $language): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = null, $S = 'function_call', $A = [$index_name, count($values), $primary_column_name, $language]);
		$language=explode('-', $language)[0];
		$index_name=strtolower($index_name);
		if (empty($primary_column_name)) {
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = "Primary column name must not be empty", $S = 'warning');
			return false;
		}
		$document = array_filter(
			$values + [$primary_column_name => $primary_key_value],
			fn($k) => $k !== '',
			ARRAY_FILTER_USE_KEY
		);
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = 'Indexing document into '.$index_name.' with '.count($document).' fields', $S = 'info');
		$url = self::index_url($index_name, '_doc');
		$json_data = json_encode($document);
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = "HTTP $http_code | response_bytes=".(is_string($response) ? strlen($response) : 0), $S = 'info');
		if ($http_code < 200 || $http_code >= 300) {
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = "Failed to add document: $error", $S = 'warning');
			return false;
		}
		return true;
	}

	/**
	 * Removes an indexed OpenSearch document identified by primary key.
	 *
	 * Deletion mirrors the update lifecycle by resolving the external
	 * primary key to an internal document ID, then deleting the _doc endpoint. A
	 * missing hit is currently treated as a successful no-op.
	 */
	public static function remove(string $index_name, string $primary_column_name, string $primary_key_value) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		$index_name=strtolower($index_name);
        $url=self::index_url($index_name, '_search');
        $query=array(
            'query'=>array(
                'term'=>array(
                    $primary_column_name=>$primary_key_value
                )
            )
        );
        $json_data=json_encode($query);
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $response=curl_exec($ch);
        curl_close($ch);
        if($response===false){
            return false;
        }
        $response_data=json_decode($response, true);
        if(isset($response_data['hits']['hits']) && count($response_data['hits']['hits'])>0){
            $document_id=$response_data['hits']['hits'][0]['_id'];
            $url=self::index_url($index_name, '_doc/'.$document_id);
            $ch=curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $response=curl_exec($ch);
            curl_close($ch);
            if($response===false){
                return false;
            }
        }
        return true;
    }

	/**
	 * Maps Dataphyre language codes to OpenSearch analyzer names.
	 *
	 * Supported language roots receive dedicated analyzers, and every
	 * other tag falls back to standard so index creation and query building remain
	 * deterministic for unknown locales.
	 */
	private static function mapLanguageToAnalyzer(string $lang): string {
		return match (strtolower($lang)) {
			'en' => 'english',
			'fr' => 'french',
			'de' => 'german',
			'es' => 'spanish',
			default => 'standard',
		};
	}

	/**
	 * Builds the bool/match query body used by OpenSearch search.
	 *
	 * Boolean mode controls both clause type and match operator, every
	 * search-data field becomes a language-aware fuzzy match clause, and short
	 * terms disable fuzzy expansion to reduce low-signal matches.
	 */
    private static function buildElasticsearchQuery(array $search_data, bool|string $boolean_mode, string $language, int $max_results){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		$language=explode('-', $language)[0];
		$operator=match(true){
			is_bool($boolean_mode)=>($boolean_mode ? 'and' : 'or'),
			default=>in_array(strtolower((string)$boolean_mode), ['and', 'or'], true) ? strtolower((string)$boolean_mode) : 'or',
		};
        $query=array(
            'size'=>$max_results,
            'query'=>array(
                'bool'=>array()
            )
        );
        $clauses=$operator==='and' ? 'must':'should';
        foreach($search_data as $key=>$value){
            $query['query']['bool'][$clauses][]=array(
				'match'=>array(
					$key=>array(
						'query'=>$value,
						'operator'=>$operator,
						'analyzer'=> self::mapLanguageToAnalyzer($language),
						'fuzziness' => (strlen($value) >= 4 ? 'AUTO' : 0),
						'prefix_length' => 1,
						'max_expansions' => 50
					)
				)
            );
        }
        return $query;
    }

	/**
	 * Converts OpenSearch hits into Dataphyre fulltext match results.
	 *
	 * Only hits whose score meets the requested threshold are surfaced,
	 * and each row keeps the upstream engine-agnostic shape of
	 * [primary-key-value => score].
	 */
    private static function processElasticsearchResults(array $response_data, string $primary_column_name, float $threshold){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
        $results=array();
        if(isset($response_data['hits']['hits'])){
            foreach($response_data['hits']['hits'] as $hit){
                if($hit['_score']>=$threshold){
                    $results[]=array($hit['_source'][$primary_column_name]=>$hit['_score']);
                }
            }
        }
        return $results;
    }

}
