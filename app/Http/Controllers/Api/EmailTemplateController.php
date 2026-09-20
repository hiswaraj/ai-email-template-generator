<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateEmailTemplateRequest;
use App\Services\EmailTemplateService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class EmailTemplateController extends Controller
{
    public function __construct(
        protected EmailTemplateService $templateService
    ) {}

    public function generate(GenerateEmailTemplateRequest $request): JsonResponse
    {
        try {
            $data = $this->templateService->generateTemplate(
                purpose: $request->validated('purpose'),
                recipientName: $request->validated('recipient_name'),
                tone: $request->validated('tone')
            );

            return response()->json([
                'status' => 'success',
                'data' => $data['template'],
                'meta' => $data['meta'],
            ], 200);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }
}
