<template>
    <AppLayout title="Spam Overview">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Email"
                title="Spam Overview"
                description="Review Rspamd activity for the node handling this hosting account."
            >
                <template #actions>
                    <button @click="loadStats" :disabled="loading" class="btn-primary">
                        {{ loading ? 'Loading...' : 'Refresh Stats' }}
                    </button>
                    <Link :href="route('my.metrics.index')" class="text-sm font-medium text-indigo-400 transition-colors hover:text-indigo-300">
                        Open Metrics
                    </Link>
                </template>
            </PageHeader>

            <div v-if="account.node" class="rounded-xl border border-gray-800 bg-gray-900 px-4 py-3 text-sm text-gray-400">
                Mail node: <span class="font-mono text-gray-200">{{ account.node.name }}</span>
            </div>

            <div v-if="error" class="rounded-lg border border-red-800 bg-red-900/30 px-4 py-3 text-sm text-red-400">{{ error }}</div>

            <div v-if="stats" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-3">
                    <StatCard label="Messages Scanned" :value="stats.scanned ?? 0" color="indigo" />
                    <StatCard label="Spam" :value="stats.spam_count ?? 0" color="red" />
                    <StatCard label="Ham" :value="stats.ham_count ?? 0" color="emerald" />
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

            <EmptyState
                v-else-if="!loading && !error"
                title="No spam statistics loaded"
                description="Refresh stats to retrieve current Rspamd activity from the account mail node."
            />
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
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
