<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    /** Home page section id, e.g. "about" or "location". */
    hash: string;
}>();

const page = usePage();
const isOnHomePage = computed(
    () => new URL(page.url, 'http://placeholder').pathname === '/',
);
const href = computed(() => `/#${props.hash}`);
</script>

<template>
    <!-- On the home page a plain anchor scrolls natively with no server round
         trip; elsewhere an Inertia visit avoids a full page reload and scrolls
         to the section once the home page renders. -->
    <a v-if="isOnHomePage" :href="href"><slot /></a>
    <Link v-else :href="href"><slot /></Link>
</template>
