<?php
// Lightweight Shiprocket wrapper with token caching in DB
require_once __DIR__ . '/../dbconf.php';

class Shiprocket {
    private $base;
    private $conn;

    public function __construct(PDO $conn) {
        $this->conn = $conn;
        // Allow overriding API base via env var for tests / regional endpoints
        $this->base = rtrim($_ENV['SHIPROCKET_BASE_URL'] ?? 'https://apiv2.shiprocket.in/v1/external', '/');
    }

    private function log($msg) {
        error_log('[Shiprocket] ' . $msg);
    }

    private function getCachedToken() {
        $stmt = $this->conn->query("SELECT * FROM shiprocket_tokens ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $expires = new DateTime($row['expires_at']);
        $now = new DateTime();
        if ($expires > $now) return $row['token'];
        return null;
    }

    private function cacheToken($token, $ttlSeconds = 3600, $raw = null) {
        $expires = (new DateTime())->add(new DateInterval('PT' . intval($ttlSeconds) . 'S'));
        // Delete all old tokens first, then insert fresh one
        $this->conn->exec("DELETE FROM shiprocket_tokens");
        $stmt = $this->conn->prepare(
            "INSERT INTO shiprocket_tokens (token, expires_at, raw_response) VALUES (?, ?, ?)"
        );
        $stmt->execute([$token, $expires->format('Y-m-d H:i:s'), $raw ? json_encode($raw) : null]);
    }

private function authenticate() {
    $cached = $this->getCachedToken();
    if ($cached) return $cached;

    $email = $_ENV['SHIPROCKET_EMAIL'] ?? null;
    $password = $_ENV['SHIPROCKET_PASSWORD'] ?? null;

    if (!$email || !$password) {
        throw new Exception('SHIPROCKET_EMAIL and SHIPROCKET_PASSWORD must be set');
    }

    $url = $this->base . '/auth/login';
    $payload = ['email' => $email, 'password' => $password];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 60,
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false) { throw new Exception('Auth network error: ' . curl_error($ch)); }
    curl_close($ch);
    $json = json_decode($resp, true);

    if ($code !== 200 || empty($json['token'])) {
        throw new Exception('Shiprocket auth failed: ' . ($json['message'] ?? $resp));
    }

    // Shiprocket tokens are valid ~24 hours
    $this->cacheToken($json['token'], 86400, $json);
    return $json['token'];
}


    // Convenience method to test credentials without calling other endpoints
    public function testAuth() {
        $token = $this->authenticate();
        return ['token' => $token, 'base' => $this->base];
    }
public function request($method, $path, $body = null, $retry = true) {
    $token = $this->authenticate();
    $url = rtrim($this->base, '/') . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false) {
        throw new Exception('Shiprocket request network error: ' . curl_error($ch));
    }
    curl_close($ch);

    if (in_array($code, [401, 403]) && $retry) {
        // token expired → force new login
        $this->conn->exec("DELETE FROM shiprocket_tokens");
        return $this->request($method, $path, $body, false);
    }

    $json = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
  throw new Exception(json_encode([
            'shiprocket_error' => true,
            'http_code' => $code,
            'url' => $url,
            'request_body' => $body,
            'raw_response' => $resp,
            'decoded_response' => $json
        ], JSON_PRETTY_PRINT));
    }

    return $json;
}

    // Create ad-hoc order
    public function createOrder(array $orderPayload) {
        return $this->request('POST', '/orders/create/adhoc', $orderPayload);
    }

    // Assign AWB / allocate courier
    public function assignAwb(array $payload) {
        // Typical payload: { "order_id": "123" } or { "orders": [ {"order_id":...} ] }
        return $this->request('POST', '/courier/assign/awb', $payload);
    }

    // Get AWB label(s) - sometimes returned from assignAwb; provide wrapper to fetch labels if available
    public function getLabel(array $payload) {
        return $this->request('POST', '/courier/awb/label', $payload);
    }

    // Schedule pickup
    public function schedulePickup(array $payload) {
        return $this->request('POST', '/courier/pickup', $payload);
    }

    // Track by AWB
    public function trackAwb($awb) {
        return $this->request('GET', '/courier/track/awb/' . urlencode($awb));
    }

    // // Get all available couriers
    // public function getCouriers() {
    //     return $this->request('GET', '/courier/serviceability?pickup_postcode=110001&delivery_postcode=110001&weight=1&cod=0');
    // }

    public function getCouriers($deliveryPostcode, $weight = 1, $cod = 0) {
        $pickup = $_ENV['SHIPROCKET_PICKUP_PINCODE'] ?? '';
        if (!preg_match('/^[1-9][0-9]{5}$/', $pickup)) {
            throw new Exception('SHIPROCKET_PICKUP_PINCODE is not configured or invalid.');
        }

        $query = http_build_query([
            'pickup_postcode'   => $pickup,
            'delivery_postcode' => $deliveryPostcode,
            'weight'            => $weight,
            'cod'               => $cod
        ]);

        return $this->request('GET', '/courier/serviceability?' . $query);
    }

}

// Helper factory
function shiprocketClient() {
    global $conn;
    return new Shiprocket($conn);
}

