<?php
/*
  Plugin Name: LykkePay payment gateway
  Description: Accept Bitcoin, Ethereum and other Crypto as a payment with LykkePay payment gateway
  Version: 1.0.0
 */
require_once 'wc-lykkepay.php';

function cronstarter_deactivate() {
	$timestamp = wp_next_scheduled ('lykkecronjob');
	wp_unschedule_event ($timestamp, 'lykkecronjob');
}
register_deactivation_hook (__FILE__, 'cronstarter_deactivate');

function cronstarter_activation($args) {
	if( !wp_next_scheduled( 'lykkecronjob' ) ) {
		wp_schedule_event( time(), 'everyminute', 'lykkecronjob', $args );
	}
}
function lykkeUpdateStatus($args) {
	$index = strrpos($args, ';');
	$pay = new WC_LYKKEPAY();
	$order_id = substr($args, 0, $index);
	$invoiceId = substr($args, $index + 1 );
	$args = array(
		'InvoiceId' => $invoiceId
	);

	$pkeyid = openssl_pkey_get_private($pay->signature);
	openssl_sign($pay->apikey.http_build_query($args), $signature, $pkeyid, OPENSSL_ALGO_SHA256);
	$sign = base64_encode($signature);

	$lykkepayCurl = curl_init();
	$headers = [
		'Lykke-Merchant-Id: '.$pay->merchantId,
		'Lykke-Merchant-Sign: '.$sign
	];
	if ($pay->testmode == 'yes') {
		$action_adr = $pay->testurl;
	} else {
		$action_adr = $pay->liveurl;
	}
	$action_adr .= 'Status';
	curl_setopt_array($lykkepayCurl, array(
		CURLOPT_URL => $action_adr,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_POSTFIELDS => http_build_query($args)
	));

	$response = curl_exec($lykkepayCurl);
	curl_close($lykkepayCurl);
	$response = json_decode($response, true);

	$errorCode = $response['errorCode'];
	if ($errorCode == 0) {
		if ($response['status'] == 'Paid') {
			$order = new WC_Order( $order_id );
			$order->update_status( 'completed', __( 'Платеж успешно оплачен', 'woocommerce' ) );
			$order->payment_complete();
			cronstarter_deactivate();
		}
	}
}

add_action ('lykkecronjob', 'lykkeUpdateStatus');

function cron_add_minute( $schedules ) {
	// Adds once every minute to the existing schedules.
	$schedules['everyminute'] = array(
		'interval' => 60,
		'display' => __( 'Once Every Minute' )
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'cron_add_minute' );