<template>
    <div class="mb-6 overflow-hidden rounded-2xl border border-gray-800 bg-gray-900">
        <div class="relative p-6">
            <div class="absolute inset-0 bg-gradient-to-br from-indigo-500/10 via-sky-500/5 to-transparent"></div>
            <div class="relative flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p v-if="eyebrow" class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-400">{{ eyebrow }}</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-100">{{ title }}</h1>
                    <p v-if="description" class="mt-2 max-w-3xl text-sm leading-6 text-gray-400">{{ description }}</p>
                </div>
                <div v-if="$slots.actions" class="flex flex-wrap items-center gap-3">
                    <slot name="actions" />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    eyebrow: { type: String, default: '' },
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>
