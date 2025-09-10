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

class RegisterPay extends Controller {
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

            /* If there are no errors continue the registering process */
            if(!Alerts::has_field_errors() && !Alerts::has_errors()) {

                /* Define some needed variables */
                $active = (int) !settings()->users->email_confirmation;
                $email_code = md5($_POST['email'] . microtime());

                /* Determine what plan is set by default */
                $plan_id = $this->plan_id;
                $plan_settings = json_encode($this->plan->settings ?? '');
                $plan_expiration_date = get_date();

                /* For paid plans, set expiration date based on payment frequency */
                if($this->plan_id != 'free' && isset($_POST['payment_frequency'])) {
                    $payment_frequency = $_POST['payment_frequency'];
                    
                    switch($payment_frequency) {
                        case 'monthly':
                            $plan_expiration_date = (new \DateTime())->modify('+1 month')->format('Y-m-d H:i:s');
                            break;
                        case 'quarterly':
                            $plan_expiration_date = (new \DateTime())->modify('+3 months')->format('Y-m-d H:i:s');
                            break;
                        case 'biannual':
                            $plan_expiration_date = (new \DateTime())->modify('+6 months')->format('Y-m-d H:i:s');
                            break;
                        case 'annual':
                            $plan_expiration_date = (new \DateTime())->modify('+1 year')->format('Y-m-d H:i:s');
                            break;
                        case 'lifetime':
                            $plan_expiration_date = (new \DateTime())->modify('+100 years')->format('Y-m-d H:i:s');
                            break;
                    }
                }

                $registered_user = (new User())->create(
                    $_POST['email'],
                    $_POST['password'],
                    $_POST['name'],
                    (int) !settings()->users->email_confirmation,
                    'direct',
                    $email_code,
                    null,
                    $_POST['is_newsletter_subscribed'],
                    $plan_id,
                    $plan_settings,
                    $plan_expiration_date,
                    settings()->main->default_timezone
                );

                /* Log the action */
                Logger::users($registered_user['user_id'], 'register.success');

                /* If active = 1 then login the user, else send the user an activation email */
                if($active == '1') {

                    /* Set session for the new user */
                    $_SESSION['user_id'] = $registered_user['user_id'];
                    $_SESSION['user_password_hash'] = md5($registered_user['password']);

                    Logger::users($registered_user['user_id'], 'login.success');

                    /* If it's a free plan, redirect to dashboard */
                    if($this->plan_id == 'free') {
                        /* Send a welcome email if needed */
                        if(settings()->users->welcome_email_is_enabled) {
                            $email_template = get_email_template(
                                [],
                                l('global.emails.user_welcome.subject'),
                                [
                                    '{{NAME}}' => $_POST['name'],
                                    '{{URL}}' => url(),
                                    '{{DASHBOARD_LINK}}' => url('dashboard'),
                                ],
                                l('global.emails.user_welcome.body')
                            );

                            send_mail($_POST['email'], $email_template->subject, $email_template->body);
                        }

                        /* Send notification to admin if needed */
                        if(settings()->email_notifications->new_user && !empty(settings()->email_notifications->emails)) {
                            $email_template = get_email_template(
                                [],
                                l('global.emails.admin_new_user_notification.subject'),
                                [
                                    '{{NAME}}' => str_replace('.', '. ', $_POST['name']),
                                    '{{EMAIL}}' => $_POST['email'],
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
                        redirect($redirect . '&welcome=' . $registered_user['user_id']);
                    } else {
                        /* For paid plans, process payment */
                        $this->process_payment($registered_user, $_POST);
                    }
                } else {
                    /* Send activation email */
                    $email_template = get_email_template(
                        [
                            '{{NAME}}' => str_replace('.', '. ', $_POST['name']),
                        ],
                        l('global.emails.user_activation.subject'),
                        [
                            '{{ACTIVATION_LINK}}' => url('activate-user?email=' . md5($_POST['email']) . '&email_activation_code=' . $email_code . '&type=user_activation' . '&redirect=' . $redirect),
                            '{{NAME}}' => str_replace('.', '. ', $_POST['name']),
                        ],
                        l('global.emails.user_activation.body')
                    );

                    send_mail($_POST['email'], $email_template->subject, $email_template->body);

                    Alerts::add_success(l('register.success_message.registration'));
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

        $view = new \Altum\View('register-pay/index', (array) $this);

        $this->add_view_content('content', $view->run($data));
    }

    private function process_payment($user, $post_data) {
        /* This method will handle the payment processing */
        /* For now, we'll redirect to the regular pay page with the user logged in */
        
        /* Validate required payment fields */
        if(!isset($post_data['payment_frequency']) || !isset($post_data['payment_processor']) || !isset($post_data['payment_type'])) {
            Alerts::add_error(l('register_pay.error_message.missing_payment_data'));
            return;
        }
        
        /* Redirect to the regular pay page with the user logged in */
        redirect('pay/' . $this->plan_id . '?payment_frequency=' . $post_data['payment_frequency'] . '&payment_processor=' . $post_data['payment_processor'] . '&payment_type=' . $post_data['payment_type']);
    }
}
