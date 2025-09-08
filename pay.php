<?php
/* Load the application to access settings */
require_once realpath(__DIR__) . '/app/init.php';
$App = new Altum\App();

/* Debug logging */
$log_entry = '[' . date('Y-m-d H:i:s') . '] - PAY_PHP_ACCESSED - URL: ' . $_SERVER['REQUEST_URI'] . ' - GET params: ' . json_encode($_GET) . PHP_EOL;
file_put_contents('payment_errors.log', $log_entry, FILE_APPEND | LOCK_EX);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Processing - Paddle Checkout</title>
    <script src="https://cdn.paddle.com/paddle/paddle.js"></script>
</head>
<body>
    <div id="checkout-container">
        <h2>Processing your payment...</h2>
        <p>Please wait while we redirect you to the secure payment page.</p>
    </div>

    <script>
        // Initialize Paddle
        Paddle.Setup({
            vendor: <?php echo json_encode(settings()->paddle->vendor_id); ?>,
            environment: <?php echo json_encode(settings()->paddle->mode == 'live' ? 'production' : 'sandbox'); ?>
        });

        // Check if _ptxn parameter is present
        const urlParams = new URLSearchParams(window.location.search);
        const transactionId = urlParams.get('_ptxn');
        
        if (transactionId) {
            // Log for debugging
            console.log('Paddle transaction ID:', transactionId);
            
            // Add a small delay to ensure Paddle is fully loaded
            setTimeout(function() {
                try {
                    // Open Paddle checkout with the transaction
                    Paddle.Checkout.open({
                        transactionId: transactionId
                    });
                } catch (error) {
                    console.error('Paddle checkout error:', error);
                    // Fallback: redirect to Paddle's direct checkout URL
                    window.location.href = 'https://checkout.paddle.com/transaction/' + transactionId;
                }
            }, 1000);
        } else {
            // No transaction ID, redirect to home
            console.log('No transaction ID found, redirecting to home');
            window.location.href = '<?php echo SITE_URL; ?>';
        }
    </script>
</body>
</html>
