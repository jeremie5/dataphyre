<?php
namespace Shopiro;

class ShippingMethod {
	
    private $shopiroClient;

    public function __construct(ShopiroClient $shopiroClient) {
        $this->shopiroClient = $shopiroClient;
    }
	
    public function getAll(int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'shipping_methods'], $payload=["ct" => $count, "of"=>$offset]);
		return $response;
    }
	
	public function get(int $shippingMethodId){
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'shipping_method'], $payload=["sid" => $shippingMethodId]);
		return $response;
	}

}