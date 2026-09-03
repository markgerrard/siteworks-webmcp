<?php

use Symfony\Component\Finder\Finder;

/**
 * Regression guard for the duplicate-delivery incident class: if redis
 * retry_after is smaller than any job's $timeout, Redis re-delivers the
 * job to a sibling worker while the original is still running (double
 * pipeline execution for tries>1 jobs, premature failed() for tries=1).
 *
 * retry_after must stay strictly greater than the largest $timeout
 * declared across app/Jobs — raise REDIS_QUEUE_RETRY_AFTER (or the
 * config default) whenever a longer-running job is introduced.
 */
it('keeps redis retry_after above every job timeout', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    $timeouts = collect(Finder::create()->files()->in(app_path('Jobs'))->name('*.php'))
        ->map(function ($file) {
            $relative = str_replace([app_path().DIRECTORY_SEPARATOR, '.php'], '', $file->getRealPath());

            return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        })
        ->filter(fn (string $class) => class_exists($class))
        ->map(fn (string $class) => (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null)
        ->filter(fn ($timeout) => is_int($timeout));

    expect($timeouts)->not->toBeEmpty();

    $maxTimeout = $timeouts->max();

    expect($retryAfter)->toBeGreaterThan(
        $maxTimeout,
        "queue.connections.redis.retry_after ({$retryAfter}s) must exceed the largest job \$timeout ({$maxTimeout}s), "
        .'otherwise in-flight jobs are re-delivered to sibling workers mid-run.'
    );
});
