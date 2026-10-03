<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * The landing page is for visitors. Someone logged in goes straight to
     * where they'd go anyway: their open room, or creating one.
     */
    public function __invoke(Request $request)
    {
        if ($user = $request->user()) {
            return $user->activeHostedRoom()
                ? redirect()->route('dashboard')
                : redirect()->route('rooms.create');
        }

        return view('welcome');
    }
}
