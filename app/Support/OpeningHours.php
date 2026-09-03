<?php

namespace App\Support;

final class OpeningHours
{
    /** @return list<array{day: string, hours: string}> */
    public static function rows(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $rows = [];
        if (array_is_list($raw)) {
            foreach ($raw as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $day = $entry['day'] ?? null;
                $hours = $entry['hours'] ?? ($entry['time'] ?? null);
                if (is_string($day) && is_string($hours) && trim($day) !== '' && trim($hours) !== '') {
                    $rows[] = ['day' => trim($day), 'hours' => trim($hours)];
                }
            }

            return $rows;
        }
        foreach ($raw as $day => $hours) {
            if (is_string($day) && is_string($hours) && trim($day) !== '' && trim($hours) !== '') {
                $rows[] = ['day' => trim($day), 'hours' => trim($hours)];
            }
        }

        return $rows;
    }

    /** @return array<string, string> */
    public static function fromLines(string $text): array
    {
        $map = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match('/^\s*([^:]{1,40}?)\s*:\s*(.{1,60}?)\s*$/u', $line, $m) === 1) {
                $map[$m[1]] = $m[2];
            }
        }

        return $map;
    }
}
