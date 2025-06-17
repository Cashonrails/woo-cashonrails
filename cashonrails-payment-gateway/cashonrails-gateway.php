<?php
/**
 * Plugin Name: CashOnRails WooCommerce Gateway
 * Description: A WooCommerce payment gateway for CashOnRails, including subscriptions and webhook handling.
 * Version: 1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', 'cashonrails_init_gateway', 11);
function cashonrails_init_gateway() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_CashOnRails extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'cashonrails';
            $this->method_title = 'CashOnRails';
            $this->method_description = 'Pay via CashOnRails. Supports subscriptions.';
            $this->has_fields = false;

            $this->supports = array(
                'products',
                'subscriptions',
                'subscription_cancellation',
                'subscription_suspension',
                'subscription_reactivation',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_payment_method_change',
                'subscription_payment_method_change_customer',
                'subscription_payment_method_change_admin',
                'multiple_subscriptions',
            );

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->secret_key = $this->get_option('secret_key');
            $this->currency = $this->get_option('currency');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable CashOnRails Gateway',
                    'default' => 'yes'
                ],
                'title' => [
                    'title'   => 'Title',
                    'type'    => 'text',
                    'default' => 'CashOnRails'
                ],
                'secret_key' => [
                    'title' => 'Secret Key',
                    'type'  => 'text'
                ],
                'currency' => [
                    'title'   => 'Currency',
                    'type'    => 'select',
                    'description' => 'Choose the currency to use with this gateway.',
                    'default' => 'NGN',
                    'options' => [
                        'NGN' => 'Nigerian Naira (NGN)',
                        'USD' => 'US Dollar (USD)',
                        'EUR' => 'Euro (EUR)',
                        'GBP' => 'British Pound (GBP)',
                    ]
                ]
            ];
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $email = $order->get_billing_email();
            $amount = $order->get_total();
            $reference = 'CR-' . uniqid();

            $customer_payload = json_encode([
                'email' => $email,
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'phone' => $order->get_billing_phone()
            ]);

            $ch = curl_init('https://mainapi.cashonrails.com/api/v1/customer');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $customer_payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->secret_key,
                ],
            ]);
            $customer_response = curl_exec($ch);
            $customer_result = json_decode($customer_response, true);
            curl_close($ch);

            if (!$customer_result['success']) {
                wc_add_notice($customer_result['message'], 'error');
                return;
            }

            $customer_code = $customer_result['data']['customer_code'] ?? null;
            if (!$customer_code) {
                wc_add_notice('Invalid customer response. Missing customer code.', 'error');
                return;
            }

            $payload = json_encode([
                'email' => $email,
                'amount' => strval($amount),
                'currency' => $this->currency,
                'reference' => $reference,
                'customer_code' => $customer_code,
                'redirectUrl' => $this->get_return_url($order),
                'logoUrl' => get_site_icon_url()
            ]);

            $ch = curl_init('https://mainapi.cashonrails.com/api/v1/transaction/initialize');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->secret_key,
                ],
            ]);
            $response = curl_exec($ch);
            $res = json_decode($response, true);
            curl_close($ch);

            if (!isset($res['success']) || !$res['success']) {
                wc_add_notice($res['message'], 'error');
                return;
            }

            $order->update_meta_data('_cashonrails_ref', $reference);
            $order->update_meta_data('_cashonrails_customer_code', $customer_code);
            $order->save();

            return [
                'result' => 'success',
                'redirect' => $res['data']['authorization_url']
            ];
        }

        public function scheduled_subscription_payment($amount_to_charge, $order) {
            return $this->process_subscription_payment($order, $amount_to_charge);
        }

        public function process_subscription_payment($order, $amount) {
            // Handle recurring subscription payment manually here if needed
            return true;
        }
    }

    // Verify payment on return from CashOnRails
    add_action('woocommerce_thankyou_cashonrails', 'cashonrails_verify_payment');
    function cashonrails_verify_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $reference = $order->get_meta('_cashonrails_ref');
        if (!$reference) return;


        $secret_key = get_option('woocommerce_cashonrails_settings')['secret_key'];
        $verify_url = 'https://mainapi.cashonrails.com/api/v1/s2s/transaction/verify/' . $reference;

        $ch = curl_init($verify_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $secret_key,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $res = json_decode($response, true);
        curl_close($ch);



        if (isset($res['success']) && $res['success'] === true && $res['data']['status'] === 'success') {
            if (!in_array($order->get_status(), ['processing', 'completed'])) {
                $order->payment_complete($reference);
                $order->add_order_note('CashOnRails payment verified on return.');


                if (function_exists('wcs_get_subscriptions_for_order')) {
                    $subscriptions = wcs_get_subscriptions_for_order($order, ['parent', 'renewal']);
                    foreach ($subscriptions as $subscription) {
                        if ($subscription->has_status(['pending', 'on-hold'])) {
                            $subscription->payment_complete();
                            $subscription->update_status('active');
                            $subscription->add_order_note('CashOnRails: Subscription activated after payment.');
                        }
                    }
                }

                do_action('woocommerce_payment_complete', $order->get_id()); // 🔑 Triggers other payment hooks
            }
        } else {
            $order->add_order_note('CashOnRails payment verification failed or not successful.');
        }

    }

    // Webhook handler
    function cashonrails_webhook_handler() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_GET['cashonrails-webhook'])) return;

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!isset($data['reference']) || !isset($data['status'])) {
            status_header(400);
            echo 'Invalid payload';
            exit;
        }

        $orders = wc_get_orders(['meta_key' => '_cashonrails_ref', 'meta_value' => $data['reference']]);
        $order = !empty($orders) ? $orders[0] : null;

        if ($order && $data['status'] === 'success') {
            if (!in_array($order->get_status(), ['processing', 'completed'])) {
                $order->payment_complete($data['reference']);
                do_action('woocommerce_payment_complete', $order->get_id()); // 🔑 Trigger subscription hooks
                $order->add_order_note('CashOnRails payment confirmed via webhook.');
            }
        }

        status_header(200);
        echo 'OK';
        exit;
    }

    add_action('init', 'cashonrails_webhook_handler');
}

add_filter('woocommerce_payment_gateways', function($methods) {
    $methods[] = 'WC_Gateway_CashOnRails';
    return $methods;
});
?>