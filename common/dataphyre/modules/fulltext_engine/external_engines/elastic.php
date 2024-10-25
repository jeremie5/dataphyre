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
    
    private $elasticsearch_url = 'http://localhost:9200'; // Replace with your Elasticsearch server URL

    public function find($index_name, $search_data, $primary_column_name, $boolean_mode, $language, $max_results, $threshold){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        $query=$this->buildElasticsearchQuery($search_data, $boolean_mode, $language);
        $url=$this->elasticsearch_url.'/'.$index_name.'/_search';
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
            return array();
        }
        $response_data=json_decode($response, true);
        return $this->processElasticsearchResults($response_data, $primary_column_name, $threshold);
    }
	
    public function create_index($index_name, $primary_key_column_name){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        $url=$this->elasticsearch_url.'/'.$index_name;
        $settings=array(
            'settings'=>array(
                'index'=>array(
                    'number_of_shards'=>1,
                    'number_of_replicas'=>0
                )
            ),
            'mappings'=>array(
                '_doc'=>array(
                    'properties'=>array(
                        $primary_key_column_name=>array(
                            'type'=>'keyword'
                        )
                    )
                )
            )
        );
        $json_data=json_encode($settings);
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $response=curl_exec($ch);
        curl_close($ch);
        if($response===false){
            // Handle error
            return false;
        }
        return true;
    }
	
    public function delete_index($index_name){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        $url=$this->elasticsearch_url.'/'.$index_name;
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
	
    public function update($index_name, $values, $primary_column_name, $primary_key_value, $language){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        // First, search for the document with the given primary_key_value
        $url=$this->elasticsearch_url.'/'.$index_name.'/_search';
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
            $url=$this->elasticsearch_url.'/'.$index_name.'/_doc/'.$document_id.'/_update';
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
	
	public function add($index_name, $values, $primary_column_name, $primary_key_value, $language){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		// Prepare the document to be indexed
		$document=$values;
		$document[$primary_column_name]=$primary_key_value;
		$url=$this->elasticsearch_url.'/'.$index_name.'/_doc';
		$json_data=json_encode($document);
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
		return true;
	}
	
	public function remove($index_name, $primary_column_name, $primary_key_value){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        // First, search for the document with the given primary_key_value
        $url=$this->elasticsearch_url.'/'.$index_name.'/_search';
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
            $url=$this->elasticsearch_url.'/'.$index_name.'/_doc/'.$document_id;
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

    private function buildElasticsearchQuery($search_data, $boolean_mode, $language){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
                        'operator'=>$boolean_mode,
                        'analyzer'=>$language
                    )
                )
            );
        }
        return $query;
    }

    private function processElasticsearchResults($response_data, $primary_column_name, $threshold){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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