<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TokenResource;
use App\Support\ApiTokenAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends ApiController
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'token' => $token instanceof PersonalAccessToken ? [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'ability_label' => ApiTokenAbilities::label(is_array($token->abilities) ? $token->abilities : []),
                ] : null,
            ],
        ]);
    }

    public function tokens(Request $request): JsonResponse
    {
        return TokenResource::collection(
            $request->user()->tokens()->latest()->paginate($this->perPage($request))
        )->response();
    }

    public function storeToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'ability' => ['required', 'in:*,read,write'],
        ]);

        $issued = $request->user()->createToken($data['name'], ApiTokenAbilities::expand($data['ability']));

        return (new TokenResource($issued->accessToken, $issued->plainTextToken))
            ->additional([
                'message' => 'Copy this token now. It will not be shown again.',
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function destroyToken(Request $request, int $token): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($token)->delete();

        if ($deleted === 0) {
            $this->fail('Token not found.', 404);
        }

        return response()->json(null, 204);
    }
}
