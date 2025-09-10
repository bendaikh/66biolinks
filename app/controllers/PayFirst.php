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

use Altum\Alerts;
use Altum\Captcha;
use Altum\Logger;
use Altum\Models\User;
use Altum\PaymentGateways\Coinbase;
use Altum\PaymentGateways\Lemonsqueezy;
use Altum\PaymentGateways\Paddle;
use Altum\PaymentGateways\Paystack;
use Altum\Response;
use Altum\Title;
use Razorpay\Api\Api;

defined('ALTUMCODE') || die();

class PayFirst extends Controller {
    public $plan_id;
    public $return_type;
    public $payment_processor;
    public $plan;
    public $plan_taxes;
    public $applied_taxes_ids = [];
    public $code = null;
    public $payment_extra_data = null;

    public function index() {

        \Altum\Authentication::guard('guest');

        /* Check if Registration is enabled first */
        if(!settings()->users->register_is_enabled) {
            redirect('not-found');
        }

        /* Check if Payment is enabled */
        if(!settings()->payment->is_enabled) {
            redirect('not-found');
        }

        /* Handle success and cancel returns from payment processors */
        if(isset($_GET['success']) && $_GET['success'] == '1') {
            $this->success();
            return;
        }

        if(isset($_GET['cancel']) && $_GET['cancel'] == '1') {
            $this->cancel();
            return;
        }

        $payment_processors = require APP_PATH . 'includes/payment_processors.php';
        $this->plan_id = isset($this->params[0]) ? $this->params[0] : null;

        /* Get the plan details */
        if($this->plan_id == 'free') {
            $this->plan = settings()->plan_free;
        } else {
            $this->plan_id = (int) $this->plan_id;
            $plans = (new \Altum\Models\Plan())->get_plans();
            $this->plan = $plans[$this->plan_id] ?? null;
            
            if(!$this->plan) {
                redirect('plan');
            }
        }

        /* Make sure the plan is enabled */
        if(!$this->plan->status) {
            redirect('plan');
        }

        /* Handle Paddle transaction checkout */
        $this->handle_paddle_checkout();

        /* Check for potential taxes */
        if($this->plan_id != 'free') {
            $this->plan_taxes = (new \Altum\Models\Plan())->get_plan_taxes_by_taxes_ids($this->plan->taxes_ids);
        }

        \Altum\CustomHooks::user_initiate_registration();

        $redirect = process_and_get_redirect_params() ?? 'dashboard';
        $redirect_append = $redirect ? '?redirect=' . $redirect : null;

        /* Default variables */
        $values = [
            'name' => isset($_GET['name']) ? query_clean($_GET['name']) : '',
            'email' => isset($_GET['email']) ? query_clean($_GET['email']) : '',
            'password' => ''
        ];

        /* Initiate captcha */
        $captcha = new Captcha();

        if(!empty($_POST) && !settings()->users->register_only_social_logins) {
            // DEBUG: Log the form submission
            error_log('PayFirst DEBUG: Form submitted');
            error_log('PayFirst DEBUG: POST data: ' . json_encode($_POST));
            error_log('PayFirst DEBUG: Plan ID: ' . $this->plan_id);
            error_log('PayFirst DEBUG: Payment processor: ' . ($_POST['payment_processor'] ?? 'NOT SET'));

            /* Clean some posted variables */
            $_POST['name'] = input_clean($_POST['name'], 64);
            $_POST['email'] = input_clean_email($_POST['email'] ?? '');
            $_POST['is_newsletter_subscribed'] = settings()->users->register_display_newsletter_checkbox && isset($_POST['is_newsletter_subscribed']);

            /* Default variables */
            $values['name'] = $_POST['name'];
            $values['email'] = $_POST['email'];
            $values['password'] = $_POST['password'];

            /* Check for any errors */
            $required_fields = ['name', 'email', 'password'];
            foreach($required_fields as $field) {
                if(!isset($_POST[$field]) || (isset($_POST[$field]) && empty($_POST[$field]) && $_POST[$field] != '0')) {
                    Alerts::add_field_error($field, l('global.error_message.empty_field'));
                }
            }

            if(settings()->captcha->register_is_enabled && !$captcha->is_valid()) {
                Alerts::add_field_error('captcha', l('global.error_message.invalid_captcha'));
            }
            if(mb_strlen($_POST['name']) < 1 || mb_strlen($_POST['name']) > 64) {
                Alerts::add_field_error('name', l('register.error_message.name_length'));
            }
            if(db()->where('email', $_POST['email'])->has('users')) {
                Alerts::add_field_error('email', l('register.error_message.email_exists'));
            }
            if(!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                Alerts::add_field_error('email', l('global.error_message.invalid_email'));
            }
            if(!settings()->users->email_aliases_is_enabled && str_contains($_POST['email'], '+')) {
                Alerts::add_field_error('email', l('register.error_message.email_aliases_not_allowed'));
            }
            if(mb_strlen($_POST['password']) < 6 || mb_strlen($_POST['password']) > 64) {
                Alerts::add_field_error('password', l('global.error_message.password_length'));
            }

            /* Make sure the domain is not blacklisted */
            $email_domain = get_domain_from_email($_POST['email']);
            if(settings()->users->blacklisted_domains && in_array($email_domain, settings()->users->blacklisted_domains)) {
                Alerts::add_field_error('email', l('register.error_message.blacklisted_domain'));
            }

            /* Email shield plugin */
            if(\Altum\Plugin::is_active('email-shield') && settings()->email_shield->is_enabled && !\Altum\Plugin\EmailShield::validate($email_domain)) {
                Alerts::add_field_error('email', l('register.error_message.blacklisted_domain'));
            }

            /* Detect the location */
            try {
                $maxmind = (get_maxmind_reader_country())->get(get_ip());
            } catch(\Exception $exception) { /* :) */ }
            $country = isset($maxmind) && isset($maxmind['country']) ? $maxmind['country']['iso_code'] : null;

            /* Make sure the country is not blacklisted */
            if($country && in_array($country, settings()->users->blacklisted_countries ?? [])) {
                Alerts::add_error(l('register.error_message.blacklisted_country'));
            }

            /* Make sure to check against the limiter */
            if(settings()->users->register_lockout_is_enabled) {
                $days_ago_datetime = (new \DateTime())->modify('-' . settings()->users->register_lockout_time . ' days')->format('Y-m-d H:i:s');

                $recent_registrations = db()->where('ip', get_ip())->where('type', 'register.success')->where('datetime', $days_ago_datetime, '>=')->getValue('users_logs', 'COUNT(*)');

                if($recent_registrations >= settings()->users->register_lockout_max_registrations) {
                    Alerts::add_error(sprintf(l('global.error_message.limit_try_again'), settings()->users->register_lockout_time, l('global.date.days')));
                    setcookie('register_lockout', 'true', time()+60*60*24*settings()->users->register_lockout_time, COOKIE_PATH);
                    $_COOKIE['register_lockout'] = 'true';
                }
            }

            /* Validate payment fields for paid plans */
            if($this->plan_id != 'free') {
                if(!isset($_POST['payment_frequency']) || !isset($_POST['payment_processor']) || !isset($_POST['payment_type'])) {
                    Alerts::add_error(l('pay_first.error_message.missing_payment_data'));
                }
            }

            /* If there are no errors, process payment first */
            if(!Alerts::has_field_errors() && !Alerts::has_errors()) {

                /* For free plan, create account directly */
                if($this->plan_id == 'free') {
                    $this->create_account_and_login($_POST, $redirect);
                } else {
                    /* For paid plans, process payment first */
                    $this->process_payment_first($_POST, $redirect);
                }
            }
        }

        /* Main View */
        $data = [
            'values' => $values,
            'captcha' => $captcha,
            'redirect_append' => $redirect_append,
            'plan' => $this->plan,
            'plan_id' => $this->plan_id,
            'plan_taxes' => $this->plan_taxes ?? [],
            'payment_processors' => $payment_processors,
        ];

        $view = new \Altum\View('pay-first/index', (array) $this);

        $this->add_view_content('content', $view->run($data));
    }

    private function create_account_and_login($post_data, $redirect) {
        /* Define some needed variables */
        $active = (int) !settings()->users->email_confirmation;
        $email_code = md5($post_data['email'] . microtime());

        /* Determine what plan is set by default */
        $plan_id = $this->plan_id;
        $plan_settings = json_encode($this->plan->settings ?? '');
        $plan_expiration_date = get_date();

        $registered_user = (new User())->create(
            $post_data['email'],
            $post_data['password'],
            $post_data['name'],
            (int) !settings()->users->email_confirmation,
            'direct',
            $email_code,
            null,
            $post_data['is_newsletter_subscribed'],
            $plan_id,
            $plan_settings,
            $plan_expiration_date,
            settings()->main->default_timezone
        );

        /* Log the action */
        Logger::users($registered_user['user_id'], 'register.success');

        /* If active = 1 then login the user, else send the user an activation email */
        if($active == '1') {
            /* Send a welcome email if needed */
            if(settings()->users->welcome_email_is_enabled) {
                $email_template = get_email_template(
                    [],
                    l('global.emails.user_welcome.subject'),
                    [
                        '{{NAME}}' => $post_data['name'],
                        '{{URL}}' => url(),
                        '{{DASHBOARD_LINK}}' => url('dashboard'),
                    ],
                    l('global.emails.user_welcome.body')
                );

                send_mail($post_data['email'], $email_template->subject, $email_template->body);
            }

            /* Send notification to admin if needed */
            if(settings()->email_notifications->new_user && !empty(settings()->email_notifications->emails)) {
                $email_template = get_email_template(
                    [],
                    l('global.emails.admin_new_user_notification.subject'),
                    [
                        '{{NAME}}' => str_replace('.', '. ', $post_data['name']),
                        '{{EMAIL}}' => $post_data['email'],
                        '{{SOURCE}}' => $registered_user['source'],
                        '{{IP}}' => $registered_user['ip'],
                        '{{COUNTRY_NAME}}' => $registered_user['country'] ? get_country_from_country_code($registered_user['country']) : l('global.unknown'),
                        '{{CITY_NAME}}' => $registered_user['city_name'] ?? l('global.unknown'),
                        '{{DEVICE_TYPE}}' => l('global.device.' . $registered_user['device_type']),
                        '{{OS_NAME}}' => $registered_user['os_name'],
                        '{{BROWSER_NAME}}' => $registered_user['browser_name'],
                        '{{USER_LINK}}' => url('admin/user-view/' . $registered_user['user_id']),
                    ],
                    l('global.emails.admin_new_user_notification.body')
                );

                send_mail(explode(',', settings()->email_notifications->emails), $email_template->subject, $email_template->body);
            }

            Alerts::add_success(l('register.success_message.login'));

            $_SESSION['user_id'] = $registered_user['user_id'];
            $_SESSION['user_password_hash'] = md5($registered_user['password']);

            Logger::users($registered_user['user_id'], 'login.success');

            redirect($redirect . '&welcome=' . $registered_user['user_id']);
        } else {
            /* Send activation email */
            $email_template = get_email_template(
                [
                    '{{NAME}}' => str_replace('.', '. ', $post_data['name']),
                ],
                l('global.emails.user_activation.subject'),
                [
                    '{{ACTIVATION_LINK}}' => url('activate-user?email=' . md5($post_data['email']) . '&email_activation_code=' . $email_code . '&type=user_activation' . '&redirect=' . $redirect),
                    '{{NAME}}' => str_replace('.', '. ', $post_data['name']),
                ],
                l('global.emails.user_activation.body')
            );

            send_mail($post_data['email'], $email_template->subject, $email_template->body);

            Alerts::add_success(l('register.success_message.registration'));
        }
    }

    private function process_payment_first($post_data, $redirect) {
        /* Store user data in session for after payment */
        $_SESSION['pending_registration'] = [
            'name' => $post_data['name'],
            'email' => $post_data['email'],
            'password' => $post_data['password'],
            'is_newsletter_subscribed' => $post_data['is_newsletter_subscribed'],
            'plan_id' => $this->plan_id,
            'redirect' => $redirect
        ];

        /* Process payment using the same logic as Pay controller */
        $this->return_type = isset($_GET['return_type']) ? $_GET['return_type'] : null;
        $this->payment_processor = $_POST['payment_processor'];
        
        /* Check for code usage */
        if(settings()->payment->codes_is_enabled && isset($_POST['code'])) {
            $_POST['code'] = query_clean($_POST['code']);
            $this->code = database()->query("SELECT * FROM `codes` WHERE `code` = '{$_POST['code']}' AND `redeemed` < `quantity`")->fetch_object();

            if($this->code) {
                $this->code->plans_ids = json_decode($this->code->plans_ids ?? '');

                if(!in_array($this->plan_id, $this->code->plans_ids)) {
                    $this->code = null;
                }
            }
        }

        /* Check for any errors */
        if(!\Altum\Csrf::check()) {
            Alerts::add_error(l('global.error_message.invalid_csrf_token'));
        }

        if(!Alerts::has_field_errors() && !Alerts::has_errors()) {
            // DEBUG: Log before payment processing
            error_log('PayFirst DEBUG: About to process payment with processor: ' . $this->payment_processor);
            
            /* Process payment based on processor */
            switch($this->payment_processor) {
                case 'stripe':
                    $this->process_stripe_payment($post_data);
                    break;
                    
                case 'paypal':
                    $this->process_paypal_payment($post_data);
                    break;
                    
                case 'razorpay':
                    $this->process_razorpay_payment($post_data);
                    break;
                    
                case 'mollie':
                    $this->process_mollie_payment($post_data);
                    break;
                    
                case 'coinbase':
                    $this->process_coinbase_payment($post_data);
                    break;
                    
                case 'paddle':
                    error_log('PayFirst DEBUG: Processing Paddle payment');
                    $this->process_paddle_payment($post_data);
                    error_log('PayFirst DEBUG: Paddle payment processing completed');
                    break;
                    
                case 'paystack':
                    $this->process_paystack_payment($post_data);
                    break;
                    
                case 'lemonsqueezy':
                    $this->process_lemonsqueezy_payment($post_data);
                    break;
                    
                default:
                    Alerts::add_error(l('pay.error_message.invalid_payment_processor'));
                    break;
            }
        }
    }

    private function process_stripe_payment($post_data) {
        /* Calculate pricing */
        $payment_frequency = $_POST['payment_frequency'];
        $base_amount = $this->plan->prices->{$payment_frequency}->{currency()};
        $discount_amount = 0;
        $code = null;

        /* Apply discount code if available */
        if($this->code && $this->code->type == 'discount') {
            $discount_amount = $this->code->discount_type == 'percentage' 
                ? ($base_amount * $this->code->discount / 100) 
                : $this->code->discount;
            $code = $this->code->code;
        }

        $total_amount = $base_amount - $discount_amount;
        $stripe_formatted_price = (int) ($total_amount * 100);

        /* Prepare metadata for tracking */
        $stripe_metadata = [
            'plan_id' => $this->plan_id,
            'payment_frequency' => $payment_frequency,
            'base_amount' => $base_amount,
            'code' => $code,
            'discount_amount' => $discount_amount,
            'taxes_ids' => json_encode($this->applied_taxes_ids),
            'pending_registration' => 'true'
        ];

        /* Prepare line item for payment/session */
        $stripe_line_item = [
            'price_data' => [
                'currency' => currency(),
                'product_data' => [
                    'name' => settings()->business->brand_name . ' - ' . $this->plan->name,
                    'description' => l('plan.custom_plan.' . $payment_frequency),
                ],
                'unit_amount' => $stripe_formatted_price
            ],
            'quantity' => 1
        ];

        /* Add recurring interval for subscription if needed */
        if ($_POST['payment_type'] === 'recurring') {
            $payment_frequency_days = match($payment_frequency) {
                'monthly' => 30,
                'quarterly' => 90,
                'biannual' => 180,
                'annual' => 365,
                'lifetime' => 36500,
                default => 30
            };
            
            $stripe_line_item['price_data']['recurring'] = [
                'interval' => 'day',
                'interval_count' => $payment_frequency_days
            ];
        }

        /* Build the Stripe session payload */
        $stripe_session_data = [
            'mode' => $_POST['payment_type'] === 'recurring' ? 'subscription' : 'payment',
            'customer_email' => $post_data['email'],
            'currency' => currency(),
            'line_items' => [ $stripe_line_item ],
            'metadata' => $stripe_metadata,
            'success_url' => url('pay-first/' . $this->plan_id . '?success=1'),
            'cancel_url' => url('pay-first/' . $this->plan_id . '?cancel=1'),
        ];

        /* Add subscription data if payment is recurring */
        if ($_POST['payment_type'] === 'recurring') {
            $stripe_session_data['subscription_data'] = [
                'metadata' => $stripe_metadata
            ];
        }

        try {
            \Stripe\Stripe::setApiKey(settings()->stripe->secret_key);
            $stripe_session = \Stripe\Checkout\Session::create($stripe_session_data);
            
            /* Store pending registration data in database for webhook access */
            db()->insert('pending_registrations', [
                'payment_id' => $stripe_session->id,
                'processor' => 'stripe',
                'registration_data' => json_encode($_SESSION['pending_registration']),
                'datetime' => get_date()
            ]);
            
            redirect($stripe_session->url);
        } catch(\Exception $exception) {
            Alerts::add_error($exception->getMessage());
        }
    }

    private function process_paypal_payment($post_data) {
        /* Get price details */
        $payment_frequency = $_POST['payment_frequency'];
        $base_amount = $this->plan->prices->{$payment_frequency}->{currency()};
        $discount_amount = 0;
        $code = null;

        /* Apply discount code if available */
        if($this->code && $this->code->type == 'discount') {
            $discount_amount = $this->code->discount_type == 'percentage' 
                ? ($base_amount * $this->code->discount / 100) 
                : $this->code->discount;
            $code = $this->code->code;
        }

        $total_amount = $base_amount - $discount_amount;
        $formatted_price = number_format($total_amount, 2, '.', '');

        try {
            /* Prepare PayPal order data */
            $paypal_data = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'amount' => [
                            'currency_code' => currency(),
                            'value' => $formatted_price
                        ],
                        'description' => settings()->business->brand_name . ' - ' . $this->plan->name . ' - ' . l('plan.custom_plan.' . $payment_frequency),
                        'custom_id' => json_encode([
                            'plan_id' => $this->plan_id,
                            'payment_frequency' => $payment_frequency,
                            'base_amount' => $base_amount,
                            'code' => $code,
                            'discount_amount' => $discount_amount,
                            'taxes_ids' => json_encode($this->applied_taxes_ids),
                            'pending_registration' => 'true'
                        ])
                    ]
                ],
                'application_context' => [
                    'return_url' => url('pay-first/' . $this->plan_id . '?success=1'),
                    'cancel_url' => url('pay-first/' . $this->plan_id . '?cancel=1'),
                    'brand_name' => settings()->business->brand_name,
                    'user_action' => 'PAY_NOW'
                ]
            ];

            /* Create PayPal order */
            $paypal_response = \Altum\PaymentGateways\Paypal::create_order($paypal_data);

            if($paypal_response->code >= 400 || !isset($paypal_response->body->id)) {
                $error_message = isset($paypal_response->body->message) 
                    ? $paypal_response->body->message 
                    : 'Unknown error occurred';
                
                Alerts::add_error($error_message);
                return;
            }

            $order_id = $paypal_response->body->id;

            /* Store pending registration data in database for webhook access */
            db()->insert('pending_registrations', [
                'payment_id' => $order_id,
                'processor' => 'paypal',
                'registration_data' => json_encode($_SESSION['pending_registration']),
                'datetime' => get_date()
            ]);

            /* Redirect to PayPal approval URL */
            $approval_url = null;
            foreach($paypal_response->body->links as $link) {
                if($link->rel === 'approve') {
                    $approval_url = $link->href;
                    break;
                }
            }

            if($approval_url) {
                redirect($approval_url);
            } else {
                throw new \Exception('No approval URL received from PayPal');
            }

        } catch(\Exception $exception) {
            Alerts::add_error($exception->getMessage());
        }
    }

    private function process_razorpay_payment($post_data) {
        /* Similar implementation for Razorpay */
        Alerts::add_error('Razorpay payment processing not yet implemented for pay-first flow');
    }

    private function process_mollie_payment($post_data) {
        /* Similar implementation for Mollie */
        Alerts::add_error('Mollie payment processing not yet implemented for pay-first flow');
    }

    private function process_coinbase_payment($post_data) {
        /* Similar implementation for Coinbase */
        Alerts::add_error('Coinbase payment processing not yet implemented for pay-first flow');
    }

    private function process_paddle_payment($post_data) {
        error_log('PayFirst DEBUG: Starting Paddle payment processing');
        error_log('PayFirst DEBUG: Post data: ' . json_encode($post_data));
        
        /* Get price details */
        $payment_frequency = $_POST['payment_frequency'];
        $base_amount = $this->plan->prices->{$payment_frequency}->{currency()};
        $discount_amount = 0;
        $code = null;

        /* Apply discount code if available */
        if($this->code && $this->code->type == 'discount') {
            $discount_amount = $this->code->discount_type == 'percentage' 
                ? ($base_amount * $this->code->discount / 100) 
                : $this->code->discount;
            $code = $this->code->code;
        }

        $total_amount = $base_amount - $discount_amount;
        $formatted_price = number_format($total_amount, 2, '.', '');

        try {
            error_log('PayFirst DEBUG: Creating Paddle product and price');
            $product_name = settings()->business->brand_name . ' - ' . $this->plan->name . ' - ' . l('plan.custom_plan.' . $payment_frequency);
            
            /* Check if product already exists, if not create it */
            $product_id = $this->get_or_create_paddle_product($product_name);
            
            if (!$product_id) {
                throw new \Exception('Failed to create or retrieve product');
            }

            /* Create price for the product */
            $billing_cycle_interval = null;
            if ($payment_frequency === 'monthly') {
                $billing_cycle_interval = 'month';
            } elseif ($payment_frequency === 'annual') {
                $billing_cycle_interval = 'year';
            }
            
            $price_id = $this->get_or_create_paddle_price($product_id, $formatted_price, currency(), $billing_cycle_interval);
            
            if (!$price_id) {
                throw new \Exception('Failed to create or retrieve price');
            }

            /* Prepare transaction items */
            $items = [
                [
                    'price_id' => $price_id,
                    'quantity' => 1
                ]
            ];

            /* Prepare custom data for tracking */
            $custom_data = [
                'plan_id' => $this->plan_id,
                'payment_frequency' => $payment_frequency,
                'base_amount' => $base_amount,
                'code' => $code,
                'discount_amount' => $discount_amount,
                'taxes_ids' => json_encode($this->applied_taxes_ids),
                'pending_registration' => 'true'
            ];

            /* Create transaction with pay-first specific return URL */
            $transaction_response = $this->create_paddle_transaction_pay_first(
                $items,
                $post_data['email'],
                $custom_data
            );

            /* Handle API failure */
            if ($transaction_response->code >= 400 || !isset($transaction_response->body->data)) {
                $error_message = isset($transaction_response->body->error) 
                    ? $transaction_response->body->error->detail 
                    : 'Unknown error occurred';
                
                Alerts::add_error($error_message);
                return;
            }

            /* Store pending registration data in database for webhook access */
            $transaction_id = $transaction_response->body->data->id;
            db()->insert('pending_registrations', [
                'payment_id' => $transaction_id,
                'processor' => 'paddle',
                'registration_data' => json_encode($_SESSION['pending_registration']),
                'datetime' => get_date()
            ]);

            /* Redirect to Paddle's checkout URL from the response */
            if (isset($transaction_response->body->data->checkout->url)) {
                $paddle_checkout_url = $transaction_response->body->data->checkout->url;
                error_log('PayFirst DEBUG: Redirecting to Paddle checkout: ' . $paddle_checkout_url);
                header('Location: ' . $paddle_checkout_url);
                die();
            } else {
                /* Fallback: construct Paddle checkout URL using transaction ID */
                $transaction_id = $transaction_response->body->data->id;
                $paddle_checkout_url = 'https://checkout.paddle.com/transaction/' . $transaction_id;
                error_log('PayFirst DEBUG: Using fallback Paddle checkout URL: ' . $paddle_checkout_url);
                header('Location: ' . $paddle_checkout_url);
                die();
            }

        } catch(\Exception $exception) {
            error_log('PayFirst DEBUG: Paddle payment error: ' . $exception->getMessage());
            Alerts::add_error($exception->getMessage());
        }
    }

    private function get_or_create_paddle_product($product_name) {
        /* For now, we'll create a new product each time */
        /* In production, you might want to cache or store product IDs */
        
        $response = \Altum\PaymentGateways\Paddle::create_product($product_name);
        
        if ($response->code >= 400 || !isset($response->body->data)) {
            return null;
        }
        
        return $response->body->data->id;
    }

    private function get_or_create_paddle_price($product_id, $amount, $currency, $billing_cycle_interval) {
        /* For now, we'll create a new price each time */
        /* In production, you might want to cache or store price IDs */
        
        $response = \Altum\PaymentGateways\Paddle::create_price($product_id, $amount, $currency, $billing_cycle_interval);
        
        if ($response->code >= 400 || !isset($response->body->data)) {
            return null;
        }
        
        return $response->body->data->id;
    }

    private function process_paystack_payment($post_data) {
        /* Similar implementation for Paystack */
        Alerts::add_error('Paystack payment processing not yet implemented for pay-first flow');
    }

    private function process_lemonsqueezy_payment($post_data) {
        /* Similar implementation for LemonSqueezy */
        Alerts::add_error('LemonSqueezy payment processing not yet implemented for pay-first flow');
    }

    public function success() {
        /* Handle successful payment and create account */
        if(!isset($_SESSION['pending_registration'])) {
            redirect('plan');
        }

        $pending_data = $_SESSION['pending_registration'];
        unset($_SESSION['pending_registration']);

        /* Create the account */
        $this->create_account_and_login($pending_data, $pending_data['redirect']);
    }

    public function cancel() {
        /* Handle cancelled payment */
        if(isset($_SESSION['pending_registration'])) {
            unset($_SESSION['pending_registration']);
        }
        
        Alerts::add_error(l('pay_first.error_message.payment_cancelled'));
        redirect('pay-first/' . $this->plan_id);
    }

    private function create_paddle_transaction_pay_first($items, $customer_email, $custom_data = []) {
        /* Use the standard Paddle create_transaction method but with pay-first specific checkout URL */
        $payload = [
            'items' => $items,
            'customer_email' => $customer_email,
            'custom_data' => $custom_data,
            'collection_mode' => 'automatic',
            'currency_code' => currency(),
            'checkout' => [
                'url' => SITE_URL . 'pay-first.php'
            ]
        ];

        $response = \Unirest\Request::post(
            \Altum\PaymentGateways\Paddle::get_api_url() . 'transactions',
            \Altum\PaymentGateways\Paddle::get_headers(),
            \Unirest\Request\Body::json($payload)
        );

        return $response;
    }

    private function handle_paddle_checkout() {
        /* Log all GET parameters for debugging */
        error_log('PayFirst DEBUG: GET parameters: ' . json_encode($_GET));
        
        /* Check if this is a Paddle transaction checkout */
        if(isset($_GET['_ptxn']) && !empty($_GET['_ptxn'])) {
            $transaction_id = $_GET['_ptxn'];
            
            error_log('PayFirst DEBUG: Handling Paddle checkout with transaction ID: ' . $transaction_id);
            
            /* Redirect to Paddle's hosted checkout */
            $paddle_checkout_url = 'https://checkout.paddle.com/transaction/' . $transaction_id;
            error_log('PayFirst DEBUG: Redirecting to Paddle hosted checkout: ' . $paddle_checkout_url);
            header('Location: ' . $paddle_checkout_url);
            die();
        }
    }
}
