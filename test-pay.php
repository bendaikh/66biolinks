<?php
/* Simple test file to check if direct access works */
$log_entry = '[' . date('Y-m-d H:i:s') . '] - TEST_PAY_ACCESSED - URL: ' . $_SERVER['REQUEST_URI'] . ' - GET params: ' . json_encode($_GET) . PHP_EOL;
file_put_contents('payment_errors.log', $log_entry, FILE_APPEND | LOCK_EX);

echo "Test file accessed successfully!";
echo "<br>URL: " . $_SERVER['REQUEST_URI'];
echo "<br>GET params: " . json_encode($_GET);
?>
