<template>
    <Link
        :href="href"
        class="group block rounded-2xl border border-gray-800 bg-gray-900 p-5 transition hover:-translate-y-0.5 hover:border-indigo-500/60 hover:bg-gray-800/70 hover:shadow-2xl"
    >
        <div class="flex items-start gap-4">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-600/15 text-indigo-300 ring-1 ring-indigo-500/30">
                <slot name="icon">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </slot>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-100">{{ title }}</p>
                <p v-if="description" class="mt-1 text-sm leading-5 text-gray-500">{{ description }}</p>
                <p class="mt-3 text-xs font-semibold text-indigo-400 group-hover:text-indigo-300">{{ cta }}</p>
            </div>
        </div>
    </Link>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    cta: { type: String, default: 'Open' },
});
</script>
