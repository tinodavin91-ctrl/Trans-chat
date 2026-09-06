<?php

namespace App\Http\Middleware;

use App\Services\FirestoreService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFirestoreToken
{
    public function __construct(private FirestoreService $firestore)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->bearerToken();

        if (! $header) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = $this->firestore->findUserByToken($header);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
