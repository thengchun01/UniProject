<?php
/**
 * Email verification codes: generation, storage and Gmail delivery.
 *
 * Generates a numeric verification code, stores only a hash of it, and emails
 * it through Gmail SMTP with a Google app password. Mail settings come from
 * includes/config.php (PSM_MAIL_*).
 *
 * Call send_verification_code() from includes/config.php rather than using this
 * file directly, so the SMTP client is only loaded when mail is actually sent.
 *
 * Storage: the email_verification table (see database/psm_schema.sql).
 */

/** How long an unactivated account, its activation link and its code survive. */
function psm_verification_grace_hours(): int
{
    $hours = (int) PSM_VERIFICATION_GRACE_HOURS;

    return $hours > 0 ? $hours : 24;
}

/** How long a freshly issued code and activation link stay valid, in seconds. */
function psm_verification_ttl(): int
{
    return psm_verification_grace_hours() * 3600;
}

/** How many wrong guesses one code tolerates before it is burned. */
function psm_verification_max_attempts(): int
{
    return 5;
}

/** Minimum seconds between two codes for the same address. */
function psm_verification_cooldown(): int
{
    return 60;
}

/**
 * A 256-bit activation token, sent inside the activation link.
 *
 * Only its SHA-256 hash is stored: the token is high-entropy random data, so a
 * fast hash is the right tool, unlike the 6-digit code which needs the slow
 * password_hash().
 */
function psm_generate_verification_token(): string
{
    return bin2hex(random_bytes(32));
}

function psm_verification_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * The absolute link that activates an account.
 *
 * It carries the single-use token, which activates in one click, plus the email
 * address and code so the activation page opens with the code already filled in.
 * That matters when the token is no longer usable — expired, already used, or
 * the page was reached on a different browser where no session is pending.
 */
function psm_verification_link(string $token, string $email = '', string $code = ''): string
{
    $url = psm_site_url() . '/verify_email.php?token=' . rawurlencode($token);

    if ($email !== '') {
        $url .= '&email=' . rawurlencode($email);
    }

    if ($code !== '') {
        $url .= '&code=' . rawurlencode($code);
    }

    return $url;
}

/**
 * A cryptographically random numeric code, zero-padded to $digits.
 */
function psm_generate_verification_code(int $digits = 6): string
{
    $digits = max(4, min(10, $digits));
    $max = (10 ** $digits) - 1;

    return str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
}

/**
 * Seconds the caller must still wait before another code may be sent, or 0.
 */
function psm_verification_cooldown_remaining(string $email): int
{
    // Elapsed time is measured by the database, against the same clock that
    // wrote created_at.
    $stmt = db()->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM email_verification
          WHERE email = :email
          ORDER BY verification_id DESC
          LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $elapsed = $stmt->fetchColumn();

    if ($elapsed === false || $elapsed === null) {
        return 0;
    }

    $elapsed = max(0, (int) $elapsed);
    $cooldown = psm_verification_cooldown();

    return $elapsed >= $cooldown ? 0 : $cooldown - $elapsed;
}

/**
 * Replace any live code for $email with a hash of $code.
 */
function psm_store_verification_code(string $email, string $code, ?int $userId = null, ?string $token = null): void
{
    db()->prepare(
        'UPDATE email_verification
            SET consumed_at = CURRENT_TIMESTAMP
          WHERE email = :email AND consumed_at IS NULL'
    )->execute(['email' => $email]);

    // The expiry is computed by the database so that every timestamp in this
    // table comes from one clock: PHP's date.timezone and MySQL's session time
    // zone can differ by hours.
    $ttl = (int) psm_verification_ttl(); // internal constant, never user input

    $stmt = db()->prepare(
        'INSERT INTO email_verification (email, user_id, code_hash, token_hash, attempts, expires_at)
         VALUES (:email, :user_id, :code_hash, :token_hash, 0, DATE_ADD(NOW(), INTERVAL ' . $ttl . ' SECOND))'
    );
    $stmt->execute([
        'email' => $email,
        'user_id' => $userId,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'token_hash' => $token !== null && $token !== '' ? psm_verification_token_hash($token) : null,
    ]);
}

/**
 * Mark an account as email-verified (its activation is complete).
 *
 * Used after a code is accepted, and directly for accounts that must not be
 * gated: those created by staff, and those whose verification email could not
 * be delivered.
 */
function psm_mark_email_verified(int $userId): void
{
    db()->prepare(
        'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP
          WHERE user_id = :id AND email_verified_at IS NULL'
    )->execute(['id' => $userId]);
}

/**
 * Check a submitted $code against the newest live code for an account.
 *
 * Pass $userId whenever it is known (registration and the login gate both do);
 * email is only used as a fallback lookup, because the users table does not
 * enforce unique email addresses.
 *
 * @return array{success: bool, error?: string, user_id?: int|null}
 */
function psm_verify_email_code(string $email, string $code, ?int $userId = null): array
{
    $email = trim($email);
    $code = trim($code);

    if ($code === '') {
        return ['success' => false, 'error' => 'Please enter the verification code.'];
    }

    if ($userId === null && $email === '') {
        return ['success' => false, 'error' => 'Please enter the email address you registered with.'];
    }

    $columns = 'SELECT verification_id, user_id, code_hash, attempts, (expires_at <= NOW()) AS is_expired
                  FROM email_verification
                 WHERE consumed_at IS NULL';

    if ($userId !== null) {
        $stmt = db()->prepare($columns . ' AND user_id = :user_id ORDER BY verification_id DESC LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
    } else {
        $stmt = db()->prepare($columns . ' AND email = :email ORDER BY verification_id DESC LIMIT 1');
        $stmt->execute(['email' => $email]);
    }

    $row = $stmt->fetch();

    if (!$row) {
        return ['success' => false, 'error' => 'No active verification code. Please request a new one.'];
    }

    // Expiry is decided by the database clock, matching how expires_at was written.
    if ((int) $row['is_expired'] === 1) {
        return ['success' => false, 'error' => 'That verification code has expired. Please request a new one.'];
    }

    if ((int) $row['attempts'] >= psm_verification_max_attempts()) {
        return ['success' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
    }

    db()->prepare('UPDATE email_verification SET attempts = attempts + 1 WHERE verification_id = :id')
        ->execute(['id' => $row['verification_id']]);

    if (!password_verify($code, (string) $row['code_hash'])) {
        return ['success' => false, 'error' => 'That verification code is not correct.'];
    }

    db()->prepare('UPDATE email_verification SET consumed_at = CURRENT_TIMESTAMP WHERE verification_id = :id')
        ->execute(['id' => $row['verification_id']]);

    return [
        'success' => true,
        'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
    ];
}

/**
 * Activate an account from the token carried by the emailed activation link.
 *
 * @return array{success: bool, error?: string, user_id?: int|null, email?: string}
 */
function psm_verify_email_token(string $token): array
{
    $token = trim($token);

    if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return ['success' => false, 'error' => 'That activation link is not valid.'];
    }

    $stmt = db()->prepare(
        'SELECT verification_id, user_id, email, (expires_at <= NOW()) AS is_expired
           FROM email_verification
          WHERE token_hash = :token_hash AND consumed_at IS NULL
          ORDER BY verification_id DESC
          LIMIT 1'
    );
    $stmt->execute(['token_hash' => psm_verification_token_hash($token)]);
    $row = $stmt->fetch();

    if (!$row) {
        return [
            'success' => false,
            'error' => 'That activation link is not valid or has already been used. Accounts left unactivated for more than '
                . psm_verification_grace_hours() . ' hours are cancelled, so you may need to register again.',
        ];
    }

    if ((int) $row['is_expired'] === 1) {
        return ['success' => false, 'error' => 'That activation link has expired. Please request a new one.'];
    }

    db()->prepare('UPDATE email_verification SET consumed_at = CURRENT_TIMESTAMP WHERE verification_id = :id')
        ->execute(['id' => $row['verification_id']]);

    return [
        'success' => true,
        'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'email' => (string) $row['email'],
    ];
}

/**
 * Cancel accounts that were registered but never activated.
 *
 * They are deleted, which releases their username and email for reuse and takes
 * their codes with them through the foreign key. Returns how many were cancelled.
 */
function psm_purge_expired_unactivated_accounts(): int
{
    $hours = psm_verification_grace_hours(); // internal constant, never user input

    $stmt = db()->prepare(
        'DELETE FROM users
          WHERE email_verified_at IS NULL
            AND email IS NOT NULL
            AND email <> \'\'
            AND created_at < DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)'
    );
    $stmt->execute();

    $cancelled = $stmt->rowCount();

    if ($cancelled > 0) {
        error_log('[PSM accounts] cancelled ' . $cancelled . ' unactivated account(s) older than ' . $hours . ' hour(s).');
    }

    return $cancelled;
}

/**
 * Reject a username or email that another account already uses.
 *
 * Returns a user-facing message, or null when both are free.
 */
function psm_find_account_conflict(string $username, string $email): ?string
{
    $username = trim($username);
    $email = trim($email);

    if ($username !== '') {
        $stmt = db()->prepare('SELECT user_id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);

        if ($stmt->fetchColumn() !== false) {
            return 'That username is already taken. Please choose a different one.';
        }
    }

    if ($email !== '') {
        $stmt = db()->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        if ($stmt->fetchColumn() !== false) {
            return 'That email address is already used by another account. Please use a different one.';
        }
    }

    return null;
}

/**
 * Best-effort cancellation of unactivated accounts, safe to call on any request:
 * it never throws and does nothing when the database is unavailable.
 */
function psm_cancel_expired_registrations(): void
{
    if (!is_database_connected()) {
        return;
    }

    try {
        psm_purge_expired_unactivated_accounts();
    } catch (Throwable $exception) {
        error_log('[PSM accounts] cancelling unactivated accounts failed: ' . $exception->getMessage());
    }
}

/**
 * Called before a self-registration is inserted.
 *
 * First cancels accounts that were registered but never activated, so an
 * abandoned registration cannot hold a username or email hostage, then reports
 * any remaining clash with an existing account. Enforced in code as well as by
 * the UNIQUE keys, because a unique email index cannot be created on a database
 * that already contains a duplicate address.
 */
function psm_registration_conflict(string $username, string $email): ?string
{
    psm_cancel_expired_registrations();

    return psm_find_account_conflict($username, $email);
}

/**
 * Generate a code, store its hash, and email it to $email.
 *
 * @return array{success: bool, error?: string, expires_in?: int, retry_after?: int}
 */
function psm_send_verification_code(string $email, string $name = '', ?int $userId = null): array
{
    $email = trim($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }

    if (!psm_mail_is_configured()) {
        return [
            'success' => false,
            'error' => 'Email sending is not configured yet. Set PSM_MAIL_USERNAME and PSM_MAIL_APP_PASSWORD.',
        ];
    }

    if (!is_database_connected()) {
        return [
            'success' => false,
            'error' => 'The verification code could not be saved because the PSM database is not connected.',
        ];
    }

    try {
        $wait = psm_verification_cooldown_remaining($email);
    } catch (Throwable $exception) {
        error_log('[PSM mail] verification cooldown lookup failed: ' . $exception->getMessage());
        $wait = 0;
    }

    if ($wait > 0) {
        return [
            'success' => false,
            'error' => 'Please wait ' . $wait . ' more second' . ($wait === 1 ? '' : 's') . ' before requesting another code.',
            'retry_after' => $wait,
        ];
    }

    $code = psm_generate_verification_code();
    $token = psm_generate_verification_token();

    try {
        psm_store_verification_code($email, $code, $userId, $token);
    } catch (Throwable $exception) {
        error_log('[PSM mail] storing the verification code failed: ' . $exception->getMessage());

        return [
            'success' => false,
            'error' => 'The verification code could not be saved. Check that the email_verification table exists.',
        ];
    }

    $link = psm_verification_link($token, $email, $code);

    try {
        psm_smtp_send(
            $email,
            $name,
            'Activate your ' . SITE_NAME . ' account',
            psm_verification_html_body($code, $name, $link),
            psm_verification_text_body($code, $name, $link)
        );
    } catch (Throwable $exception) {
        // Technical detail stays in the server log; the caller gets a safe message.
        error_log('[PSM mail] sending the activation email failed: ' . $exception->getMessage());

        return ['success' => false, 'error' => 'The activation email could not be sent. Please try again later.'];
    }

    return ['success' => true, 'expires_in' => psm_verification_ttl()];
}

/**
 * Plain-text body for the activation email: code first, then the link.
 */
function psm_verification_text_body(string $code, string $name = '', string $link = ''): string
{
    $greeting = trim($name) !== '' ? 'Hi ' . trim($name) . ',' : 'Hello,';
    $hours = psm_verification_grace_hours();

    $lines = [
        $greeting,
        '',
        'ACTIVATE YOUR ' . strtoupper(SITE_NAME) . ' ACCOUNT',
        '',
        'Your activation code:',
        '',
        '    ' . $code,
        '',
    ];

    if ($link !== '') {
        $lines[] = 'Open this link and the code is already filled in:';
        $lines[] = '';
        $lines[] = $link;
        $lines[] = '';
    }

    $lines[] = 'The code and the link are valid for ' . $hours . ' hour' . ($hours === 1 ? '' : 's')
        . '. Accounts that are not activated within that time are cancelled.';
    $lines[] = 'If you did not create this account, you can ignore this email.';

    return implode("\n", $lines) . "\n";
}

/**
 * HTML body for the activation email: a card with the code centred and the
 * button beneath it. Styles are inline because mail clients strip stylesheets.
 * Colours are the ones already used by the account screens.
 */
function psm_verification_html_body(string $code, string $name = '', string $link = ''): string
{
    $greeting = trim($name) !== '' ? 'Hi ' . trim($name) . ',' : 'Hello,';
    $hours = psm_verification_grace_hours();
    $validity = $hours . ' hour' . ($hours === 1 ? '' : 's');

    $html = '<div style="background:#f3f1f8;padding:28px 12px;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#111111;line-height:1.5">'
        . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #ddd6fe;border-radius:16px;padding:32px 28px;text-align:center">'

        . '<div style="font-size:12px;font-weight:bold;letter-spacing:0.14em;text-transform:uppercase;color:#241047">' . e(SITE_NAME) . '</div>'
        . '<div style="font-size:22px;font-weight:bold;color:#111111;margin:12px 0 10px">Activate your account</div>'
        . '<div style="font-size:15px;color:#555555">' . e($greeting) . ' use the code below to activate your piano learning account.</div>'

        . '<div style="background:#f3f1f8;border:1px dashed #ddd6fe;border-radius:12px;padding:18px 12px;margin:24px 0">'
        . '<div style="font-size:12px;font-weight:bold;letter-spacing:0.1em;text-transform:uppercase;color:#241047">Activation code</div>'
        . '<div style="font-size:34px;font-weight:bold;letter-spacing:8px;color:#241047;margin-top:8px">' . e($code) . '</div>'
        . '</div>';

    if ($link !== '') {
        $html .= '<a href="' . e($link) . '" style="display:inline-block;background:#241047;color:#ffffff;text-decoration:none;'
            . 'padding:14px 28px;border-radius:9px;font-size:15px;font-weight:bold">Activate my account</a>';
    }

    $html .= '<div style="font-size:13px;color:#555555;margin-top:24px">The code and the button are valid for '
        . $validity . '. Accounts that are not activated within that time are cancelled.</div>';

    if ($link !== '') {
        $html .= '<div style="font-size:12px;color:#555555;margin-top:14px">If the button does not work, copy this address into your browser:'
            . '<div style="word-break:break-all;margin-top:6px">' . e($link) . '</div></div>';
    }

    $html .= '<div style="font-size:12px;color:#555555;margin-top:16px">If you did not create this account, you can ignore this email.</div>'
        . '</div></div>';

    return $html;
}

/**
 * Send one message through the configured SMTP account.
 *
 * @throws RuntimeException when the server refuses the connection, the
 *                          credentials, or the message.
 */
function psm_smtp_send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): void
{
    $client = new PsmSmtpClient(psm_mail_config());
    $client->send($toEmail, $toName, $subject, $htmlBody, $textBody);
}

/**
 * Minimal SMTP client: STARTTLS or implicit TLS, plus AUTH LOGIN.
 *
 * Deliberately dependency-free — the project does not use Composer, and Gmail
 * with an app password needs nothing beyond PHP streams and openssl.
 */
final class PsmSmtpClient
{
    private array $config;

    /** @var resource|null */
    private $socket = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @throws RuntimeException
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): void
    {
        $this->connect();

        try {
            $this->authenticate();
            $this->deliver($toEmail, $this->buildMessage($toEmail, $toName, $subject, $htmlBody, $textBody));
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $config = $this->config;
        $timeout = (int) ($config['timeout'] ?? 15);
        $transport = $config['encryption'] === 'ssl' ? 'ssl://' : '';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $transport . $config['host'] . ':' . (int) $config['port'],
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException(
                'could not connect to ' . $config['host'] . ':' . (int) $config['port']
                . ' (' . ($errstr !== '' ? $errstr : 'error ' . $errno) . ')'
            );
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $timeout);

        $this->expect([220], 'SMTP greeting');
        $this->command('EHLO ' . $this->clientName(), [250], 'SMTP EHLO');

        if ($config['encryption'] === 'tls') {
            $this->command('STARTTLS', [220], 'SMTP STARTTLS');

            if (@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('could not negotiate TLS with the mail server');
            }

            $this->command('EHLO ' . $this->clientName(), [250], 'SMTP EHLO after STARTTLS');
        }
    }

    private function authenticate(): void
    {
        $this->command('AUTH LOGIN', [334], 'SMTP authentication');

        // The username and password are only ever sent in this base64 form and
        // are never included in error messages or logs.
        $this->command(base64_encode($this->config['username']), [334], 'SMTP authentication');
        $this->command(base64_encode($this->config['password']), [235], 'SMTP authentication');
    }

    private function deliver(string $toEmail, string $message): void
    {
        $this->command('MAIL FROM:<' . $this->config['from_address'] . '>', [250], 'SMTP MAIL FROM');
        $this->command('RCPT TO:<' . $toEmail . '>', [250, 251], 'SMTP RCPT TO');
        $this->command('DATA', [354], 'SMTP DATA');

        $message = rtrim($message, "\r\n") . "\r\n";

        // Dot-stuffing: a body line starting with '.' must be doubled.
        $stuffed = preg_replace('/^\./m', '..', $message);

        $this->write($stuffed . ".\r\n");
        $this->expect([250], 'SMTP message body');
    }

    private function buildMessage(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): string
    {
        $config = $this->config;
        $boundary = '=_psm_' . bin2hex(random_bytes(16));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($config['from_address'], (string) $config['from_name']),
            'To: ' . $this->formatAddress($toEmail, $toName),
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->messageIdHost() . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        // base64 bodies keep every line short and free of raw 8-bit characters.
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($textBody), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody), 76, "\r\n")
            . '--' . $boundary . "--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function formatAddress(string $email, string $name): string
    {
        $name = trim($name);

        return $name === '' ? $email : $this->encodeHeader($name) . ' <' . $email . '>';
    }

    /**
     * Strip CR/LF (so a crafted value cannot inject extra headers) and encode
     * the value when it is not plain ASCII.
     */
    private function encodeHeader(string $value): string
    {
        $value = trim((string) preg_replace('/[\r\n]+/', ' ', $value));

        if ($value === '') {
            return '';
        }

        return preg_match('/[^\x20-\x7E]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    private function messageIdHost(): string
    {
        $domain = (string) substr((string) strrchr($this->config['from_address'], '@') ?: '', 1);

        return $domain !== '' ? $domain : 'localhost';
    }

    private function clientName(): string
    {
        $name = gethostname();
        $name = is_string($name) ? (string) preg_replace('/[^A-Za-z0-9.\-]/', '', $name) : '';

        return $name !== '' ? $name : 'localhost';
    }

    /**
     * @param int[] $expected
     *
     * @throws RuntimeException
     */
    private function command(string $payload, array $expected, string $label): string
    {
        $this->write($payload . "\r\n");

        return $this->expect($expected, $label);
    }

    /**
     * @param int[] $expected
     *
     * @throws RuntimeException
     */
    private function expect(array $expected, string $label): string
    {
        $response = $this->read();
        $code = (int) substr(trim($response), 0, 3);

        if (!in_array($code, $expected, true)) {
            throw new RuntimeException($label . ' was rejected by the mail server: ' . trim($response));
        }

        return $response;
    }

    /**
     * @throws RuntimeException
     */
    private function read(): string
    {
        $response = '';

        if ($this->socket === null) {
            throw new RuntimeException('the SMTP connection is not open');
        }

        while (($line = fgets($this->socket, 2048)) !== false) {
            $response .= $line;

            // A space (not a hyphen) after the status code marks the last line.
            if (preg_match('/^\d{3} /', $line) === 1) {
                break;
            }
        }

        if ($response === '') {
            $meta = stream_get_meta_data($this->socket);

            throw new RuntimeException(
                !empty($meta['timed_out'])
                    ? 'the mail server did not respond in time'
                    : 'the mail server closed the connection unexpectedly'
            );
        }

        return $response;
    }

    /**
     * @throws RuntimeException
     */
    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('the SMTP connection is not open');
        }

        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($this->socket, substr($data, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('could not write to the SMTP connection');
            }

            $offset += $written;
        }
    }

    private function close(): void
    {
        if ($this->socket === null) {
            return;
        }

        try {
            // Best effort: the message has already been accepted by now.
            $this->write("QUIT\r\n");
            @fgets($this->socket, 2048);
        } catch (Throwable $exception) {
            // A failed QUIT does not invalidate an accepted message.
        }

        @fclose($this->socket);
        $this->socket = null;
    }
}
