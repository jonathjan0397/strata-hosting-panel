<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
        :class="classes[status] ?? classes.unknown"
    >
        <span class="h-1.5 w-1.5 rounded-full" :class="dotClasses[status] ?? dotClasses.unknown"></span>
        {{ status }}
    </span>
</template>

<script setup>
defineProps({ status: String });

const classes = {
    online:    'bg-emerald-900/40 text-emerald-400',
    offline:   'bg-red-900/40 text-red-400',
    upgrading: 'bg-amber-900/40 text-amber-400',
    unknown:   'bg-gray-800 text-gray-400',
};
const dotClasses = {
    online:    'bg-emerald-400',
    offline:   'bg-red-400',
    upgrading: 'bg-amber-400',
    unknown:   'bg-gray-500',
};
</script>
