<?php
session_start();
$_SESSION = [];
session_destroy();
header('Location: /share/login.php');
exit;
