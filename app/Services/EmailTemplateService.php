<?php

namespace App\Services;

use App\Services\Ai\OpenRouterEmailGenerator;

class EmailTemplateService
{
    public function __construct(
        protected OpenRouterEmailGenerator $generator
    ) {}

    /**
     * Generate an email template using the configured AI driver.
     */
    public function generateTemplate(string $purpose, string $recipientName, string $tone): array
    {
        $result = $this->generator->generate(
            purpose: trim($purpose),
            recipientName: trim($recipientName),
            tone: trim($tone)
        );

        return [
            'template' => [
                'recipient_name' => trim($recipientName),
                'tone' => trim($tone),
                'purpose' => trim($purpose),
                'category' => $result['category'],
                'confidence' => $result['confidence'],
                'subject' => $result['subject'],
                'body' => $result['body'],
            ],
            'meta' => [
                'provider' => $result['provider'],
                'duration_ms' => $result['duration_ms'],
                'started_at' => $result['started_at'],
                'completed_at' => $result['completed_at'],
            ],
        ];
    }
}
