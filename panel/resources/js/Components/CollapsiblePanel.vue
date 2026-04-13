<template>
    <section class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
        <button
            type="button"
            class="flex w-full items-start justify-between gap-4 px-4 py-3 text-left transition-colors hover:bg-gray-800/50"
            @click="isOpen = !isOpen"
        >
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-semibold text-gray-100">{{ title }}</h3>
                    <slot name="badge" />
                </div>
                <p v-if="description" class="mt-1 text-xs text-gray-500">{{ description }}</p>
            </div>
            <span class="mt-0.5 text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">
                {{ isOpen ? 'Hide' : 'Show' }}
            </span>
        </button>

        <div v-show="isOpen" class="border-t border-gray-800">
            <div :class="contentClass">
                <slot />
            </div>
        </div>
    </section>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    defaultOpen: {
        type: Boolean,
        default: true,
    },
    contentClass: {
        type: String,
        default: 'p-4',
    },
});

const isOpen = ref(props.defaultOpen);
</script>
