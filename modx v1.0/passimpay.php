<?php
#place to /assets/components/minishop2/payment/
define('MODX_API_MODE', true);
require dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/index.php';
$modx->getService('error','error.modError');
$modx->setLogLevel(modX::LOG_LEVEL_ERROR);
$modx->setLogTarget('FILE');
$miniShop2 = $modx->getService('minishop2');
$miniShop2->loadCustomClasses('payment');
if (!class_exists('msPassimpay')) {exit('Error: could not load payment class "msPassimpay".');}
$handler = new msPassimpay($modx->newObject('msOrder'));
$hash = $_POST['hash'];
$data = [
	'platform_id' => (int) $_POST['platform_id'], // Platform ID
	'payment_id' => (int) $_POST['payment_id'], // currency ID
	'order_id' => (int) $_POST['order_id'], // Payment ID of your platform
	'amount' => $_POST['amount'], // transaction amount
	'txhash' => $_POST['txhash'], // transaction ID in the cryptocurrency network
	'address_from' => $_POST['address_from'], // sender address
	'address_to' => $_POST['address_to'], // recipient address
	'fee' => $_POST['fee'], // network fee
];
if (isset($_POST['confirmations'])) $data['confirmations'] = $_POST['confirmations']; // number of network confirmations (Bitcoin, Litecoin, Dogecoin, Bitcoin Cash)
$payload = http_build_query($data);
if (!isset($hash) || hash_hmac('sha256', $payload, trim($modx->getOption('mspa_secret_key', null, '', true))) != $hash)
	$modx->log(modX::LOG_LEVEL_ERROR, '[miniShop2:Passimpay] Hash broken for order '.$_REQUEST['order_id']);

if (!empty($_REQUEST['order_id'])) {
	if ($order = $modx->getObject('msOrder', $_REQUEST['order_id'])) {
		$props = $order->get('properties');
		if( $props['passimpay']['amount'] > $_REQUEST['amount'])
			$modx->log( modX::LOG_LEVEL_ERROR, '[miniShop2:Passimpay] Payed only part of order #' .$_REQUEST['order_id']. ': '. $_REQUEST['amount'] .' '. $props['passimpay']['paysys']['currency'] );
        else $miniShop2->changeOrderStatus( $order->get('id'), trim($modx->getOption('mspa_order_status', null, '', true)) );
	}
	else $modx->log(modX::LOG_LEVEL_ERROR, '[miniShop2:Passimpay] Could not retrieve order with id '.$_REQUEST['order_id']);
}
