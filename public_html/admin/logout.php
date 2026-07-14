<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

logout_user();
flash('success', 'Admin session closed.');
redirect('login.php');
