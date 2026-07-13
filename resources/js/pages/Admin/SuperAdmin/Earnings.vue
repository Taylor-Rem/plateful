<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Button } from '@/components/ui/button';
import { computed } from 'vue';

type Person = { id: number; name: string } | null;
type Earner = {
    userId: number | null;
    name: string;
    email: string | null;
    roles: Record<string, number>;
    totalCents: number;
};

const props = defineProps<{
    month: string;
    monthLabel: string;
    prevMonth: string;
    nextMonth: string;
    earners: Earner[];
    totalCents: number;
    shares: Record<string, number>;
    platformRoles: { founder: Person; operator: Person };
    assignableUsers: { id: number; name: string; email: string }[];
}>();

// Only roles that actually pay out get a column.
const roleColumns = computed(() =>
    Object.keys(props.shares).filter((role) => (props.shares[role] ?? 0) > 0),
);

function money(cents: number): string {
    return ((cents ?? 0) / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
    });
}

function titleCase(s: string): string {
    return s.charAt(0).toUpperCase() + s.slice(1);
}

const platformForm = useForm({
    founder_id: props.platformRoles.founder?.id ?? null,
    operator_id: props.platformRoles.operator?.id ?? null,
});

function savePlatformRoles() {
    platformForm.put('/super/platform-roles', { preserveScroll: true });
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head title="Earnings" />
        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <Link
                        href="/super/restaurants"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        ←
                    </Link>
                    <h1 class="text-lg font-semibold text-foreground">Earnings</h1>
                </div>
                <AppearanceTabs />
            </div>
        </header>

        <main class="mx-auto max-w-4xl space-y-6 px-6 py-8">
            <!-- Month navigation -->
            <div class="flex items-center justify-between">
                <Link
                    :href="`/super/earnings?month=${prevMonth}`"
                    class="text-sm text-primary hover:underline"
                >
                    ← {{ prevMonth }}
                </Link>
                <h2 class="text-base font-semibold text-foreground">{{ monthLabel }}</h2>
                <Link
                    :href="`/super/earnings?month=${nextMonth}`"
                    class="text-sm text-primary hover:underline"
                >
                    {{ nextMonth }} →
                </Link>
            </div>

            <!-- Earnings table -->
            <section class="rounded-lg border border-border bg-card p-6">
                <p class="text-sm text-muted-foreground">
                    What each person earned from the platform fee in {{ monthLabel }}, for
                    direct-deposit payout. Fully-refunded orders are excluded.
                </p>

                <div
                    v-if="earners.length === 0"
                    class="mt-4 rounded-md border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
                >
                    No earnings recorded for this month yet.
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                <th class="py-2 pr-4 font-medium">Person</th>
                                <th
                                    v-for="role in roleColumns"
                                    :key="role"
                                    class="py-2 pr-4 text-right font-medium"
                                >
                                    {{ titleCase(role) }}
                                </th>
                                <th class="py-2 text-right font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="earner in earners"
                                :key="earner.userId ?? 'orphan'"
                                class="border-b border-border/60"
                            >
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-foreground">{{ earner.name }}</div>
                                    <div v-if="earner.email" class="text-xs text-muted-foreground">
                                        {{ earner.email }}
                                    </div>
                                </td>
                                <td
                                    v-for="role in roleColumns"
                                    :key="role"
                                    class="py-3 pr-4 text-right tabular-nums text-muted-foreground"
                                >
                                    {{ earner.roles[role] ? money(earner.roles[role]) : '—' }}
                                </td>
                                <td class="py-3 text-right font-semibold tabular-nums text-foreground">
                                    {{ money(earner.totalCents) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="py-3 pr-4 font-semibold text-foreground">Total</td>
                                <td
                                    v-for="role in roleColumns"
                                    :key="role"
                                    class="py-3 pr-4"
                                ></td>
                                <td class="py-3 text-right font-semibold tabular-nums text-foreground">
                                    {{ money(totalCents) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <!-- Platform role holders -->
            <section class="rounded-lg border border-border bg-card p-6">
                <h2 class="text-base font-semibold text-foreground">Platform roles</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    The Founder earns the {{ shares.founder ?? 0 }}% founder share on every
                    restaurant. The Operator is the fallback overseer for any restaurant without
                    one assigned — inheriting the {{ shares.overseer ?? 0 }}% overseer share there.
                </p>

                <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="savePlatformRoles">
                    <div>
                        <label for="founder_id" class="block text-xs font-medium text-muted-foreground">
                            Founder
                        </label>
                        <select
                            id="founder_id"
                            v-model="platformForm.founder_id"
                            class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option :value="null" disabled>Select a user…</option>
                            <option v-for="u in assignableUsers" :key="u.id" :value="u.id">
                                {{ u.name }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label for="operator_id" class="block text-xs font-medium text-muted-foreground">
                            Operator
                        </label>
                        <select
                            id="operator_id"
                            v-model="platformForm.operator_id"
                            class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground"
                        >
                            <option :value="null" disabled>Select a user…</option>
                            <option v-for="u in assignableUsers" :key="u.id" :value="u.id">
                                {{ u.name }}
                            </option>
                        </select>
                    </div>
                    <div class="sm:col-span-2 flex items-center gap-3">
                        <Button type="submit" :disabled="platformForm.processing">
                            {{ platformForm.processing ? 'Saving…' : 'Save platform roles' }}
                        </Button>
                        <p v-if="platformForm.recentlySuccessful" class="text-sm text-green-600">
                            Saved.
                        </p>
                        <p
                            v-if="platformForm.errors.founder_id || platformForm.errors.operator_id"
                            class="text-sm text-destructive"
                        >
                            {{ platformForm.errors.founder_id || platformForm.errors.operator_id }}
                        </p>
                    </div>
                </form>
            </section>
        </main>
    </div>
</template>
