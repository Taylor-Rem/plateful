<script setup lang="ts">
import HoursEditor from '@/components/HoursEditor.vue';

defineProps<{
    restaurant: App.Data.RestaurantData;
}>();

const emit = defineEmits<{ advance: [] }>();
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-muted-foreground">
            We've started you with 11:00 AM – 9:00 PM every day — tweak
            anything, mark days closed, and save.
        </p>

        <HoursEditor
            :restaurant="restaurant"
            prefill-defaults
            show-timezone
            submit-label="Save hours & continue"
            @saved="emit('advance')"
        />
    </div>
</template>
