<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpotifyAccount extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'client_secret',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'spotify_user_id',
        'display_name',
        'avatar_url',
        'active_device_id',
        'active_device_name',
    ];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at === null || $this->token_expires_at->isPast();
    }
}
