<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Button } from '@/components/ui/button';

type SignupDetail = {
    id: number;
    restaurantName: string;
    subdomain: string;
    customDomain: string | null;
    city: string | null;
    state: string | null;
    cuisineType: string | null;
    notes: string | null;
    ownerName: string | null;
    ownerEmail: string | null;
    ownerPhone: string | null;
    status: 'pending' | 'approved' | 'rejected';
    submittedAt: string | null;
    reviewedAt: string | null;
    reviewerName: string | null;
    rejectionReason: string | null;
    restaurantSubdomain: string | null;
};

const props = defineProps<{ signup: SignupDetail }>();

const approveForm = useForm({});
const rejectForm = useForm<{ rejection_reason: string }>({ rejection_reason: '' });
const showReject = ref(false);

function approve() {
    approveForm.post(`/super/signups/${props.signup.id}/approve`, {
        preserveScroll: true,
    });
}

function reject() {
    rejectForm.post(`/super/signups/${props.signup.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            showReject.value = false;
        },
    });
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return '—';
    }
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head :title="`Signup: ${signup.restaurantName}`" />

        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <Link href="/super/signups" class="text-sm text-muted-foreground hover:text-foreground">← Signups</Link>
                    <h1 class="text-lg font-semibold">{{ signup.restaurantName }}</h1>
                </div>
                <AppearanceTabs />
            </div>
        </header>

        <main class="mx-auto max-w-3xl space-y-8 px-6 py-8">
            <section class="rounded-lg border border-border bg-card p-6">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-base font-semibold">Application</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Submitted {{ formatDate(signup.submittedAt) }}</p>
                    </div>
                    <span
                        :class="[
                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                            signup.status === 'pending' && 'bg-amber-100 text-amber-800',
                            signup.status === 'approved' && 'bg-green-100 text-green-800',
                            signup.status === 'rejected' && 'bg-neutral-200 text-neutral-700',
                        ]"
                        data-test="signup-status-badge"
                    >
                        {{ signup.status }}
                    </span>
                </div>

                <dl class="mt-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Subdomain</dt>
                        <dd class="font-mono">{{ signup.subdomain }}</dd>
                    </div>
                    <div v-if="signup.customDomain">
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Custom domain</dt>
                        <dd class="font-mono">{{ signup.customDomain }}</dd>
                    </div>
                    <div v-if="signup.cuisineType">
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Cuisine</dt>
                        <dd>{{ signup.cuisineType }}</dd>
                    </div>
                    <div v-if="signup.city || signup.state">
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Location</dt>
                        <dd>{{ [signup.city, signup.state].filter(Boolean).join(', ') }}</dd>
                    </div>
                </dl>

                <div v-if="signup.notes" class="mt-6">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Notes from the owner</p>
                    <p class="mt-1 whitespace-pre-line text-sm">{{ signup.notes }}</p>
                </div>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold">Owner</h2>
                <dl class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Name</dt>
                        <dd>{{ signup.ownerName }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Email</dt>
                        <dd>{{ signup.ownerEmail }}</dd>
                    </div>
                    <div v-if="signup.ownerPhone">
                        <dt class="text-xs uppercase tracking-wide text-muted-foreground">Phone</dt>
                        <dd>{{ signup.ownerPhone }}</dd>
                    </div>
                </dl>
            </section>

            <section v-if="signup.status === 'pending'" class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold">Review</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Approving creates the restaurant and makes the owner an admin. Rejecting keeps their Plateful account but does not create a restaurant.
                </p>

                <div class="mt-6 flex flex-wrap gap-3">
                    <Button
                        type="button"
                        :disabled="approveForm.processing"
                        @click="approve"
                        data-test="approve-signup-button"
                    >
                        Approve
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="showReject = !showReject"
                        data-test="reject-signup-toggle"
                    >
                        Reject…
                    </Button>
                </div>

                <form v-if="showReject" class="mt-4 space-y-2" @submit.prevent="reject" data-test="reject-form">
                    <label for="rejection_reason" class="block text-xs uppercase tracking-wide text-muted-foreground">
                        Reason (shared with the owner)
                    </label>
                    <textarea
                        id="rejection_reason"
                        v-model="rejectForm.rejection_reason"
                        rows="3"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        required
                    ></textarea>
                    <p v-if="rejectForm.errors.rejection_reason" class="text-sm text-red-600">{{ rejectForm.errors.rejection_reason }}</p>
                    <Button type="submit" :disabled="rejectForm.processing" data-test="confirm-reject-button">
                        Confirm rejection
                    </Button>
                </form>
            </section>

            <section v-else class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold">Decision</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ signup.status === 'approved' ? 'Approved' : 'Rejected' }}
                    on {{ formatDate(signup.reviewedAt) }}
                    <span v-if="signup.reviewerName"> by {{ signup.reviewerName }}</span>.
                </p>

                <p v-if="signup.status === 'approved' && signup.restaurantSubdomain" class="mt-3 text-sm">
                    Restaurant:
                    <Link :href="`/${signup.restaurantSubdomain}/dashboard`" class="text-primary hover:underline">
                        {{ signup.restaurantSubdomain }}
                    </Link>
                </p>

                <p v-if="signup.status === 'rejected' && signup.rejectionReason" class="mt-3 text-sm">
                    <span class="text-xs uppercase tracking-wide text-muted-foreground">Reason</span><br />
                    {{ signup.rejectionReason }}
                </p>
            </section>
        </main>
    </div>
</template>
