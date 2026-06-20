<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelegramSettingsController extends Controller
{
    /** Return current link status for the authenticated user. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'linked'     => (bool) $user->telegram_chat_id,
            'chat_id'    => $user->telegram_chat_id,
        ]);
    }

    /**
     * Generate a one-time 8-character code the user pastes to the bot.
     * Code expires in 15 minutes.
     */
    public function generateCode(Request $request): JsonResponse
    {
        $user = $request->user();

        $code = strtoupper(Str::random(8));

        $user->update([
            'telegram_link_token'      => $code,
            'telegram_link_expires_at' => now()->addMinutes(15),
        ]);

        $botUsername = config('services.telegram.bot_username', 'FitAccessBot');

        return response()->json([
            'code'       => $code,
            'expires_in' => 900,
            'bot_url'    => "https://t.me/{$botUsername}?start=link",
        ]);
    }

    /** Remove the Telegram link from the authenticated user. */
    public function unlink(Request $request): JsonResponse
    {
        $request->user()->update([
            'telegram_chat_id'         => null,
            'telegram_link_token'      => null,
            'telegram_link_expires_at' => null,
        ]);

        return response()->json(['message' => 'Telegram account unlinked.']);
    }
}
