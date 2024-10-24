<?php
namespace Shopiro\Listing;

class BaseListingObject {
	
    public $data;
	
    private $listingInstance;

    public function __construct(array $data, object $listingInstance) {
        $this->data = $data;
		$this->listingInstance=$listingInstance;
    }

    public function save() {
		return $this->listingInstance->modifySingle($this->data);
    }
	
    public function delete() {
		return $this->listingInstance->deleteSingle($this->data['slid']);
    }
	
}