<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed, reactive } from 'vue';

type Window = { opens_at: string; closes_at: string };
type WindowsByDay = Record<string, Window[]>;

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

// Render order: Monday first (matches typical schedule editors), but day_of_week stays Sunday=0.
const dayOrder: Array<{ dow: number; label: string }> = [
    { dow: 1, label: 'Monday' },
    { dow: 2, label: 'Tuesday' },
    { dow: 3, label: 'Wednesday' },
    { dow: 4, label: 'Thursday' },
    { dow: 5, label: 'Friday' },
    { dow: 6, label: 'Saturday' },
    { dow: 0, label: 'Sunday' },
];

const initial = (): WindowsByDay => {
    const out: WindowsByDay = {};
    for (let d = 0; d < 7; d++) {
        const arr = (props.restaurant.hoursByDay?.[d as unknown as keyof typeof props.restaurant.hoursByDay] as
            | Array<{ opensAt: string; closesAt: string }>
            | undefined) ?? [];
        out[String(d)] = arr.map((w) => ({ opens_at: w.opensAt, closes_at: w.closesAt }));
    }
    return out;
};

const form = useForm<{ windows: WindowsByDay }>({
    windows: initial(),
});

const state = reactive({
    closed: Object.fromEntries(
        Array.from({ length: 7 }, (_, d) => [
            String(d),
            (form.windows[String(d)] ?? []).length === 0,
        ]),
    ) as Record<string, boolean>,
});

const toggleClosed = (dow: number, closed: boolean): void => {
    const key = String(dow);
    state.closed[key] = closed;
    if (closed) {
        form.windows[key] = [];
    } else if ((form.windows[key] ?? []).length === 0) {
        form.windows[key] = [{ opens_at: '09:00', closes_at: '21:00' }];
    }
};

const addWindow = (dow: number): void => {
    const key = String(dow);
    if (!form.windows[key]) form.windows[key] = [];
    form.windows[key].push({ opens_at: '09:00', closes_at: '21:00' });
};

const removeWindow = (dow: number, idx: number): void => {
    const key = String(dow);
    form.windows[key].splice(idx, 1);
    if (form.windows[key].length === 0) {
        state.closed[key] = true;
    }
};

const errorFor = (dow: number, idx: number, field: 'opens_at' | 'closes_at'): string | undefined => {
    const key = `windows.${dow}.${idx}.${field}`;
    return (form.errors as unknown as Record<string, string>)[key];
};

const submit = (): void => {
    form.put(`/${props.restaurant.subdomain}/hours`, {
        preserveScroll: true,
    });
};

const tz = computed(() => props.restaurant.timezone);
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} Hours`" />

        <div class="flex items-baseline justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-foreground">Hours</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    All times in {{ tz }}.
                </p>
            </div>
        </div>

        <form class="mt-6 max-w-2xl space-y-3" @submit.prevent="submit">
            <section
                v-for="{ dow, label } in dayOrder"
                :key="dow"
                class="rounded-lg border border-border bg-card p-5"
            >
                <div class="flex items-center justify-between gap-4">
                    <h3 class="text-base font-medium text-foreground">{{ label }}</h3>
                    <label class="inline-flex items-center gap-2 text-sm text-muted-foreground">
                        <input
                            type="checkbox"
                            class="rounded"
                            :checked="state.closed[String(dow)]"
                            @change="
                                toggleClosed(
                                    dow,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        Closed
                    </label>
                </div>

                <div
                    v-if="!state.closed[String(dow)]"
                    class="mt-4 space-y-2"
                >
                    <div
                        v-for="(win, idx) in form.windows[String(dow)] ?? []"
                        :key="idx"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <input
                            v-model="win.opens_at"
                            type="time"
                            class="rounded-md border border-input bg-background px-3 py-1.5 text-sm"
                        />
                        <span class="text-sm text-muted-foreground">to</span>
                        <input
                            v-model="win.closes_at"
                            type="time"
                            class="rounded-md border border-input bg-background px-3 py-1.5 text-sm"
                        />
                        <button
                            type="button"
                            class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                            :aria-label="`Remove window ${idx + 1}`"
                            @click="removeWindow(dow, idx)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                        <p
                            v-if="errorFor(dow, idx, 'opens_at')"
                            class="basis-full text-xs text-destructive"
                        >
                            {{ errorFor(dow, idx, 'opens_at') }}
                        </p>
                        <p
                            v-if="errorFor(dow, idx, 'closes_at')"
                            class="basis-full text-xs text-destructive"
                        >
                            {{ errorFor(dow, idx, 'closes_at') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground underline hover:text-foreground"
                        @click="addWindow(dow)"
                    >
                        <Plus class="size-3" />
                        Add window
                    </button>
                </div>
            </section>

            <div class="sticky bottom-0 z-10 -mx-1 flex justify-end bg-background/80 py-3 backdrop-blur">
                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : 'Save hours' }}
                </Button>
            </div>
        </form>
    </TenantAdminLayout>
</template>
