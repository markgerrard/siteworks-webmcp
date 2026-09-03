# Logo heuristic test fixtures

These PNGs back `tests/Unit/Logo/EnhancementHeuristicsTest.php`. The heuristic decides:

- `shouldRedraw()` — `true` when `max(width, height) < 600`
- `traceMode()` — `'potrace'` when sampled colour-count ≤ 6 (after 5-bit-per-channel quantisation), `'vtracer'` otherwise

| File | Purpose | Rough properties |
|---|---|---|
| `small_300x300.png` | `shouldRedraw=true` (max dim 300, below threshold) | 300×300, low-detail, ≤6 colours |
| `large_1024x512.png` | `shouldRedraw=false` (max dim 1024 ≥ 600) | 1024×512, low-detail |
| `mono_2color.png` | `traceMode='potrace'` (≤6 unique sampled colours) | Tiny, black-on-white wordmark style |
| `colour_photo.png` | `traceMode='vtracer'` (>6 sampled colours after quantisation) | Photographic / many-hue source |

## Regenerating

If you need to add a fixture (e.g. exactly N colours), the simplest path is a one-off PHP script using GD:

```php
// tests/fixtures/logos/_make_fixture.php (uncommitted)
$im = imagecreatetruecolor(300, 300);
$bg = imagecolorallocate($im, 255, 255, 255);
$fg = imagecolorallocate($im, 0, 0, 0);
imagefilledrectangle($im, 0, 0, 299, 299, $bg);
// …add shapes via imageline / imagefilledellipse / imagefilledpolygon…
imagepng($im, __DIR__.'/your_fixture.png');
imagedestroy($im);
```

Then `php tests/fixtures/logos/_make_fixture.php` and commit the PNG only (the script stays out of git).

The heuristic samples a 64×64 downsized copy with 5-bit-per-channel quantisation, so adding a tiny amount of anti-aliasing won't push a 2-colour fixture over the 6-colour threshold — but a true gradient will. When in doubt, run the unit test against the new fixture.
