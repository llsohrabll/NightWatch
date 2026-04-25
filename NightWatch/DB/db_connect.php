<?php
require_once(dirname(__DIR__) . '/functions/common.php');
require_db_config_file();

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
?>
