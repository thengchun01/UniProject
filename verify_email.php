<?php
/**
 * Account activation — enter the 6-digit code emailed by send_verification_code().
 *
 * Reached from two places:
 *   - registration in account.php, which sends the first code and records the
 *     pending account in the session;
 *   - the login gate in account.php, when a correct password belongs to an
 *     account that is not activated yet.
 *
 * With no pending account (for example after the session expired) it falls back
 * to asking for the email address before sending a code.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/verification_mail.php';

if (is_logged_in()) {
    redirect_to('account.php');
}

$error = null;

// Activation by the link in the emailed message.
$linkToken = trim((string) ($_GET['token'] ?? ''));

if ($linkToken !== '') {
    if (!is_database_connected()) {
        $error = 'Account activation needs the PSM database connection.';
    } else {
        psm_cancel_expired_registrations();

        $tokenResult = psm_verify_email_token($linkToken);

        if (!empty($tokenResult['success'])) {
            if (($tokenResult['user_id'] ?? null) !== null) {
                psm_mark_email_verified((int) $tokenResult['user_id']);
            }

            unset(
                $_SESSION['pending_verify_user_id'],
                $_SESSION['pending_verify_email'],
                $_SESSION['pending_verify_username']
            );

            flash_set('success', 'Your account is activated. Please log in.');
            redirect_to('account.php?mode=login');
        }

        $error = (string) ($tokenResult['error'] ?? 'That activation link could not be used.');
    }
}
$hasPendingSession = isset($_SESSION['pending_verify_user_id']);
$pendingUserId = $hasPendingSession ? (int) $_SESSION['pending_verify_user_id'] : null;
$pendingEmail = trim((string) ($_SESSION['pending_verify_email'] ?? ''));
$pendingUsername = trim((string) ($_SESSION['pending_verify_username'] ?? ''));

// The activation link carries the address and the code, so opening it fills the
// form in and the visitor only has to press the button.
$manualEmail = trim((string) ($_GET['email'] ?? ''));
$code = trim((string) ($_GET['code'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['verify_action'] ?? '');
    $manualEmail = trim((string) ($_POST['verify_email'] ?? ''));
    $code = trim((string) ($_POST['verify_code'] ?? ''));

    // An account abandoned past the grace period must not be resendable.
    psm_cancel_expired_registrations();

    if (!is_database_connected()) {
        $error = 'Account activation needs the PSM database connection.';
    } elseif ($action === 'verify') {
        // Without a session the account is identified by the submitted address.
        $targetEmail = $pendingEmail !== '' ? $pendingEmail : $manualEmail;
        $result = psm_verify_email_code($targetEmail, $code, $pendingUserId);

        if (!empty($result['success'])) {
            $verifiedUserId = $result['user_id'] ?? $pendingUserId;

            if ($verifiedUserId !== null) {
                psm_mark_email_verified((int) $verifiedUserId);
            }

            unset(
                $_SESSION['pending_verify_user_id'],
                $_SESSION['pending_verify_email'],
                $_SESSION['pending_verify_username']
            );

            flash_set('success', 'Your account is activated. Please log in.');
            redirect_to('account.php?mode=login');
        }

        $error = (string) ($result['error'] ?? 'That code could not be verified.');
    } elseif ($action === 'resend') {
        $targetEmail = $pendingEmail !== '' ? $pendingEmail : $manualEmail;
        $targetUserId = $pendingUserId;

        if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter the email address you registered with.';
        } else {
            if ($targetUserId === null) {
                // Email is only a fallback: users.email has no unique constraint.
                $lookup = db()->prepare(
                    'SELECT user_id FROM users
                      WHERE email = :email AND email_verified_at IS NULL
                      ORDER BY user_id DESC
                      LIMIT 1'
                );
                $lookup->execute(['email' => $targetEmail]);
                $found = $lookup->fetchColumn();
                $targetUserId = $found === false ? null : (int) $found;
            }

            if ($targetUserId === null) {
                // Neutral reply: never reveal whether an address has an account.
                flash_set('success', 'If that address has an unactivated account, a new code has been sent to it.');
                redirect_to('verify_email.php');
            }

            $state = db()->prepare('SELECT email, username, email_verified_at FROM users WHERE user_id = :id');
            $state->execute(['id' => $targetUserId]);
            $account = $state->fetch();

            if (!$account) {
                $error = 'That account could not be found.';
            } elseif (!empty($account['email_verified_at'])) {
                unset(
                    $_SESSION['pending_verify_user_id'],
                    $_SESSION['pending_verify_email'],
                    $_SESSION['pending_verify_username']
                );
                flash_set('success', 'That account is already activated. Please log in.');
                redirect_to('account.php?mode=login');
            } elseif (!psm_mail_is_configured()) {
                $error = 'Email sending is not configured, so no code can be sent right now.';
            } else {
                $accountEmail = trim((string) ($account['email'] ?? ''));
                $accountName = (string) ($account['username'] ?? '');
                $result = send_verification_code($accountEmail, $accountName, $targetUserId);

                if (!empty($result['success'])) {
                    session_regenerate_id(true);
                    $_SESSION['pending_verify_user_id'] = $targetUserId;
                    $_SESSION['pending_verify_email'] = $accountEmail;
                    $_SESSION['pending_verify_username'] = $accountName;
                    flash_set('success', 'A new code was sent to ' . $accountEmail . '.');
                    redirect_to('verify_email.php');
                }

                $error = (string) ($result['error'] ?? 'The code could not be sent. Please try again.');
            }
        }
    }
}

// Address to show and pre-fill: the pending account, else the one in the link.
$displayEmail = $pendingEmail !== '' ? $pendingEmail : $manualEmail;

include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="<?= e(url_path('assets/css/account.css')); ?>">

<div class="account-page-content">
    <?php if ($message = flash_get('error')): ?>
        <div class="alert error"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($message = flash_get('success')): ?>
        <div class="alert success"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="account-auth-layout">
        <section class="account-auth-intro">
            <p class="eyebrow">One step left</p>
            <h1>Activate your account.</h1>
            <?php if ($displayEmail !== ''): ?>
                <p>We emailed an activation code to <strong><?php echo e($displayEmail); ?></strong>. Open the button in that email — the code is filled in for you — or type the 6-digit code here.</p>
            <?php else: ?>
                <p>Enter the email address you registered with and the 6-digit code from your activation email.</p>
            <?php endif; ?>
            <div class="auth-benefits" aria-label="Activation notes">
                <span>Links and codes last <?php echo (int) psm_verification_grace_hours(); ?> hours</span>
                <span>Five attempts per code</span>
                <span>Request a new link any time</span>
            </div>
        </section>

        <div class="account-auth-form">
            <div class="account-auth-card">
                <section class="auth-panel" id="verify-code-panel">
                    <h2>Activate your account</h2>
                    <p class="auth-panel-copy"><?php echo $hasPendingSession
                        ? 'Type the 6-digit code from your activation email.'
                        : 'Enter the email address you registered with, then the 6-digit code from your activation email.'; ?></p>

                    <form class="form-panel compact account-activation-form" method="post" action="<?php echo e(url_path('verify_email.php')); ?>">
                        <?php if (!$hasPendingSession): ?>
                        <label>
                            Email
                            <input type="email" name="verify_email" value="<?php echo e($displayEmail); ?>" maxlength="150" autocomplete="email" required>
                        </label>
                        <?php endif; ?>

                        <label>
                            6-digit code
                            <input type="text" name="verify_code" value="<?php echo e($code); ?>" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
                        </label>

                        <button type="submit" name="verify_action" value="verify">Activate Account</button>
                        <button type="submit" name="verify_action" value="resend" class="secondary-btn">Send a new activation link</button>
                    </form>
                </section>

                <p class="auth-panel-links">
                    Wrong address, or already activated?
                    <a href="<?php echo e(url_path('account.php?mode=login')); ?>">Back to login</a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
