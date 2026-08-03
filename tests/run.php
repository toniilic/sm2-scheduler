<?php

declare(strict_types=1);

/**
 * Zero-dependency test runner: php tests/run.php
 */

require __DIR__ . '/../src/Sm2Scheduler.php';

$failures = 0;
$count = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
        echo "FAIL  {$label}  {$detail}\n";
    } else {
        echo "ok    {$label}\n";
    }
}

function assertSame($expected, $actual, string $label): void
{
    check($label, $expected === $actual, 'expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
}

function assertTrue($cond, string $label): void
{
    check($label, $cond === true, 'expected true, got ' . var_export($cond, true));
}

$s = new Sm2Scheduler();
$now = new DateTimeImmutable('2026-08-03 12:00:00');

// --- New card learning steps ---
$r = $s->schedule(Sm2Scheduler::RATING_AGAIN, 0, 250, $now);
assertSame('learning', $r['status'], 'new+Again → learning');
assertSame(0, $r['interval_days'], 'new+Again → 0-day interval');
assertSame(230, $r['ease_factor'], 'new+Again → ease 250-20=230');
assertSame('2026-08-03 12:10:00', $r['next_review_at']->format('Y-m-d H:i:s'), 'new+Again → +10 minutes');

$r = $s->schedule(Sm2Scheduler::RATING_HARD, 0, 250, $now);
assertSame(1, $r['interval_days'], 'new+Hard → 1d');
assertSame(235, $r['ease_factor'], 'new+Hard → ease 250-15=235');
assertSame('2026-08-04 12:00:00', $r['next_review_at']->format('Y-m-d H:i:s'), 'new+Hard → +1 day');

$r = $s->schedule(Sm2Scheduler::RATING_GOOD, 0, 250, $now);
assertSame(3, $r['interval_days'], 'new+Good → 3d');
assertSame(250, $r['ease_factor'], 'new+Good → ease unchanged');
assertSame('learning', $r['status'], 'new+Good → still learning');

$r = $s->schedule(Sm2Scheduler::RATING_EASY, 0, 250, $now);
assertSame(7, $r['interval_days'], 'new+Easy → 7d');
assertSame(265, $r['ease_factor'], 'new+Easy → ease 250+15=265');
assertSame('known', $r['status'], 'new+Easy → known');

// --- Graduated card ---
$r = $s->schedule(Sm2Scheduler::RATING_GOOD, 8, 250, $now);
assertSame(20, $r['interval_days'], 'graduated+Good → 8*2.5=20d');
assertSame('known', $r['status'], 'graduated+Good ≥7d → known');

$r = $s->schedule(Sm2Scheduler::RATING_HARD, 8, 250, $now);
assertSame(6, $r['interval_days'], 'graduated+Hard → 8*0.8=6.4→6d');
assertTrue($r['interval_days'] < 8, 'Hard < current interval');

$r = $s->schedule(Sm2Scheduler::RATING_EASY, 8, 250, $now);
assertSame(28, $r['interval_days'], 'graduated+Easy → 8 * (2.5+0.15) * 1.3 = 27.56 → 28d');

// --- Lapse on graduated card ---
$r = $s->schedule(Sm2Scheduler::RATING_AGAIN, 20, 250, $now);
assertSame(0, $r['interval_days'], 'lapse → 0-day relearn step');
assertSame(230, $r['ease_factor'], 'lapse → ease 250-20=230');
assertSame('2026-08-03 12:10:00', $r['next_review_at']->format('Y-m-d H:i:s'), 'lapse → +10 minutes');

// --- Clamps ---
$r = $s->schedule(Sm2Scheduler::RATING_EASY, 300, 290, $now);
assertSame(365, $r['interval_days'], 'interval capped at 365d');
assertSame(300, $r['ease_factor'], 'ease capped at 3.0');

$r = $s->schedule(Sm2Scheduler::RATING_AGAIN, 365, 130, $now);
assertSame(130, $r['ease_factor'], 'ease floored at 1.3');

// --- Hard < Good invariant on same base ---
for ($iv = 1; $iv <= 30; $iv++) {
    $hard = $s->schedule(Sm2Scheduler::RATING_HARD, $iv, 250, $now)['interval_days'];
    $good = $s->schedule(Sm2Scheduler::RATING_GOOD, $iv, 250, $now)['interval_days'];
    check("Hard < Good at iv={$iv}d", $hard <= $good, "hard={$hard} good={$good}");
}

// --- Valid rating guard ---
try {
    $s->schedule(9, 0, 250, $now);
    check('invalid rating throws', false);
} catch (InvalidArgumentException $e) {
    check('invalid rating throws', true);
}

echo "\n{$count} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
