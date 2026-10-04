<?php

namespace App\Http\Middleware;

use App\Services\Auth\UserSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A device the account signed out remotely would otherwise just meet a login
 * screen with no explanation (or Livewire's "page expired" prompt on an open
 * tab). UserSessions leaves a note per revoked session; the next page load
 * there shows it as a message, and a Livewire refresh from an open tab gets a
 * 401 that the page turns into a trip to the login page (see resources/js).
 */
class ShowRevokedSessionNotice
{
    private const CHECKED = '_revocation_checked';

    public function __construct(private UserSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        // A session that has already been checked and found clean carries a marker, so the cache is not
        // asked again on every poll. A revoked session loses its row, and with it that marker, so the
        // very next request looks the note up.
        if ($session->has(self::CHECKED)) {
            return $next($request);
        }

        $id = $session->getId();
        $note = $this->sessions->note($id);

        if (! $note) {
            $session->put(self::CHECKED, true);
        }

        if ($note) {
            // An open tab polling in the background: leave the note for the page load it triggers.
            if ($request->headers->has('X-Livewire')) {
                return response('', 401, ['X-Session-Revoked' => '1']);
            }

            $this->sessions->clearNote($id);
            $request->session()->flash('status', UserSessions::noteMessage($note));
        }

        return $next($request);
    }
}
