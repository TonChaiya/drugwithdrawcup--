<?php

function security_rate_identity(string $value): string
{
    return hash('sha256', $value);
}

function security_rate_retry_after(PDO $pdo, string $bucket, string $identity): int
{
    try {
        $stmt = $pdo->prepare('SELECT blocked_until FROM security_rate_limits WHERE bucket=? AND identity_hash=? LIMIT 1');
        $stmt->execute([substr($bucket, 0, 40), security_rate_identity($identity)]);
        $blockedUntil = $stmt->fetchColumn();
        return $blockedUntil ? max(0, strtotime((string)$blockedUntil) - time()) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function security_rate_record(PDO $pdo, string $bucket, string $identity, int $limit, int $windowSeconds, int $blockSeconds): void
{
    if ($pdo->inTransaction()) return;
    try {
        $pdo->beginTransaction();
        $hash = security_rate_identity($identity);
        $stmt = $pdo->prepare('SELECT attempts,window_started_at FROM security_rate_limits WHERE bucket=? AND identity_hash=? FOR UPDATE');
        $stmt->execute([substr($bucket, 0, 40), $hash]);
        $row = $stmt->fetch();
        $windowExpired = !$row || strtotime((string)$row['window_started_at']) < time() - $windowSeconds;
        $attempts = $windowExpired ? 1 : (int)$row['attempts'] + 1;
        $blockedUntil = $attempts >= $limit ? date('Y-m-d H:i:s', time() + $blockSeconds) : null;
        if (!$row) {
            $insert = $pdo->prepare('INSERT INTO security_rate_limits(bucket,identity_hash,attempts,window_started_at,blocked_until) VALUES(?,?,?,NOW(),?)');
            $insert->execute([substr($bucket, 0, 40),$hash,$attempts,$blockedUntil]);
        } else {
            $update = $pdo->prepare('UPDATE security_rate_limits SET attempts=?,window_started_at=IF(?,NOW(),window_started_at),blocked_until=? WHERE bucket=? AND identity_hash=?');
            $update->execute([$attempts,$windowExpired ? 1 : 0,$blockedUntil,substr($bucket, 0, 40),$hash]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Persistent rate limit unavailable: '.$e->getMessage());
    }
}

function security_rate_clear(PDO $pdo, string $bucket, string $identity): void
{
    try {
        $stmt = $pdo->prepare('DELETE FROM security_rate_limits WHERE bucket=? AND identity_hash=?');
        $stmt->execute([substr($bucket, 0, 40), security_rate_identity($identity)]);
    } catch (Throwable $e) {
        // Migration may not yet have been applied; session throttling still applies.
    }
}
