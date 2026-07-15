import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import StorefrontLayout from '@/layouts/StorefrontLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('ForRestaurants/'):
                // Standalone marketing/signup pages with their own chrome.
                return null;
            case name.startsWith('Legal/'):
                // Public legal pages wrap themselves in LegalLayout.
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            case name === 'Storefront/Unavailable':
                // Served by ResolveTenant when there is no live tenant, so there
                // is no restaurant to build storefront chrome from. Must sit
                // above the Storefront/ case. A page-level `layout: null` cannot
                // express this — Inertia resolves the effective layout with
                // `page.layout ?? defaultLayout(...)`, so null falls through to
                // here rather than opting out.
                return null;
            case name.startsWith('Storefront/'):
                return StorefrontLayout;
            case name === 'Admin/Login':
                // The admin sign-in screen shares the centered auth chrome
                // (logo + title + card) with the customer auth pages.
                return AuthLayout;
            case name.startsWith('Admin/'):
                // Admin pages compose their own layouts (TenantAdminLayout, etc.) internally
                return null;
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
