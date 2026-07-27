<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { edit as securityEdit } from '@/routes/admin/security';

// Gentle, dismissible prompt for restaurant admins to enroll in two-factor
// authentication. Super admins never see it — they are hard-required via the
// two-factor.required middleware instead. Dismissal is per-browser.
const STORAGE_KEY = 'plateful-2fa-nudge-dismissed';

const page = usePage<{
    auth: { twoFactorEnabled?: boolean };
    currentRestaurantRole: string | null;
}>();

const dismissed = ref<boolean>(localStorage.getItem(STORAGE_KEY) === '1');

const visible = computed(
    () =>
        !dismissed.value &&
        !page.props.auth.twoFactorEnabled &&
        page.props.currentRestaurantRole === 'admin',
);

function dismiss(): void {
    localStorage.setItem(STORAGE_KEY, '1');
    dismissed.value = true;
}
</script>

<template>
    <div
        v-if="visible"
        class="border-b border-blue-300 bg-blue-50 text-blue-900"
        data-test="two-factor-nudge-banner"
    >
        <div
            class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-3 text-sm md:px-6"
        >
            <p>
                <strong class="font-semibold"
                    >Protect your restaurant account.</strong
                >
                Add two-factor authentication so a stolen password alone can't
                access orders and payouts.
                <Link
                    :href="securityEdit()"
                    class="font-medium underline underline-offset-2 hover:text-blue-700"
                >
                    Enable it in Security settings
                </Link>
            </p>
            <button
                type="button"
                class="shrink-0 rounded p-1 text-blue-700 hover:bg-blue-100 hover:text-blue-900"
                aria-label="Dismiss"
                @click="dismiss"
            >
                <X class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>
