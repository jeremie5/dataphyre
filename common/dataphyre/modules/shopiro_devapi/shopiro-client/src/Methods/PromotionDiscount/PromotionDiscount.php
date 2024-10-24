<?php
namespace Shopiro;

class PromotionDiscount {
	
    private $shopiroClient;
	private $promotionDiscountFactory;

    public function __construct(ShopiroClient $shopiroClient, promotionDiscountFactory $promotionDiscountFactory) {
        $this->shopiroClient = $shopiroClient;
        $this->promotionDiscountFactory = $promotionDiscountFactory;
    }
	
    public function create(string $type, array $data) {
		$promotionDiscountObject = $this->promotionDiscountFactory->create($type, $data);
		return $promotionDiscountObject;
    }
	
    public function getAll(int $count, int $offset) {
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'promotion_discount'], $payload=["ct" => $count, "of"=>$offset]);
		return $response;
    }
	
	public function get(int $discountId){
        $response = $this->shopiroClient->createRequest($endpoint=['get', 'promotion_discount'], $payload=["did" => $discountId]);
		return $response;
	}
	
	public function modify(array $discount){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_promotion_discount'], $payload=["act"=>"edit", "data" => $discount]);
		return $response;
	}
 
 	public function delete(int $discount){
        $response = $this->shopiroClient->createRequest($endpoint=['set', 'edit_promotion_discount'], $payload=["act"=>"remove", "data" => $discount]);
		return $response;
	}
	
}