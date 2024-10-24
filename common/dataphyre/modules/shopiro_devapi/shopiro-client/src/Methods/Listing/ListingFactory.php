<?php
namespace Shopiro\Listing;

class ListingFactory {

    public static function create(object $listingInstance, array $data) {
        switch ($data['type']) {
            case 'marketplace_low_volume':
                return new \Shopiro\Listing\MarketplaceLowVolumeListingObject($data, $listingInstance);
            case 'marketplace_high_volume':
				return new \Shopiro\Listing\MarketplaceHighVolumeListingObject($data, $listingInstance);
            case 'marketplace_food':
				return new \Shopiro\Listing\MarketplaceFoodListingObject($data, $listingInstance);
            case 'wholesale':
				return new \Shopiro\Listing\WholesaleListingObject($data, $listingInstance);
            case 'job':
				return new \Shopiro\Listing\JobListingObject($data, $listingInstance);
            case 'vehicle_sale':
				return new \Shopiro\Listing\VehicleSaleListingObject($data, $listingInstance);
            case 'vehicle_rental':
				return new \Shopiro\Listing\VehicleRentalListingObject($data, $listingInstance);
            case 'realestate_sale':
				return new \Shopiro\Listing\RealEstateSaleListingObject($data, $listingInstance);
            case 'realestate_rental':
				return new \Shopiro\Listing\RealEstateRentalListingObject($data, $listingInstance);
            case 'realestate_rental':
				return new \Shopiro\Listing\RealEstateRentalListingObject($data, $listingInstance);
            case 'grocery':
				return new \Shopiro\Listing\GroceryListingObject($data, $listingInstance);
            default:
			throw new \Exception("Unknown listing type {$data['type']}");
        }
    }
	
}