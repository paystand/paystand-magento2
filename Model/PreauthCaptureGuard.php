<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Model;

/**
 * Admin capture is the full remaining authorization, or a refusal.
 */
class PreauthCaptureGuard
{
    public const TOLERANCE = 0.01;

    /**
     * @param array $hold status, remaining, expires_at
     * @return string|null refusal text, or null when the capture may proceed
     */
    public function refusal(array $hold, $amount): ?string
    {
        $requested = (float) $amount;
        if ($requested <= 0) {
            return 'Capture amount must be greater than zero.';
        }

        $remaining = (float) ($hold['remaining'] ?? 0);
        if (abs($requested - $remaining) > self::TOLERANCE) {
            return 'Capture must be the full remaining authorization.';
        }

        $status = strtolower(trim((string) ($hold['status'] ?? '')));
        if ($status !== 'authorized') {
            return 'Authorization cannot be captured.';
        }

        $expiresAt = $hold['expires_at'] ?? '';
        if ($expiresAt !== '' && strtotime((string) $expiresAt) !== false && strtotime((string) $expiresAt) < time()) {
            return 'Authorization has expired.';
        }

        return null;
    }
}
