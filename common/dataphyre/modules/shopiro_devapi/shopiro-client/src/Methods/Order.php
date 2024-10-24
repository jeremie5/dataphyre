<?php
namespace Shopiro;

class Order {
	
    private $shopiroClient;

    public function __construct(ShopiroClient $shopiroClient) {
        $this->shopiroClient = $shopiroClient;
    }
	
    public function getAll(int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'orders'], $payload=["ct" => $count, "of"=>$offset]);
		return $response;
    }
	
	public function get(int $orderId){
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'order'], $payload=["oid" => $orderId]);
		return $response;
	}
	
	public function confirm(int $orderId){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'confirm_order'], $payload=["oid" => $orderId]);
		return $response;
	}
	
	public function cancel(int $orderId, string $reason){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'cancel_order'], $payload=["oid" => $orderId, "rs"=>$reason]);
		return $response;
	}
	
	public function updateTracking(int $orderId, string $tracking_number){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'set_order_tracking_number'], $payload=["oid" => $orderId, "tn"=>$tracking_number]);
		return $response;
	}

}