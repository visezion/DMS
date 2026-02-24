<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

class AuditAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(AuditLog::query()->latest('id')->paginate(50));
    }
}
