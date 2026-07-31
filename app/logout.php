<?php
require_once __DIR__ . '/includes/auth.php';
fmLogout();
header('Location: login.php');
exit;
