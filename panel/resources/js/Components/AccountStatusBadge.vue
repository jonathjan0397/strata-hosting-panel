<template>
    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium" :class="classes[status] ?? classes.unknown">
        {{ status }}
    </span>
</template>

<script setup>
defineProps({ status: String });

const classes = {
    active:     'bg-emerald-900/40 text-emerald-400',
    suspended:  'bg-amber-900/40 text-amber-400',
    terminated: 'bg-red-900/40 text-red-400',
    unknown:    'bg-gray-800 text-gray-400',
};
</script>
