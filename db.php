<?php
define('DB_HOST', 'localhost:3307');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'lorens');

$database = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($database->connect_error) {
    die("Connection failed: " . $database->connect_error);
}
