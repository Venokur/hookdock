<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\Request;
use App\Http\Response;
use App\Services\CircuitBreaker;

/**
 * The state of forward targets, for the operator.
 *
 * Deleting a scenario is not how a broken endpoint is fixed, so the breaker needs
 * a screen: "which addresses are being skipped, since when, and how do I retry
 * one right now". Without it a skipped forward is invisible — the room looks quiet
 * and nobody connects that to a target that has been down for ten minutes.
 */
final class BreakersController
{
    public function index(Request $request): void
    {
        Auth::requireUser($request);

        Response::json(['data' => CircuitBreaker::all()]);
    }

    /**
     * Clears an address by hand after the operator fixed it.
     *
     * The row is deleted rather than flipped to `closed`, so the failure count does
     * not survive: a stale count would re-open the breaker on the very next error
     * and the operator would reasonably conclude the reset did nothing.
     */
    public function reset(Request $request): void
    {
        Auth::requireUser($request, true);

        $payload = $request->json() ?? [];
        $address = trim((string) ($payload['address'] ?? ''));

        if ($address === '') {
            Response::error('Укажите address адреса пересылки', 422);
        }

        $removed = CircuitBreaker::reset($address);

        Response::json(['ok' => true, 'reset' => $removed]);
    }
}
