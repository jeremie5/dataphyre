<?php
namespace Shopiro\Warehouse\WarehouseLocation;

class WarehouseLocationFactory {

    public static function create(object $warehouseLocationInstance, array $data) {
		return new \Shopiro\Warehouse\WarehouseLocationObject($data, $warehouseLocationInstance);
    }
	
}