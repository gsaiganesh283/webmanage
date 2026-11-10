<?php
require __DIR__ . '/includes/bootstrap.php';

start_session();
$_SESSION = [];
session_destroy();
redirect('login.php');
