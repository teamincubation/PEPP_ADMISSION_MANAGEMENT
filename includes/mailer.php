<?php
/**
 * PEPP Learning — Unified Mail Dispatcher & SMTP Client.
 * Routes all system emails either via authenticated SMTP (if enabled)
 * or falls back to native PHP mail().
 */

class PEPPSMTPClient {
    private $host;
    private $port;
    private $secure;
    private $username;
    private $password;
    private $timeout = 10;
    private $socket;
    private $error = '';

    public function __construct($host, $port, $secure, $username, $password) {
        $this->host = $host;
        $this->port = (int)$port;
        $this->secure = strtolower($secure);
        $this->username = $username;
        $this->password = $password;
    }

    private function read() {
        $data = '';
        while ($str = fgets($this->socket, 515)) {
            $data .= $str;
            if (isset($str[3]) && $str[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    private function command($cmd, $expectedCode) {
        fwrite($this->socket, $cmd . "\r\n");
        $resp = $this->read();
        $code = (int)substr($resp, 0, 3);
        if ($code !== $expectedCode) {
            throw new Exception("SMTP command failed: '$cmd'. Response: '$resp'");
        }
        return $resp;
    }

    public function send($fromEmail, $fromName, $toEmail, $subject, $bodyHtml, $bodyText = '', array $attachments = []) {
        try {
            $server = $this->host;
            if ($this->secure === 'ssl') {
                $server = 'ssl://' . $this->host;
            }
            
            $this->socket = @fsockopen($server, $this->port, $errno, $errstr, $this->timeout);
            if (!$this->socket) {
                throw new Exception("Connection to SMTP server failed: $errstr ($errno)");
            }

            $this->read(); // Read greeting
            
            $this->command('EHLO ' . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost'), 250);

            if ($this->secure === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception("TLS encryption handshake failed.");
                }
                $this->command('EHLO ' . (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost'), 250);
            }

            if ($this->username) {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334);
                $this->command(base64_encode($this->password), 235);
            }

            $this->command('MAIL FROM:<' . $fromEmail . '>', 250);
            $this->command('RCPT TO:<' . $toEmail . '>', 250);
            $this->command('DATA', 354);

            // Construct MIME headers and body
            $bMix = 'mix_' . md5(uniqid('', true));
            $bAlt = 'alt_' . md5(uniqid('', true));

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Date: " . date('r') . "\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "From: " . '=?UTF-8?B?' . base64_encode($fromName) . '?=' . " <$fromEmail>\r\n";
            $headers .= "To: <$toEmail>\r\n";
            $headers .= "Content-Type: multipart/mixed; boundary=\"$bMix\"\r\n";
            $headers .= "X-Mailer: PEPP-SMTP\r\n";

            $body  = "--$bMix\r\n";
            $body .= "Content-Type: multipart/alternative; boundary=\"$bAlt\"\r\n\r\n";
            
            $body .= "--$bAlt\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= ($bodyText ?: strip_tags($bodyHtml)) . "\r\n\r\n";
            
            $body .= "--$bAlt\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $bodyHtml . "\r\n\r\n";
            $body .= "--$bAlt--\r\n\r\n";

            foreach ($attachments as $att) {
                $body .= "--$bMix\r\n";
                $body .= "Content-Type: " . ($att['type'] ?? 'application/octet-stream') . "; name=\"" . ($att['name'] ?? 'file') . "\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n";
                $body .= "Content-Disposition: attachment; filename=\"" . ($att['name'] ?? 'file') . "\"\r\n\r\n";
                $body .= chunk_split(base64_encode($att['bytes'] ?? '')) . "\r\n";
            }
            $body .= "--$bMix--";

            $bodySMTP = preg_replace('/^\./m', '..', $body);

            fwrite($this->socket, $headers . "\r\n" . $bodySMTP . "\r\n.\r\n");
            $resp = $this->read();
            if ((int)substr($resp, 0, 3) !== 250) {
                throw new Exception("DATA transmission failed: $resp");
            }

            $this->command('QUIT', 221);
            fclose($this->socket);
            return true;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            if ($this->socket) {
                @fclose($this->socket);
            }
            error_log('SMTP Mail Error: ' . $this->error);
            return false;
        }
    }

    public function getLastError() {
        return $this->error;
    }
}

/**
 * Enqueue an email for delivery via the unified mail queue.
 * This is the primary function all business callers should use.
 * It routes through pepp_enqueue_mail() → communication_queue → cron dispatch.
 *
 * Falls back to synchronous dispatch only when the queue is unavailable.
 */
function pepp_mail($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], $fromEmail = '', $fromName = '') {
    $finalFromEmail = $fromEmail ?: 'noreply@pepplearning.in';
    $finalFromName  = $fromName  ?: 'PEPP Learning';

    // Try to route through the unified mail queue
    $queueFile = __DIR__ . '/mail_queue.php';
    if (file_exists($queueFile)) {
        require_once $queueFile;
        $queueId = pepp_enqueue_mail($to, $subject, $bodyHtml, $bodyText, $attachments, $finalFromEmail, $finalFromName);
        if ($queueId !== false) {
            return true; // Successfully queued
        }
        // Queue failed — fall through to synchronous dispatch
        error_log("pepp_mail: Queue unavailable for {$to}, falling back to synchronous dispatch");
    }

    // Synchronous fallback
    return pepp_mail_dispatch($to, $subject, $bodyHtml, $bodyText, $attachments, $finalFromEmail, $finalFromName);
}

/**
 * Direct synchronous email dispatch (SMTP with mail() fallback).
 * Called by the QueueProcessor when processing email queue items,
 * and as a last-resort fallback when the queue is unavailable.
 *
 * DO NOT call this from business logic — use pepp_mail() instead.
 */
function pepp_mail_dispatch($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], $fromEmail = '', $fromName = '') {
    global $pdo;
    if (!isset($pdo)) {
        try {
            $dbPath = dirname(__DIR__) . '/config/database.php';
            if (file_exists($dbPath)) {
                require_once $dbPath;
            }
        } catch (Exception $e) {}
    }

    static $smtp = null;
    if ($smtp === null) {
        try {
            if (isset($pdo)) {
                $stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'smtp_%'");
                $smtp = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            } else {
                $smtp = [];
            }
        } catch (Exception $e) {
            $smtp = [];
        }
    }

    $enabled   = ($smtp['smtp_enabled'] ?? '0') === '1';
    $host      = $smtp['smtp_host'] ?? '';
    $port      = (int)($smtp['smtp_port'] ?? 465);
    $secure    = $smtp['smtp_secure'] ?? 'ssl';
    $user      = $smtp['smtp_user'] ?? '';
    $pass      = $smtp['smtp_pass'] ?? '';
    
    $finalFromEmail = $fromEmail ?: ($smtp['smtp_from_email'] ?? 'noreply@pepplearning.in');
    $finalFromName  = $fromName ?: ($smtp['smtp_from_name'] ?? 'PEPP Learning');

    if ($enabled && $host && $user && $pass) {
        $client = new PEPPSMTPClient($host, $port, $secure, $user, $pass);
        $res = $client->send($finalFromEmail, $finalFromName, $to, $subject, $bodyHtml, $bodyText, $attachments);
        if ($res) {
            return true;
        } else {
            error_log("SMTP dispatch failed, falling back to local mail(): " . $client->getLastError());
        }
    }

    return pepp_mail_fallback($to, $subject, $bodyHtml, $bodyText, $attachments, $finalFromEmail, $finalFromName);
}

function pepp_mail_fallback($to, $subject, $bodyHtml, $bodyText = '', array $attachments = [], $fromEmail = 'noreply@pepplearning.in', $fromName = 'PEPP Learning') {
    $bMix = 'mix_' . md5(uniqid('', true));
    $bAlt = 'alt_' . md5(uniqid('', true));

    $headers  = "From: " . '=?UTF-8?B?' . base64_encode($fromName) . '?=' . " <$fromEmail>\r\n";
    $headers .= "Reply-To: $fromEmail\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "X-Mailer: PEPP-Fallback\r\n";
    
    if (!empty($attachments)) {
        $headers .= "Content-Type: multipart/mixed; boundary=\"$bMix\"";
        
        $body  = "--$bMix\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"$bAlt\"\r\n\r\n";
        
        $body .= "--$bAlt\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= ($bodyText ?: strip_tags($bodyHtml)) . "\r\n\r\n";
        
        $body .= "--$bAlt\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $bodyHtml . "\r\n\r\n";
        $body .= "--$bAlt--\r\n\r\n";

        foreach ($attachments as $att) {
            $body .= "--$bMix\r\n";
            $body .= "Content-Type: " . ($att['type'] ?? 'application/octet-stream') . "; name=\"" . ($att['name'] ?? 'file') . "\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"" . ($att['name'] ?? 'file') . "\"\r\n\r\n";
            $body .= chunk_split(base64_encode($att['bytes'] ?? '')) . "\r\n";
        }
        $body .= "--$bMix--";
    } else {
        $headers .= "Content-Type: multipart/alternative; boundary=\"$bAlt\"";
        
        $body  = "--$bAlt\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= ($bodyText ?: strip_tags($bodyHtml)) . "\r\n\r\n";
        
        $body .= "--$bAlt\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $bodyHtml . "\r\n\r\n";
        $body .= "--$bAlt--";
    }

    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    try {
        return @mail($to, $subjectEnc, $body, $headers);
    } catch (Exception $e) {
        error_log('Fallback mail: ' . $e->getMessage());
        return false;
    }
}
