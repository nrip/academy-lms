<?php

declare(strict_types=1);

namespace Academy\Application\Notifications;

use Academy\Application\Branding\AcademyBranding;
use Academy\Domain\Exception\DomainRuleException;

/**
 * Branded HTML alternative for existing transactional letters.
 * Not a template editor. Plain text stays the fallback and the inbox copy.
 */
final class AcademyEmailLayout
{
    public function __construct(
        private readonly AcademyBranding $branding,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @param array<string, string> $variables
     * @return array{text: string, html: string}
     */
    public function wrap(string $templateKey, string $plainBody, array $variables): array
    {
        [$label, $url] = $this->callToAction($templateKey, $variables);

        return $this->compose($plainBody, $label, $url);
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    public function verification(string $rawToken): array
    {
        $url = $this->actionUrl('/verify-email', $rawToken);
        $body = 'Welcome to ' . $this->branding->name . ".\n\n"
            . 'Confirm your email address so you can sign in and apply for a course. '
            . "This link expires. If it does, request a new one from the sign-in page.\n\n"
            . 'If you did not create an account, you can ignore this email.';

        $letter = $this->compose($body, 'Verify email', $url);

        return [
            'subject' => 'Verify your ' . $this->branding->name . ' account',
            'text' => $letter['text'],
            'html' => $letter['html'],
        ];
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    public function passwordReset(string $rawToken): array
    {
        $url = $this->actionUrl('/reset-password', $rawToken);
        $body = 'We received a request to reset your ' . $this->branding->name . " password.\n\n"
            . 'If you did not ask for this, you can ignore this email.';

        $letter = $this->compose($body, 'Reset password', $url);

        return [
            'subject' => 'Reset your ' . $this->branding->name . ' password',
            'text' => $letter['text'],
            'html' => $letter['html'],
        ];
    }

    /**
     * @param array<string, string> $variables
     * @return array{0: string, 1: string}
     */
    private function callToAction(string $templateKey, array $variables): array
    {
        return match ($templateKey) {
            'application_submitted' => ['View your application', $this->safeUrl($variables['dashboard_link'] ?? '')],
            'application_admitted', 'enrolment_created' => [
                'Start learning',
                $this->safeUrl($variables['learning_link'] ?? $variables['dashboard_link'] ?? ''),
            ],
            'certificate_issued' => ['View your certificate', $this->safeUrl($variables['certificate_link'] ?? '')],
            'learning_question_asked' => ['Open question', $this->safeUrl($variables['question_link'] ?? '')],
            'learning_question_responded' => [
                'Open lesson',
                $this->safeUrl($variables['lesson_link'] ?? $variables['dashboard_link'] ?? ''),
            ],
            default => ['Open your dashboard', $this->safeUrl($variables['dashboard_link'] ?? '')],
        };
    }

    /**
     * @return array{text: string, html: string}
     */
    private function compose(string $plainBody, string $ctaLabel, string $ctaUrl): array
    {
        $plainBody = trim(str_replace(["\r\n", "\r"], "\n", $plainBody));
        $text = $this->branding->name . "\n\n" . $plainBody;
        if ($ctaLabel !== '' && $ctaUrl !== '' && !str_contains($plainBody, $ctaUrl)) {
            $text .= "\n\n" . $ctaLabel . "\n" . $ctaUrl;
        }
        if ($this->branding->supportEmail !== '') {
            $text .= "\n\nQuestions? " . $this->branding->supportEmail;
        }

        return [
            'text' => $text,
            'html' => $this->html($plainBody, $ctaLabel, $ctaUrl),
        ];
    }

    private function html(string $plainBody, string $ctaLabel, string $ctaUrl): string
    {
        $color = $this->branding->primaryColor;
        $name = $this->escape($this->branding->name);
        $paragraphs = '';
        foreach (preg_split("/\n{2,}/", $plainBody) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $paragraphs .= '<p style="margin:0 0 16px;">' . nl2br($this->escape($paragraph), false) . '</p>';
        }

        $button = '';
        if ($ctaLabel !== '' && $ctaUrl !== '') {
            $button = '<p style="margin:24px 0 8px;">'
                . '<a href="' . $this->escape($ctaUrl) . '" '
                . 'style="display:inline-block;background:' . $color . ';color:#ffffff;text-decoration:none;'
                . 'padding:12px 20px;border-radius:6px;font-weight:650;">'
                . $this->escape($ctaLabel)
                . '</a></p>';
        }

        $logo = $this->logoMarkup();
        $footer = $this->escape($this->branding->certificateIssuerName);
        if ($this->branding->supportEmail !== '') {
            $footer .= '<br>' . $this->escape($this->branding->supportEmail);
        }

        return '<!DOCTYPE html><html lang="en"><body style="margin:0;padding:0;background:#f4f7f6;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7f6;">'
            . '<tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
            . 'style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;">'
            . '<tr><td style="padding:24px 28px;background:' . $color . ';color:#ffffff;">'
            . $logo
            . '<div style="font:600 20px Georgia,serif;">' . $name . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:28px;font:16px/1.5 Georgia,serif;color:#1c2421;">'
            . $paragraphs
            . $button
            . '</td></tr>'
            . '<tr><td style="padding:8px 28px 24px;font:13px/1.4 sans-serif;color:#5c6b66;">'
            . $footer
            . '</td></tr></table></td></tr></table></body></html>';
    }

    private function logoMarkup(): string
    {
        $url = $this->absoluteLogoUrl();
        if ($url === null) {
            return '';
        }

        return '<img src="' . $this->escape($url) . '" alt="" width="160" '
            . 'style="display:block;max-width:160px;height:auto;margin:0 0 12px;border:0;">';
    }

    private function absoluteLogoUrl(): ?string
    {
        $logo = $this->branding->logoUrl;
        if (str_starts_with($logo, 'https://') || str_starts_with($logo, 'http://')) {
            return $this->safeUrl($logo);
        }
        if (str_starts_with($logo, '/') && !str_starts_with($logo, '//')) {
            return $this->safeUrl(rtrim($this->appUrl, '/') . $logo);
        }

        return null;
    }

    private function actionUrl(string $path, string $rawToken): string
    {
        if (preg_match('/^[a-f0-9]{32,128}$/', $rawToken) !== 1) {
            throw new DomainRuleException('Verification link is invalid.');
        }

        return rtrim($this->appUrl, '/') . $path . '?token=' . rawurlencode($rawToken);
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#\Ahttps?://#', $url) !== 1) {
            return '';
        }
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, ' ')) {
            return '';
        }

        return $url;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
