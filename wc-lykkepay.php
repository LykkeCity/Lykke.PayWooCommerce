<?php
/*
  Plugin Name: LykkePay payment gateway
  Description: Accept Bitcoin, Ethereum and other Crypto as a payment with LykkePay payment gateway
  Version: 1.0.0
 */

session_start();
require_once 'include.php';
require_once 'text.php';
require_once 'cron.php';

if (!defined('ABSPATH')) exit;


add_action('plugins_loaded', 'woocommerce_lykkepay', 0);

function woocommerce_lykkepay()
{
    if (!class_exists('WC_Payment_Gateway'))
        return;
    if (class_exists('WC_LYKKEPAY'))
        return;


    class WC_LYKKEPAY extends WC_Payment_Gateway
    {

        function lykke_logger($var)
        {
            if ($var) {
                $date = '>>>> ' . date('Y-m-d H:i:s') . "\n";
                $result = $var;
                if (is_array($var) || is_object($var)) {
                    $result = print_r($var, true);
                }
                $result .= "\n\n";
                $path = 'wp-content/plugins/lykkepay-for-woocomerce/' . LOG_FILE_NAME;
                error_log($date . $result, 3, $path);
                return true;
            }
            return false;
        }

        function callb()
        {
            if (isset($_GET['lykkepay']) AND $_GET['lykkepay'] == 'result') {

                $args = array(
                    'orderId' =>$_GET['order_id'].';'.$_SESSION['invoiceId'],
                );
	            $errorCode = 0;
	            if ($errorCode == 0) {
		            $order_id = $_GET['order_id'];
		            $order    = new WC_Order( $order_id );
		            WC()->cart->empty_cart();
	                //if ($response['status'] == 'Unpaid') {

		                cronstarter_activation($args);
		                $order->update_status( 'pending', __( 'Payment has been received', 'woocommerce' ) );

		                //$order->payment_complete();
		                wp_redirect( $this->get_return_url( $order ) );
		                exit;
	                //}
	            }
            }
        }

        public function __construct()
        {

            if (isset($_GET['wc-callb']) AND $_GET['wc-callb'] == 'callback_function') {
                $this->callb();
                exit;
            }

            $this->id = LYKKEPAY_ID;
            $this->has_fields = false;
            $this->liveurl = $this->get_option('produrl');//LYKKE_PROD_URL;
            $this->testurl = $this->get_option('testurl');

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->merchantId = $this->get_option('merchantId');
            $this->signature = $this->get_option('signature');
	        $this->apikey = $this->get_option('apikey');
            $this->testmode = $this->get_option('testmode');
            $this->description = $this->get_option('description');

            // Actions
            add_action('valid-lykkepay-standard-ipn-reques', array($this, 'successful_request'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            // Save options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
            $this->callb();
        }


        /**
         * Check if this gateway is enabled and available in the user's country
         */
        function is_valid_for_use()
        {
            if (!in_array(get_option('woocommerce_currency'), array('CHF', 'USD'))) {
                return false;
            }
            return true;
        }

        /**
         * Admin Panel Options
         */
        public function admin_options()
        {
            ?>
            <h3><?php _e(LYKKEPAY_TITLE_1, 'woocommerce'); ?></h3>
            <p><?php _e(LYKKEPAY_TITLE_2, 'woocommerce'); ?></p>

            <?php if ($this->is_valid_for_use()) : ?>

            <table class="form-table">

                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table>

        <?php else : ?>
            <div class="inline error"><p>
                    <strong><?php _e('Gateway is switched off', 'woocommerce'); ?></strong>: <?php _e($this->id . ' does not support currency of your web-store.', 'woocommerce'); ?>
                </p></div>
            <?php
        endif;

        }

        /**
         * Initialise Gateway Settings Form Fields
         */
        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enabled', 'woocommerce'),
                    'default' => 'yes'
                ),
                'produrl' => array(
	                'title' => __('URL Production API', 'woocommerce'),
	                'type' => 'text',
	                'description' => __('URL Production API.', 'woocommerce'),
	                'default' => ''
                ),
                'testurl' => array(
	                'title' => __('URL Test API', 'woocommerce'),
	                'type' => 'text',
	                'description' => __('URL Test API.', 'woocommerce'),
	                'default' => ''
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This is the title a consumer sees when check in.', 'woocommerce'),
                    'default' => __(LYKKEPAY_NAME, 'woocommerce')
                ),
                'merchantId' => array(
                    'title' => __('merchantId', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Please provide merchantId', 'woocommerce'),
                    'default' => ''
                ),
                'apikey' => array(
	                'title' => __('apikey', 'woocommerce'),
	                'type' => 'text',
	                'description' => __('Please provide apikey', 'woocommerce'),
	                'default' => ''
                ),
                'signature' => array(
                    'title' => __('signature', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Please provide signature.', 'woocommerce'),
                    'default' => ''
                ),
                'testmode' => array(
                    'title' => __('Test mode', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Включен', 'woocommerce'),
                    'description' => __('In this mode consumers will not be charged for purchases.', 'woocommerce'),
                    'default' => 'no'
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Description of the payment method the customers will see on your web-site.', 'woocommerce'),
                    'default' => 'Pay with ' . LYKKEPAY_NAME
                )
            );
        }

        /**
         * Generate the dibs button link
         */
        public function generate_form($order_id)
        {

            $order = new WC_Order($order_id);

            if ($this->testmode == 'yes') {
                $action_adr = $this->testurl;
            } else {
                $action_adr = $this->liveurl;
            }

	        $extra_url_param = '&wc-callb=callback_function';//GA
	        $action_adr .= 'Create';
            $_SESSION['merchantId'] = $this->merchantId;
            $_SESSION['signature'] = $this->signature;
            $_SESSION['testmode'] = $this->testmode;
	        $_SESSION['apikey'] = $this->apikey;

            $args = array(
                    'merchantId' => $this->merchantId,
                    'InvoiceNumber' => $order_id, //. '_' . $i,
                    'amount' => round($order->order_total, 0),
                    'clientName' => $order->get_billing_first_name().' '.$order->get_billing_last_name(),
                    'clientEmail' => $order->get_billing_email(),
                    'currency' => $order->get_currency(),
                    'callbackUrl' => SHOP_URL . '?wc-api=WC_LYKKEPAY&lykkepay=result&order_id=' . $order_id . $extra_url_param,
            );
	        $pkeyid = openssl_pkey_get_private($this->signature);
	        openssl_sign($this->apikey.http_build_query($args), $signature, $pkeyid, OPENSSL_ALGO_SHA256);
	        $sign = base64_encode($signature);

	        $lykkepayCurl = curl_init();
	        $headers = [
		        'Lykke-Merchant-Id: '.$this->merchantId,
		        'Lykke-Merchant-Sign: '.$sign
	        ];
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
	            $_SESSION['invoiceId'] = $response['invoiceId'];
                return '<p>' . __('Thank you for your order, please, click the button below in order to pay.', 'woocommerce') . '</p>' .
                '<a class="button cancel" href="' . $response['invoiceURL'] . '">' . __('Pay', 'woocommerce') . '</a>' .
                '<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel', 'woocommerce') . '</a>';
            } else {
                return '<p>' . __('Error #' . $errorCode . ': ' . $response['errorMessage'], 'woocommerce') . '</p>' .
                '<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel', 'woocommerce') . '</a>';
            }
        }

        /**
         * Process the payment and return the result
         */
        function process_payment($order_id)
        {

            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
            );
        }

        /**
         * Receipt page
         */
        function receipt_page($order)
        {
            echo $this->generate_form($order);
        }
    }

    /**
     * Add the gateway to WooCommerce
     */
    function add_lykkepay_gateway($methods)
    {

        $methods[] = 'WC_LYKKEPAY';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_lykkepay_gateway');
}

?>
