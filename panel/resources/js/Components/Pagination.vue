<template>
    <div v-if="links && links.length > 3" class="flex items-center justify-center gap-1">
        <template v-for="link in links" :key="link.label">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                class="rounded px-3 py-1.5 text-xs transition-colors"
                :class="link.active
                    ? 'bg-indigo-600 text-white'
                    : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200'"
                v-html="link.label"
            />
            <span
                v-else
                class="rounded px-3 py-1.5 text-xs text-gray-600"
                v-html="link.label"
            />
        </template>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({ links: Array });
</script>
