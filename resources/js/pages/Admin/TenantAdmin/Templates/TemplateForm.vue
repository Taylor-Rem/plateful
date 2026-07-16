<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2, ArrowUp, ArrowDown } from 'lucide-vue-next';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type OptionRow = {
    id: number | null;
    name: string;
    price_delta: string;
    is_available: boolean;
};

type GroupRow = {
    id: number | null;
    name: string;
    min_selections: number;
    max_selections: string; // empty string = no max
    options: OptionRow[];
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    template: App.Data.ItemTemplateData | null;
}>();

const isEdit = computed(() => props.template !== null);
const base = computed(() => `/${props.restaurant.subdomain}`);

const initialGroups: GroupRow[] = (props.template?.groups ?? []).map((g) => ({
    id: g.id,
    name: g.name,
    min_selections: g.minSelections,
    max_selections: g.maxSelections === null ? '' : String(g.maxSelections),
    options: g.options.map((o) => ({
        id: o.id,
        name: o.name,
        price_delta: (o.priceDeltaCents / 100).toFixed(2),
        is_available: o.isAvailable,
    })),
}));

const form = useForm({
    _method: 'post' as 'post' | 'put',
    name: props.template?.name ?? '',
    description: props.template?.description ?? '',
    is_active: props.template ? props.template.isActive : true,
    groups: initialGroups,
});

const addGroup = (): void => {
    form.groups.push({
        id: null,
        name: '',
        min_selections: 0,
        max_selections: '',
        options: [],
    });
};

const removeGroup = (index: number): void => {
    form.groups.splice(index, 1);
};

const moveGroup = (index: number, dir: -1 | 1): void => {
    const next = index + dir;

    if (next < 0 || next >= form.groups.length) {
        return;
    }

    const tmp = form.groups[index];
    form.groups[index] = form.groups[next];
    form.groups[next] = tmp;
};

const addOption = (gIndex: number): void => {
    form.groups[gIndex].options.push({
        id: null,
        name: '',
        price_delta: '0.00',
        is_available: true,
    });
};

const removeOption = (gIndex: number, oIndex: number): void => {
    form.groups[gIndex].options.splice(oIndex, 1);
};

const moveOption = (gIndex: number, oIndex: number, dir: -1 | 1): void => {
    const arr = form.groups[gIndex].options;
    const next = oIndex + dir;

    if (next < 0 || next >= arr.length) {
        return;
    }

    const tmp = arr[oIndex];
    arr[oIndex] = arr[next];
    arr[next] = tmp;
};

const submit = (): void => {
    if (isEdit.value && props.template) {
        form._method = 'put';
        form.post(`${base.value}/menu/templates/${props.template.id}`, {
            preserveScroll: true,
        });
    } else {
        form._method = 'post';
        form.post(`${base.value}/menu/templates`, { preserveScroll: true });
    }
};

const errorAt = (key: string): string | undefined => {
    return (form.errors as Record<string, string>)[key];
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <section class="rounded-lg border border-border bg-card p-5">
            <h3 class="text-base font-medium text-foreground">
                Template details
            </h3>
            <div class="mt-4 grid gap-4">
                <div class="grid gap-2">
                    <Label for="tpl-name">Name</Label>
                    <Input
                        id="tpl-name"
                        v-model="form.name"
                        required
                        placeholder="e.g. Pizza"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="tpl-description">Description</Label>
                    <textarea
                        id="tpl-description"
                        v-model="form.description"
                        rows="2"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus:border-ring focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                    <InputError :message="form.errors.description" />
                </div>
                <label class="flex items-center gap-2 text-sm text-foreground">
                    <Checkbox v-model="form.is_active" />
                    Active
                </label>
            </div>
        </section>

        <section class="rounded-lg border border-border bg-card p-5">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-base font-medium text-foreground">
                        Groups
                    </h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Each group lets the customer pick from a set of options.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addGroup"
                >
                    <Plus class="size-4" /> Add group
                </Button>
            </div>

            <p
                v-if="form.groups.length === 0"
                class="mt-4 text-sm text-muted-foreground"
            >
                No groups yet. Add one to begin (e.g. "Size", "Crust",
                "Toppings").
            </p>

            <div
                v-for="(group, gIndex) in form.groups"
                :key="gIndex"
                class="mt-4 rounded-md border border-border bg-muted/20 p-4"
            >
                <div class="grid gap-3 sm:grid-cols-12 sm:items-end">
                    <div class="grid gap-1 sm:col-span-4">
                        <Label :for="`g-name-${gIndex}`">Group name</Label>
                        <Input
                            :id="`g-name-${gIndex}`"
                            v-model="group.name"
                            required
                        />
                        <InputError
                            :message="errorAt(`groups.${gIndex}.name`)"
                        />
                    </div>
                    <div class="grid gap-1 sm:col-span-3">
                        <Label :for="`g-min-${gIndex}`">Min selections</Label>
                        <Input
                            :id="`g-min-${gIndex}`"
                            v-model.number="group.min_selections"
                            type="number"
                            min="0"
                        />
                        <InputError
                            :message="
                                errorAt(`groups.${gIndex}.min_selections`)
                            "
                        />
                    </div>
                    <div class="grid gap-1 sm:col-span-3">
                        <Label :for="`g-max-${gIndex}`">Max selections</Label>
                        <Input
                            :id="`g-max-${gIndex}`"
                            v-model="group.max_selections"
                            type="number"
                            min="0"
                            placeholder="No max"
                        />
                        <InputError
                            :message="
                                errorAt(`groups.${gIndex}.max_selections`)
                            "
                        />
                    </div>
                    <div
                        class="flex items-center justify-end gap-1 sm:col-span-2"
                    >
                        <button
                            type="button"
                            class="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-40"
                            aria-label="Move group up"
                            :disabled="gIndex === 0"
                            @click="moveGroup(gIndex, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-40"
                            aria-label="Move group down"
                            :disabled="gIndex === form.groups.length - 1"
                            @click="moveGroup(gIndex, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-destructive"
                            aria-label="Remove group"
                            @click="removeGroup(gIndex)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>

                <div class="mt-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-foreground"
                            >Options</span
                        >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addOption(gIndex)"
                        >
                            <Plus class="size-4" /> Add option
                        </Button>
                    </div>

                    <p
                        v-if="group.options.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        No options. Add at least one for customers to choose.
                    </p>

                    <div
                        v-for="(opt, oIndex) in group.options"
                        :key="oIndex"
                        class="grid gap-2 rounded border border-border bg-card p-2 sm:grid-cols-12 sm:items-end"
                    >
                        <div class="grid gap-1 sm:col-span-5">
                            <Label
                                :for="`o-name-${gIndex}-${oIndex}`"
                                class="text-xs"
                                >Name</Label
                            >
                            <Input
                                :id="`o-name-${gIndex}-${oIndex}`"
                                v-model="opt.name"
                                required
                            />
                            <InputError
                                :message="
                                    errorAt(
                                        `groups.${gIndex}.options.${oIndex}.name`,
                                    )
                                "
                            />
                        </div>
                        <div class="grid gap-1 sm:col-span-3">
                            <Label
                                :for="`o-price-${gIndex}-${oIndex}`"
                                class="text-xs"
                                >Price delta</Label
                            >
                            <Input
                                :id="`o-price-${gIndex}-${oIndex}`"
                                v-model="opt.price_delta"
                                type="number"
                                step="0.01"
                                required
                            />
                            <InputError
                                :message="
                                    errorAt(
                                        `groups.${gIndex}.options.${oIndex}.price_delta`,
                                    )
                                "
                            />
                        </div>
                        <div class="flex items-center gap-2 sm:col-span-2">
                            <label
                                class="flex items-center gap-2 text-xs text-foreground"
                            >
                                <Checkbox v-model="opt.is_available" />
                                Available
                            </label>
                        </div>
                        <div
                            class="flex items-center justify-end gap-1 sm:col-span-2"
                        >
                            <button
                                type="button"
                                class="rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-40"
                                aria-label="Move option up"
                                :disabled="oIndex === 0"
                                @click="moveOption(gIndex, oIndex, -1)"
                            >
                                <ArrowUp class="size-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground disabled:opacity-40"
                                aria-label="Move option down"
                                :disabled="oIndex === group.options.length - 1"
                                @click="moveOption(gIndex, oIndex, 1)"
                            >
                                <ArrowDown class="size-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-muted-foreground hover:bg-accent hover:text-destructive"
                                aria-label="Remove option"
                                @click="removeOption(gIndex, oIndex)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="flex items-center justify-end gap-2">
            <Button as-child variant="outline">
                <Link :href="`${base}/menu/templates`">Cancel</Link>
            </Button>
            <Button type="submit" :disabled="form.processing">{{
                isEdit ? 'Save changes' : 'Create template'
            }}</Button>
        </div>
    </form>
</template>
