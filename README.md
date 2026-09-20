# AI-Powered Email Template Generator Service

A lightweight, production-ready backend microservice built with Laravel that generates customer-friendly, context-aware email templates using OpenRouter AI.

---

## Features

- **RESTful API**: Single clean POST endpoint accepting `purpose`, `recipient_name`, and `tone`.
- **Clean Architecture**: Complete separation of concerns across Form Request validation, Controllers, Services, and AI Provider contracts.
- **OpenRouter AI Integration**: Powered by OpenRouter's Unified API (`openai/gpt-4o-mini` by default) with JSON response mode for guaranteed structure.
- **Latency & Observability**: Measures exact execution time (`duration_ms`), starting and ending ISO timestamps, and logs detailed request metrics to `storage/logs/laravel.log`.
- **Automated Test Coverage**: Feature tests covering input validation, upstream API error handling, and response payloads using HTTP fakes.

---

## Architecture Overview

```
HTTP Request (POST /api/v1/email-templates/generate)
   │
   ▼
GenerateEmailTemplateRequest (Validation & Sanitization)
   │
   ▼
EmailTemplateController (Thin Controller)
   │
   ▼
EmailTemplateService (Business Orchestration)
   │
   ▼
OpenRouterEmailGenerator (OpenRouter AI Integration)
   │
   ▼
Structured JSON Response + Latency Logging
```

---

## Getting Started

### Prerequisites
- PHP 8.3 or 8.4
- Composer 2+

### 1. Installation
```bash
git clone https://github.com/hiswaraj/ai-email-template-generator.git
cd ai-email-template-generator

composer install
cp .env.example .env
php artisan key:generate
```

### 2. Environment Configuration
Configure your OpenRouter credentials in `.env`:

```env
OPENROUTER_API_KEY=your_openrouter_api_key_here
OPENROUTER_MODEL=openai/gpt-4o-mini
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_TIMEOUT=30
```

### 3. Start the Server
```bash
php artisan serve
```
The service will be listening at `http://127.0.0.1:8000`.

---

## API Documentation

### Generate Email Template

`POST /api/v1/email-templates/generate`

#### Headers
| Header | Value |
| --- | --- |
| `Content-Type` | `application/json` |
| `Accept` | `application/json` |

#### Request Body
| Field | Type | Required | Description | Example |
| --- | --- | --- | --- | --- |
| `purpose` | string | Yes | The objective or topic of the email (3 - 500 chars) | `"Follow up on enterprise licensing agreement"` |
| `recipient_name` | string | Yes | Name of the recipient (2 - 100 chars) | `"Sarah Connor"` |
| `tone` | string | Yes | Desired tone (2 - 50 chars) | `"friendly"`, `"formal"`, `"urgent"`, `"casual"` |

#### Example Request (cURL)
```bash
curl -X POST http://127.0.0.1:8000/api/v1/email-templates/generate \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "purpose": "Follow up after discussing enterprise licensing agreement",
    "recipient_name": "Sarah Connor",
    "tone": "friendly"
  }'
```

#### Sample Successful Response (200 OK)
```json
{
  "status": "success",
  "data": {
    "recipient_name": "Sarah Connor",
    "tone": "formal",
    "purpose": "Invoice #8920 payment overdue notification",
    "category": "Billing & Finance",
    "confidence": 0.95,
    "subject": "Overdue Payment Notification for Invoice #8920",
    "body": "Dear Sarah Connor,\n\nI hope this message finds you well. I am writing to inform you that payment for Invoice #8920 is now overdue. We kindly request that you review your records and arrange for payment at your earliest convenience.\n\nIf you have already processed this payment, please disregard this notice. Otherwise, do not hesitate to reach out if you have any questions or require further assistance.\n\nThank you for your attention to this matter.\n\nBest regards,\n\n[Your Name]\n[Your Position]\n[Your Company]"
  },
  "meta": {
    "provider": "openrouter (openai/gpt-4o-mini)",
    "duration_ms": 3631.72,
    "started_at": "2026-09-20T08:45:50+00:00",
    "completed_at": "2026-09-20T08:45:54+00:00"
  }
}
```

#### Sample Error Response (422 Validation Error)
```json
{
  "message": "The recipient name is required.",
  "errors": {
    "recipient_name": [
      "The recipient name is required."
    ]
  }
}
```

---

## AI Prompt Design & Engineering

The prompt is engineered to ensure high-quality, professional, and customer-friendly email output while categorizing intent and confidence.

### 1. System Prompt
```text
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
```

### 2. Category & Confidence Fallback Mapping
If the upstream AI omits category or confidence score, the service automatically falls back to an internal heuristic classifier and calibrated confidence baseline:
- `Billing & Finance`: `0.95`
- `Sales & Outreach`: `0.95`
- `Customer Support`: `0.92`
- `Meeting & Coordination`: `0.92`
- `Onboarding`: `0.90`
- `General Communication`: `0.85`

### 2. Prompt Engineering Rationale
- **Role Assignment**: Guiding the LLM with a specialized role (*business communication copywriter*) anchors the vocabulary and structure in professional business norms.
- **Enforced JSON Schema (`response_format: json_object`)**: Guarantees parseable output with explicit `subject` and `body` keys without brittle regex or markdown stripping.
- **Context Isolation**: Parameters (`recipient_name`, `tone`, `purpose`) are passed as distinct fields to avoid prompt injection or ambiguous context mixing.
- **Temperature Setting (`0.7`)**: Strikes an optimal balance between natural phrasing for various tones and strict instruction following.

---

## Response Time Logging

Every AI invocation records performance metrics to `storage/logs/laravel.log`:

```log
[2026-09-20 08:45:54] local.INFO: AI API Call Completed {
  "provider": "openrouter",
  "model": "openai/gpt-4o-mini",
  "successful": true,
  "duration_ms": 3636.12,
  "started_at": "2026-09-20T08:45:50+00:00",
  "completed_at": "2026-09-20T08:45:54+00:00",
  "tokens_prompt": 173,
  "tokens_completion": 107
}
```

The difference between `started_at` and `completed_at` (and exact sub-millisecond calculation using `microtime(true)`) ensures visibility into upstream API latency.

---

## Running Tests & Code Quality

### Run Test Suite
```bash
php artisan test
```

### Code Style (Laravel Pint)
```bash
vendor/bin/pint --test
```
