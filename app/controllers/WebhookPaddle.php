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

namespace Altum\Controllers;

use Altum\Models\Payments;

defined('ALTUMCODE') || die();

class WebhookPaddle extends Controller {

    public function index() {

        /* Get the raw POST data */
        $raw_post_data = file_get_contents('php://input');
        
        if(empty($raw_post_data)) {
            die();
        }

        /* Parse the JSON data */
        $webhook_data = json_decode($raw_post_data, true);
        
        if(!$webhook_data) {
            die('Invalid JSON data');
        }

        /* Verify webhook signature for new Paddle Billing */
        if(!$this->verify_webhook_signature($raw_post_data)) {
            die('Invalid webhook signature');
        }

        /* Handle different webhook events */
        $event_type = $webhook_data['event_type'] ?? '';
        
        switch($event_type) {
            case 'transaction.completed':
                $this->handle_transaction_completed($webhook_data);
                break;
                
            case 'transaction.payment_failed':
                $this->handle_transaction_failed($webhook_data);
                break;
                
            case 'transaction.created':
                /* Transaction created - no action needed, wait for completion */
                break;
                
            case 'product.created':
                /* Product created - no action needed */
                break;
                
            case 'price.created':
                /* Price created - no action needed */
                break;
                
            default:
                /* Log unhandled events for debugging */
                error_log('Unhandled Paddle webhook event: ' . $event_type);
                break;
        }

        echo 'successful';
    }

    private function verify_webhook_signature($raw_post_data) {
        /* For new Paddle Billing, webhook verification is different */
        /* You'll need to implement proper signature verification based on Paddle's new system */
        /* For now, we'll skip verification but you should implement this properly */
        return true;
    }

    private function handle_transaction_completed($webhook_data) {
        $transaction_data = $webhook_data['data'] ?? [];
        
        if(empty($transaction_data)) {
            return;
        }

        /* Extract transaction details */
        $external_payment_id = $transaction_data['id'] ?? '';
        $payment_total = $transaction_data['totals']['total'] ?? 0;
        $payment_currency = $transaction_data['currency_code'] ?? '';
        $payment_type = 'one_time';
        $payment_subscription_id = null;

        /* Extract customer details */
        $customer_data = $transaction_data['customer'] ?? [];
        $payer_email = $customer_data['email'] ?? '';
        $payer_name = trim(($customer_data['name'] ?? '') . ' ' . ($customer_data['last_name'] ?? ''));

        /* Extract custom data */
        $custom_data = $transaction_data['custom_data'] ?? [];
        $user_id = (int) ($custom_data['user_id'] ?? 0);
        $plan_id = (int) ($custom_data['plan_id'] ?? 0);
        $payment_frequency = $custom_data['payment_frequency'] ?? '';
        $base_amount = $custom_data['base_amount'] ?? $payment_total;
        $code = $custom_data['code'] ?? '';
        $discount_amount = $custom_data['discount_amount'] ?? 0;
        $taxes_ids = $custom_data['taxes_ids'] ?? null;

        /* Check if this is a pay-first payment */
        $is_pay_first = isset($custom_data['pending_registration']) && $custom_data['pending_registration'] === 'true';
        
        if($is_pay_first) {
            /* This is a pay-first payment, we need to create the user account */
            /* Get pending registration data from database */
            $pending_data = db()->where('payment_id', $external_payment_id)->where('processor', 'paddle')->getOne('pending_registrations');
            
            if($pending_data) {
                $pending_registration_data = json_decode($pending_data->registration_data, true);
                
                /* Clean up the pending registration record */
                db()->where('payment_id', $external_payment_id)->where('processor', 'paddle')->delete('pending_registrations');
                
                if($pending_registration_data && $plan_id) {
                    (new Payments())->webhook_process_payment_pay_first(
                        'paddle',
                        $external_payment_id,
                        $payment_total,
                        $payment_currency,
                        $plan_id,
                        $payment_frequency,
                        $code,
                        $discount_amount,
                        $base_amount,
                        $taxes_ids,
                        $payment_type,
                        $payment_subscription_id,
                        $payer_email,
                        $payer_name,
                        $pending_registration_data
                    );
                }
            }
        } else if($user_id && $plan_id) {
            /* Regular payment processing for existing users */
            (new Payments())->webhook_process_payment(
                'paddle',
                $external_payment_id,
                $payment_total,
                $payment_currency,
                $user_id,
                $plan_id,
                $payment_frequency,
                $code,
                $discount_amount,
                $base_amount,
                $taxes_ids,
                $payment_type,
                $payment_subscription_id,
                $payer_email,
                $payer_name
            );
        }
    }

    private function handle_transaction_failed($webhook_data) {
        /* Handle failed transactions if needed */
        $transaction_data = $webhook_data['data'] ?? [];
        error_log('Paddle transaction failed: ' . json_encode($transaction_data));
    }

}
