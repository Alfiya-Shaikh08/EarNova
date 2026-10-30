<?php
// Creates the shared MySQLi connection used by pages that access the user database.
mysqli_report(MYSQLI_REPORT_OFF);

$host = 'localhost';
$username = 'root';
$dbPassword = '';
$database = 'user_database';

$conn = @new mysqli($host, $username, $dbPassword, $database);
if ($conn->connect_error) {
    die('Database connection failed. Import database.sql in phpMyAdmin and start MySQL in XAMPP.');
}

$conn->set_charset('utf8mb4');
