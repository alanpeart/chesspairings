<?php
/**
 * Simple file-based cache for scraped tournament data.
 *
 * Cache key = tournament URL (normalised). TTL varies:
 *   - Live tournaments: 2 minutes (data changes between rounds)
 *   - Completed tournaments: 60 minutes (data is static)
 *
 * Cache is stored as serialized PHP in /cache/<md5>.php files.
 * The /cache/ directory is .gitignored.
 */
class TournamentCache
{
    private string $cacheDir;
    private int $liveTtl;
    private int $completedTtl;

    public function __construct(
        ?string $cacheDir = null,
        int $liveTtl = 120,       // 2 minutes for live tournaments
        int $completedTtl = 3600  // 1 hour for completed tournaments
    ) {
        $this->cacheDir = $cacheDir ?? __DIR__ . '/../cache';
        $this->liveTtl = $liveTtl;
        $this->completedTtl = $completedTtl;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Generate a cache key from a tournament URL.
     * Strips volatile query params (zeilen, etc.) so the same tournament
     * always hits the same cache entry.
     */
    private function cacheKey(string $url): string
    {
        // Normalise: extract just the tnrNNNNN and lan parameter
        if (preg_match('#tnr(\d+)\.aspx\?lan=(\d+)#i', $url, $m)) {
            $key = "tnr{$m[1]}_lan{$m[2]}";
        } else {
            $key = md5($url);
        }
        return $key;
    }

    private function cacheFile(string $key): string
    {
        return $this->cacheDir . '/' . $key . '.cache';
    }

    /**
     * Retrieve cached tournament data if still valid.
     * Returns null on cache miss or expiry.
     */
    public function get(string $url): ?array
    {
        $file = $this->cacheFile($this->cacheKey($url));

        if (!file_exists($file)) {
            return null;
        }

        $cached = @unserialize(file_get_contents($file));
        if (!is_array($cached) || !isset($cached['data'], $cached['timestamp'], $cached['isCompleted'])) {
            @unlink($file);
            return null;
        }

        $ttl = $cached['isCompleted'] ? $this->completedTtl : $this->liveTtl;
        $age = time() - $cached['timestamp'];

        if ($age > $ttl) {
            @unlink($file);
            return null;
        }

        return $cached['data'];
    }

    /**
     * Store tournament data in cache.
     */
    public function set(string $url, array $data, bool $isCompleted): void
    {
        $file = $this->cacheFile($this->cacheKey($url));

        $payload = [
            'data' => $data,
            'timestamp' => time(),
            'isCompleted' => $isCompleted,
        ];

        file_put_contents($file, serialize($payload), LOCK_EX);
    }

    /**
     * Purge expired cache files. Call periodically or via cron.
     */
    public function purgeExpired(): int
    {
        $purged = 0;
        foreach (glob($this->cacheDir . '/*.cache') as $file) {
            $cached = @unserialize(file_get_contents($file));
            if (!is_array($cached) || !isset($cached['timestamp'], $cached['isCompleted'])) {
                @unlink($file);
                $purged++;
                continue;
            }
            $ttl = $cached['isCompleted'] ? $this->completedTtl : $this->liveTtl;
            if (time() - $cached['timestamp'] > $ttl) {
                @unlink($file);
                $purged++;
            }
        }
        return $purged;
    }
}
