<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a notification's `toExpo()` payload to every Expo push token the
 * notifiable holds, via Expo's push API (which fans out to APNs/FCM). Tokens
 * Expo reports as DeviceNotRegistered are pruned on the spot, so an
 * uninstalled app stops costing a request the next time.
 */
class ExpoPushChannel
{
    public const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    protected const CHUNK = 100;

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toExpo') || ! method_exists($notifiable, 'routeNotificationFor')) {
            return;
        }

        $tokens = (array) $notifiable->routeNotificationFor('expo', $notification);

        if ($tokens === []) {
            return;
        }

        $message = (array) $notification->toExpo($notifiable);

        foreach (array_chunk(array_values($tokens), self::CHUNK) as $chunk) {
            $this->deliver($chunk, $message);
        }
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $message
     */
    protected function deliver(array $tokens, array $message): void
    {
        $payload = array_map(fn (string $token): array => [
            'to' => $token,
            'title' => (string) ($message['title'] ?? ''),
            'body' => (string) ($message['body'] ?? ''),
            'data' => (array) ($message['data'] ?? []),
            'sound' => 'default',
        ], $tokens);

        $request = Http::acceptJson()->asJson()->timeout(10);

        $accessToken = (string) config('services.expo.access_token', '');
        if ($accessToken !== '') {
            $request = $request->withToken($accessToken);
        }

        try {
            $response = $request->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Expo push unreachable', ['error' => $e->getMessage()]);

            return;
        }

        if ($response->failed()) {
            Log::warning('Expo push rejected', ['status' => $response->status(), 'body' => $response->body()]);

            return;
        }

        foreach ((array) $response->json('data', []) as $index => $ticket) {
            if (! is_array($ticket) || ($ticket['status'] ?? null) !== 'error') {
                continue;
            }

            $error = $ticket['details']['error'] ?? null;

            if ($error === 'DeviceNotRegistered' && isset($tokens[$index])) {
                DeviceToken::query()->where('token', $tokens[$index])->delete();

                continue;
            }

            Log::warning('Expo push ticket error', ['error' => $error, 'message' => $ticket['message'] ?? null]);
        }
    }
}
