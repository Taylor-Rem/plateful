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
        viewBox="0 0 940 260"
        role="img"
        aria-label="Plateful"
        :class="className"
        v-bind="$attrs"
    >
        <!-- Logo mark (plate + fork) as the leading P -->
        <g transform="translate(11.1,32.8) scale(0.5556)">
            <circle cx="176" cy="120" r="92" fill="#069494" />
            <circle
                cx="176"
                cy="120"
                r="70"
                fill="none"
                stroke="#ffffff"
                stroke-width="8"
            />
            <path
                fill="#B22222"
                d="M52,43.5 A3.5,3.5 0 0 1 59,43.5 L59,92 Q61,99 63,92 L63,43.5 A3.5,3.5 0 0 1 70,43.5 L70,92 Q72,99 74,92 L74,43.5 A3.5,3.5 0 0 1 81,43.5 L81,92 Q83,99 85,92 L85,43.5 A3.5,3.5 0 0 1 92,43.5 L92,104 C92,128 81,130 81,150 L81,296 Q81,310 72,310 Q63,310 63,296 L63,150 C63,130 52,128 52,104 Z"
            />
        </g>
        <!-- Wordmark: "late" (plate teal) + "ful" (fork crimson).
             tspans must stay adjacent — any whitespace between them renders
             as a space ("late ful"), so keep prettier from reflowing them. -->
        <!-- prettier-ignore -->
        <text x="168" y="205" font-family="'Poppins','Trebuchet MS',Arial,sans-serif" font-weight="700" font-size="210" letter-spacing="-4"><tspan fill="#069494">late</tspan><tspan fill="#B22222">ful</tspan></text>
    </svg>
</template>
