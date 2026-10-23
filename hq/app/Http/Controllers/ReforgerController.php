<?php

namespace App\Http\Controllers;

use App\Actions\Reforger\BuildWarCommandDataAction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Kolguyev command desk. */
class ReforgerController extends Controller
{
    public function __invoke(Request $request, BuildWarCommandDataAction $war): Response
    {
        return Inertia::render('reforger', [
            'war' => $war->build($this->commander($request)),
            'server' => $this->server(),
        ]);
    }

    public function desk(Request $request, BuildWarCommandDataAction $war): JsonResponse
    {
        return response()->json($war->build($this->commander($request)));
    }

    private function commander(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    /** @return array{host: string, port: int, password: string} */
    private function server(): array
    {
        return [
            'host' => (string) config('reforger.host'),
            'port' => (int) config('reforger.port'),
            'password' => (string) config('reforger.password'),
        ];
    }
}
