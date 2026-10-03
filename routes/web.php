<?php

use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ShareImageController;
use App\Http\Controllers\SpotifyConnectionController;
use App\Livewire\Dashboard\PartyScreen;
use App\Livewire\Dashboard\ShowRoom;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);

Route::get('/help', HelpController::class)->name('help');

Route::get('/join', [JoinController::class, 'create'])->name('join');
// Throttled because every successful submit creates a guest member row.
// The invite link's preview picture, fetched by chat apps' link crawlers.
Route::get('/share/{code}/{shape}.jpg', [ShareImageController::class, 'show'])
    ->where('shape', 'wide|square')
    ->middleware('throttle:120,1')
    ->name('share.image');

Route::post('/join', [JoinController::class, 'store'])->middleware('throttle:10,1')->name('join.store');

// Your room, if you're hosting one — otherwise mount() bounces to rooms.create.
Route::get('/dashboard', ShowRoom::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth'])->group(function () {
    Route::post('/spotify/connect', [SpotifyConnectionController::class, 'connect'])->name('spotify.connect');
    Route::get('/spotify/callback', [SpotifyConnectionController::class, 'callback'])->name('spotify.callback');
    Route::delete('/spotify', [SpotifyConnectionController::class, 'disconnect'])->name('spotify.disconnect');

    Route::get('/dashboard/create', [RoomController::class, 'create'])->name('rooms.create');
    Route::post('/dashboard', [RoomController::class, 'store'])->name('rooms.store');
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');
});

// Shareable guest-facing URLs — reachable by anyone with the invite code, no account needed.
Route::get('/rooms/{room}', ShowRoom::class)->name('rooms.show');
Route::get('/rooms/{room}/party', PartyScreen::class)->name('rooms.party');

require __DIR__.'/auth.php';
