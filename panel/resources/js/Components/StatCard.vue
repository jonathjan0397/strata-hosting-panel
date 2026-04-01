<template>
    <div class="rounded-xl border border-gray-800 bg-gray-900 px-5 py-4">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ label }}</p>
        <p class="mt-2 text-3xl font-semibold" :class="colorClass">{{ value }}</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: String,
    value: [String, Number],
    color: {
        type: String,
        default: 'indigo',
    },
});

const colorClass = computed(() => ({
    indigo: 'text-indigo-400',
    emerald: 'text-emerald-400',
    amber: 'text-amber-400',
    red: 'text-red-400',
    gray: 'text-gray-300',
}[props.color] ?? 'text-gray-100'));
</script>
