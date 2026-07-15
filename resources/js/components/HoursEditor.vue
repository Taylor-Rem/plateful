<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from 'lucide-vue-next';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';

type Window = { opens_at: string; closes_at: string };
type WindowsByDay = Record<string, Window[]>;

const props = withDefaults(
    defineProps<{
        restaurant: App.Data.RestaurantData;
        /** Prefill a sensible schedule when no hours exist yet (wizard mode). */
        prefillDefaults?: boolean;
        /** Show a timezone select that saves alongside the hours. */
        showTimezone?: boolean;
        submitLabel?: string;
    }>(),
    {
        prefillDefaults: false,
        showTimezone: false,
        submitLabel: 'Save hours',
    },
);

const emit = defineEmits<{ saved: [] }>();

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

const US_TIMEZONES: Array<{ value: string; label: string }> = [
    { value: 'America/New_York', label: 'Eastern (New York)' },
    { value: 'America/Chicago', label: 'Central (Chicago)' },
    { value: 'America/Denver', label: 'Mountain (Denver)' },
    { value: 'America/Phoenix', label: 'Arizona (Phoenix)' },
    { value: 'America/Los_Angeles', label: 'Pacific (Los Angeles)' },
    { value: 'America/Anchorage', label: 'Alaska (Anchorage)' },
    { value: 'Pacific/Honolulu', label: 'Hawaii (Honolulu)' },
];

const timezoneOptions = US_TIMEZONES.some(
    (tz) => tz.value === props.restaurant.timezone,
)
    ? US_TIMEZONES
    : [
          {
              value: props.restaurant.timezone,
              label: props.restaurant.timezone,
          },
          ...US_TIMEZONES,
      ];

const hasExistingHours = Object.values(props.restaurant.hoursByDay ?? {}).some(
    (windows) => (windows as unknown[]).length > 0,
);

const initial = (): WindowsByDay => {
    const out: WindowsByDay = {};

    for (let d = 0; d < 7; d++) {
        const arr =
            (props.restaurant.hoursByDay?.[
                d as unknown as keyof typeof props.restaurant.hoursByDay
            ] as Array<{ opensAt: string; closesAt: string }> | undefined) ??
            [];
        out[String(d)] = arr.map((w) => ({
            opens_at: w.opensAt,
            closes_at: w.closesAt,
        }));
    }

    // Wizard mode: start from a common schedule instead of an empty grid, so
    // the fast path is "tweak and save" rather than "build from scratch".
    if (props.prefillDefaults && !hasExistingHours) {
        for (let d = 0; d < 7; d++) {
            out[String(d)] = [{ opens_at: '11:00', closes_at: '21:00' }];
        }
    }

    return out;
};

const form = useForm<{ windows: WindowsByDay; timezone: string }>({
    windows: initial(),
    timezone: props.restaurant.timezone,
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

    if (!form.windows[key]) {
        form.windows[key] = [];
    }

    form.windows[key].push({ opens_at: '09:00', closes_at: '21:00' });
};

const removeWindow = (dow: number, idx: number): void => {
    const key = String(dow);
    form.windows[key].splice(idx, 1);

    if (form.windows[key].length === 0) {
        state.closed[key] = true;
    }
};

const errorFor = (
    dow: number,
    idx: number,
    field: 'opens_at' | 'closes_at',
): string | undefined => {
    const key = `windows.${dow}.${idx}.${field}`;

    return (form.errors as unknown as Record<string, string>)[key];
};

const submit = (): void => {
    form.put(`/${props.restaurant.subdomain}/hours`, {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
};
</script>

<template>
    <form class="space-y-3" @submit.prevent="submit">
        <div
            v-if="showTimezone"
            class="rounded-lg border border-border bg-card p-5"
        >
            <label
                for="hours-timezone"
                class="text-sm font-medium text-foreground"
            >
                Timezone
            </label>
            <div class="mt-2 flex items-center gap-3">
                <select
                    id="hours-timezone"
                    v-model="form.timezone"
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm"
                    data-test="hours-timezone-select"
                >
                    <option
                        v-for="tz in timezoneOptions"
                        :key="tz.value"
                        :value="tz.value"
                    >
                        {{ tz.label }}
                    </option>
                </select>
            </div>
            <p
                v-if="form.errors.timezone"
                class="mt-1 text-xs text-destructive"
            >
                {{ form.errors.timezone }}
            </p>
        </div>

        <section
            v-for="{ dow, label } in dayOrder"
            :key="dow"
            class="rounded-lg border border-border bg-card p-5"
        >
            <div class="flex items-center justify-between gap-4">
                <h3 class="text-base font-medium text-foreground">
                    {{ label }}
                </h3>
                <label
                    class="inline-flex items-center gap-2 text-sm text-muted-foreground"
                >
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

            <div v-if="!state.closed[String(dow)]" class="mt-4 space-y-2">
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

        <div
            class="sticky bottom-0 z-10 -mx-1 flex justify-end bg-background/80 py-3 backdrop-blur"
        >
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="save-hours-button"
            >
                {{ form.processing ? 'Saving...' : submitLabel }}
            </Button>
        </div>
    </form>
</template>
