<template>
    <div>
        <div class="flex justify-between text-xs mb-1">
            <span class="text-gray-500">{{ label }}</span>
            <span class="text-gray-400">
                <template v-if="limit > 0">{{ used.toLocaleString() }} / {{ limit.toLocaleString() }} {{ unit }}</template>
                <template v-else>{{ used.toLocaleString() }} {{ unit }} (unlimited)</template>
            </span>
        </div>
        <div class="h-1.5 w-full rounded-full bg-gray-800 overflow-hidden">
            <div
                class="h-full rounded-full transition-all"
                :class="barColor"
                :style="{ width: pct + '%' }"
            ></div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: String,
    used:  { type: Number, default: 0 },
    limit: { type: Number, default: 0 },
    unit:  { type: String, default: 'MB' },
});

const pct = computed(() => {
    if (props.limit <= 0) return 0;
    return Math.min(100, (props.used / props.limit) * 100);
});

const barColor = computed(() => {
    if (pct.value > 85) return 'bg-red-500';
    if (pct.value > 70) return 'bg-amber-500';
    return 'bg-indigo-500';
});
</script>
