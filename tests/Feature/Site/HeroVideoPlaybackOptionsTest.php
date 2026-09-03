<?php

use App\Enums\HeroVideoLoop;

it('resolves an unset loop column to continuous so existing sites are unchanged', function () {
    expect(HeroVideoLoop::resolve(null))->toBe(HeroVideoLoop::Continuous)
        ->and(HeroVideoLoop::resolve(null)->isNative())->toBeTrue()
        ->and(HeroVideoLoop::resolve(null)->repeats())->toBeNull();
});

it('falls back to continuous for an unrecognised stored value', function () {
    expect(HeroVideoLoop::resolve('nonsense'))->toBe(HeroVideoLoop::Continuous);
});

it('treats only continuous as natively loopable', function () {
    expect(HeroVideoLoop::Continuous->isNative())->toBeTrue()
        ->and(HeroVideoLoop::None->isNative())->toBeFalse()
        ->and(HeroVideoLoop::Count1->isNative())->toBeFalse()
        ->and(HeroVideoLoop::Count2->isNative())->toBeFalse()
        ->and(HeroVideoLoop::Count3->isNative())->toBeFalse();
});

it('reports repeats as plays-after-the-first', function () {
    expect(HeroVideoLoop::None->repeats())->toBe(0)
        ->and(HeroVideoLoop::Count1->repeats())->toBe(1)
        ->and(HeroVideoLoop::Count2->repeats())->toBe(2)
        ->and(HeroVideoLoop::Count3->repeats())->toBe(3)
        ->and(HeroVideoLoop::Continuous->repeats())->toBeNull();
});

it('exposes exactly the five modes the studio offers', function () {
    expect(array_map(fn ($c) => $c->value, HeroVideoLoop::cases()))
        ->toBe(['none', '1', '2', '3', 'continuous']);
});
