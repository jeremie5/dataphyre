<?php
namespace Shopiro\PromotionEvent;

class PromotionEventFactory {

    public function create(string $type, array $data) {
        return new PromotionEventObject($data);
    }

}