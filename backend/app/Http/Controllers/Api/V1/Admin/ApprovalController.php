<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ApprovalRequest::query()->latest('created_at')->paginate(25));
    }

    public function approve(Request $request, string $approvalId): JsonResponse
    {
        $approval = ApprovalRequest::query()->findOrFail($approvalId);
        $approval->update([
            'status' => 'approved',
            'decided_by' => $request->user()?->id,
            'decided_at' => now(),
        ]);

        return response()->json($approval);
    }

    public function reject(Request $request, string $approvalId): JsonResponse
    {
        $approval = ApprovalRequest::query()->findOrFail($approvalId);
        $approval->update([
            'status' => 'rejected',
            'decided_by' => $request->user()?->id,
            'decided_at' => now(),
            'decision_note' => (string) $request->input('note', 'Rejected by operator.'),
        ]);

        return response()->json($approval);
    }
}
