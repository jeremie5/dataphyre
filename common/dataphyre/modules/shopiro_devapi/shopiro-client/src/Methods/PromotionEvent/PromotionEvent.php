<?php
namespace Shopiro;

class PromotionEvent {
	
    private $shopiroClient;
	private $promotionEventFactory;

    public function __construct(ShopiroClient $shopiroClient, PromotionEventFactory $promotionEventFactory) {
        $this->shopiroClient = $shopiroClient;
        $this->promotionEventFactory = $promotionEventFactory;
    }
	
    public function create(string $type, array $data) {
		$promotionCouponObject = $this->promotionEventFactory->create($type, $data);
		return $promotionCouponObject;
    }
	
    public function getAll(int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'promotion_event'], $payload=["ct" => $count, "of"=>$offset]);
		return $response;
    }
	
	public function get(int $eventId){
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'promotion_event'], $payload=["cid" => $eventId]);
		return $response;
	}
	
	public function modify(array $event){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_promotion_event'], $payload=["act"=>"edit", "data" => $event]);
		return $response;
	}
 
 	public function delete(int $event){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_promotion_event'], $payload=["act"=>"remove", "data" => $event]);
		return $response;
	}
	
}