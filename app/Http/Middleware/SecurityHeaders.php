<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para la web y el panel.
 *
 * - Nadie puede meter el sitio dentro de otra página para engañar los clics.
 * - Al salir hacia otro sitio (Google Maps, WhatsApp) solo viaja el dominio: el enlace personal
 *   de la cita (/c/…) no se filtra.
 * - Con https, el navegador recuerda que debe entrar siempre así.
 */
class SecurityHeaders
{
    /** Seis meses: el navegador vuelve a preguntar después. */
    private const HSTS_SECONDS = 15_552_000;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        // Micrófono para dictar en la historia; cámara para las fotos. Ubicación y pagos no se usan.
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(), payment=()', false);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.self::HSTS_SECONDS, false);
        }

        return $response;
    }
}
