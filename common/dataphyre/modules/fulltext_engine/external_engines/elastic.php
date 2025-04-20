<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */


namespace dataphyre\fulltext_engine;

class elasticsearch {
    
    private static $elasticsearch_url = 'http://192.168.18.22:9200'; // Replace with your Elasticsearch server URL

    public static function find(string $index_name, array $search_data, string $primary_column_name, string $boolean_mode, string $language, int $max_results, float $threshold){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		$language=explode('-', $language)[0];
		$index_name=strtolower($index_name);
        $query=self::buildElasticsearchQuery($search_data, $boolean_mode, $language, $max_results);
        $url=self::$elasticsearch_url.'/'.$index_name.'/_search';
        $json_data=json_encode($query);
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $response=curl_exec($ch);
        curl_close($ch);
        if($response===false){
            // Handle error
            return [];
        }
        $response_data=json_decode($response, true);
        return self::processElasticsearchResults($response_data, $primary_column_name, $threshold);
    }
	
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
				'properties' => [
					$primary_key => ['type' => 'keyword'],
					'title' => ['type' => 'text', 'analyzer' => $analyzer],
					'description' => ['type' => 'text', 'analyzer' => $analyzer],
				]
			]
		];
		$url = self::$elasticsearch_url . '/' . urlencode($index_name);
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

	
    public static function delete_index(string $index_name): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index_name=strtolower($index_name);
        $url=self::$elasticsearch_url.'/'.$index_name;
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response=curl_exec($ch);
        curl_close($ch);
        if($response===false){
            // Handle error
            return false;
        }
        return true;
	}
	
    public static function update(string $index_name, array $values, string $primary_column_name, string $primary_key_value, string $language): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$language=explode('-', $language)[0];
		$index_name=strtolower($index_name);
        // First, search for the document with the given primary_key_value
        $url=self::$elasticsearch_url.'/'.$index_name.'/_search';
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
            // Handle error
            return false;
        }
        $response_data=json_decode($response, true);
        if(isset($response_data['hits']['hits']) && count($response_data['hits']['hits'])>0){
            // If the document is found, update it using its ID
            $document_id=$response_data['hits']['hits'][0]['_id'];
            $url=self::$elasticsearch_url.'/'.$index_name.'/_doc/'.$document_id.'/_update';
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
                // Handle error
                return false;
            }
        }
		else
		{
            // Document not found, return false
            return false;
        }
        return true;
    }
	
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
		$url = self::$elasticsearch_url . '/' . $index_name . '/_doc';
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
	
	public static function remove(string $index_name, string $primary_column_name, string $primary_key_value) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$index_name=strtolower($index_name);
        // First, search for the document with the given primary_key_value
        $url=self::$elasticsearch_url.'/'.$index_name.'/_search';
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
            // Handle error
            return false;
        }
        $response_data=json_decode($response, true);
        if(isset($response_data['hits']['hits']) && count($response_data['hits']['hits'])>0){
            // If the document is found, delete it using its ID
            $document_id=$response_data['hits']['hits'][0]['_id'];
            $url=self::$elasticsearch_url.'/'.$index_name.'/_doc/'.$document_id;
            $ch=curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $response=curl_exec($ch);
            curl_close($ch);
            if($response===false){
                // Handle error
                return false;
            }
        }
        return true;
    }

	private static function mapLanguageToAnalyzer(string $lang): string {
		return match (strtolower($lang)) {
			'en' => 'english',
			'fr' => 'french',
			'de' => 'german',
			'es' => 'spanish',
			default => 'standard',
		};
	}

    private static function buildElasticsearchQuery(array $search_data, string $boolean_mode, string $language, int $max_results){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		$language=explode('-', $language)[0];
        $query=array(
            'size'=>$max_results,
            'query'=>array(
                'bool'=>array()
            )
        );
        $clauses=$boolean_mode==='AND' ? 'must':'should';
        foreach($search_data as $key=>$value){
            $query['query']['bool'][$clauses][]=array(
				'match'=>array(
					$key=>array(
						'query'=>$value,
						'operator'=> in_array(strtolower($boolean_mode), ['and', 'or']) ? strtolower($boolean_mode) : 'or',
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

    private static function processElasticsearchResults(array $response_data, string $primary_column_name, float $threshold){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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