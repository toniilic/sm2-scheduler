<?php

declare(strict_types=1);

/**
 * SM-2 spaced repetition scheduler with learning steps.
 *
 * Faithful port of the scheduler running in production at
 * LearnCoachAssist (https://learncoachassist.com) after the
 * "state never persisted" bug class was fixed.
 *
 * Design:
 *  - New cards: Again → 10m relearn step, Hard → 1d, Good → 3d, Easy → 7d
 *  - Graduated: Hard = round(iv * 0.8) (always shorter than Good),
 *    Good = round(iv * ef), Easy = round(iv * ef * 1.3)
 *  - Lapse (Again): 10m relearn step, ease − 0.20
 *  - Ease clamped [1.3, 3.0], interval capped at 365 days
 *  - Card becomes "known" once its interval reaches ≥ 7 days
 *
 * @see https://github.com/toniilic/sm2-scheduler
 */
final class Sm2Scheduler
{
    public const RATING_AGAIN = 0;
    public const RATING_HARD  = 1;
    public const RATING_GOOD  = 2;
    public const RATING_EASY  = 3;

    public const STATUS_LEARNING = 'learning';
    public const STATUS_KNOWN    = 'known';

    public const EASE_MIN = 1.3;
    public const EASE_MAX = 3.0;

    public const EASE_DELTA_AGAIN = 0.20;
    public const EASE_DELTA_HARD  = 0.15;
    public const EASE_DELTA_EASY  = 0.15;

    public const MAX_INTERVAL_DAYS  = 365;
    public const KNOWN_THRESHOLD_DAYS = 7;
    public const RELEARN_STEP_MINUTES = 10;

    // New-card learning steps (days). Interval 0 = the 10-minute relearn step.
    public const NEW_AGAIN_DAYS = 0;
    public const NEW_HARD_DAYS  = 1;
    public const NEW_GOOD_DAYS  = 3;
    public const NEW_EASY_DAYS  = 7;

    /**
     * Compute the next scheduling state for a card.
     *
     * @param  int  $rating        0=Again, 1=Hard, 2=Good, 3=Easy
     * @param  int  $intervalDays  current interval in days (0 = never graduated)
     * @param  int  $easeFactor    current ease factor as integer × 100 (e.g. 250 = 2.5)
     *
     * @return array{
     *     status: string,
     *     interval_days: int,
     *     ease_factor: int,
     *     next_review_at: DateTimeImmutable
     * }
     */
    public function schedule(int $rating, int $intervalDays, int $easeFactor, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $ef = $easeFactor / 100.0;
        $isNew = $intervalDays < 1;

        switch ($rating) {
            case self::RATING_AGAIN: // 10-minute relearn step, ease − 0.20
                $newInterval = self::NEW_AGAIN_DAYS;
                $ef = max(self::EASE_MIN, $ef - self::EASE_DELTA_AGAIN);
                $status = self::STATUS_LEARNING;
                break;

            case self::RATING_HARD: // always shorter than Good
                $ef = max(self::EASE_MIN, $ef - self::EASE_DELTA_HARD);
                $newInterval = $isNew
                    ? self::NEW_HARD_DAYS
                    : max(1, (int) round($intervalDays * 0.8));
                $status = self::STATUS_LEARNING;
                break;

            case self::RATING_GOOD: // classic SM-2: interval × ease
                if ($isNew) {
                    $newInterval = self::NEW_GOOD_DAYS;
                    $status = self::STATUS_LEARNING;
                } else {
                    $newInterval = max(1, (int) round($intervalDays * $ef));
                    $status = $newInterval >= self::KNOWN_THRESHOLD_DAYS
                        ? self::STATUS_KNOWN
                        : self::STATUS_LEARNING;
                }
                break;

            case self::RATING_EASY:
                $ef = min(self::EASE_MAX, $ef + self::EASE_DELTA_EASY);
                $newInterval = $isNew
                    ? self::NEW_EASY_DAYS
                    : max(1, (int) round($intervalDays * $ef * 1.3));
                $status = self::STATUS_KNOWN;
                break;

            default:
                throw new InvalidArgumentException("Unknown rating: {$rating}");
        }

        $newInterval = min(self::MAX_INTERVAL_DAYS, $newInterval);

        // Interval 0 means "the 10-minute relearn step" — not "review tomorrow".
        $nextReviewAt = $newInterval > 0
            ? $now->modify("+{$newInterval} days")
            : $now->modify('+' . self::RELEARN_STEP_MINUTES . ' minutes');

        return [
            'status'         => $status,
            'interval_days'  => $newInterval,
            'ease_factor'    => (int) round($ef * 100),
            'next_review_at' => $nextReviewAt,
        ];
    }
}
