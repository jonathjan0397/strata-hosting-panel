<template>
    <Link
        :href="href"
        class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
        :class="active
            ? 'bg-indigo-600/20 text-indigo-400'
            : 'text-gray-400 hover:bg-gray-800 hover:text-gray-100'"
    >
        <span class="shrink-0" :class="active ? 'text-indigo-400' : 'text-gray-500 group-hover:text-gray-300'">
            <slot name="icon" />
        </span>
        <slot />
    </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: String,
    active: Boolean,
});
</script>
