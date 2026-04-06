<template>
    <AppLayout title="Spam Overview">
        <div class="mb-6">
            <h2 class="text-lg font-semibold text-gray-100">Spam Overview</h2>
            <p class="mt-1 text-sm text-gray-400">
                Rspamd activity for the node handling this hosting account.
                <span v-if="account.node" class="font-mono text-gray-500">{{ account.node.name }}</span>
            </p>
        </div>

        <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
            <div class="mb-4 flex items-center gap-3">
                <button @click="loadStats" :disabled="loading" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500 disabled:opacity-50">
                    {{ loading ? 'Loading...' : 'Refresh Stats' }}
                </button>
                <Link :href="route('my.metrics.index')" class="text-sm text-gray-400 transition-colors hover:text-gray-200">
                    Open Metrics
                </Link>
            </div>

            <div v-if="error" class="rounded-lg border border-red-800 bg-red-900/30 px-4 py-3 text-sm text-red-400">{{ error }}</div>

            <div v-if="stats" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="rounded-lg bg-gray-800 p-4 text-center">
                        <p class="text-2xl font-bold text-gray-100">{{ stats.scanned ?? 0 }}</p>
                        <p class="mt-1 text-xs text-gray-400">Messages Scanned</p>
                    </div>
                    <div class="rounded-lg bg-gray-800 p-4 text-center">
                        <p class="text-2xl font-bold text-red-400">{{ stats.spam_count ?? 0 }}</p>
                        <p class="mt-1 text-xs text-gray-400">Spam</p>
                    </div>
                    <div class="rounded-lg bg-gray-800 p-4 text-center">
                        <p class="text-2xl font-bold text-emerald-400">{{ stats.ham_count ?? 0 }}</p>
                        <p class="mt-1 text-xs text-gray-400">Ham</p>
                    </div>
                </div>

                <div v-if="stats.actions" class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                    <div class="border-b border-gray-800 px-4 py-3 text-sm font-semibold text-gray-200">Rspamd Actions</div>
                    <div class="divide-y divide-gray-800">
                        <div v-for="(count, action) in stats.actions" :key="action" class="flex justify-between px-4 py-2.5 text-sm">
                            <span class="capitalize text-gray-300">{{ action.replace(/_/g, ' ') }}</span>
                            <span class="font-mono text-gray-400">{{ count }}</span>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-950/50 p-4 text-sm text-gray-400">
                    <p class="font-medium text-gray-200">Current scope</p>
                    <p class="mt-2">
                        This view surfaces live spam-filter activity for your account's mail node. Per-mailbox spam policy controls are not wired yet in the agent, so this is visibility first.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

defineProps({
    account: Object,
});

const loading = ref(false);
const stats = ref(null);
const error = ref('');

async function loadStats() {
    loading.value = true;
    error.value = '';
    try {
        const res = await fetch(route('my.email.spam.stats'), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error((await res.json()).error ?? 'Failed to load spam stats.');
        stats.value = await res.json();
    } catch (e) {
        error.value = e.message;
        stats.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(loadStats);
</script>
