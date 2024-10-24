<?php
namespace Shopiro;

class Transaction {
	
    private $shopiroClient;

    public function __construct(ShopiroClient $shopiroClient) {
        $this->shopiroClient = $shopiroClient;
    }
	
    public static function getAll(int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'transactions'], $payload=["ct" => $count, "of"=>$offset]);
		return $response;
    }
	
	public static function get(int $transactionId){
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'transaction'], $payload=["tid" => $transactionId]);
		return $response;
	}

}