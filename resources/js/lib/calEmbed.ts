/**
 * Cal.com embed bootstrap, adapted from Cal's official loader snippet.
 *
 * The loader installs a queueing `window.Cal` stub and injects the real
 * embed.js, which drains the queue once it arrives — so calls made before
 * the script loads are never lost. If the script never loads (blocked,
 * offline), the queue just sits idle and the page's plain-link fallback
 * remains usable.
 */
interface CalApi {
    (...args: unknown[]): void;
    q?: unknown[][];
    ns?: Record<string, CalApi>;
    loaded?: boolean;
}

declare global {
    interface Window {
        Cal?: CalApi;
    }
}

const EMBED_SCRIPT_URL = 'https://app.cal.com/embed/embed.js';

function ensureCal(): CalApi {
    if (window.Cal) {
        return window.Cal;
    }

    const push = (api: CalApi, args: unknown[]): void => {
        (api.q = api.q ?? []).push(args);
    };

    const cal: CalApi = (...args: unknown[]): void => {
        if (!cal.loaded) {
            cal.ns = {};
            cal.q = cal.q ?? [];
            const script = document.createElement('script');
            script.src = EMBED_SCRIPT_URL;
            document.head.appendChild(script);
            cal.loaded = true;
        }

        if (args[0] === 'init' && typeof args[1] === 'string') {
            const api: CalApi = (...namespacedArgs: unknown[]): void => {
                push(api, namespacedArgs);
            };
            const namespace = args[1];
            api.q = api.q ?? [];
            cal.ns![namespace] = cal.ns![namespace] ?? api;
            push(cal.ns![namespace], args);
            push(cal, ['initNamespace', namespace]);

            return;
        }

        push(cal, args);
    };

    window.Cal = cal;

    return cal;
}

/**
 * Render a Cal.com scheduling calendar inline inside the given element.
 *
 * @param selector CSS selector of the container element (must be mounted).
 * @param calLink  Cal.com link in "username/event" form.
 */
export function initCalInline(selector: string, calLink: string): void {
    const cal = ensureCal();

    cal('init', { origin: 'https://app.cal.com' });
    cal('inline', {
        elementOrSelector: selector,
        calLink,
        config: { layout: 'month_view' },
    });
    cal('ui', { hideEventTypeDetails: false, layout: 'month_view' });
}
