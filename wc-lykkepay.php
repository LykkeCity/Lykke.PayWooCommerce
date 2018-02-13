<?php
/*
  Plugin Name: Платежный шлюз LykkePay
  Description: Позволяет использовать платежный шлюз LykkePay с плагином WooCommerce
  Version: 1.0.0
 */

session_start();
require_once 'include.php';
require_once 'text.php';

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
                if ($_SESSION['testmode'] == 'yes') {
                    $action_adr = LYKKE_TEST_URL;
                } else {
                    $action_adr = LYKKE_PROD_URL;
                }

                $action_adr .= 'status';

                $args = array(
                    'invoiceId' => $_SESSION['invoiceid']
                );
	            $lykkepayCurl = curl_init();
	            curl_setopt_array($lykkepayCurl, array(
		            CURLOPT_URL => $action_adr,
		            CURLOPT_RETURNTRANSFER => true,
		            CURLOPT_POST => true,
		            CURLOPT_POSTFIELDS => http_build_query($args)
	            ));
	            $response = curl_exec($lykkepayCurl);
	            curl_close($lykkepayCurl);
	            $response = json_decode($response, true);

	            $errorCode = $response['ErrorCode'];
	            if ($errorCode == 0) {
	                //if ($response['Status'] == 'Unpaid') {
		                $order_id = $_GET['order_id'];
		                $order    = new WC_Order( $order_id );
		                $order->update_status( 'processing', __( 'Платеж успешно оплачен', 'woocommerce' ) );
		                WC()->cart->empty_cart();
		                $order->payment_complete();
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
            //if (!in_array(get_option('woocommerce_currency'), array('CHF'))) {
            //    return false;
            //}
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
                    <strong><?php _e('Шлюз отключен', 'woocommerce'); ?></strong>: <?php _e($this->id . ' не поддерживает валюты Вашего магазина.', 'woocommerce'); ?>
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
                    'title' => __('Включить/Выключить', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Включен', 'woocommerce'),
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
                    'title' => __('Название', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Это название, которое пользователь видит во время проверки.', 'woocommerce'),
                    'default' => __(LYKKEPAY_NAME, 'woocommerce')
                ),
                'merchantId' => array(
                    'title' => __('merchantId', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Пожалуйста введите merchantId', 'woocommerce'),
                    'default' => ''
                ),
                'apikey' => array(
	                'title' => __('apikey', 'woocommerce'),
	                'type' => 'text',
	                'description' => __('Пожалуйста введите apikey', 'woocommerce'),
	                'default' => ''
                ),
                'signature' => array(
                    'title' => __('signature', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Пожалуйста введите signature.', 'woocommerce'),
                    'default' => ''
                ),
                'testmode' => array(
                    'title' => __('Тест режим', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Включен', 'woocommerce'),
                    'description' => __('В этом режиме плата за товар не снимается.', 'woocommerce'),
                    'default' => 'no'
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Описанием метода оплаты которое клиент будет видеть на вашем сайте.', 'woocommerce'),
                    'default' => 'Оплата с помощью ' . LYKKEPAY_NAME
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
	        $action_adr .= 'create';
            $_SESSION['merchantId'] = $this->merchantId;
            $_SESSION['signature'] = $this->signature;
            $_SESSION['testmode'] = $this->testmode;
	        $_SESSION['apikey'] = $this->apikey;

            $args = array(
                    'merchantId' => $this->merchantId,
                    'InvoiceNumber' => $order_id, //. '_' . $i,
                    'amount' => $order->order_total,
                    'clientName' => $order->get_billing_first_name().' '.$order->get_billing_last_name(),
                    'clientEmail' => $order->get_billing_email(),
                    'currency' => 'CHF',
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

            $errorCode = $response['ErrorCode'];
            if ($errorCode == 0) {
	            $_SESSION['invoiceid'] = $response['InvoiceId'];
                return '<p>' . __('Спасибо за Ваш заказ, пожалуйста, нажмите кнопку ниже, чтобы заплатить.', 'woocommerce') . '</p>' .
                '<a class="button cancel" href="' . $response['InvoiceURL'] . '">' . __('Оплатить', 'woocommerce') . '</a>' .
                '<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Отказаться от оплаты & вернуться в корзину', 'woocommerce') . '</a>';
            } else {
                return '<p>' . __('Ошибка #' . $errorCode . ': ' . $response['errorMessage'], 'woocommerce') . '</p>' .
                '<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Отказаться от оплаты & вернуться в корзину', 'woocommerce') . '</a>';
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

        /**
         * Check response
         */

        function check_response()
        {
            global $woocommerce;

            wp_redirect($this->get_return_url($order));
            if (isset($_GET['lykkepay']) AND $_GET['lykkepay'] == 'result') {
                if (DEBUG) {
                    $action_adr = LYKKE_TEST_URL;
                } else {
                    $action_adr = LYKKE_PROD_URL;
                }

                $action_adr .= 'getOrderStatusExtended.do';

                $args = array(
                    'merchantId' => 'test',//$this->get_option('merchant'),
                    'signature' => 'testPwd',//$this->get_option('password'),
                    'orderId' => $_GET['orderId'],
                );

                $lykkepayCurl = curl_init();
                curl_setopt_array($lykkepayCurl, array(
                    CURLOPT_URL => $action_adr,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($args)
                ));
                $response = curl_exec($lykkepayCurl);
                curl_close($lykkepayCurl);
                error_log($response);
                $response = json_decode($response, true);

                $orderStatus = $response['OrderStatus'];

                if ($orderStatus == '1' || $orderStatus == '2') {
                    $order_id = $_GET['order_id'];
                    $order = new WC_Order($order_id);
                    $order->update_status('completed', __('Платеж успешно оплачен', 'woocommerce'));
                    WC()->cart->empty_cart();
                    $order->payment_complete();
                    wp_redirect($this->get_return_url($order));
                    exit;
                } else {
                    $order_id = $_GET['order_id'];
                    $order = new WC_Order($order_id);
                    $order->update_status('failed', __('Платеж не оплачен', 'woocommerce'));
                    add_filter('woocommerce_add_to_cart_message', 'my_cart_messages', 99);
                    $order->cancel_order();
                    $woocommerce->add_error(__('Ошибка в проведении оплаты<br/>' . $response['actionCodeDescription'], 'woocommerce'));
                    wp_redirect($order->get_cancel_order_url());
                    exit;
                }
            }
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
