<?php defined('ALTUMCODE') || die() ?>

<?= \Altum\Alerts::output_alerts() ?>

<div class="row">
    <div class="col-lg-6">
        <!-- Plan Information -->
        <div class="plan-highlight">
            <h3 class="h4 mb-2"><?= $data->plan->translations->{\Altum\Language::$name}->name ?: $data->plan->name ?></h3>
            <div class="h2 mb-2">
                <?php if($data->plan_id == 'free'): ?>
                    <?= $data->plan->translations->{\Altum\Language::$name}->price ?: $data->plan->price ?>
                <?php else: ?>
                    <span class="plan-price-amount"><?= nr($data->plan->prices->monthly->{currency()}, 2) ?> <?= currency() ?></span>
                    <small class="text-white-50">/ <?= l('plan.frequency.monthly') ?></small>
                <?php endif ?>
            </div>
            <p class="mb-0 opacity-75"><?= $data->plan->translations->{\Altum\Language::$name}->description ?: $data->plan->description ?></p>
        </div>

        <!-- Registration Form -->
        <div class="form-section">
            <h4><i class="fas fa-user-plus fa-fw me-2"></i><?= l('register_pay.create_account') ?></h4>
            
            <form method="post" role="form">
                <input type="hidden" name="token" value="<?= \Altum\Csrf::get() ?>" />
                
                <div class="form-group mb-3">
                    <label for="name"><?= l('register.form.name') ?> <span class="text-danger">*</span></label>
                    <input type="text" id="name" name="name" class="form-control form-control-lg <?= \Altum\Alerts::has_field_errors('name') ? 'is-invalid' : null ?>" value="<?= $data->values['name'] ?>" required="required" autocomplete="name" />
                    <?= \Altum\Alerts::output_field_error('name') ?>
                </div>

                <div class="form-group mb-3">
                    <label for="email"><?= l('register.form.email') ?> <span class="text-danger">*</span></label>
                    <input type="email" id="email" name="email" class="form-control form-control-lg <?= \Altum\Alerts::has_field_errors('email') ? 'is-invalid' : null ?>" value="<?= $data->values['email'] ?>" required="required" autocomplete="email" />
                    <?= \Altum\Alerts::output_field_error('email') ?>
                </div>

                <div class="form-group mb-3">
                    <label for="password"><?= l('register.form.password') ?> <span class="text-danger">*</span></label>
                    <input type="password" id="password" name="password" class="form-control form-control-lg <?= \Altum\Alerts::has_field_errors('password') ? 'is-invalid' : null ?>" value="<?= $data->values['password'] ?>" required="required" autocomplete="new-password" />
                    <?= \Altum\Alerts::output_field_error('password') ?>
                </div>

                <?php if(settings()->users->register_display_newsletter_checkbox): ?>
                    <div class="form-group mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_newsletter_subscribed" id="is_newsletter_subscribed" value="1" <?= $data->values['is_newsletter_subscribed'] ? 'checked="checked"' : null ?>>
                            <label class="form-check-label" for="is_newsletter_subscribed">
                                <?= l('register.form.newsletter') ?>
                            </label>
                        </div>
                    </div>
                <?php endif ?>

                <?php if(settings()->captcha->register_is_enabled): ?>
                    <div class="form-group mb-3">
                        <?php $data->captcha->display() ?>
                    </div>
                <?php endif ?>

                <?php if($data->plan_id != 'free'): ?>
                    <!-- Payment Options -->
                    <div class="form-section">
                        <h4><i class="fas fa-credit-card fa-fw me-2"></i><?= l('register_pay.payment_options') ?></h4>
                        
                        <!-- Payment Frequency -->
                        <div class="form-group mb-3">
                            <label><?= l('register_pay.frequency') ?> <span class="text-danger">*</span></label>
                            <div class="payment-options">
                                <?php if($data->plan->prices->monthly->{currency()}): ?>
                                    <div class="payment-option" data-frequency="monthly">
                                        <input type="radio" name="payment_frequency" value="monthly" id="frequency_monthly" class="d-none" required>
                                        <label for="frequency_monthly" class="w-100">
                                            <div class="fw-bold"><?= l('register_pay.frequency.monthly') ?></div>
                                            <div class="text-muted"><?= nr($data->plan->prices->monthly->{currency()}, 2) ?> <?= currency() ?></div>
                                        </label>
                                    </div>
                                <?php endif ?>
                                
                                <?php if($data->plan->prices->annual->{currency()}): ?>
                                    <div class="payment-option" data-frequency="annual">
                                        <input type="radio" name="payment_frequency" value="annual" id="frequency_annual" class="d-none" required>
                                        <label for="frequency_annual" class="w-100">
                                            <div class="fw-bold"><?= l('register_pay.frequency.annual') ?></div>
                                            <div class="text-muted"><?= nr($data->plan->prices->annual->{currency()}, 2) ?> <?= currency() ?></div>
                                        </label>
                                    </div>
                                <?php endif ?>
                                
                                <?php if($data->plan->prices->lifetime->{currency()}): ?>
                                    <div class="payment-option" data-frequency="lifetime">
                                        <input type="radio" name="payment_frequency" value="lifetime" id="frequency_lifetime" class="d-none" required>
                                        <label for="frequency_lifetime" class="w-100">
                                            <div class="fw-bold"><?= l('register_pay.frequency.lifetime') ?></div>
                                            <div class="text-muted"><?= nr($data->plan->prices->lifetime->{currency()}, 2) ?> <?= currency() ?></div>
                                        </label>
                                    </div>
                                <?php endif ?>
                            </div>
                        </div>

                        <!-- Payment Processor -->
                        <div class="form-group mb-3">
                            <label><?= l('register_pay.processor') ?> <span class="text-danger">*</span></label>
                            <div class="payment-options">
                                <?php foreach($data->payment_processors as $key => $processor): ?>
                                    <?php if(settings()->{$key}->is_enabled && in_array(currency(), settings()->{$key}->currencies ?? [])): ?>
                                        <div class="payment-option" data-processor="<?= $key ?>">
                                            <input type="radio" name="payment_processor" value="<?= $key ?>" id="processor_<?= $key ?>" class="d-none" required>
                                            <label for="processor_<?= $key ?>" class="w-100 d-flex align-items-center">
                                                <i class="<?= $processor['icon'] ?> fa-fw me-2" style="color: <?= $processor['color'] ?>"></i>
                                                <div class="fw-bold"><?= l('pay.custom_plan.' . $key) ?></div>
                                            </label>
                                        </div>
                                    <?php endif ?>
                                <?php endforeach ?>
                            </div>
                        </div>

                        <!-- Payment Type -->
                        <div class="form-group mb-3">
                            <label><?= l('register_pay.type') ?> <span class="text-danger">*</span></label>
                            <div class="payment-options">
                                <div class="payment-option" data-type="one_time">
                                    <input type="radio" name="payment_type" value="one_time" id="type_one_time" class="d-none" required>
                                    <label for="type_one_time" class="w-100">
                                        <div class="fw-bold"><?= l('register_pay.type.one_time') ?></div>
                                        <div class="text-muted small"><?= l('register_pay.type.one_time_help') ?></div>
                                    </label>
                                </div>
                                <div class="payment-option" data-type="recurring">
                                    <input type="radio" name="payment_type" value="recurring" id="type_recurring" class="d-none" required>
                                    <label for="type_recurring" class="w-100">
                                        <div class="fw-bold"><?= l('register_pay.type.recurring') ?></div>
                                        <div class="text-muted small"><?= l('register_pay.type.recurring_help') ?></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif ?>

                <div class="d-grid">
                    <button type="submit" name="submit" class="btn btn-register-pay btn-lg text-white">
                        <?php if($data->plan_id == 'free'): ?>
                            <i class="fas fa-user-plus fa-fw me-2"></i><?= l('register_pay.create_free_account') ?>
                        <?php else: ?>
                            <i class="fas fa-credit-card fa-fw me-2"></i><?= l('register_pay.register_and_pay') ?>
                        <?php endif ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-6">
        <!-- Plan Features -->
        <div class="form-section">
            <h4><i class="fas fa-star fa-fw me-2"></i><?= l('register_pay.plan_features') ?></h4>
            <?= include_view(THEME_PATH . 'views/partials/plans_plan_content.php', ['plan_settings' => $data->plan->settings]) ?>
        </div>

        <!-- Security & Trust -->
        <div class="form-section">
            <h4><i class="fas fa-shield-alt fa-fw me-2"></i><?= l('register_pay.security') ?></h4>
            <div class="row text-center">
                <div class="col-4">
                    <i class="fas fa-lock fa-2x text-success mb-2"></i>
                    <div class="small"><?= l('register_pay.secure_payment') ?></div>
                </div>
                <div class="col-4">
                    <i class="fas fa-user-shield fa-2x text-success mb-2"></i>
                    <div class="small"><?= l('register_pay.privacy_protected') ?></div>
                </div>
                <div class="col-4">
                    <i class="fas fa-headset fa-2x text-success mb-2"></i>
                    <div class="small"><?= l('register_pay.support') ?></div>
                </div>
            </div>
        </div>

        <!-- Already have account -->
        <div class="text-center">
            <p class="text-muted"><?= l('register_pay.already_have_account') ?></p>
            <a href="<?= url('login') ?>" class="btn btn-outline-primary"><?= l('login.title') ?></a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle payment option selection
    document.querySelectorAll('.payment-option').forEach(function(option) {
        option.addEventListener('click', function() {
            const input = this.querySelector('input[type="radio"]');
            if (input) {
                // Remove selected class from siblings
                const siblings = this.parentNode.querySelectorAll('.payment-option');
                siblings.forEach(sibling => sibling.classList.remove('selected'));
                
                // Add selected class to current option
                this.classList.add('selected');
                
                // Check the radio button
                input.checked = true;
            }
        });
    });

    // Auto-select first payment frequency if available
    const firstFrequency = document.querySelector('input[name="payment_frequency"]');
    if (firstFrequency) {
        firstFrequency.checked = true;
        firstFrequency.closest('.payment-option').classList.add('selected');
    }

    // Auto-select first payment processor if available
    const firstProcessor = document.querySelector('input[name="payment_processor"]');
    if (firstProcessor) {
        firstProcessor.checked = true;
        firstProcessor.closest('.payment-option').classList.add('selected');
    }

    // Auto-select one-time payment type
    const oneTimeType = document.querySelector('input[name="payment_type"][value="one_time"]');
    if (oneTimeType) {
        oneTimeType.checked = true;
        oneTimeType.closest('.payment-option').classList.add('selected');
    }
});
</script>
