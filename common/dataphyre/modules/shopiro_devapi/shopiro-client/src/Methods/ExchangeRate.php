<?php
namespace Shopiro;

class ExchangeRate {
    
    private $shopiroClient;

    public function __construct(ShopiroClient $shopiroClient) {
        $this->shopiroClient = $shopiroClient;
    }
	
    public function get() {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'exchange_rates']);
		return $response;
    }
	
}