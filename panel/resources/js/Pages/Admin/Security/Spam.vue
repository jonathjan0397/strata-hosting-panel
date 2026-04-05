<template>
    <AppLayout title="Spam Filter (Rspamd)">
        <div class="mb-6">
            <h2 class="text-lg font-semibold text-gray-100">Spam Filter</h2>
            <p class="text-sm text-gray-400 mt-1">Rspamd statistics per node.</p>
        </div>

        <div class="rounded-xl border border-gray-800 bg-gray-900 p-5 mb-6">
            <div class="flex items-center gap-3 mb-4">
                <select v-model="selectedNode" class="field w-64">
                    <option value="">Select node…</option>
                    <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }}</option>
                </select>
                <button @click="loadStats" :disabled="!selectedNode || loading" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors">
                    {{ loading ? 'Loading…' : 'Check Stats' }}
                </button>
            </div>

            <div v-if="error" class="rounded-lg bg-red-900/30 border border-red-800 px-4 py-3 text-sm text-red-400">{{ error }}</div>

            <div v-if="stats" class="space-y-4">
                <div class="grid grid-cols-3 gap-4">
                    <div class="rounded-lg bg-gray-800 p-4 text-center">
                        <p class="text-2xl font-bold text-gray-100">{{ stats.scanned ?? 0 }}</p>
                        <p class="text-xs text-gray-400 mt-1">Messages Scanned</p>
                    </div>
                    <div class="rounded-lg bg-gray-800 p-4 text-center">
                        <p class="text-2xl font-bold text-red-400">{{ stats.spam_count ?? 0 }}</p>
                        <p class="text-xs text-gray-400 mt-1">Spam</p>
                    </div>
                    <div class="rounded-lg bg-gray-800 p-4 text-center">
                        <p class="text-2xl font-bold text-emerald-400">{{ stats.ham_count ?? 0 }}</p>
                        <p class="text-xs text-gray-400 mt-1">Ham</p>
                    </div>
                </div>

                <div v-if="stats.actions" class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                    <div class="px-4 py-3 border-b border-gray-800 text-sm font-semibold text-gray-200">Actions</div>
                    <div class="divide-y divide-gray-800">
                        <div v-for="(count, action) in stats.actions" :key="action" class="flex justify-between px-4 py-2.5 text-sm">
                            <span class="text-gray-300 capitalize">{{ action.replace(/_/g, ' ') }}</span>
                            <span class="font-mono text-gray-400">{{ count }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ nodes: Array });

const selectedNode = ref('');
const loading = ref(false);
const stats = ref(null);
const error = ref('');

async function loadStats() {
    loading.value = true;
    error.value = '';
    stats.value = null;
    try {
        const res = await fetch(route('admin.security.spam.stats') + `?node_id=${selectedNode.value}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error(await res.text());
        stats.value = await res.json();
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none; }
</style>
