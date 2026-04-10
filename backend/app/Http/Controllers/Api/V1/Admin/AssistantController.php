<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Assistant\AssistantService;
use App\Domain\Remediation\RemediationPlannerService;
use App\Http\Controllers\Controller;
use App\Models\AiRecommendation;
use App\Models\AssistantMessage;
use App\Models\AssistantSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function ask(Request $request, AssistantService $assistant): JsonResponse
    {
        $payload = $request->validate([
            'question' => ['required', 'string', 'max:4000'],
            'mode' => ['nullable', 'in:explain,investigate,recommend,guided_fix'],
            'device_id' => ['nullable', 'uuid'],
            'group_id' => ['nullable', 'uuid'],
            'package_id' => ['nullable', 'uuid'],
            'incident_id' => ['nullable', 'uuid'],
            'conversation_id' => ['nullable', 'uuid'],
        ]);

        return response()->json($assistant->ask($payload, $request->user()));
    }

    public function history(string $sessionId): JsonResponse
    {
        $session = AssistantSession::query()->findOrFail($sessionId);

        return response()->json([
            'session' => $session,
            'messages' => AssistantMessage::query()->where('session_id', $sessionId)->orderBy('created_at')->get(),
        ]);
    }

    public function evidence(string $messageId): JsonResponse
    {
        $message = AssistantMessage::query()->findOrFail($messageId);

        return response()->json([
            'message_id' => $message->id,
            'citations' => $message->citations,
        ]);
    }

    public function convertRecommendation(string $recommendationId, Request $request, RemediationPlannerService $planner): JsonResponse
    {
        $recommendation = AiRecommendation::query()->findOrFail($recommendationId);

        return response()->json($planner->createPlanFromRecommendation($recommendation, $request->user()));
    }
}
