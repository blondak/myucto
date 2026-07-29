<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Sliding-window počítadla selhání login pokusů per (email, IP).
 * Tři okna (5 min / 15 min / 60 min) → 3 prahové hodnoty:
 *   - >= captcha_after / 5 min  → CAPTCHA required
 *   - >= lockout_15m_at / 15 min → 15min lockout
 *   - >= lockout_24h_at / 60 min → 24h lockout
 */
final class BruteForceGuard
{
    public const STATE_OK              = 'ok';
    public const STATE_CAPTCHA         = 'captcha_required';
    public const STATE_LOCKED_15M      = 'locked_15m';
    public const STATE_LOCKED_24H      = 'locked_24h';

    public function __construct(
        private readonly Config $config,
        private readonly RedisFactory $redis,
        private readonly Connection $db,
    ) {}

    /** @return list<int> Tři window seconds: short / mid / long. */
    private function windows(): array
    {
        $w = $this->config->get('brute_force.window_seconds', [300, 900, 3600]);
        if (!is_array($w) || count($w) !== 3) {
            return [300, 900, 3600];
        }
        return [(int) $w[0], (int) $w[1], (int) $w[2]];
    }

    public function recordFailure(string $email, string $ip): void
    {
        $bucket = $this->bucketKey($email, $ip);

        $windows = $this->windows();
        $done = $this->redis->run(function ($r) use ($bucket, $windows) {
            foreach ($windows as $window) {
                $key = "bf:{$bucket}:{$window}";
                $r->incr($key);
                $r->expire($key, $window);
            }
            return true;
        }, false);
        if ($done === true) {
            return;
        }

        // Fallback: insert do MEMORY tabulky
        $packed = inet_pton($ip);
        if ($packed === false) {
            $packed = "\x00\x00\x00\x00";
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO login_attempts (bucket_key, email, ip_packed, success) VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$bucket, $email, $packed]);
    }

    public function recordSuccess(string $email, string $ip): void
    {
        $bucket = $this->bucketKey($email, $ip);

        $windows = $this->windows();
        $done = $this->redis->run(function ($r) use ($bucket, $windows) {
            foreach ($windows as $window) {
                $r->del("bf:{$bucket}:{$window}");
            }
            return true;
        }, false);
        if ($done === true) {
            return;
        }

        $stmt = $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE bucket_key = ?');
        $stmt->execute([$bucket]);
    }

    public function check(string $email, string $ip): string
    {
        $bucket = $this->bucketKey($email, $ip);

        $captchaAfter = (int) $this->config->get('brute_force.captcha_after', 5);
        $lockout15m   = (int) $this->config->get('brute_force.lockout_15m_at', 10);
        $lockout24h   = (int) $this->config->get('brute_force.lockout_24h_at', 30);
        [$wShort, $wMid, $wLong] = $this->windows();

        $counts = $this->redis->run(fn($r) => [
            (int) ($r->get("bf:{$bucket}:{$wShort}") ?? 0),
            (int) ($r->get("bf:{$bucket}:{$wMid}")   ?? 0),
            (int) ($r->get("bf:{$bucket}:{$wLong}")  ?? 0),
        ]);

        if (is_array($counts)) {
            [$countShort, $countMid, $countLong] = $counts;
        } else {
            $pdo = $this->db->pdo();
            $sql = 'SELECT COUNT(*) FROM login_attempts WHERE bucket_key = ? AND success = 0 AND created_at >= NOW() - INTERVAL ? SECOND';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$bucket, $wShort]); $countShort = (int) $stmt->fetchColumn();
            $stmt->execute([$bucket, $wMid]);   $countMid   = (int) $stmt->fetchColumn();
            $stmt->execute([$bucket, $wLong]);  $countLong  = (int) $stmt->fetchColumn();
        }

        if ($countLong >= $lockout24h) return self::STATE_LOCKED_24H;
        if ($countMid  >= $lockout15m) return self::STATE_LOCKED_15M;
        if ($countShort >= $captchaAfter) return self::STATE_CAPTCHA;
        return self::STATE_OK;
    }

    /**
     * TOTP brute-force counter — per user (ne per IP), nezávislý na login bucketu.
     * Útočník s platným heslem zkouší 6místný TOTP — 10⁶ kombinací. Lockout: 10 selhání / 10 min.
     *
     * @return bool true = lock active (zamítni), false = OK pokračovat
     */
    public function isTotpLocked(int $userId): bool
    {
        $key = "totp:fail:{$userId}";
        $threshold = 10;
        $count = $this->redis->run(fn($r) => (int) ($r->get($key) ?? 0));
        if ($count !== null) {
            return $count >= $threshold;
        }
        // Fallback DB — login_attempts s bucket_key totp:user:{id}
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM login_attempts
              WHERE bucket_key = ? AND success = 0
                AND created_at >= NOW() - INTERVAL 600 SECOND"
        );
        $stmt->execute(["totp:user:{$userId}"]);
        return (int) $stmt->fetchColumn() >= $threshold;
    }

    public function recordTotpFailure(int $userId): void
    {
        $key = "totp:fail:{$userId}";
        $done = $this->redis->run(function ($r) use ($key) {
            $r->incr($key);
            $r->expire($key, 600);
            return true;
        }, false);
        if ($done === true) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO login_attempts (bucket_key, email, ip_packed, success) VALUES (?, '', '', 0)"
        );
        $stmt->execute(["totp:user:{$userId}"]);
    }

    public function recordTotpSuccess(int $userId): void
    {
        $key = "totp:fail:{$userId}";
        $done = $this->redis->run(function ($r) use ($key) {
            $r->del($key);
            return true;
        }, false);
        if ($done === true) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE bucket_key = ?');
        $stmt->execute(["totp:user:{$userId}"]);
    }

    /**
     * E-mail OTP brute-force counter — per user. Analogie TOTP lockoutu pro
     * uživatele bez authenticator appky (6místný kód = 10⁶ keyspace).
     * Lockout: 10 selhání / 10 min.
     */
    public function isEmailOtpLocked(int $userId): bool
    {
        $key = "eotp:fail:{$userId}";
        $threshold = 10;
        $count = $this->redis->run(fn($r) => (int) ($r->get($key) ?? 0));
        if ($count !== null) {
            return $count >= $threshold;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM login_attempts
              WHERE bucket_key = ? AND success = 0
                AND created_at >= NOW() - INTERVAL 600 SECOND"
        );
        $stmt->execute(["eotp:user:{$userId}"]);
        return (int) $stmt->fetchColumn() >= $threshold;
    }

    public function recordEmailOtpFailure(int $userId): void
    {
        $key = "eotp:fail:{$userId}";
        $done = $this->redis->run(function ($r) use ($key) {
            $r->incr($key);
            $r->expire($key, 600);
            return true;
        }, false);
        if ($done === true) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO login_attempts (bucket_key, email, ip_packed, success) VALUES (?, '', '', 0)"
        );
        $stmt->execute(["eotp:user:{$userId}"]);
    }

    public function recordEmailOtpSuccess(int $userId): void
    {
        $key = "eotp:fail:{$userId}";
        $done = $this->redis->run(function ($r) use ($key) {
            $r->del($key);
            return true;
        }, false);
        if ($done === true) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE bucket_key = ?');
        $stmt->execute(["eotp:user:{$userId}"]);
    }

    /**
     * WebAuthn assertion failure counter — sdílený pro login, step-up a unlock.
     * IP bucket řeší RateLimitMiddleware; tento bucket chrání konkrétní účet
     * také bez Redis. Lockout: 10 selhání / 10 min.
     */
    public function isPasskeyLocked(int $userId): bool
    {
        $key = "passkey:fail:{$userId}";
        $threshold = 10;
        $count = $this->redis->run(fn($r) => (int) ($r->get($key) ?? 0));
        if ($count !== null) {
            return $count >= $threshold;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM login_attempts
              WHERE bucket_key = ? AND success = 0
                AND created_at >= NOW() - INTERVAL 600 SECOND"
        );
        $stmt->execute(["passkey:user:{$userId}"]);
        return (int) $stmt->fetchColumn() >= $threshold;
    }

    public function recordPasskeyFailure(int $userId): void
    {
        $key = "passkey:fail:{$userId}";
        $done = $this->redis->run(function ($r) use ($key) {
            $r->incr($key);
            $r->expire($key, 600);
            return true;
        }, false);
        if ($done === true) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO login_attempts (bucket_key, email, ip_packed, success) VALUES (?, '', '', 0)"
        );
        $stmt->execute(["passkey:user:{$userId}"]);
    }

    public function recordPasskeySuccess(int $userId): void
    {
        $key = "passkey:fail:{$userId}";
        $done = $this->redis->run(function ($r) use ($key) {
            $r->del($key);
            return true;
        }, false);
        if ($done === true) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE bucket_key = ?');
        $stmt->execute(["passkey:user:{$userId}"]);
    }

    private function bucketKey(string $email, string $ip): string
    {
        // /24 pro IPv4, /64 pro IPv6 — zabraňuje obcházení přes sousední IP
        $packed = inet_pton($ip);
        if ($packed === false) {
            $netKey = sha1($ip);
        } elseif (strlen($packed) === 4) {
            $netKey = bin2hex(substr($packed, 0, 3)); // /24
        } else {
            $netKey = bin2hex(substr($packed, 0, 8)); // /64
        }
        return sha1(strtolower($email)) . ':' . $netKey;
    }
}
