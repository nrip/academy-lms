<?php

declare(strict_types=1);

namespace Academy\Domain\Courses;

use Academy\Domain\Exception\ValidationException;

/**
 * Allow-listed HTML for rich-text lessons. Not a general HTML parser.
 */
final class RestrictedHtmlSanitiser
{
    public function sanitise(string $html): string
    {
        $stripped = strip_tags($html, '<p><br><strong><em><ul><ol><li><h2><h3><a>');
        $stripped = preg_replace_callback(
            '/<a\b([^>]*)>/i',
            static function (array $match): string {
                if (preg_match('/href\s*=\s*(["\'])(https:\/\/[^"\']+)\1/i', $match[1], $href) !== 1) {
                    return '';
                }

                return '<a href="' . htmlspecialchars($href[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            },
            $stripped,
        ) ?? '';
        $stripped = preg_replace('/<(p|br|strong|em|ul|ol|li|h2|h3)\b[^>]*>/i', '<$1>', $stripped) ?? $stripped;

        if (trim(strip_tags($stripped)) === '') {
            throw new ValidationException('Rich text body is required.');
        }

        return $stripped;
    }
}
