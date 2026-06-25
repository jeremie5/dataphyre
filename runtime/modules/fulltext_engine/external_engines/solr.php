<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\fulltext_engine;

/**
 * Solr engine slot backed by the current Elasticsearch-compatible adapter.
 *
 * Despite the filename, this implementation speaks the same JSON HTTP
 * protocol as the Elasticsearch/OpenSearch adapters. The class documents that
 * operational boundary explicitly so callers do not infer native Solr request
 * semantics from the module path alone.
 */
class elasticsearch {

	private const DEFAULT_URL='http://127.0.0.1:9200';

	/**
	 * Resolves the configured compatible-search endpoint root.
	 *
	 * This legacy slot reads the elastic/elasticsearch config aliases
	 * shared by the adapter family, trims blank values, and falls back to the local
	 * Elasticsearch-style default URL when no endpoint is configured.
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
	 * Builds an endpoint URL for an index and optional adapter API suffix.
	 *
	 * Index names are lowercased for storage consistency, suffixes are
	 * slash-normalized, and the returned URL targets Elasticsearch-compatible
	 * routes such as _search, _doc, and _update.
	 */
	private static function index_url(string $index_name, string $suffix=''): string {
		$index_name=strtolower($index_name);
		$suffix=ltrim($suffix, '/');
		return self::base_url().'/'.$index_name.($suffix!=='' ? '/'.$suffix : '');
	}

	/**
	 * Searches the compatible index and returns Dataphyre score matches.
	 *
	 * Dataphyre field search input is converted into a bool/match JSON
	 * query, submitted through cURL, and decoded hits are reduced to
	 * [primary-key-value => score] rows that pass the requested score threshold.
	 */
    public static function find(string $index_name, array $search_data, string $primary_column_name, bool|string $boolean_mode, string $language, int $max_results, float $threshold){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
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
	 * Creates a compatible-search index with Dataphyre's text mapping defaults.
	 *
	 * The primary key is mapped as keyword, dynamic string fields become
	 * analyzed text fields, and the chosen analyzer comes from the first segment of
	 * the supplied language tag before the PUT request is sent.
	 */
	public static function create_index(string $index_name, string $primary_key, string $language): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = null, $S = 'function_call', $A = func_get_args());
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
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = "Failed to create index [$index_name]: HTTP $code | $error | $response", $S = 'warning');
		return false;
	}


	/**
	 * Deletes a compatible-search index by normalized name.
	 *
	 * The adapter sends DELETE to the index endpoint and returns false
	 * only for cURL transport failure. Existing callers do not inspect HTTP status
	 * in this path, so remote error semantics remain outside the local contract.
	 */
    public static function delete_index(string $index_name): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
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
	 * Updates a document resolved through Dataphyre's primary key field.
	 *
	 * This adapter does not assume the primary key is the remote document
	 * ID. It first performs a term lookup, then patches the first matching internal
	 * _id with the provided values. Missing documents and transport failures return
	 * false.
	 */
    public static function update(string $index_name, array $values, string $primary_column_name, string $primary_key_value, string $language): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
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
	 * Adds a document to the compatible-search index.
	 *
	 * The primary key field is merged into the indexed document, empty
	 * field names are discarded, and the document is posted to _doc. HTTP status
	 * outside 2xx is treated as a failed mutation and logged for diagnostics.
	 */
	public static function add(string $index_name, array $values, string $primary_column_name, string $primary_key_value, string $language): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = null, $S = 'function_call', $A = func_get_args());
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
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = 'Indexing document: '.json_encode($document), $S = 'info');
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
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = "HTTP $http_code | Response: $response", $S = 'info');
		if ($http_code < 200 || $http_code >= 300) {
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = "Failed to add document: $error", $S = 'warning');
			return false;
		}
		return true;
	}

	/**
	 * Removes a document resolved through Dataphyre's primary key field.
	 *
	 * Removal uses the same lookup-first lifecycle as updates, deleting
	 * the first matching remote _doc when a hit exists. No matching document is
	 * treated as a successful no-op by this legacy adapter contract.
	 */
	public static function remove(string $index_name, string $primary_column_name, string $primary_key_value) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args());
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
	 * Maps Dataphyre language tags to compatible-search analyzer names.
	 *
	 * Only supported language roots receive specialized analyzers, and
	 * unknown tags fall back to standard so query construction and index creation
	 * remain predictable.
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
	 * Builds the bool/match JSON query used by this adapter.
	 *
	 * Boolean mode selects must versus should clauses and the match
	 * operator, each search-data field becomes a language-aware fuzzy match, and
	 * short terms disable fuzziness to avoid excessive expansion.
	 */
    private static function buildElasticsearchQuery(array $search_data, bool|string $boolean_mode, string $language, int $max_results){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
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
	 * Converts compatible-search hits into Dataphyre fulltext match rows.
	 *
	 * Only hits meeting the score threshold are exposed, and each result
	 * preserves the upstream engine-agnostic shape of [primary-key-value => score].
	 */
    private static function processElasticsearchResults(array $response_data, string $primary_column_name, float $threshold){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
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
