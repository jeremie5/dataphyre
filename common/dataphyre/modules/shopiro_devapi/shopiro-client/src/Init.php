<?php
namespace Shopiro;

if (!class_exists(\Composer\Autoload\ClassLoader::class)) {
	
	require(__DIR__."/ShopiroClient.php");
	require(__DIR__."/HttpClient.php");

	require(__DIR__."/Methods/Listing/Listing.php");
	require(__DIR__."/Methods/Listing/BaseListingObject.php");
	require(__DIR__."/Methods/Listing/ListingFactory.php");
	require(__DIR__."/Methods/Listing/GroceryListingObject.php");
	require(__DIR__."/Methods/Listing/JobListingObject.php");
	require(__DIR__."/Methods/Listing/MarketplaceFoodListingObject.php");
	require(__DIR__."/Methods/Listing/MarketplaceHighVolumeListingObject.php");
	require(__DIR__."/Methods/Listing/MarketplaceLowVolumeListingObject.php");
	require(__DIR__."/Methods/Listing/RealEstateRentalListingObject.php");
	require(__DIR__."/Methods/Listing/RealEstateSaleListingObject.php");
	require(__DIR__."/Methods/Listing/ServiceListingObject.php");
	require(__DIR__."/Methods/Listing/VehicleRentalListingObject.php");
	require(__DIR__."/Methods/Listing/VehicleSaleListingObject.php");
	require(__DIR__."/Methods/Listing/WholesaleListingObject.php");
	
	require(__DIR__."/Methods/ListingCategory.php");
	require(__DIR__."/Methods/ListingReview.php");
	require(__DIR__."/Methods/ListingSearch.php");
	
	require(__DIR__."/Methods/PromotionCoupon/PromotionCoupon.php");
	require(__DIR__."/Methods/PromotionCoupon/BasePromotionCouponObject.php");
	require(__DIR__."/Methods/PromotionCoupon/PromotionCouponFactory.php");
	require(__DIR__."/Methods/PromotionCoupon/PromotionCouponObject.php");

	require(__DIR__."/Methods/PromotionDiscount/PromotionDiscount.php");
	require(__DIR__."/Methods/PromotionDiscount/BasePromotionDiscountObject.php");
	require(__DIR__."/Methods/PromotionDiscount/PromotionDiscountFactory.php");
	require(__DIR__."/Methods/PromotionDiscount/PromotionDiscountObject.php");	
	
	require(__DIR__."/Methods/PromotionEvent/PromotionEvent.php");
	require(__DIR__."/Methods/PromotionEvent/BasePromotionEventObject.php");
	require(__DIR__."/Methods/PromotionEvent/PromotionEventFactory.php");
	require(__DIR__."/Methods/PromotionEvent/PromotionEventObject.php");	

	require(__DIR__."/Methods/User/PersonalBalance.php");
	require(__DIR__."/Methods/User/Affiliate.php");

	require(__DIR__."/Methods/Address/Address.php");
	require(__DIR__."/Methods/Address/AddressFactory.php");
	require(__DIR__."/Methods/Address/BaseAddressObject.php");
	require(__DIR__."/Methods/Address/AddressObject.php");
	
	require(__DIR__."/Methods/Warehouse/Warehouse.php");
	require(__DIR__."/Methods/Warehouse/WarehouseObject.php");
	require(__DIR__."/Methods/Warehouse/WarehouseFactory.php");
	
	require(__DIR__."/Methods/WarehouseLocation/WarehouseLocation.php");
	require(__DIR__."/Methods/WarehouseLocation/WarehouseLocationObject.php");
	require(__DIR__."/Methods/WarehouseLocation/WarehouseLocationFactory.php");
	
	require(__DIR__."/Methods/Store/Agent.php");
	
	require(__DIR__."/Methods/Conversation/Conversation.php");
	
	require(__DIR__."/Methods/Dispute.php");
	require(__DIR__."/Methods/ExchangeRate.php");
	require(__DIR__."/Methods/Graphing.php");
	require(__DIR__."/Methods/HelpArticle.php");
	require(__DIR__."/Methods/Order.php");
	require(__DIR__."/Methods/SellerReview.php");
	require(__DIR__."/Methods/SellerReview.php");
	require(__DIR__."/Methods/ShippingMethod.php");
	require(__DIR__."/Methods/Transaction.php");

	require(__DIR__."/Methods/Webhook.php");
	
}