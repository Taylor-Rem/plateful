<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    timezones: string[];
    reservedSubdomains: string[];
    primaryDomain: string;
}>();

const form = useForm({
    name: '',
    subdomain: '',
    email: '',
    phone: '',
    street: '',
    street2: '',
    city: '',
    state: '',
    postal_code: '',
    country: 'US',
    timezone: 'America/New_York',
    primary_color: '#b91c1c',
    secondary_color: '#ffffff',
    description: '',
    tax_rate_percent: '0',
    delivery_fee: '0',
    owner_email: '',
});

function sanitizeSubdomain(value: string): string {
    return value.toLowerCase().replace(/[^a-z0-9-]/g, '').replace(/-{2,}/g, '-');
}

function onSubdomainInput(event: Event) {
    const target = event.target as HTMLInputElement;
    form.subdomain = sanitizeSubdomain(target.value);
}

const subdomainReserved = computed(() =>
    props.reservedSubdomains.includes(form.subdomain.toLowerCase()),
);

const subdomainPreview = computed(() => {
    const sub = form.subdomain || 'subdomain';
    return `${sub}.${props.primaryDomain}`;
});

function submit() {
    form.post('/super/restaurants');
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <Head title="Create restaurant" />
        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-4">
                    <Link
                        href="/super/restaurants"
                        class="text-sm text-muted-foreground hover:text-foreground"
                    >
                        ←
                    </Link>
                    <h1 class="text-lg font-semibold text-foreground">Create restaurant</h1>
                </div>
                <AppearanceTabs />
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-6 py-8">
            <form @submit.prevent="submit" class="space-y-8">
                <section class="rounded-lg border border-border bg-card p-6">
                    <h2 class="text-base font-semibold text-foreground">Basics</h2>
                    <div class="mt-4 grid gap-4">
                        <div class="grid gap-2">
                            <Label for="name">Restaurant name</Label>
                            <Input id="name" v-model="form.name" required />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="subdomain">Subdomain</Label>
                            <Input
                                id="subdomain"
                                :value="form.subdomain"
                                @input="onSubdomainInput"
                                required
                                placeholder="marcos"
                            />
                            <p class="text-xs text-muted-foreground">
                                Will be:
                                <span class="font-mono text-foreground">{{ subdomainPreview }}</span>
                            </p>
                            <p
                                v-if="subdomainReserved"
                                class="text-xs text-amber-600 dark:text-amber-400"
                            >
                                "{{ form.subdomain }}" is reserved — please choose another.
                            </p>
                            <InputError :message="form.errors.subdomain" />
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-card p-6">
                    <h2 class="text-base font-semibold text-foreground">Branding</h2>
                    <div class="mt-4 grid gap-4">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="primary_color">Primary color</Label>
                                <div class="flex items-center gap-2">
                                    <input
                                        id="primary_color"
                                        type="color"
                                        v-model="form.primary_color"
                                        class="h-9 w-12 rounded border border-input"
                                    />
                                    <Input v-model="form.primary_color" class="flex-1 font-mono" />
                                </div>
                                <InputError :message="form.errors.primary_color" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="secondary_color">Secondary color</Label>
                                <div class="flex items-center gap-2">
                                    <input
                                        id="secondary_color"
                                        type="color"
                                        v-model="form.secondary_color"
                                        class="h-9 w-12 rounded border border-input"
                                    />
                                    <Input v-model="form.secondary_color" class="flex-1 font-mono" />
                                </div>
                                <InputError :message="form.errors.secondary_color" />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="description">Description</Label>
                            <textarea
                                id="description"
                                v-model="form.description"
                                rows="3"
                                maxlength="1000"
                                class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                            />
                            <InputError :message="form.errors.description" />
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-card p-6">
                    <h2 class="text-base font-semibold text-foreground">Contact</h2>
                    <div class="mt-4 grid gap-4">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="email">Public email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    v-model="form.email"
                                    required
                                />
                                <InputError :message="form.errors.email" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="phone">Phone</Label>
                                <Input id="phone" v-model="form.phone" />
                                <InputError :message="form.errors.phone" />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label for="street">Street</Label>
                            <Input id="street" v-model="form.street" />
                            <InputError :message="form.errors.street" />
                        </div>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <div class="grid gap-2">
                                <Label for="city">City</Label>
                                <Input id="city" v-model="form.city" />
                                <InputError :message="form.errors.city" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="state">State (2 char)</Label>
                                <Input
                                    id="state"
                                    v-model="form.state"
                                    maxlength="2"
                                    class="uppercase"
                                />
                                <InputError :message="form.errors.state" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="postal_code">Postal code</Label>
                                <Input id="postal_code" v-model="form.postal_code" />
                                <InputError :message="form.errors.postal_code" />
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-card p-6">
                    <h2 class="text-base font-semibold text-foreground">Operations</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="timezone">Timezone</Label>
                            <select
                                id="timezone"
                                v-model="form.timezone"
                                class="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                            >
                                <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                            </select>
                            <InputError :message="form.errors.timezone" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="tax_rate_percent">Tax rate %</Label>
                            <Input
                                id="tax_rate_percent"
                                type="number"
                                step="0.01"
                                min="0"
                                max="30"
                                v-model="form.tax_rate_percent"
                            />
                            <InputError :message="form.errors.tax_rate_percent" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="delivery_fee">Delivery fee ($)</Label>
                            <Input
                                id="delivery_fee"
                                type="number"
                                step="0.01"
                                min="0"
                                max="500"
                                v-model="form.delivery_fee"
                            />
                            <InputError :message="form.errors.delivery_fee" />
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-card p-6">
                    <h2 class="text-base font-semibold text-foreground">Owner invite (optional)</h2>
                    <div class="mt-4 grid gap-2">
                        <Label for="owner_email">Owner email</Label>
                        <Input
                            id="owner_email"
                            type="email"
                            v-model="form.owner_email"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional — if provided, we'll email them an invite to manage this restaurant.
                        </p>
                        <InputError :message="form.errors.owner_email" />
                    </div>
                </section>

                <div class="flex items-center justify-end gap-3">
                    <Link href="/super/restaurants">
                        <Button type="button" variant="outline">Cancel</Button>
                    </Link>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Creating…' : 'Create restaurant' }}
                    </Button>
                </div>
            </form>
        </main>
    </div>
</template>
