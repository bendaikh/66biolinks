<?php
/*
 * Paddle Checkout Handler
 * This file handles Paddle checkout URLs with _ptxn parameter
 */

// Include the main application
require_once __DIR__ . '/app/init.php';

// Check if _ptxn parameter is present
if (isset($_GET['_ptxn']) && !empty($_GET['_ptxn'])) {
    $transaction_id = $_GET['_ptxn'];
    
    // Log the transaction ID for debugging
    $log_entry = '[' . date('Y-m-d H:i:s') . '] - PADDLE_CHECKOUT_HANDLER - Transaction ID: ' . $transaction_id . PHP_EOL;
    file_put_contents('payment_errors.log', $log_entry, FILE_APPEND | LOCK_EX);
    
    // Redirect to Paddle's hosted checkout
    $paddle_checkout_url = 'https://checkout.paddle.com/transaction/' . $transaction_id;
    header('Location: ' . $paddle_checkout_url);
    die();
} else {
    // No _ptxn parameter, redirect to home
    header('Location: ' . SITE_URL);
    die();
}
?>
