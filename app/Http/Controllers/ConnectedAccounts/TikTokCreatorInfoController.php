<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Enums\Platform;
use App\Exceptions\TikTokCreatorInfoException;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Services\ConnectedAccounts\TikTok\TikTokCreatorInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TikTokCreatorInfoController extends Controller
{
    public function __invoke(Request $request, ConnectedAccount $account, TikTokCreatorInfo $creatorInfo): JsonResponse
    {
        abort_unless($request->user()->can('create', Post::class), 403);
        abort_unless($account->workspace_id === $request->user()->current_workspace_id, 404);
        abort_unless($account->platform === Platform::TikTok && config('services.tiktok.direct_post_enabled'), 404);

        try {
            return response()->json(['creator' => $creatorInfo->query($account)])->header('Cache-Control', 'private, no-store');
        } catch (TikTokCreatorInfoException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['creator' => [$exception->getMessage()]],
            ], 422)->header('Cache-Control', 'private, no-store');
        }
    }
}
