<?php

namespace App\Support;

/**
 * Maps a contact-details row label to its icon key. Unknown labels used to fall back to the
 * address pin, so "Hours" and "Service area" rendered as extra map pins (Camino).
 */
final class ContactLabelIcon
{
    public static function key(string $label): string
    {
        $label = strtolower(trim($label));

        return match (true) {
            $label === '' => 'address',
            str_contains($label, 'hour') || str_contains($label, 'open') => 'hours',
            str_contains($label, 'area') || str_contains($label, 'coverage') || str_contains($label, 'deliver') || str_contains($label, 'serv') => 'coverage',
            str_contains($label, 'whatsapp') => 'whatsapp',
            str_contains($label, 'mobile') => 'mobile',
            str_contains($label, 'phone') || str_contains($label, 'tel') => 'phone',
            str_contains($label, 'email') || str_contains($label, 'e-mail') => 'email',
            str_contains($label, 'address') || str_contains($label, 'find us') || str_contains($label, 'location') => 'address',
            default => $label,
        };
    }
}
