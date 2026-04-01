<template>
    <button
        @click="handleClick"
        :disabled="busy"
        class="rounded px-2 py-1 text-xs font-medium transition-colors disabled:opacity-40"
        :class="colorClasses[color] ?? colorClasses.gray"
        :title="`${label} ${service}`"
    >
        <span v-if="busy">…</span>
        <span v-else>{{ label }}</span>
    </button>

    <!-- Confirm modal -->
    <Teleport to="body">
        <div
            v-if="showConfirm"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
            @click.self="showConfirm = false"
        >
            <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                <h3 class="text-base font-semibold text-gray-100 mb-2">Confirm {{ label }}</h3>
                <p class="text-sm text-gray-400 mb-5">
                    Are you sure you want to <strong class="text-gray-200">{{ action }}</strong>
                    <strong class="text-gray-200 font-mono"> {{ service }}</strong>?
                </p>
                <div class="flex justify-end gap-3">
                    <button
                        @click="showConfirm = false"
                        class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800 transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="execute"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors"
                        :class="colorClasses[color]"
                    >
                        Yes, {{ label }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
    label:   String,
    action:  String,
    service: String,
    nodeId:  [String, Number],
    color:   { type: String, default: 'gray' },
    confirm: { type: Boolean, default: false },
});

const emit = defineEmits(['done']);

const busy        = ref(false);
const showConfirm = ref(false);

const colorClasses = {
    emerald: 'bg-emerald-700/30 text-emerald-400 hover:bg-emerald-700/50',
    amber:   'bg-amber-700/30 text-amber-400 hover:bg-amber-700/50',
    red:     'bg-red-700/30 text-red-400 hover:bg-red-700/50',
    indigo:  'bg-indigo-700/30 text-indigo-400 hover:bg-indigo-700/50',
    gray:    'bg-gray-700/40 text-gray-400 hover:bg-gray-700/60',
};

function handleClick() {
    if (props.confirm) {
        showConfirm.value = true;
    } else {
        execute();
    }
}

async function execute() {
    showConfirm.value = false;
    busy.value = true;
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        await fetch(
            route('admin.nodes.api.service-action', {
                node: props.nodeId,
                service: props.service,
            }),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ action: props.action }),
            }
        );
        emit('done');
    } finally {
        busy.value = false;
    }
}
</script>
