<?php
/**
 * File-based rate limiter.
 *
 * Tracks request timestamps per IP in JSON files under a configurable directory.
 * Supports sliding window with configurable limit and window size.
 */
class RateLimiter
{
    private string $dir;
    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(string $dir, int $maxRequests, int $windowSeconds)
    {
        $this->dir = $dir;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Get the client IP, preferring REMOTE_ADDR over forwarded headers
     * to avoid trivial spoofing. If behind a trusted reverse proxy,
     * configure the proxy to set REMOTE_ADDR correctly instead.
     */
    public static function getClientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Check whether the given IP is within the rate limit.
     * Returns true if the request is allowed, false if rate-limited.
     *
     * When allowed, the current timestamp is recorded automatically.
     */
    public function check(?string $ip = null): bool
    {
        $ip = $ip ?? self::getClientIp();
        $file = $this->dir . '/' . md5($ip) . '.json';
        $now = time();

        $timestamps = [];
        if (file_exists($file)) {
            $data = @json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                // Keep only timestamps within the current window
                $timestamps = array_values(array_filter(
                    $data,
                    fn($t) => ($now - $t) < $this->windowSeconds
                ));
            }
        }

        if (count($timestamps) >= $this->maxRequests) {
            return false;
        }

        $timestamps[] = $now;
        file_put_contents($file, json_encode($timestamps), LOCK_EX);
        return true;
    }

    /**
     * Return how many seconds until the next request will be allowed.
     * Returns 0 if not currently rate-limited.
     */
    public function retryAfter(?string $ip = null): int
    {
        $ip = $ip ?? self::getClientIp();
        $file = $this->dir . '/' . md5($ip) . '.json';
        $now = time();

        if (!file_exists($file)) {
            return 0;
        }

        $data = @json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return 0;
        }

        $timestamps = array_values(array_filter(
            $data,
            fn($t) => ($now - $t) < $this->windowSeconds
        ));

        if (count($timestamps) < $this->maxRequests) {
            return 0;
        }

        // Oldest timestamp in the window determines when a slot frees up
        sort($timestamps);
        return ($timestamps[0] + $this->windowSeconds) - $now;
    }
}
