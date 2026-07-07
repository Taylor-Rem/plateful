<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 300 340"
        role="img"
        aria-label="Plateful"
        :class="className"
        v-bind="$attrs"
    >
        <!-- Plate: the bowl of the P -->
        <circle cx="176" cy="120" r="92" fill="#069494" />
        <circle
            cx="176"
            cy="120"
            r="70"
            fill="none"
            stroke="#ffffff"
            stroke-width="8"
        />
        <!-- Fork: the stem of the P -->
        <path
            fill="#B22222"
            d="M52,43.5 A3.5,3.5 0 0 1 59,43.5 L59,92 Q61,99 63,92 L63,43.5 A3.5,3.5 0 0 1 70,43.5 L70,92 Q72,99 74,92 L74,43.5 A3.5,3.5 0 0 1 81,43.5 L81,92 Q83,99 85,92 L85,43.5 A3.5,3.5 0 0 1 92,43.5 L92,104 C92,128 81,130 81,150 L81,296 Q81,310 72,310 Q63,310 63,296 L63,150 C63,130 52,128 52,104 Z"
        />
    </svg>
</template>
