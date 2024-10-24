<?php
namespace Shopiro\Warehouse;

class WarehouseFactory {

    public static function create(object $warehouseInstance, array $data) {
		return new \Shopiro\Warehouse\WarehouseObject($data, $warehouseInstance);
    }
	
}