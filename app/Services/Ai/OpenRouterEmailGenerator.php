<?php

namespace App\Services\Ai;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenRouterEmailGenerator
{
    protected string $apiKey;

    protected string $model;

    protected string $baseUrl;

    protected int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) config('services.ai.openrouter.key');
        $this->model = (string) config('services.ai.openrouter.model', 'openai/gpt-4o-mini');
        $this->baseUrl = (string) config('services.ai.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $this->timeout = (int) config('services.ai.openrouter.timeout', 30);
    }

    public function generate(string $purpose, string $recipientName, string $tone): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OpenRouter API key is missing. Please set OPENROUTER_API_KEY in your .env file.');
        }

        $startedAt = Carbon::now();
        $startMicrotime = microtime(true);

        $prompt = $this->buildPrompt($purpose, $recipientName, $tone);

        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->systemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.7,
                ]);
        } catch (ConnectionException $e) {
            $this->logCall($startMicrotime, $startedAt, false, 'Connection failed: '.$e->getMessage());
            throw new RuntimeException('Unable to reach OpenRouter service. Please check your network connection.');
        }

        $completedAt = Carbon::now();
        $durationMs = round((microtime(true) - $startMicrotime) * 1000, 2);

        if (! $response->successful()) {
            $errorBody = $response->json();
            $errorMessage = $errorBody['error']['message'] ?? $response->body();

            Log::error('OpenRouter generation failed', [
                'status' => $response->status(),
                'error' => $errorMessage,
                'duration_ms' => $durationMs,
            ]);

            throw new RuntimeException('OpenRouter service error: '.$errorMessage);
        }

        $payload = $response->json();
        $content = $payload['choices'][0]['message']['content'] ?? '{}';
        $parsed = json_decode($content, true);

        if (! is_array($parsed) || empty($parsed['subject']) || empty($parsed['body'])) {
            Log::warning('AI returned invalid schema', ['raw_content' => $content]);
            throw new RuntimeException('AI generated an invalid response structure. Please try again.');
        }

        [$category, $confidence] = $this->resolveCategoryAndConfidence($parsed, $purpose, $tone);

        $this->logCall($startMicrotime, $startedAt, true, null, [
            'category' => $category,
            'confidence' => $confidence,
            'tokens_prompt' => $payload['usage']['prompt_tokens'] ?? null,
            'tokens_completion' => $payload['usage']['completion_tokens'] ?? null,
        ]);

        return [
            'subject' => trim($parsed['subject']),
            'body' => trim($parsed['body']),
            'category' => $category,
            'confidence' => $confidence,
            'provider' => 'openrouter ('.$this->model.')',
            'duration_ms' => $durationMs,
            'started_at' => $startedAt->toIso8601String(),
            'completed_at' => $completedAt->toIso8601String(),
        ];
    }

    /**
     * Extract or determine the email category and confidence score.
     */
    protected function resolveCategoryAndConfidence(array $parsed, string $purpose, string $tone): array
    {
        $category = ! empty($parsed['category']) ? trim((string) $parsed['category']) : null;
        $confidence = isset($parsed['confidence']) && is_numeric($parsed['confidence'])
            ? (float) $parsed['confidence']
            : null;

        // If AI did not supply category, deduce via purpose and tone heuristics
        if (empty($category)) {
            $lowerPurpose = strtolower($purpose);

            $category = match (true) {
                str_contains($lowerPurpose, 'invoice') || str_contains($lowerPurpose, 'payment') || str_contains($lowerPurpose, 'billing') => 'Billing & Finance',
                str_contains($lowerPurpose, 'demo') || str_contains($lowerPurpose, 'sales') || str_contains($lowerPurpose, 'lead') || str_contains($lowerPurpose, 'pricing') => 'Sales & Outreach',
                str_contains($lowerPurpose, 'support') || str_contains($lowerPurpose, 'help') || str_contains($lowerPurpose, 'issue') || str_contains($lowerPurpose, 'bug') => 'Customer Support',
                str_contains($lowerPurpose, 'meeting') || str_contains($lowerPurpose, 'sync') || str_contains($lowerPurpose, 'schedule') => 'Meeting & Coordination',
                str_contains($lowerPurpose, 'welcome') || str_contains($lowerPurpose, 'onboarding') => 'Onboarding',
                default => 'General Communication',
            };
        }

        // If AI did not supply confidence or supplied out-of-range value, use calibrated fallback mapping
        if ($confidence === null || $confidence < 0.0 || $confidence > 1.0) {
            $confidence = match ($category) {
                'Billing & Finance', 'Sales & Outreach' => 0.95,
                'Customer Support', 'Meeting & Coordination' => 0.92,
                'Onboarding' => 0.90,
                default => 0.85,
            };
        }

        return [$category, round($confidence, 2)];
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a professional email copywriter for business communication.
Your goal is to compose concise, customer-friendly email templates tailored to the user's intent.

Requirements:
1. Always address the recipient by name politely.
2. Keep the email clear, respectful, and strictly aligned with the specified tone.
3. Avoid generic fluff or repetitive disclaimers.
4. Categorize the email into a primary business category (e.g., "Sales & Outreach", "Customer Support", "Billing & Finance", "Meeting & Coordination", "Onboarding", "General Communication").
5. Provide a confidence score between 0.0 and 1.0 representing how well the category and email structure align with the requested purpose.
6. Output must be a valid JSON object with exactly these keys:
   - "subject": A concise, engaging email subject line.
   - "body": The complete email body including greeting, message paragraphs, and sign-off.
   - "category": The classified category.
   - "confidence": Float between 0.0 and 1.0.
PROMPT;
    }

    protected function buildPrompt(string $purpose, string $recipientName, string $tone): string
    {
        return <<<USER_PROMPT
Please generate an email with the following parameters:
- Recipient Name: {$recipientName}
- Tone: {$tone}
- Purpose/Context: {$purpose}

Respond in strict JSON with "subject", "body", "category", and "confidence" keys only.
USER_PROMPT;
    }

    protected function logCall(
        float $startMicrotime,
        Carbon $startedAt,
        bool $successful,
        ?string $error = null,
        array $extra = []
    ): void {
        $completedAt = Carbon::now();
        $durationMs = round((microtime(true) - $startMicrotime) * 1000, 2);

        Log::info('AI API Call Completed', array_merge([
            'provider' => 'openrouter',
            'model' => $this->model,
            'successful' => $successful,
            'duration_ms' => $durationMs,
            'started_at' => $startedAt->toIso8601String(),
            'completed_at' => $completedAt->toIso8601String(),
            'error' => $error,
        ], $extra));
    }
}
