<?php

if (!function_exists('communicationDateTime')) {
    function communicationDateTime($value, ?string $empty = null): string
    {
        if (!$value) {
            return $empty ?? '—';
        }

        return \Carbon\Carbon::parse($value)->format('Y-m-d H:i');
    }
}
