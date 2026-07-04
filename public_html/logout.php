<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

logout_user();
flash('success', 'You have been logged out.');
redirect('index.php');
