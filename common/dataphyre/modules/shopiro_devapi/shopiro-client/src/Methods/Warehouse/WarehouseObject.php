<?php
namespace Shopiro\Warehouse;

class WarehouseObject {
	
    public $data;
	
    private $warehouseInstance;

    public function __construct(array $data, object $warehouseInstance) {
        $this->data = $data;
		$this->warehouseInstance=$warehouseInstance;
    }

    public function toJSON() {
        return json_encode((array)$this->data);
    }

    public function toArray() {
        return (array)$this->data;
    }

    public function save() {
		return $this->warehouseInstance->modifySingle($this->data);
    }
	
    public function delete() {
		return $this->warehouseInstance->deleteSingle($this->data['warehouseid']);
    }

    public function setName(string $value) {
        $this->data['name'] = $value;
    }
	
    public function setAddressLine1(string $value) {
        $this->data['address']['address_line1'] = $value;
    }
	
    public function setAddressLine2(string $value) {
        $this->data['address']['address_line2'] = $value;
    }
	
    public function setCity(string $value) {
        $this->data['address']['city'] = $value;
    }
	
    public function setPostalCode(string $value) {
        $this->data['address']['postal_code'] = $value;
    }
	
    public function setSubdivision(string $value) {
        $this->data['address']['subdivision'] = $value;
    }
	
    public function setCountry(string $value) {
        $this->data['address']['country'] = $value;
    }
	
    public function setPosition(float $latitude, double $longitude) {
        $this->data['latitude'] = $latitude;
        $this->data['longitude'] = $longitude;
    }
	
}