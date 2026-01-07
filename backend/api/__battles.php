<?php
echo 'this is the stuff';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_log('battles.php accessed - ' . date('Y-m-d H:i:s'));

echo ' got this far';
?>