<?php

namespace App\Livewire\Actions;

use App\Services\Auth\QrLogin;
use App\Services\Auth\UserSessions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * The asking device collecting an approved QR login, in either direction. The login
 * is only handed to the browser session that asked (see QrLogin::claim()), the
 * session id is replaced on login, and the new session is marked so the devices list
 * can say it came from a QR code.
 */
class CompleteQrLogin
{
    public const WAITING = 'waiting';

    public const LOGGED_IN = 'logged_in';

    public const GONE = 'gone';

    public function __invoke(string $token): string
    {
        $userId = app(QrLogin::class)->claim($token, Session::getId());

        if (! $userId) {
            return self::WAITING;
        }

        // The account can be gone by now (deleted between approval and claim).
        if (! Auth::loginUsingId($userId)) {
            return self::GONE;
        }

        Session::regenerate();
        app(UserSessions::class)->markVia(Session::getId(), 'qr');

        Log::info('QR login completed', ['user_id' => $userId, 'agent' => request()->userAgent()]);

        return self::LOGGED_IN;
    }
}
