<?php
/*
 * Copyright (c) 2025 AltumCode (https://altumcode.com/)
 *
 * This software is licensed exclusively by AltumCode and is sold only via https://altumcode.com/.
 * Unauthorized distribution, modification, or use of this software without a valid license is not permitted and may be subject to applicable legal actions.
 *
 * 🌍 View all other existing AltumCode projects via https://altumcode.com/
 * 📧 Get in touch for support or general queries via https://altumcode.com/contact
 * 📤 Download the latest version via https://altumcode.com/downloads
 *
 * 🐦 X/Twitter: https://x.com/AltumCode
 * 📘 Facebook: https://facebook.com/altumcode
 * 📸 Instagram: https://instagram.com/altumcode
 */

namespace Altum\PaymentGateways;

/* Helper class for Paddle */
defined('ALTUMCODE') || die();

class Paddle {
    // New Paddle Billing API URLs
    static public $sandbox_api_url = 'https://sandbox-api.paddle.com/';
    static public $live_api_url = 'https://api.paddle.com/';

    public static function get_api_url() {
        return settings()->paddle->mode == 'live' ? self::$live_api_url : self::$sandbox_api_url;
    }

    public static function get_headers() {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . settings()->paddle->api_key
        ];
    }

    public static function create_product($name, $description = '') {
        $payload = [
            'name' => $name,
            'description' => $description,
            'type' => 'standard',
            'tax_category' => 'standard'
        ];

        $response = \Unirest\Request::post(
            self::get_api_url() . 'products',
            self::get_headers(),
            \Unirest\Request\Body::json($payload)
        );

        return $response;
    }

    public static function create_price($product_id, $amount, $currency, $billing_cycle = null) {
        /* Convert amount to cents (string) for Paddle API */
        $amount_in_cents = (string) round($amount * 100);
        
        $payload = [
            'product_id' => $product_id,
            'description' => 'Plan price',
            'type' => 'standard',
            'billing_cycle' => $billing_cycle ? ['interval' => $billing_cycle, 'frequency' => 1] : null,
            'trial_period' => null,
            'tax_mode' => 'account_setting',
            'unit_price' => [
                'amount' => $amount_in_cents,
                'currency_code' => $currency
            ]
        ];

        $response = \Unirest\Request::post(
            self::get_api_url() . 'prices',
            self::get_headers(),
            \Unirest\Request\Body::json($payload)
        );

        return $response;
    }

    public static function create_transaction($items, $customer_email, $custom_data = []) {
        $payload = [
            'items' => $items,
            'customer_email' => $customer_email,
            'custom_data' => $custom_data,
            'collection_mode' => 'automatic',
            'currency_code' => currency(),
            'checkout' => [
                'url' => SITE_URL . 'pay.php'
            ]
        ];

        $response = \Unirest\Request::post(
            self::get_api_url() . 'transactions',
            self::get_headers(),
            \Unirest\Request\Body::json($payload)
        );

        return $response;
    }

}
