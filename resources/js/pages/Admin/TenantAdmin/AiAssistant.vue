<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageHeader from '@/components/admin/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TenantAdminLayout from '@/layouts/admin/TenantAdminLayout.vue';

type ScopeOption = { value: string; label: string; recommended: boolean };

type CreatedKey = {
    key: App.Data.ApiKeyData;
    plainTextKey: string;
    mcpUrl: string;
    connectUrl: string;
};

const props = defineProps<{
    restaurant: App.Data.RestaurantData;
    keys: App.Data.ApiKeyData[];
    calls: App.Data.ApiCallLogData[];
    scopes: ScopeOption[];
    mcpUrl: string;
    keysPath: string;
    defaultRateLimit: number;
    maxRateLimit: number;
    createdKey: CreatedKey | null;
}>();

const form = useForm({
    name: 'Claude',
    scopes: props.scopes.filter((s) => s.recommended).map((s) => s.value),
    rate_limit_per_minute: props.defaultRateLimit,
});

const submit = (): void => {
    form.post(props.keysPath, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const revoke = (key: App.Data.ApiKeyData): void => {
    if (
        !confirm(
            `Revoke "${key.name}"? Anything connected with it stops working immediately.`,
        )
    ) {
        return;
    }

    router.delete(`${props.keysPath}/${key.id}`, { preserveScroll: true });
};

const claudeCodeCommand = computed(() =>
    props.createdKey
        ? `claude mcp add --transport http plateful ${props.createdKey.mcpUrl} --header "Authorization: Bearer ${props.createdKey.plainTextKey}"`
        : '',
);

const copied = ref<string | null>(null);

const copy = async (id: string, text: string): Promise<void> => {
    await navigator.clipboard.writeText(text);
    copied.value = id;
    setTimeout(() => {
        if (copied.value === id) {
            copied.value = null;
        }
    }, 2000);
};

const activeKeys = computed(() => props.keys.filter((k) => !k.revokedAt));
const revokedKeys = computed(() => props.keys.filter((k) => k.revokedAt));

function formatDate(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    try {
        return new Date(iso).toLocaleDateString();
    } catch {
        return '—';
    }
}

function formatDateTime(iso: string): string {
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

function argumentsSummary(call: App.Data.ApiCallLogData): string {
    if (!call.arguments) {
        return '';
    }

    return Object.entries(call.arguments)
        .filter(([key]) => key !== 'restaurant')
        .map(
            ([key, value]) =>
                `${key}=${typeof value === 'string' ? value : JSON.stringify(value)}`,
        )
        .join(' ');
}

defineOptions({ layout: TenantAdminLayout });
</script>

<template>
    <div>
        <Head title="AI assistant" />

        <div class="space-y-8">
            <PageHeader
                title="AI assistant"
                description="Connect the assistant you already use — Claude, ChatGPT or Claude Code — to this restaurant. It can read orders, edit the menu, mark items sold out and change photos, within the permissions you give it. This is free; your assistant's own plan covers the usage."
            />

            <section
                v-if="createdKey"
                class="rounded-lg border border-primary/40 bg-primary/5 p-6"
                data-testid="created-key"
            >
                <h3 class="text-base font-semibold text-foreground">
                    Your new key “{{ createdKey.key.name }}” — copy it now
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    It is shown this once. If you lose it, revoke it below and
                    create another.
                </p>

                <div
                    class="mt-4 flex flex-wrap items-center gap-2 rounded-md border border-border bg-background p-3 font-mono text-sm break-all"
                >
                    <code class="flex-1">{{ createdKey.plainTextKey }}</code>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="copy('key', createdKey.plainTextKey)"
                    >
                        <Check v-if="copied === 'key'" class="size-4" />
                        <Copy v-else class="size-4" />
                        {{ copied === 'key' ? 'Copied' : 'Copy key' }}
                    </Button>
                </div>

                <div class="mt-6 grid gap-6">
                    <div>
                        <h4 class="text-sm font-semibold text-foreground">
                            Claude Code
                        </h4>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Run this once in a terminal, then ask Claude about
                            your menu.
                        </p>
                        <div
                            class="mt-2 flex flex-wrap items-start gap-2 rounded-md border border-border bg-background p-3 font-mono text-xs break-all"
                        >
                            <code class="flex-1">{{ claudeCodeCommand }}</code>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="copy('code', claudeCodeCommand)"
                            >
                                <Check
                                    v-if="copied === 'code'"
                                    class="size-4"
                                />
                                <Copy v-else class="size-4" />
                                {{ copied === 'code' ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-foreground">
                            claude.ai
                        </h4>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Settings → Connectors → Add custom connector. Name
                            it Plateful, paste this URL, leave the OAuth fields
                            empty.
                        </p>
                        <div
                            class="mt-2 flex flex-wrap items-center gap-2 rounded-md border border-border bg-background p-3 font-mono text-xs break-all"
                        >
                            <code class="flex-1">{{
                                createdKey.connectUrl
                            }}</code>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="copy('url', createdKey.connectUrl)"
                            >
                                <Check v-if="copied === 'url'" class="size-4" />
                                <Copy v-else class="size-4" />
                                {{ copied === 'url' ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-foreground">
                            ChatGPT
                        </h4>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Settings → Connectors → Create (turn on Developer
                            mode under Advanced if you don't see it). Name it
                            Plateful, paste the same URL as above, choose “No
                            authentication”.
                        </p>
                    </div>

                    <p class="text-sm text-muted-foreground">
                        The URL contains your key, so treat it like a password:
                        anyone who has it can do what this key allows. If it
                        leaks, revoke the key here.
                    </p>
                </div>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h3 class="text-base font-semibold text-foreground">
                    Create a key
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    One key per assistant. Give it only the permissions it
                    needs; you can always make another.
                </p>
                <form class="mt-4 grid gap-5" @submit.prevent="submit">
                    <div class="grid gap-3 sm:grid-cols-[1fr_220px]">
                        <div>
                            <Label for="key-name">Name</Label>
                            <Input
                                id="key-name"
                                v-model="form.name"
                                type="text"
                                placeholder="Claude"
                                autocomplete="off"
                                maxlength="100"
                            />
                            <InputError
                                :message="form.errors.name"
                                class="mt-1"
                            />
                        </div>
                        <div>
                            <Label for="key-rate-limit"
                                >Requests per minute</Label
                            >
                            <Input
                                id="key-rate-limit"
                                v-model="form.rate_limit_per_minute"
                                type="number"
                                min="1"
                                :max="maxRateLimit"
                                step="1"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                Caps a runaway assistant. {{ defaultRateLimit }}
                                is plenty for one person; the most is
                                {{ maxRateLimit }}.
                            </p>
                            <InputError
                                :message="form.errors.rate_limit_per_minute"
                                class="mt-1"
                            />
                        </div>
                    </div>

                    <fieldset>
                        <legend class="text-sm font-medium text-foreground">
                            Permissions
                        </legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="scope in scopes"
                                :key="scope.value"
                                class="flex items-start gap-3 rounded-md border border-border p-3"
                                :for="`scope-${scope.value}`"
                            >
                                <input
                                    :id="`scope-${scope.value}`"
                                    v-model="form.scopes"
                                    type="checkbox"
                                    :value="scope.value"
                                    class="mt-1 h-4 w-4 rounded border-input"
                                />
                                <span class="grid gap-0.5">
                                    <span
                                        class="text-sm font-medium text-foreground"
                                        >{{ scope.label }}</span
                                    >
                                    <span
                                        class="font-mono text-xs text-muted-foreground"
                                        >{{ scope.value }}</span
                                    >
                                </span>
                            </label>
                        </div>
                        <InputError
                            :message="form.errors.scopes"
                            class="mt-1"
                        />
                    </fieldset>

                    <div>
                        <Button type="submit" :disabled="form.processing">
                            {{ form.processing ? 'Creating…' : 'Create key' }}
                        </Button>
                    </div>
                </form>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h3 class="text-base font-semibold text-foreground">Keys</h3>
                <div
                    v-if="activeKeys.length === 0"
                    class="mt-4 rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                >
                    No assistant connected yet.
                </div>
                <ul v-else class="mt-4 divide-y divide-border text-sm">
                    <li
                        v-for="key in activeKeys"
                        :key="key.id"
                        class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3"
                    >
                        <div class="min-w-0">
                            <div class="font-medium text-foreground">
                                {{ key.name }}
                                <span
                                    class="ml-2 font-mono text-xs text-muted-foreground"
                                    >{{ key.keyPrefix }}…</span
                                >
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ key.scopes.join(', ') }} ·
                                {{ key.rateLimitPerMinute }}/min · Last used
                                {{ formatDate(key.lastUsedAt) }} · Created
                                {{ formatDate(key.createdAt) }}
                                <template v-if="key.createdByName">
                                    by {{ key.createdByName }}
                                </template>
                            </div>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="revoke(key)"
                        >
                            Revoke
                        </Button>
                    </li>
                </ul>
                <details v-if="revokedKeys.length > 0" class="mt-4 text-sm">
                    <summary class="cursor-pointer text-muted-foreground">
                        {{ revokedKeys.length }} revoked
                    </summary>
                    <ul class="mt-2 divide-y divide-border">
                        <li
                            v-for="key in revokedKeys"
                            :key="key.id"
                            class="py-2 text-muted-foreground"
                        >
                            {{ key.name }}
                            <span class="font-mono text-xs"
                                >{{ key.keyPrefix }}…</span
                            >
                            · revoked {{ formatDate(key.revokedAt) }}
                        </li>
                    </ul>
                </details>
            </section>

            <section class="rounded-lg border border-border bg-card p-6">
                <h3 class="text-base font-semibold text-foreground">
                    Recent activity
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Every call your assistants made here, newest first. Refused
                    calls are listed too.
                </p>
                <div
                    v-if="calls.length === 0"
                    class="mt-4 rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                >
                    Nothing yet.
                </div>
                <div v-else class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-muted-foreground">
                            <tr>
                                <th class="py-2 pr-4 font-medium">When</th>
                                <th class="py-2 pr-4 font-medium">Who</th>
                                <th class="py-2 pr-4 font-medium">Action</th>
                                <th class="py-2 pr-4 font-medium">Result</th>
                                <th class="py-2 font-medium">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="call in calls" :key="call.id">
                                <td
                                    class="py-2 pr-4 whitespace-nowrap text-muted-foreground"
                                >
                                    {{ formatDateTime(call.createdAt) }}
                                </td>
                                <td class="py-2 pr-4 whitespace-nowrap">
                                    {{ call.keyName ?? call.userName ?? '—' }}
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="font-mono text-xs">{{
                                        call.action
                                    }}</span>
                                    <span
                                        class="ml-1 rounded bg-muted px-1 font-mono text-[10px] text-muted-foreground uppercase"
                                        >{{ call.channel }}</span
                                    >
                                    <div
                                        v-if="argumentsSummary(call)"
                                        class="max-w-md truncate font-mono text-xs text-muted-foreground"
                                    >
                                        {{ argumentsSummary(call) }}
                                    </div>
                                </td>
                                <td class="py-2 pr-4">
                                    <span
                                        v-if="call.ok"
                                        class="text-emerald-700 dark:text-emerald-400"
                                        >OK</span
                                    >
                                    <span
                                        v-else
                                        class="text-destructive"
                                        :title="call.error ?? ''"
                                        >{{ call.error ?? 'Failed' }}</span
                                    >
                                </td>
                                <td
                                    class="py-2 whitespace-nowrap text-muted-foreground"
                                >
                                    {{ call.durationMs }} ms
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>
