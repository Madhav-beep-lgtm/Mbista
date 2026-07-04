<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$user = current_user();
$companyIdForContact = (int) (($user['company_id'] ?? 0) ?: current_company_id());

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('contact/index.php');
}

verify_csrf();

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$subject = trim((string) ($_POST['subject'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

if ($name === '' || $email === '' || $subject === '' || $message === '') {
    flash('error', 'Please complete every contact field.');
    redirect('contact/general-enquiry.php');
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    flash('error', 'Please provide a valid email address.');
    redirect('contact/general-enquiry.php');
}

save_contact([
    'company_id' => $companyIdForContact > 0 ? $companyIdForContact : null,
    'name' => $name,
    'email' => $email,
    'subject' => $subject,
    'message' => $message,
]);

flash('success', 'Thanks. Your message has been saved.');
redirect('contact/index.php');
