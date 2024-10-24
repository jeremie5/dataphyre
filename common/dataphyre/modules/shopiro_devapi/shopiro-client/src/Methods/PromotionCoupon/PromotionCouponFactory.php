<?php
namespace Shopiro\PromotionCoupon;

class PromotionCouponFactory {

    public function create(string $type, array $data) {
        return new PromotionCouponObject($data);
    }

}