<?php
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity_log.php';

ensure_default_superadmin($pdo);
