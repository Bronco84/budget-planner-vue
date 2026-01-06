<?php

namespace App\Services;

use App\Models\User;
use App\Models\MagicLinkToken;
use App\Mail\MagicLinkEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class MagicLinkService
{
    /**
     * Generate and send a magic link to the user.
     */
    public function sendMagicLink(User $user): MagicLinkToken
    {
        // Generate a secure random token
        $token = Str::random(64);
        
        // Hash the token for storage
        $hashedToken = hash('sha256', $token);
        
        // Create the magic link token record
        $magicLinkToken = $user->magicLinkTokens()->create([
            'token' => $hashedToken,
            'expires_at' => now()->addMinutes((int) config('auth.magic_link_expiry_minutes', 15)),
        ]);
        
        // Generate the magic link URL
        $url = route('magic-link.authenticate', ['token' => $token, 'email' => $user->email]);
        
        // Log magic link generation for debugging
        \Log::info('Magic link generated', [
            'user_id' => $user->id,
            'email' => $user->email,
            'url' => $url,
            'expires_at' => $magicLinkToken->expires_at,
        ]);
        
        // Send the email
        Mail::to($user->email)->send(new MagicLinkEmail($user, $url));
        
        return $magicLinkToken;
    }

    /**
     * Verify a magic link token.
     */
    public function verifyToken(string $token): ?User
    {
        // Hash the token to compare with stored hash
        $hashedToken = hash('sha256', $token);
        
        // Find a valid token
        $magicLinkToken = MagicLinkToken::where('token', $hashedToken)
            ->valid()
            ->first();
        
        if (!$magicLinkToken) {
            \Log::warning('Magic link verification failed', [
                'token_length' => strlen($token),
                'token_prefix' => substr($token, 0, 10) . '...',
            ]);
            return null;
        }
        
        \Log::info('Magic link verified successfully', [
            'user_id' => $magicLinkToken->user_id,
        ]);
        
        // Mark token as used
        $magicLinkToken->markAsUsed();
        
        return $magicLinkToken->user;
    }

    /**
     * Clean up expired tokens.
     */
    public function cleanupExpiredTokens(): int
    {
        return MagicLinkToken::expired()->delete();
    }

    /**
     * Revoke all magic link tokens for a user.
     */
    public function revokeAllTokens(User $user): int
    {
        return $user->magicLinkTokens()->delete();
    }
}

