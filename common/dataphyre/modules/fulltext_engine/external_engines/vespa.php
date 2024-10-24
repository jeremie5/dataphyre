<?php
/*************************************************************************
*  © 2022 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

namespace dataphyre\fulltext_engine;

class vespa {
    
    private $vespa_endpoint = 'http://localhost:8080'; // Replace with your Vespa endpoint

    public function find($application_name, $search_data, $primary_column_name, $boolean_mode, $language, $max_results, $threshold) {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        $yql_query=$this->buildVespaQuery($search_data, $boolean_mode, $language, $max_results);
        $url=$this->vespa_endpoint.'/search/?yql='.urlencode($yql_query);
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response=curl_exec($ch);
        curl_close($ch);
        if($response===false) {
            // Handle error
            return array();
        }
        $response_data=json_decode($response, true);
        return $this->processVespaResults($response_data, $primary_column_name, $threshold);
    }
    
    public function add($application_name, $document_type, $document_id, $fields) {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        $url=$this->vespa_endpoint.'/document/v1/'.$application_name.'/'.$document_type.'/docid/'.$document_id;
        $json_data=json_encode(['fields'=>$fields]);
        $ch=curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $response=curl_exec($ch);
        curl_close($ch);
        if($response===false) {
            // Handle error
            return false;
        }
        return true;
    }
	
	public function update($application_name, $document_type, $document_id, $fields) {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$url = $this->vespa_endpoint . '/document/v1/' . $application_name . '/' . $document_type . '/docid/' . $document_id;
		$json_data = json_encode(['fields' => $fields]);
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		$response = curl_exec($ch);
		curl_close($ch);
		if ($response === false) {
			// Handle error
			return false;
		}
		return true;
	}
	
	public function remove($application_name, $document_type, $document_id) {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$url = $this->vespa_endpoint . '/document/v1/' . $application_name . '/' . $document_type . '/docid/' . $document_id;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$response = curl_exec($ch);
		curl_close($ch);
		if ($response === false) {
			// Handle error
			return false;
		}
		return true;
	}

	private function buildVespaQuery($search_data, $boolean_mode, $language, $max_results) {
		// Start constructing the YQL query
		$yql = "select * from sources * where ";
		$conditions = [];
		// Loop through the search data to build conditions
		foreach ($search_data as $field => $value) {
			// For a basic text search, we use the 'contains' operator
			// Note: Vespa's full-text search capabilities and operators might require adjusting the query
			// to match the configured index and search definitions for the field in your application schema
			$value = addslashes($value); // Escape single quotes in the search term
			$conditions[] = "$field contains '" . $value . "'";
		}
		// Combine conditions based on the specified boolean mode
		$yql .= implode($boolean_mode === 'AND' ? ' and ' : ' or ', $conditions);
		// Append limit to the query to control the maximum number of results
		$yql .= " limit $max_results;";
		return $yql;
	}

    private function processVespaResults($response_data, $primary_column_name, $threshold) {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        $results=[];
        if(isset($response_data['root']['children'])) {
            foreach($response_data['root']['children'] as $child) {
                $fields=$child['fields'];
                $score=$child['relevance'];
                if($score>=$threshold) {
                    $results[]=array($fields[$primary_column_name]=>$score);
                }
            }
        }
        return $results;
    }
}