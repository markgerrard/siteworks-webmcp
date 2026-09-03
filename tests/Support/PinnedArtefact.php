<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

final class PinnedArtefact
{
    public static function assertMatches(
        string $label,
        string $expectedSha,
        string $actualSha,
        string $pinnedAtFile,
        string $howToRegenerate,
    ): void {
        Assert::assertSame($expectedSha, $actualSha, implode("\n", [
            "Pinned artefact: {$label}",
            "Expected sha256: {$expectedSha}",
            "Actual sha256: {$actualSha}",
            "Pin location: {$pinnedAtFile}",
            "Regenerate: {$howToRegenerate}",
            'Update the pin in the SAME commit as the change — never delete the assertion.',
        ]));
    }
}
