<?php
/**
 * PEPP Learning ERP — Google Workspace DWD OAuth Client
 *
 * Implements Google Service Account authentication with Domain-Wide Delegation (DWD)
 * impersonating admin@pepponline.in using pure vanilla PHP (OpenSSL + cURL).
 * Zero external Composer dependencies.
 *
 * SAFETY INVARIANTS:
 * 1. ONLY admin@pepponline.in is allowed as the impersonated user.
 * 2. meet@pepponline.in is STRICTLY BLOCKED and rejected.
 * 3. Never logs private keys, JWT assertions, or raw bearer tokens.
 * 4. Loads credentials outside public web root.
 */

declare(strict_types=1);

class GoogleWorkspaceClient {
    public const DEFAULT_ORGANIZER = 'admin@pepponline.in';
    public const PROHIBITED_ACCOUNT = 'meet@pepponline.in';
    public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public const SCOPE_CALENDAR       = 'https://www.googleapis.com/auth/calendar';
    public const SCOPE_MEET_SPACE_REQ = 'https://www.googleapis.com/auth/meetings.space.created';
    public const SCOPE_MEET_SETTINGS  = 'https://www.googleapis.com/auth/meetings.space.settings';
    public const SCOPE_MEET_READONLY  = 'https://www.googleapis.com/auth/meetings.space.readonly';

    /** @var array<string, mixed> */
    protected array $serviceAccountConfig = [];

    protected string $impersonatedUser;

    /** @var array<string, array{token: string, expires_at: int}> */
    protected static array $tokenCache = [];

    /** @var (callable(string, string, ?string, array<string>): array{status: int, body: string, error: ?string})|null */
    protected static $mockTransport = null;

    /**
     * @param string|array<string, mixed>|null $credentials Path to JSON file, JSON string, or parsed array
     * @param string $impersonatedUser Must be admin@pepponline.in
     */
    public function __construct($credentials = null, string $impersonatedUser = self::DEFAULT_ORGANIZER) {
        $this->setImpersonatedUser($impersonatedUser);
        $this->loadCredentials($credentials);
    }

    /**
     * Set the impersonated organizer account with strict security validation.
     */
    public function setImpersonatedUser(string $email): void {
        $normalized = strtolower(trim($email));
        if ($normalized === self::PROHIBITED_ACCOUNT) {
            throw new InvalidArgumentException(
                "Security Exception: Account " . self::PROHIBITED_ACCOUNT . " is strictly prohibited for Google Workspace operations."
            );
        }
        if ($normalized !== self::DEFAULT_ORGANIZER) {
            error_log("GoogleWorkspaceClient warning: Non-standard organizer {$normalized} requested; standard is " . self::DEFAULT_ORGANIZER);
        }
        $this->impersonatedUser = $normalized;
    }

    public function getImpersonatedUser(): string {
        return $this->impersonatedUser;
    }

    /**
     * Load credentials from file, environment, or constant.
     */
    protected function loadCredentials($credentials): void {
        if (is_array($credentials)) {
            $this->serviceAccountConfig = $credentials;
            return;
        }

        if (is_string($credentials) && trim($credentials) !== '') {
            if (str_starts_with(trim($credentials), '{')) {
                $decoded = json_decode($credentials, true);
                if (is_array($decoded)) {
                    $this->serviceAccountConfig = $decoded;
                    return;
                }
            } elseif (file_exists($credentials)) {
                $content = file_get_contents($credentials);
                if ($content !== false) {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $this->serviceAccountConfig = $decoded;
                        return;
                    }
                }
            }
        }

        // Load application secrets if available
        if (!defined('PEPP_GOOGLE_WORKSPACE_SA_KEY_PATH')) {
            $secretsPath = dirname(__DIR__, 2) . '/config/secrets.php';
            if (file_exists($secretsPath)) {
                require_once $secretsPath;
            }
        }

        // Try environment variable
        $envPath = getenv('PEPP_GOOGLE_SERVICE_ACCOUNT_JSON') ?: getenv('GOOGLE_SERVICE_ACCOUNT_JSON');
        if ($envPath && file_exists($envPath)) {
            $content = file_get_contents($envPath);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $this->serviceAccountConfig = $decoded;
                    return;
                }
            }
        }

        // Try defined constant
        if (defined('PEPP_GOOGLE_WORKSPACE_SA_KEY_PATH') && file_exists(PEPP_GOOGLE_WORKSPACE_SA_KEY_PATH)) {
            $content = file_get_contents(PEPP_GOOGLE_WORKSPACE_SA_KEY_PATH);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $this->serviceAccountConfig = $decoded;
                    return;
                }
            }
        }

        // Fallback candidate paths outside public_html (Hostinger production & local dev)
        $candidatePaths = [
            '/home/u361910773/google-secrets/pepp-erp-google-workspace.json',
            dirname(__DIR__, 2) . '/google-secrets/pepp-erp-google-workspace.json',
            dirname(__DIR__, 2) . '/config/secrets/pepp-erp-google-workspace.json',
            dirname(__DIR__, 3) . '/google-secrets/pepp-erp-google-workspace.json',
            dirname(__DIR__, 4) . '/pepp-erp-google-workspace.json',
            dirname(__DIR__, 4) . '/google-secrets/pepp-erp-google-workspace.json',
        ];

        foreach ($candidatePaths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $this->serviceAccountConfig = $decoded;
                        return;
                    }
                }
            }
        }
    }

    public function isConfigured(): bool {
        return !empty($this->serviceAccountConfig['client_email'])
            && !empty($this->serviceAccountConfig['private_key']);
    }

    public function getClientEmail(): string {
        return (string)($this->serviceAccountConfig['client_email'] ?? '');
    }

    public function getProjectId(): string {
        return (string)($this->serviceAccountConfig['project_id'] ?? 'pepp-live-sessions');
    }

    /**
     * Get authorized default scopes.
     * @return array<string>
     */
    public static function getDefaultScopes(): array {
        return [
            self::SCOPE_CALENDAR,
            self::SCOPE_MEET_SPACE_REQ,
            self::SCOPE_MEET_SETTINGS,
            self::SCOPE_MEET_READONLY,
        ];
    }

    /**
     * Generate or retrieve a cached OAuth2 access token for the requested scopes.
     *
     * @param array<string>|null $scopes
     * @return string
     * @throws RuntimeException
     */
    public function getAccessToken(?array $scopes = null): string {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Google Workspace Service Account credentials are not configured or missing private key.");
        }

        $scopes = $scopes ?: self::getDefaultScopes();
        sort($scopes);
        $scopeKey = implode(' ', $scopes);
        $cacheKey = md5($this->impersonatedUser . '|' . $scopeKey);

        $now = time();
        if (isset(self::$tokenCache[$cacheKey]) && self::$tokenCache[$cacheKey]['expires_at'] > ($now + 60)) {
            return self::$tokenCache[$cacheKey]['token'];
        }

        $assertion = $this->createJwtAssertion($scopes);
        $tokenData = $this->exchangeJwtForAccessToken($assertion);

        $accessToken = (string)($tokenData['access_token'] ?? '');
        $expiresIn = (int)($tokenData['expires_in'] ?? 3600);

        if ($accessToken === '') {
            throw new RuntimeException("Failed to acquire Google OAuth access token: response missing access_token");
        }

        self::$tokenCache[$cacheKey] = [
            'token' => $accessToken,
            'expires_at' => $now + $expiresIn,
        ];

        return $accessToken;
    }

    /**
     * Mint an RS256 JWT assertion signed with the Service Account private key.
     *
     * @param array<string> $scopes
     * @return string
     */
    public function createJwtAssertion(array $scopes): string {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $this->getClientEmail(),
            'sub'   => $this->impersonatedUser,
            'scope' => implode(' ', $scopes),
            'aud'   => self::TOKEN_ENDPOINT,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $headerEncoded = self::base64UrlEncode((string)json_encode($header));
        $claimsEncoded = self::base64UrlEncode((string)json_encode($claims));
        $unsignedToken = $headerEncoded . '.' . $claimsEncoded;

        $privateKey = (string)$this->serviceAccountConfig['private_key'];
        $signature = '';

        $keyResource = openssl_pkey_get_private($privateKey);
        if ($keyResource === false) {
            throw new RuntimeException("Invalid Google Service Account private key: openssl_pkey_get_private failed.");
        }

        $success = openssl_sign($unsignedToken, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        if (!$success) {
            throw new RuntimeException("OpenSSL failed to sign Google JWT assertion.");
        }

        return $unsignedToken . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Exchange assertion at token endpoint.
     *
     * @return array<string, mixed>
     */
    protected function exchangeJwtForAccessToken(string $assertion): array {
        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]);

        $res = $this->executeHttp('POST', self::TOKEN_ENDPOINT, $postFields, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        if ($res['status'] !== 200) {
            $errMsg = "Google OAuth token exchange failed (HTTP {$res['status']})";
            if (!empty($res['error'])) {
                $errMsg .= " cURL error: " . $res['error'];
            }
            if (!empty($res['body'])) {
                $errJson = json_decode($res['body'], true);
                if (isset($errJson['error_description'])) {
                    $errMsg .= ": " . $errJson['error_description'];
                } elseif (isset($errJson['error'])) {
                    $errMsg .= ": " . $errJson['error'];
                }
            }
            throw new RuntimeException($errMsg);
        }

        $decoded = json_decode($res['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON response from Google token endpoint.");
        }

        return $decoded;
    }

    /**
     * Perform an authenticated API request to Google APIs.
     *
     * @param string $method GET, POST, PATCH, DELETE, PUT
     * @param string $url Full target URL
     * @param array<string, mixed>|string|null $body
     * @param array<string> $extraHeaders
     * @param array<string>|null $scopes
     * @return array{success: bool, status: int, data: array<string, mixed>|null, error: ?string, raw: string}
     */
    public function apiRequest(string $method, string $url, $body = null, array $extraHeaders = [], ?array $scopes = null): array {
        try {
            $token = $this->getAccessToken($scopes);
        } catch (Exception $e) {
            return [
                'success' => false,
                'status'  => 0,
                'data'    => null,
                'error'   => $e->getMessage(),
                'raw'     => '',
            ];
        }

        $headers = array_merge([
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ], $extraHeaders);

        $payload = null;
        if ($body !== null) {
            if (is_array($body)) {
                $payload = json_encode($body);
                $headers[] = 'Content-Type: application/json';
            } else {
                $payload = (string)$body;
            }
        }

        $res = $this->executeHttp($method, $url, $payload, $headers);
        $status = $res['status'];
        $raw = $res['body'];

        if ($status >= 200 && $status < 300) {
            $data = ($raw !== '' && $raw !== 'null') ? json_decode($raw, true) : [];
            return [
                'success' => true,
                'status'  => $status,
                'data'    => is_array($data) ? $data : [],
                'error'   => null,
                'raw'     => $raw,
            ];
        }

        $errorMsg = "HTTP {$status}";
        if ($res['error']) {
            $errorMsg .= " cURL error: " . $res['error'];
        } elseif ($raw !== '') {
            $parsed = json_decode($raw, true);
            if (isset($parsed['error']['message'])) {
                $errorMsg .= ": " . $parsed['error']['message'];
            } elseif (isset($parsed['error_description'])) {
                $errorMsg .= ": " . $parsed['error_description'];
            } else {
                $errorMsg .= ": " . mb_strimwidth($raw, 0, 200, '...');
            }
        }

        return [
            'success' => false,
            'status'  => $status,
            'data'    => null,
            'error'   => $errorMsg,
            'raw'     => $raw,
        ];
    }

    /**
     * HTTP execution layer (cURL or mock transport).
     *
     * @param array<string> $headers
     * @return array{status: int, body: string, error: ?string}
     */
    protected function executeHttp(string $method, string $url, ?string $payload, array $headers): array {
        if (self::$mockTransport !== null) {
            return (self::$mockTransport)($method, $url, $payload, $headers);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        if (DIRECTORY_SEPARATOR === '\\') {
            $winCaPaths = [
                'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt',
                'C:/Program Files/Git/usr/ssl/certs/ca-bundle.crt',
            ];
            foreach ($winCaPaths as $caPath) {
                if (file_exists($caPath)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $caPath);
                    break;
                }
            }
        }

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = null;

        if ($body === false) {
            $error = curl_error($ch);
            $body = '';
        }

        curl_close($ch);

        return [
            'status' => (int)$status,
            'body'   => (string)$body,
            'error'  => $error,
        ];
    }

    public static function setMockTransport(?callable $transport): void {
        self::$mockTransport = $transport;
    }

    public static function clearTokenCache(): void {
        self::$tokenCache = [];
    }

    public static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
