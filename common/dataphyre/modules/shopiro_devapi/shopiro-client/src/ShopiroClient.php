<?php
namespace Shopiro;

class ShopiroClient{
	
    private $application_id;
    private $application_private_key;
	
	public $max_network_retries;
	
	private $requestQueues = [];
	
	private $httpClient;
	
	private $operationInstances = [];
	
	private $last_response_raw;
	
	const MAX_CHAIN_LENGTH=64;
	
	const API_BASE_URL="https://shopiro.ca/api/v1";

    public function __construct(int $application_id, #[\SensitiveParameter] string $application_private_key){
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            throw new \Exception("ShopiroClient requires PHP version 8.0 or higher.");
        }
        $this->application_id = $application_id;
        $this->application_private_key = $application_private_key;
		$this->max_network_retries=3;
		$this->httpClient = new HttpClient();
    }
	
	public function __get($name) {
		if (!isset($this->operationInstances[$name])) {
			$className = "\\Shopiro\\" . ucfirst($name);
			if (!class_exists($className)) {
				throw new \Exception("Class $className does not exist.");
			}
			$reflector = new \ReflectionClass($className);
			$constructor = $reflector->getConstructor();
			if ($constructor && $constructor->getNumberOfRequiredParameters() > 1) {
				throw new \Exception("Constructor for $className requires more than one argument.");
			} else {
				$this->operationInstances[$name] = new $className($this);
			}
		}
		return $this->operationInstances[$name];
	}
	
    public function createRequest(array $endpoint, array $payload, string|null|bool $queue=null, callable|null $callback=null){
        if ($queue === null || $queue === false) {
            return $this->executeRequest($endpoint, $payload, $callback)[0];
        }
		else
		{
            $this->requestQueues[$queue][] = function() use ($endpoint, $payload, $callback) {
                return $this->executeRequest($endpoint, $payload, $callback);
            };
        }
    }
	
    public function getLastResponse(){
        return $this->last_response_raw;
    }
	
	private static function processResponse(string $requestId, array $response) {
		if (isset($response['success'])) {
			if(!is_array($response['success']))$response['success']=[];
			$response['success']['status']='success';
			return $response['success'];
		} elseif (isset($response['failed'])) {
			if (is_array($response['failed'])) {
				$response['failed']['status']='failed';
			}
			return array_filter([
				"status"=>"failed", 
				"error_code"=>$response['failed'], 
				"field_errors"=>$response['field_errors'],
				"errors"=>$response['errors'],
				"requestid"=>$requestId
			]);
		}
		return null;
	}
	
    public function executeQueue(string $queueName){
        if(!isset($this->requestQueues[$queueName])){
            return false;
        }
        $results = [];
        foreach($this->requestQueues[$queueName] as $requestFunction){
            $results[] = $requestFunction();
        }
        return $results;
    }

	private function executeRequest(array $endpoint, array $payload, callable|null $callback=null){
		if(empty($endpoint)){
			throw new \Exception('Cannot send API request, no endpoint');
		}
		$requests[]=[
			"endpoint"=>$endpoint,
			"payload"=>$payload,
			"callback"=>$callback
		];
		return $this->executeRequests($requests);
	}

	private function executeRequests(array $requests){
		if(count($requests)>self::MAX_CHAIN_LENGTH){
			throw new \Exception('Cannot send more than '.self::MAX_CHAIN_LENGTH.' API requests at once');
		}
		foreach($requests as $request){
			if (!is_array($request)) {
				throw new \Exception('Invalid request format. Expected an array.');
			}
			$chain[implode("/", $request["endpoint"])]=[
				"post"=>$request["payload"]
			];
		}
		$request['payload']['chain']=json_encode($chain);
        $results = [];
		$url = self::API_BASE_URL.'/'.$this->application_id.'/'.$this->application_private_key.'/chained';
		//$url = self::API_BASE_URL.'/'.$this->application_id.'/chained';
		//$headers = ['X-Custom-Pvk' => $this->application_private_key];
		$headers=[];
		$response = $this->httpClient->sendRequest($url, 'POST', $request['payload'], $headers, $this->max_network_retries);
		if($response === false){
			throw new \Exception('CURL request failed after ' . $this->max_network_retries . ' attempts');
		}
		if($response === null){
			throw new \Exception('CURL request failed');
		}
		$decodedResponse = json_decode($response, true);
		if(null === $decodedResponse){
			throw new \Exception('Invalid JSON response: '.$response);
		}
		if($decodedResponse === null){
			throw new \Exception('Invalid decoded response: '.$response);
		}
		if(isset($decodedResponse['failed'])){
			throw new \Exception('API request failed: '.$decodedResponse['failed']);
		}
		$results = [];
		foreach($decodedResponse as $endpoint => $reqDetails){
			if(is_array($reqDetails)){
				foreach($reqDetails as $reqId => $reqData){
					if(isset($reqData['chain_key']) && isset($requests[$reqData['chain_key']])){
						$chainKey = $reqData['chain_key'];
						$this->last_response_raw=$reqData;
						if(is_callable($callback = $requests[$chainKey]['callback'])){
							$results[] = $callback(self::processResponse($reqId, $reqData));
						}
						else
						{
							$results[] = self::processResponse($reqId, $reqData);
						}
					}
				}
			}
		}
		return $results;
	}
		
}