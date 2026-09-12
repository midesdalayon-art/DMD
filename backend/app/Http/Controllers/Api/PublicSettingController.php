<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemSettings;
use Illuminate\Http\JsonResponse;

class PublicSettingController extends Controller
{
    public function show(SystemSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => $settings->publicWebsiteSettings(),
        ]);
    }
}
