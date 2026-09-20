<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailTemplateGenerationTest extends TestCase
{
    public function test_it_generates_email_template_with_category_and_confidence(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-12345',
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'subject' => 'Payment overdue notification for invoice #1042',
                                'body' => "Dear Michael Scott,\n\nThis is a friendly reminder that invoice #1042 is past due.\n\nBest regards,\nAccounting",
                                'category' => 'Billing & Finance',
                                'confidence' => 0.98,
                            ]),
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 85,
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/email-templates/generate', [
            'purpose' => 'Payment overdue notification for invoice #1042',
            'recipient_name' => 'Michael Scott',
            'tone' => 'formal',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'recipient_name',
                    'tone',
                    'purpose',
                    'category',
                    'confidence',
                    'subject',
                    'body',
                ],
                'meta' => [
                    'provider',
                    'duration_ms',
                    'started_at',
                    'completed_at',
                ],
            ])
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'recipient_name' => 'Michael Scott',
                    'tone' => 'formal',
                    'purpose' => 'Payment overdue notification for invoice #1042',
                    'category' => 'Billing & Finance',
                    'confidence' => 0.98,
                    'subject' => 'Payment overdue notification for invoice #1042',
                ],
            ]);

        $this->assertStringContainsString('Michael Scott', $response->json('data.body'));
        $this->assertGreaterThan(0, $response->json('meta.duration_ms'));
    }

    public function test_it_uses_fallback_mapping_when_ai_omits_category_or_confidence(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-67890',
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'subject' => 'Follow up on product demo',
                                'body' => "Hi Jim,\n\nFollowing up on our sales demo.\n\nBest,\nDwight",
                                // Note: category and confidence deliberately omitted to test fallback
                            ]),
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 110,
                    'completion_tokens' => 60,
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/email-templates/generate', [
            'purpose' => 'Product demo follow up and pricing review',
            'recipient_name' => 'Jim Halpert',
            'tone' => 'friendly',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'category' => 'Sales & Outreach',
                    'confidence' => 0.95,
                ],
            ]);
    }

    public function test_it_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/email-templates/generate', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['purpose', 'recipient_name', 'tone']);
    }

    public function test_it_validates_field_lengths(): void
    {
        $response = $this->postJson('/api/v1/email-templates/generate', [
            'purpose' => str_repeat('a', 501),
            'recipient_name' => str_repeat('b', 101),
            'tone' => 'urgent',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['purpose', 'recipient_name']);
    }

    public function test_it_handles_upstream_api_errors_gracefully(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Rate limit exceeded or insufficient credits',
                ],
            ], 429),
        ]);

        $response = $this->postJson('/api/v1/email-templates/generate', [
            'purpose' => 'System maintenance alert',
            'recipient_name' => 'Sarah Connor',
            'tone' => 'urgent',
        ]);

        $response->assertStatus(502)
            ->assertJson([
                'status' => 'error',
                'message' => 'OpenRouter service error: Rate limit exceeded or insufficient credits',
            ]);
    }
}
