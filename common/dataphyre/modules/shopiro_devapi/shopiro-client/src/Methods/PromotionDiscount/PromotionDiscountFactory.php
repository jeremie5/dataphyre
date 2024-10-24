<?php
namespace Shopiro\PromotionDiscount;

class PromotionDiscountFactory {

    public function create(string $type, array $data) {
        return new PromotionDiscountObject($data);
    }

}