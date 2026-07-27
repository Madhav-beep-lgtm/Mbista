<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/two_factor.php';

/**
 * The second half of signing in.
 *
 * Reached only from login.php, which has already checked the password and then
 * DEMOTED the session to a pending state — $_SESSION['user_id'] is gone, so
 * nothing in the app treats this visitor as signed in while they are here.
 */

$pageTitle = 'Two-factor authentication';
$bodyClass = 'auth-page';

// Already through? Nothing to do here.
if (current_user()) {
    redirect(role_home_path(current_user()));
}

$pendingUserId = two_factor_pending_user_id();
if ($pendingUserId <= 0) {
    // No pending challenge, or it expired. Back to the start — and say so,
    // rather than showing a form that cannot work.
    flash('error', 'Your sign-in timed out. Please enter your password again.');
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // The challenge is brute-forceable in a way the password is not: it is six
    // digits. It uses the SAME throttle the login form does, keyed on its own
    // event type so a locked-out code does not also lock out the password.
    if (login_is_throttled(null, 'two_factor_failed')) {
        security_event('two_factor_throttled', 'denied', 'Too many failed second-factor attempts.', null, $pendingUserId);
        two_factor_clear_pending();
        flash('error', 'Too many attempts. Please sign in again in a few minutes.');
        redirect('login.php');
    }

    $result = two_factor_verify($pendingUserId, (string) ($_POST['code'] ?? ''));
    if (!$result['ok']) {
        security_event('two_factor_failed', 'failure', $result['error'], null, $pendingUserId);
        flash('error', $result['error']);
        redirect('two-factor.php');
    }

    $remember = !empty($_SESSION['2fa_remember']);
    unset($_SESSION['2fa_remember']);
    two_factor_complete_pending($pendingUserId);

    $user = current_user();
    security_event('two_factor_passed', 'success',
        !empty($result['used_recovery']) ? 'Signed in with a recovery code.' : 'Second factor accepted.',
        (int) ($user['company_id'] ?? 0) ?: null, $pendingUserId);

    if ($remember) {
        setcookie(session_name(), session_id(), [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    if (!empty($result['used_recovery'])) {
        $left = (int) ($result['remaining'] ?? 0);
        flash('success', 'Signed in with a recovery code. ' . $left . ' left — generate a new set from your profile.');
    } else {
        flash('success', 'Welcome back, ' . ($user['name'] ?? 'user') . '.');
    }
    redirect(role_home_path($user));
}

include __DIR__ . '/../app/views/partials/header.php';
?>
<div class="auth2-wrap">
    <div class="auth2-card">
        <div class="auth2-head">
            <h1>Two-factor authentication</h1>
            <p>Enter the 6-digit code from your authenticator app.</p>
        </div>
        <?= flash_messages() ?>
        <form method="post" class="auth2-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label class="auth2-field">
                <span>Authentication code</span>
                <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                       pattern="[0-9A-Za-z\-]{6,11}" maxlength="11" required autofocus
                       placeholder="123456">
            </label>
            <button type="submit" class="auth2-submit"><?= icon('login') ?>Verify</button>
        </form>
        <div class="auth2-links">
            <a href="<?= e(url('login.php')) ?>">Sign in as someone else</a>
        </div>
        <p class="auth2-note">Lost your phone? Enter one of your recovery codes instead.</p>
    </div>
</div>
<?php include __DIR__ . '/../app/views/partials/footer.php'; ?>
