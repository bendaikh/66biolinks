<?php
/* Debug logging - log immediately when file is accessed */
$log_entry = '[' . date('Y-m-d H:i:s') . '] - PAY_PHP_ACCESSED - URL: ' . $_SERVER['REQUEST_URI'] . ' - GET params: ' . json_encode($_GET) . PHP_EOL;
file_put_contents('payment_errors.log', $log_entry, FILE_APPEND | LOCK_EX);

/* Load config to get Paddle settings */
require_once realpath(__DIR__) . '/config.php';

/* Get Paddle settings from config */
$paddle_vendor_id = '244508'; // Your Paddle vendor ID
$paddle_environment = 'sandbox'; // Change to 'production' for live
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Processing - Paddle Checkout</title>
</head>
<body>
    <div id="checkout-container">
        <h2>Processing your payment...</h2>
        <p>Please wait while we redirect you to the secure payment page.</p>
    </div>

    <script>
        // Check if _ptxn parameter is present
        const urlParams = new URLSearchParams(window.location.search);
        const transactionId = urlParams.get('_ptxn');
        
        if (transactionId) {
            // Log for debugging
            console.log('Paddle transaction ID:', transactionId);
            
            // For new Paddle Billing, we need to use the correct checkout URL
            // The new Paddle Billing system uses a different URL format
            // Let's try the correct format for the new system
            const checkoutUrl = 'https://checkout.paddle.com/checkout/' + transactionId;
            console.log('Redirecting to checkout URL:', checkoutUrl);
            window.location.href = checkoutUrl;
        } else {
            // No transaction ID, redirect to home
            console.log('No transaction ID found, redirecting to home');
            window.location.href = '<?php echo SITE_URL; ?>';
        }
    </script>
</body>
</html>
