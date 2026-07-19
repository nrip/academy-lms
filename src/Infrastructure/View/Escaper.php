<?php

declare(strict_types=1);

namespace Academy\Infrastructure\View;

final class Escaper
{
    public function html(string|int|float|null $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function attr(string|int|float|null $value): string
    {
        return $this->html($value);
    }

    public function js(mixed $value): string
    {
        $encoded = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        return $encoded;
    }
}
