<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BookingController extends Controller
{
    /**
     * The /book page — the speakable booking URL every marketing surface can
     * point at (one-pagers, Instagram bio, the savings calculator CTA).
     *
     * Three shapes, by configuration:
     * - no booking URL configured → 301 to the for-restaurants page, so a
     *   printed /book link never 404s before scheduling exists and search
     *   engines consolidate on /for-restaurants rather than /book;
     * - a cal.com URL → render the page with the calendar embedded inline,
     *   keeping the prospect on plateful.fyi;
     * - any other scheduling provider → redirect straight to it.
     */
    public function __invoke(Request $request): SymfonyResponse|Response
    {
        $bookingUrl = config('platform.booking_url');

        if (! $bookingUrl) {
            return redirect()->route('owner-signup.landing', status: 301);
        }

        $calLink = $this->calLink($bookingUrl);

        if ($calLink === null) {
            return redirect()->away($bookingUrl);
        }

        $user = $request->user();

        return Inertia::render('Book', [
            'authUserName' => $user?->name,
            'hasAdminAccess' => (bool) $user?->isAdmin(),
            'adminUrl' => $request->getScheme().'://admin.'.config('platform.primary_domain'),
            'calLink' => $calLink,
            'bookingUrl' => $bookingUrl,
            'longBookingUrl' => config('platform.booking_url_long'),
        ]);
    }

    /**
     * Extract the Cal.com embed link ("username/event") from a booking URL,
     * or null when the URL is not a cal.com page and cannot be embedded.
     */
    private function calLink(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($host, ['cal.com', 'www.cal.com', 'app.cal.com'], true)) {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path !== '' ? $path : null;
    }
}
