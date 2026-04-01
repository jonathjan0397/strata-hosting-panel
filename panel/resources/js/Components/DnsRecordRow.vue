<template>
    <div class="rounded-lg bg-gray-900/60 border border-gray-800 p-3">
        <div class="flex items-center gap-2 mb-1.5">
            <span class="rounded px-1.5 py-0.5 text-xs font-mono font-semibold bg-indigo-900/50 text-indigo-300">{{ type }}</span>
            <span class="text-xs font-semibold text-gray-300">{{ label }}</span>
            <span class="text-xs font-mono text-gray-500">{{ host }}</span>
            <span v-if="extra" class="text-xs text-gray-600">· {{ extra }}</span>
            <button
                @click="copyValue"
                class="ml-auto text-gray-600 hover:text-gray-300 transition-colors"
                :title="`Copy ${label} record`"
            >
                <svg v-if="!copied" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                </svg>
                <svg v-else class="h-3.5 w-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </button>
        </div>
        <p class="text-xs font-mono text-gray-400 break-all leading-relaxed">{{ value }}</p>
    </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
    label: String,
    type:  String,
    host:  String,
    value: String,
    extra: String,
});

const copied = ref(false);

function copyValue() {
    navigator.clipboard.writeText(props.value ?? '').then(() => {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    });
}
</script>
