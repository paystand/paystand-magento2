<?php
declare(strict_types=1);

namespace PayStand\PayStandMagento\Model;

/**
 * Admin capture is the full remaining authorization, or a refusal.
 * Partial capture is not supported. Roadrunner still enforces over-capture.
 */
class PreauthCaptureGuard
{
    public const TOLERANCE = 0.01;

    private const CAPTURABLE_STATUSES = ['authorized', 'partially_captured'];

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
        if ($requested - $remaining > self::TOLERANCE) {
            return 'Capture amount is above the remaining authorization.';
        }
        if ($remaining - $requested > self::TOLERANCE) {
            return 'Partial capture is not supported. Capture the full remaining authorization.';
        }

        if (!$this->hasCapturableStatus($hold)) {
            return 'Authorization cannot be captured.';
        }

        if ($this->isExpired($hold)) {
            return 'Authorization has expired.';
        }

        return null;
    }

    public function canCapture(array $hold): bool
    {
        return $this->refusal($hold, $hold['remaining'] ?? 0) === null;
    }

    private function hasCapturableStatus(array $hold): bool
    {
        $status = strtolower(trim((string) ($hold['status'] ?? '')));

        return in_array($status, self::CAPTURABLE_STATUSES, true);
    }

    private function isExpired(array $hold): bool
    {
        $expiresAt = strtotime((string) ($hold['expires_at'] ?? ''));

        return $expiresAt !== false && $expiresAt < time();
    }
}
