<?php
require_once __DIR__ . '/config.php';
session_name('dso_admin');
session_start();
session_destroy();
header('Location: index.php');
exit;
