<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import TenantAdminLayout from '@/pages/Admin/TenantAdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

type TemplateRow = {
    id: number;
    name: string;
    description: string | null;
    isActive: boolean;
    groupsCount: number;
    menuItemsCount: number;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    templates: TemplateRow[];
}>();

const base = computed(() => `/${props.restaurant.subdomain}`);

const deleteTemplate = (t: TemplateRow): void => {
    if (t.menuItemsCount > 0) {
        alert(`Cannot delete: ${t.menuItemsCount} menu item(s) use this template.`);
        return;
    }
    if (!confirm(`Delete template "${t.name}"?`)) {
        return;
    }
    router.delete(`${base.value}/menu/templates/${t.id}`, { preserveScroll: true });
};
</script>

<template>
    <TenantAdminLayout :restaurant="restaurant">
        <Head :title="`${restaurant.name} — Item templates`" />

        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-foreground">Item templates</h2>
                <p class="mt-1 text-sm text-muted-foreground">Reusable groups of options that menu items can offer.</p>
            </div>
            <div class="flex items-center gap-2">
                <Button as-child variant="outline">
                    <Link :href="`${base}/menu`">Back to menu</Link>
                </Button>
                <Link
                    :href="`${base}/menu/templates/create`"
                    class="inline-flex items-center gap-1 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    <Plus class="size-4" /> New template
                </Link>
            </div>
        </div>

        <div v-if="templates.length === 0" class="mt-12 rounded-lg border border-dashed border-border bg-card p-10 text-center">
            <h3 class="text-base font-medium text-foreground">No templates yet</h3>
            <p class="mt-1 text-sm text-muted-foreground">Create a template (e.g. Pizza) to share configuration across menu items.</p>
            <Button as-child class="mt-4">
                <Link :href="`${base}/menu/templates/create`">
                    <Plus class="size-4" /> New template
                </Link>
            </Button>
        </div>

        <div v-else class="mt-6 overflow-hidden rounded-lg border border-border bg-card">
            <table class="w-full text-sm">
                <thead class="border-b border-border bg-muted/30 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">Groups</th>
                        <th class="px-4 py-2 font-medium">Items using</th>
                        <th class="px-4 py-2 font-medium">Active</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="t in templates" :key="t.id">
                        <td class="px-4 py-3 text-foreground">
                            <div class="font-medium">{{ t.name }}</div>
                            <div v-if="t.description" class="text-xs text-muted-foreground">{{ t.description }}</div>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">{{ t.groupsCount }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ t.menuItemsCount }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ t.isActive ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <Link
                                    :href="`${base}/menu/templates/${t.id}/edit`"
                                    class="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                                    aria-label="Edit template"
                                >
                                    <Pencil class="size-4" />
                                </Link>
                                <button
                                    type="button"
                                    class="rounded p-1.5 text-muted-foreground hover:bg-accent hover:text-destructive disabled:cursor-not-allowed disabled:opacity-40"
                                    aria-label="Delete template"
                                    :title="t.menuItemsCount > 0 ? 'Detach menu items first' : 'Delete template'"
                                    :disabled="t.menuItemsCount > 0"
                                    @click="deleteTemplate(t)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </TenantAdminLayout>
</template>
