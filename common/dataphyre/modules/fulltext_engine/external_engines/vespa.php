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

class vespa {
    
    private static $vespa_endpoint = 'http://192.168.18.22:8080'; // Replace with your Vespa endpoint
	private static string $vespa_config_endpoint = 'http://192.168.18.22:19071';

	public static function delete_index(string $application_name): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = null, $S = 'function_call', $A = func_get_args());
		// Vespa hardcodes the deployment zone in single-node: dev/default/default
		$url = self::$vespa_config_endpoint . '/application/v2/tenant/default/application/' .
		$application_name . '/environment/dev/region/default/instance/default';
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		// Accept 200 OK and 202 Accepted as success
		return in_array($http_code, [200, 202]);
	}

	public function zip_app_dir(string $app_dir, string $zip_path): bool {
		if (!is_dir($app_dir)) return false;
		$zip_cmd = sprintf('cd %s && zip -r %s .', escapeshellarg($app_dir), escapeshellarg($zip_path));
		$output = shell_exec($zip_cmd);
		return file_exists($zip_path);
	}

	public static function create_index(string $application_name, string $primary_key): bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = null, $S = 'function_call', $A = func_get_args());
		$app_dir = ROOTPATH['dataphyre']."cache/fulltext_engine/vespa/$application_name";
		$schema_dir = "$app_dir/schemas";
		$zip_path = "$app_dir.zip";
		$schema_name = $application_name;
		$schema_def = <<<SD
	schema $schema_name {
		document $schema_name {
			field $primary_key type string {
				indexing: summary | attribute
			}
		}
		document-summary default {
			summary $primary_key type string
		}
	}
	SD;
		$services_xml = <<<XML
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
		\dataphyre\core::file_put_contents_forced("$schema_dir/$schema_name.sd", $schema_def);
		\dataphyre\core::file_put_contents_forced("$app_dir/services.xml", $services_xml);
		if (!is_dir($app_dir)) return false;
		$zip_cmd = sprintf('cd %s && zip -r %s .', escapeshellarg($app_dir), escapeshellarg($zip_path));
		shell_exec($zip_cmd);
		if (!file_exists($zip_path)) return false;
		$prepare_url = self::$vespa_config_endpoint . '/application/v2/tenant/default/prepare';
		$max_attempts = 10;
		$session_data = null;
		while ($max_attempts-- > 0) {
			$ch = curl_init($prepare_url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($zip_path));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/zip']);
			$response = curl_exec($ch);
			curl_close($ch);
			if ($response !== false) {
				\dataphyre\core::file_put_contents_forced(__DIR__."/vespa_prepare_response_$application_name.json", $response);
			}
			if ($response !== false && ($session_data = json_decode($response, true)) && isset($session_data['session'])) {
				break;
			}
			sleep(3); // wait before retrying
		}
		if (!isset($session_data['session'])) {
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T = "Vespa prepare failed after retries", $S = "fatal");
			return false;
		}
		$activate_url = self::$vespa_config_endpoint . $session_data['session'] . '/active';
		$ch = curl_init($activate_url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_exec($ch);
		curl_close($ch);
		return true;
	}

    public static function find($application_name, $search_data, $primary_column_name, $boolean_mode, $language, $max_results, $threshold) {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
        $yql_query=self::buildVespaQuery($search_data, $boolean_mode, $language, $max_results);
        $url=self::$vespa_endpoint.'/search/?yql='.urlencode($yql_query);
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
    
    public static function add($application_name, $document_type, $document_id, $fields) {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        $url=self::$vespa_endpoint.'/document/v1/'.$application_name.'/'.$document_type.'/docid/'.$document_id;
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
	
	public static function update($application_name, $document_type, $document_id, $fields) {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$url = self::$vespa_endpoint . '/document/v1/' . $application_name . '/' . $document_type . '/docid/' . $document_id;
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
	
	public static function remove($application_name, $document_type, $document_id) {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$url = self::$vespa_endpoint . '/document/v1/' . $application_name . '/' . $document_type . '/docid/' . $document_id;
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

	private static function buildVespaQuery($search_data, $boolean_mode, $language, $max_results) {
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

    private static function processVespaResults($response_data, $primary_column_name, $threshold) {
        tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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