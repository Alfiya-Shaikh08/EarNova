<?php
// Clears the current login session and returns the visitor to the public homepage.
session_start();

$_SESSION = [];
session_destroy();

header('Location: ../homepage.php');
exit;
