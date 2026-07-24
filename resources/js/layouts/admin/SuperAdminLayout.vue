<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import SuperAdminSidebar from '@/components/admin/SuperAdminSidebar.vue';
import {
    SidebarInset,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';

const page = usePage<{ sidebarOpen: boolean }>();
</script>

<template>
    <SidebarProvider :default-open="page.props.sidebarOpen">
        <SuperAdminSidebar />
        <SidebarInset>
            <header
                class="flex h-14 shrink-0 items-center gap-3 border-b border-border px-4 md:px-6"
            >
                <SidebarTrigger class="-ml-1" />
                <span class="truncate text-sm font-medium text-foreground">
                    Platform admin
                </span>
            </header>
            <main class="mx-auto w-full max-w-5xl px-4 py-6 md:px-6 md:py-8">
                <slot />
            </main>
        </SidebarInset>
    </SidebarProvider>
</template>
