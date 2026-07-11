<?php
declare(strict_types=1);

/**
 * Outgoing email for the application.
 *
 * Transport is chosen from .env:
 *   MAIL_HOST set            -> real SMTP (with MAIL_PORT, MAIL_USERNAME,
 *                               MAIL_PASSWORD, MAIL_ENCRYPTION = tls|ssl|none)
 *   MAIL_TRANSPORT=mail      -> PHP mail() (works on most cPanel hosts)
 *   otherwise                -> log (writes .eml files to storage/mail/)
 */

function mail_transport(): string
{
    if ((string) env('MAIL_HOST', '') !== '') {
        return 'smtp';
    }
    $transport = strtolower((string) env('MAIL_TRANSPORT', 'log'));
    return in_array($transport, ['mail', 'log'], true) ? $transport : 'log';
}

function mail_is_configured(): bool
{
    return mail_transport() !== 'log';
}

function mail_from_address(): string
{
    return (string) env('MAIL_FROM_ADDRESS', env('MAIL_USERNAME', 'no-reply@' . (parse_url(APP_URL ?: 'https://localhost', PHP_URL_HOST) ?: 'localhost')));
}

function mail_from_name(): string
{
    return (string) env('MAIL_FROM_NAME', APP_NAME);
}

/**
 * Wraps plain message HTML in the firm's branded email shell: navy header
 * with the wordmark, gold accent rule, content card, and footer. Pass the
 * result to send_app_email().
 */
function branded_email_html(string $title, string $innerHtml): string
{
    $brand = htmlspecialchars((string) (function_exists('setting') ? setting('brand_name', 'M.Bista & Associates') : 'M.Bista & Associates'), ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $year = date('Y');
    return <<<HTML
<div style="margin:0;padding:24px 12px;background:#eef2f7;font-family:'Segoe UI',Arial,sans-serif;">
  <div style="max-width:600px;margin:0 auto;">
    <div style="background:#0b1c36;border-radius:12px 12px 0 0;padding:20px 28px;">
      <span style="display:inline-block;border:2px solid #d9a33a;color:#ffffff;font-family:Georgia,serif;font-weight:bold;font-size:16px;padding:6px 10px;">MB</span>
      <span style="color:#ffffff;font-family:Georgia,serif;font-size:17px;font-weight:bold;margin-left:12px;">{$brand}</span>
      <div style="color:#f2c766;font-size:11px;letter-spacing:3px;margin-top:6px;">CHARTERED ACCOUNTANTS</div>
    </div>
    <div style="height:3px;background:#d9a33a;"></div>
    <div style="background:#ffffff;padding:26px 28px;border:1px solid #e3e9f2;border-top:0;">
      <h2 style="margin:0 0 14px;color:#0f1e33;font-family:Georgia,serif;font-size:19px;">{$safeTitle}</h2>
      <div style="color:#26344c;font-size:14px;line-height:1.65;">{$innerHtml}</div>
    </div>
    <div style="background:#f7fafd;border:1px solid #e3e9f2;border-top:0;border-radius:0 0 12px 12px;padding:14px 28px;color:#4d5d76;font-size:11.5px;line-height:1.6;">
      This is an automated message from the {$brand} portal — please sign in to respond.<br>
      &copy; {$year} {$brand}. All rights reserved.
    </div>
  </div>
</div>
HTML;
}

/**
 * @param array $attachments Each item: ['name' => string, 'mime' => string, 'content' => string]
 * @return array{ok: bool, error: ?string, transport: string}
 */
function send_app_email(string $to, string $subject, string $htmlBody, array $attachments = []): array
{
    $transport = mail_transport();
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient email.', 'transport' => $transport];
    }

    $boundary = 'mb-' . bin2hex(random_bytes(12));
    $fromAddress = mail_from_address();
    $fromName = mail_from_name();

    $headers = [
        'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromAddress . '>',
        'Reply-To: ' . $fromAddress,
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'X-Mailer: MB-World-App',
    ];

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    foreach ($attachments as $attachment) {
        $name = (string) ($attachment['name'] ?? 'attachment.dat');
        $mime = (string) ($attachment['mime'] ?? 'application/octet-stream');
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Type: ' . $mime . '; name="' . $name . "\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= 'Content-Disposition: attachment; filename="' . $name . "\"\r\n\r\n";
        $body .= chunk_split(base64_encode((string) ($attachment['content'] ?? ''))) . "\r\n";
    }
    $body .= "--{$boundary}--\r\n";

    if ($transport === 'log') {
        $dir = dirname(__DIR__) . '/storage/mail';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create storage/mail directory.', 'transport' => $transport];
        }
        $file = $dir . '/' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9]+/i', '-', substr($subject, 0, 40)) . '.eml';
        $raw = 'To: ' . $to . "\r\n" . 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8') . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body;
        file_put_contents($file, $raw);
        return ['ok' => true, 'error' => null, 'transport' => $transport];
    }

    if ($transport === 'mail') {
        $ok = mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers));
        return ['ok' => $ok, 'error' => $ok ? null : 'PHP mail() returned false. Check the host mail configuration.', 'transport' => $transport];
    }

    return smtp_send($to, $subject, $headers, $body);
}

/**
 * Minimal SMTP client: EHLO, optional STARTTLS, AUTH LOGIN, one recipient.
 */
function smtp_send(string $to, string $subject, array $headers, string $mimeBody): array
{
    $host = (string) env('MAIL_HOST', '');
    $port = (int) (env('MAIL_PORT', '587') ?? 587);
    $username = (string) env('MAIL_USERNAME', '');
    $password = (string) env('MAIL_PASSWORD', '');
    $encryption = strtolower((string) env('MAIL_ENCRYPTION', 'tls'));
    $timeout = 20;

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote . ':' . $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return ['ok' => false, 'error' => 'SMTP connect failed: ' . $errstr . ' (' . $errno . ')', 'transport' => 'smtp'];
    }
    stream_set_timeout($socket, $timeout);

    $read = static function () use ($socket): array {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return [(int) substr($response, 0, 3), trim($response)];
    };
    $send = static function (string $command) use ($socket): void {
        fwrite($socket, $command . "\r\n");
    };
    $fail = static function (string $step, string $response) use ($socket): array {
        fclose($socket);
        return ['ok' => false, 'error' => 'SMTP ' . $step . ' failed: ' . substr($response, 0, 200), 'transport' => 'smtp'];
    };

    [$code, $response] = $read();
    if ($code !== 220) {
        return $fail('greeting', $response);
    }

    $ehloHost = parse_url(APP_URL ?: 'https://localhost', PHP_URL_HOST) ?: 'localhost';
    $send('EHLO ' . $ehloHost);
    [$code, $response] = $read();
    if ($code !== 250) {
        return $fail('EHLO', $response);
    }

    if ($encryption === 'tls') {
        $send('STARTTLS');
        [$code, $response] = $read();
        if ($code !== 220) {
            return $fail('STARTTLS', $response);
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['ok' => false, 'error' => 'SMTP TLS negotiation failed.', 'transport' => 'smtp'];
        }
        $send('EHLO ' . $ehloHost);
        [$code, $response] = $read();
        if ($code !== 250) {
            return $fail('EHLO after TLS', $response);
        }
    }

    if ($username !== '') {
        $send('AUTH LOGIN');
        [$code, $response] = $read();
        if ($code !== 334) {
            return $fail('AUTH LOGIN', $response);
        }
        $send(base64_encode($username));
        [$code, $response] = $read();
        if ($code !== 334) {
            return $fail('AUTH username', $response);
        }
        $send(base64_encode($password));
        [$code, $response] = $read();
        if ($code !== 235) {
            return $fail('AUTH password', $response);
        }
    }

    $send('MAIL FROM:<' . mail_from_address() . '>');
    [$code, $response] = $read();
    if ($code !== 250) {
        return $fail('MAIL FROM', $response);
    }

    $send('RCPT TO:<' . $to . '>');
    [$code, $response] = $read();
    if (!in_array($code, [250, 251], true)) {
        return $fail('RCPT TO', $response);
    }

    $send('DATA');
    [$code, $response] = $read();
    if ($code !== 354) {
        return $fail('DATA', $response);
    }

    $data = 'To: ' . $to . "\r\n"
        . 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8') . "\r\n"
        . 'Date: ' . date(DATE_RFC2822) . "\r\n"
        . implode("\r\n", $headers) . "\r\n\r\n"
        . preg_replace('/^\./m', '..', $mimeBody);
    $send($data . "\r\n.");
    [$code, $response] = $read();
    if ($code !== 250) {
        return $fail('message delivery', $response);
    }

    $send('QUIT');
    fclose($socket);
    return ['ok' => true, 'error' => null, 'transport' => 'smtp'];
}
