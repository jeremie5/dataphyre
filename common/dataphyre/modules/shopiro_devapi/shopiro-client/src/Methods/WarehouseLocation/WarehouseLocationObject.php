<?php
namespace Shopiro\WarehouseLocation;

class WarehouseLocationObject {
	
    public $data;
	
    private $warehouseLocationInstance;

    public function __construct(array $data, object $warehouseLocationInstance) {
        $this->data = $data;
		$this->warehouseLocationInstance=$warehouseLocationInstance;
    }
	
    public function toJSON() {
        return json_encode((array)$this->data);
    }

    public function toArray() {
        return (array)$this->data;
    }

    public function save() {
		return $this->warehouseLocationInstance->modifySingle($this->data);
    }
	
    public function delete() {
		return $this->warehouseLocationInstance->deleteSingle($this->data['locationid']);
    }
	
}