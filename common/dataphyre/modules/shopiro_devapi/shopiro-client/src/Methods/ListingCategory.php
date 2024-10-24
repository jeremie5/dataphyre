<?php
namespace Shopiro;

class ListingCategory {
	
    private $shopiroClient;

    public function __construct(ShopiroClient $shopiroClient) {
        $this->shopiroClient = $shopiroClient;
    }
	
    public function getAll(string $platformSegment, int $parentCategory, int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'listing_categories'], $payload=["ct" => $count, "of"=>$offset, "prt" => $parentCategory, "sgt"=>$platformSegment]);
		return $response;
    }
	
	public function get(int $categoryId){
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'listing_category'], $payload=["cty" => $categoryId]);
		return $response;
	}
	
}