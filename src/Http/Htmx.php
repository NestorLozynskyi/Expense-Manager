<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Responses for HTMX requests with a plain-HTTP fallback (pages work without JavaScript):
 *  - after a successful change: close dialogs, show a toast and reload the page content (#main)
 *    via HX-Location; without HTMX: flash message + redirect (Post/Redirect/Get).
 */
final readonly class Htmx
{
    public function __construct(private RequestStack $requests)
    {
    }

    public static function isHtmx(Request $request): bool
    {
        return $request->headers->has('HX-Request');
    }

    /**
     * @param string $fallbackUrl where to go without HTMX (and with HTMX when the current URL is unknown)
     */
    public function done(string $title, ?string $message, string $fallbackUrl, string $type = 'success'): Response
    {
        $request = $this->requests->getCurrentRequest();
        if (!$request || !self::isHtmx($request)) {
            $session = $request?->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add($type, $title.(null !== $message ? '|'.$message : ''));
            }

            return new RedirectResponse($fallbackUrl, Response::HTTP_SEE_OTHER);
        }

        $current = $request->headers->get('HX-Current-URL') ?: $fallbackUrl;
        $response = new Response('', Response::HTTP_OK);
        // Order matters: the toast event must fire while the triggering element (often inside the
        // dialog) is still attached, because closing the dialogs removes disposable ones.
        $response->headers->set('HX-Trigger', json_encode([
            'em:toast' => ['type' => $type, 'title' => $title, 'message' => $message ?? ''],
            'em:close-modals' => true,
        ], \JSON_THROW_ON_ERROR));
        $response->headers->set('HX-Location', json_encode([
            'path' => self::pathOf($current),
            'target' => '#main',
            'select' => '#main',
            'swap' => 'outerHTML',
        ], \JSON_THROW_ON_ERROR));
        $response->headers->set('HX-Reswap', 'none');

        return $response;
    }

    /** Full-page navigation (HX-Redirect) for HTMX, a normal redirect otherwise. */
    public static function redirect(Request $request, string $url): Response
    {
        if (self::isHtmx($request)) {
            return new Response('', Response::HTTP_OK, ['HX-Redirect' => $url]);
        }

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }

    private static function pathOf(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
