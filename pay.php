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
            
            // Open Paddle checkout with the transaction
            Paddle.Checkout.open({
                transactionId: transactionId
            });
        } else {
            // No transaction ID, redirect to home
            window.location.href = '<?php echo SITE_URL; ?>';
        }
    </script>
</body>
</html>
