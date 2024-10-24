<?php
namespace Shopiro;

class Warehouse {
	
    private $shopiroClient;

    public function __construct(ShopiroClient $shopiroClient) {
        $this->shopiroClient = $shopiroClient;
    }
	
    public static function getAll(int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'warehouses'], $payload=["ct" => $count, "of"=>$offset]);
		return $response;
    }
	
	public function get(string|array $warehouseid){
		if(is_array($warehouseid)){
			return $this->getMany($warehouseid);
		}
		return $this->getSingle($warehouseid);
	}
	
    public function getSingle(string $warehouseid) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'warehouse'], $payload=["warehouseid" => $warehouseid]);
		return \Shopiro\Listing\ListingFactory::create($this, $response);
    }
	
    public function getMany(array $warehouses) {
        $result = [];
        foreach ($warehouses as $warehouseid) {
            $this->shopiroClient->createRequest($endpoint=['get', 'warehouse'], $payload=["warehouseid" => $warehouseid], $queue='q', $callback=function($response) use (&$result, $warehouseid) {
                $result[$warehouseid] = \Shopiro\Listing\ListingFactory::create($this, $response);
            });
        }
        return $result;
    }
	
	public function modify(array $warehouse){
		if(is_array($warehouse)){
			return $this->modifyMany($warehouse);
		}
		return $this->modifySingle($warehouse);
	}
	
    public function modifySingle(array $warehouse) {
		if(!is_array($warehouse)){
			throw new \Exception('Bad warehouse representation');
		}
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_warehouse'], $payload=["data" => json_encode($warehouse), "act"=>"modify"]);
        return $response;
    }
	
    public function modifyMany(array $warehouses) {
        $responses = [];
        foreach ($warehouses as $warehouse) {
			if(!is_array($warehouse)){
				throw new \Exception('Bad warehouse representation');
			}
            $this->shopiroClient->createRequest($endpoint=['set', 'edit_warehouse'], $payload=["data" => json_encode($warehouse), "act"=>"modify"], $queue='q', $callback=function($response) use (&$responses, $warehouse) {
                $responses[$warehouse['warehouseid']] = $response;
            });
        }
		$this->shopiroClient->executeQueue($queue);
        return $responses;
    }
	
	public function delete(string|array $warehouse){
		if(is_array($warehouse)){
			return $this->deleteMany($warehouse);
		}
		return $this->deleteSingle($warehouse);
	}
	
    public function deleteSingle(string $warehouse) {
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_warehouse'], $payload=["warehouseid" => $warehouse, "act"=>"delete"]);
        return $response;
    }
	
    public function deleteMany(array $warehouses) {
        $responses = [];
        foreach ($warehouses as $warehouseid) {
			$this->shopiroClient->createRequest($endpoint=['set', 'warehouse'], $payload=["warehouseid" => $warehouseid, "act"=>"delete"], $queue='q', $callback=function($response) use (&$responses, $warehouse) {
                $responses[$warehouse['warehouseid']] = $response;
            });
        }
		$this->shopiroClient->executeQueue($queue);
        return $responses;
    }
	
}