# sm2-scheduler

SM-2 spaced repetition scheduler with **learning steps**, lapse relearn, ease clamps and a 365-day cap — in one dependency-free PHP class.

This is a faithful port of the scheduler running in production at [LearnCoachAssist](https://learncoachassist.com) — free AI flashcards with spaced repetition, written after fixing a bug class where review state (`ease_factor`, `interval_days`, `review_count`) was never persisted — silently resetting every card to "new" on every review. Read the story: [Why Flashcard Apps Fail at Spaced Repetition](https://learncoachassist.com/blog/spaced-repetition-apps-wrong).

## Why this scheduler

Most SM-2 implementations get the basics right but trip on three things:

1. **No learning steps.** A brand-new card rated "Again" comes back tomorrow — but you're still forming the memory. This scheduler uses a **10-minute relearn step** (interval `0` = +10 minutes, not +1 day).
2. **Hard ≥ Good.** Some implementations grow Hard by a fixed factor that overtakes Good. Here **Hard = 80% of the current interval**, always shorter than Good.
3. **State that doesn't persist.** If the card's interval/ease/review count never saves, every review re-computes from defaults and the card is permanently "new". This package is the scheduling logic; make sure you *actually save* the returned state.

## Scheduling rules

| Rating | New card | Graduated card | Ease change |
|---|---|---|---|
| Again (0) | 10 min relearn step | 10 min relearn step (lapse) | −0.20 |
| Hard (1) | 1 day | `max(1, round(iv × 0.8))` | −0.15 |
| Good (2) | 3 days | `max(1, round(iv × ef))` | — |
| Easy (3) | 7 days | `max(1, round(iv × ef × 1.3))` | +0.15 |

- Ease factor clamped to **[1.3, 3.0]**, stored as integer × 100 (250 = 2.5)
- Interval capped at **365 days** (nothing gets lost forever)
- Card becomes `known` once its interval reaches **≥ 7 days**
- Interval `0` always means "10-minute relearn step", never "review tomorrow"

## Install

```bash
composer require toniilic/sm2-scheduler
# or just drop src/Sm2Scheduler.php into your project — zero dependencies
```

## Usage

```php
$scheduler = new Sm2Scheduler();

// Card: rated Good, current interval 3 days, ease 250 (2.5)
$result = $scheduler->schedule(
    Sm2Scheduler::RATING_GOOD,
    intervalDays: 3,
    easeFactor: 250,
);

// [
//   'status'         => 'learning',
//   'interval_days'  => 8,          // 3 × 2.5 = 7.5 → 8
//   'ease_factor'    => 250,
//   'next_review_at' => DateTimeImmutable('+8 days'),
// ]

// Persist all four fields. Seriously — the bug this fixes was a save that
// silently dropped interval/ease/review_count.
```

## Tests

```bash
php tests/run.php
```

Zero dependencies, plain assertions, CI-friendly exit code.

## License

MIT
