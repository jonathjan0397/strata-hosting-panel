<template>
    <button @click="showModal = true" :class="btnClass">{{ label }}</button>

    <Teleport to="body">
        <div
            v-if="showModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
            @click.self="showModal = false"
        >
            <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-gray-100 mb-2">Confirm</h3>
                <p class="text-sm text-gray-400 mb-5">{{ confirmMessage || `Are you sure you want to ${label.toLowerCase()}?` }}</p>
                <div class="flex justify-end gap-3">
                    <button
                        @click="showModal = false"
                        class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800 transition-colors"
                    >
                        Cancel
                    </button>
                    <Link
                        :href="href"
                        :method="method"
                        as="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors"
                        :class="confirmBtnClass"
                        @click="showModal = false"
                    >
                        {{ label }}
                    </Link>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href:           String,
    method:         { type: String, default: 'post' },
    label:          String,
    confirmMessage: String,
    color:          { type: String, default: 'red' },
});

const showModal = ref(false);

const colorMap = {
    red:     { btn: 'bg-red-700/30 text-red-400 hover:bg-red-700/50', confirm: 'bg-red-600 hover:bg-red-500' },
    amber:   { btn: 'bg-amber-700/30 text-amber-400 hover:bg-amber-700/50', confirm: 'bg-amber-600 hover:bg-amber-500' },
    emerald: { btn: 'bg-emerald-700/30 text-emerald-400 hover:bg-emerald-700/50', confirm: 'bg-emerald-600 hover:bg-emerald-500' },
    indigo:  { btn: 'bg-indigo-700/30 text-indigo-400 hover:bg-indigo-700/50', confirm: 'bg-indigo-600 hover:bg-indigo-500' },
};

const btnClass        = computed(() => `rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${colorMap[props.color]?.btn ?? colorMap.red.btn}`);
const confirmBtnClass = computed(() => colorMap[props.color]?.confirm ?? colorMap.red.confirm);
</script>
