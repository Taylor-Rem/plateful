/**
 * Wayfinder bakes the route's domain into absolute URLs for domain-routed
 * routes (the admin console). Inertia requests are always same-origin, so
 * submit the path only — it keeps them working behind any host, including
 * the in-process server the browser tests run against.
 */
export function relativeUrl(url: string): string {
    const parsed = new URL(url, window.location.origin);

    return parsed.pathname + parsed.search;
}
