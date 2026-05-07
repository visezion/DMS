<?php

namespace App\Http\Middleware;

use App\Support\UiTextLocalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyLocalizedUiStrings
{
    public function __construct(
        private readonly UiTextLocalizer $localizer
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $locale = (string) app()->getLocale();
        if ($locale === 'en') {
            return $response;
        }

        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        $isHtml = $contentType === ''
            || str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml+xml');

        if (! $isHtml) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || trim($content) === '') {
            return $response;
        }

        $translated = $this->localizer->translateHtml($content, $locale);
        if ($translated === $content) {
            return $response;
        }

        $response->setContent($translated);
        $response->headers->remove('Content-Length');

        return $response;
    }
}
