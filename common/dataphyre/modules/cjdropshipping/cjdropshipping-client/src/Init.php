<?php
namespace CJ;

if (!class_exists(\Composer\Autoload\ClassLoader::class)) {
	require(__DIR__."/CJClient.php");
	require(__DIR__."/HttpClient.php");
	require(__DIR__."/Methods/Balance.php");
	require(__DIR__."/Methods/Category.php");
	require(__DIR__."/Methods/Dispute.php");
	require(__DIR__."/Methods/Logistic.php");
	require(__DIR__."/Methods/Product.php");
	require(__DIR__."/Methods/Setting.php");
	require(__DIR__."/Methods/Shopping.php");
	require(__DIR__."/Methods/Storage.php");
	require(__DIR__."/Methods/Webhook.php");
}