<?php
namespace Shopiro;

class WarehouseLocation {
	
    private $shopiroClient;

    public function __construct(ShopiroClient $shopiroClient) {
        $this->shopiroClient = $shopiroClient;
    }
	
    public static function getAll(int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'warehouse_locations'], $payload=["ct" => $count, "of"=>$offset]);
		return $response;
    }
	
	public function get(string|array $locationid){
		if(is_array($locationid)){
			return $this->getMany($locationid);
		}
		return $this->getSingle($locationid);
	}
	
    public function getSingle(string $locationid) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'warehouse_location'], $payload=["locationid" => $locationid]);
		return \Shopiro\Listing\ListingFactory::create($this, $response);
    }
	
    public function getMany(array $warehouse_location_locations) {
        $result = [];
        foreach ($warehouse_location_locations as $locationid) {
            $this->shopiroClient->createRequest($endpoint=['get', 'warehouse_location'], $payload=["locationid" => $locationid], $queue='q', $callback=function($response) use (&$result, $locationid) {
                $result[$locationid] = \Shopiro\Listing\ListingFactory::create($this, $response);
            });
        }
        return $result;
    }
	
	public function modify(array $warehouse_location){
		if(is_array($warehouse_location)){
			return $this->modifyMany($warehouse_location);
		}
		return $this->modifySingle($warehouse_location);
	}
	
    public function modifySingle(array $warehouse_location) {
		if(!is_array($warehouse_location)){
			throw new \Exception('Bad warehouse_location representation');
		}
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_warehouse_location'], $payload=["data" => json_encode($warehouse_location), "act"=>"modify"]);
        return $response;
    }
	
    public function modifyMany(array $warehouse_locations) {
        $responses = [];
        foreach ($warehouse_locations as $warehouse_location) {
			if(!is_array($warehouse_location)){
				throw new \Exception('Bad warehouse_location representation');
			}
            $this->shopiroClient->createRequest($endpoint=['set', 'edit_warehouse_location'], $payload=["data" => json_encode($warehouse_location), "act"=>"modify"], $queue='q', $callback=function($response) use (&$responses, $warehouse_location) {
                $responses[$warehouse_location['locationid']] = $response;
            });
        }
		$this->shopiroClient->executeQueue($queue);
        return $responses;
    }
	
	public function delete(string|array $warehouse_location){
		if(is_array($warehouse_location)){
			return $this->deleteMany($warehouse_location);
		}
		return $this->deleteSingle($warehouse_location);
	}
	
    public function deleteSingle(string $warehouse_location) {
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_warehouse_location'], $payload=["locationid" => $warehouse_location, "act"=>"delete"]);
        return $response;
    }
	
    public function deleteMany(array $warehouse_locations) {
        $responses = [];
        foreach ($warehouse_locations as $locationid) {
			$this->shopiroClient->createRequest($endpoint=['set', 'warehouse_location'], $payload=["locationid" => $locationid, "act"=>"delete"], $queue='q', $callback=function($response) use (&$responses, $warehouse_location) {
                $responses[$warehouse_location['locationid']] = $response;
            });
        }
		$this->shopiroClient->executeQueue($queue);
        return $responses;
    }
	
}