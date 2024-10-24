<?php
namespace Shopiro;

class Webhook {
    
    private $shopiroClient;

    public function __construct(ShopiroClient $shopiroClient) {
        $this->shopiroClient = $shopiroClient;
    }
	
    public static function get() {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'webhook']);
		return $response;
    }
	
    public static function set(string $webhook_url) {
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'webhook'], $payload=["whk" => $webhook_url, "act"=>"set"]);
        return $response;
    }
	
    public static function delete() {
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'webhook'], $payload=["act"=>"delete"]);
        return $response;
    }
	
}