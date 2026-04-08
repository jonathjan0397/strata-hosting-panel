<template>
    <AppLayout title="Mail Queue">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Mail"
                title="Mail Queue"
                description="Inspect Postfix queues, flush deferred delivery, and remove stuck messages from online nodes."
            />

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-100">Node Queue</h2>
                        <p class="mt-1 text-sm text-gray-400">Queue controls operate directly on the selected node.</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <select v-model="nodeId" class="field min-w-64" @change="changeNode">
                            <option v-for="node in nodes" :key="node.id" :value="node.id">
                                {{ node.name }}{{ node.is_primary ? ' (primary)' : '' }}
                            </option>
                        </select>
                        <Link :href="route('admin.mail-queue.index', { node_id: nodeId })" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-200 transition-colors hover:bg-gray-800">
                            Refresh
                        </Link>
                    </div>
                </div>
            </div>

            <div v-if="error" class="rounded-xl border border-red-800 bg-red-950/40 px-5 py-4 text-sm text-red-200">
                {{ error }}
            </div>

            <div v-if="!nodes.length" class="rounded-xl border border-gray-800 bg-gray-900 p-8 text-center text-sm text-gray-400">
                No online nodes are available for mail queue inspection.
            </div>

            <div v-if="queue" class="grid gap-5 lg:grid-cols-3">
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Queued Messages</p>
                    <p class="mt-3 text-4xl font-semibold text-gray-100">{{ queue.count ?? 0 }}</p>
                    <p class="mt-2 text-sm text-gray-400">Parsed from <span class="font-mono">postqueue -p</span>.</p>
                </div>

                <form @submit.prevent="flushQueue" class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="text-sm font-semibold text-gray-100">Flush Queue</h3>
                    <p class="mt-2 text-sm leading-6 text-gray-400">Ask Postfix to immediately retry deferred delivery.</p>
                    <button type="submit" :disabled="flushForm.processing" class="btn-primary mt-5">
                        {{ flushForm.processing ? 'Flushing...' : 'Flush Mail Queue' }}
                    </button>
                </form>

                <form @submit.prevent="purgeQueue" class="rounded-xl border border-red-900 bg-red-950/30 p-5">
                    <h3 class="text-sm font-semibold text-red-100">Delete All Queued Mail</h3>
                    <p class="mt-2 text-sm leading-6 text-red-100/80">Permanent. Type <span class="font-mono">DELETE</span> before purging the selected node queue.</p>
                    <input v-model="purgeForm.confirm" type="text" class="field mt-4 w-full" placeholder="DELETE" />
                    <button type="submit" :disabled="purgeForm.processing || purgeForm.confirm !== 'DELETE'" class="mt-4 rounded-lg border border-red-700 px-4 py-2 text-sm font-semibold text-red-200 transition-colors hover:bg-red-900/30 disabled:opacity-50">
                        {{ purgeForm.processing ? 'Deleting...' : 'Delete All' }}
                    </button>
                </form>
            </div>

            <div v-if="queue" class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 px-5 py-4">
                    <h3 class="text-sm font-semibold text-gray-200">Queued Messages</h3>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-3">Queue ID</th>
                            <th class="px-5 py-3">Size</th>
                            <th class="px-5 py-3">Sender</th>
                            <th class="px-5 py-3">Summary</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="entry in queue.entries" :key="entry.id">
                            <td class="px-5 py-3.5 font-mono text-xs text-gray-200">{{ entry.id }}</td>
                            <td class="px-5 py-3.5 font-mono text-xs text-gray-400">{{ formatBytes(entry.size) }}</td>
                            <td class="px-5 py-3.5 font-mono text-xs text-gray-400">{{ entry.sender || '-' }}</td>
                            <td class="px-5 py-3.5 text-xs text-gray-400">{{ entry.summary }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <button type="button" class="text-xs font-semibold text-red-400 hover:text-red-300" @click="deleteMessage(entry.id)">
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!queue.entries?.length">
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-500">The selected node mail queue is empty.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="queue?.raw" class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-200">Raw Postfix Output</h3>
                <pre class="mt-4 max-h-96 overflow-auto rounded-lg border border-gray-800 bg-gray-950 p-4 text-xs leading-6 text-gray-300">{{ queue.raw }}</pre>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    nodes: { type: Array, default: () => [] },
    selectedNodeId: { type: Number, default: null },
    queue: { type: Object, default: null },
    error: { type: String, default: null },
});

const nodeId = ref(props.selectedNodeId ?? props.nodes[0]?.id ?? '');
const flushForm = useForm({ node_id: nodeId.value });
const purgeForm = useForm({ node_id: nodeId.value, confirm: '' });

function changeNode() {
    router.get(route('admin.mail-queue.index'), { node_id: nodeId.value });
}

function flushQueue() {
    flushForm.node_id = nodeId.value;
    flushForm.post(route('admin.mail-queue.flush'), { preserveScroll: true });
}

function purgeQueue() {
    if (purgeForm.confirm !== 'DELETE') return;
    if (!confirm('Delete every queued message on this node? This cannot be undone.')) return;
    purgeForm.node_id = nodeId.value;
    purgeForm.delete(route('admin.mail-queue.delete-all'), {
        preserveScroll: true,
        onSuccess: () => {
            purgeForm.confirm = '';
        },
    });
}

function deleteMessage(queueId) {
    if (!confirm(`Delete queued message ${queueId}?`)) return;
    router.delete(route('admin.mail-queue.delete', queueId), {
        data: { node_id: nodeId.value },
        preserveScroll: true,
    });
}

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = Number(bytes);
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }
    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}
</script>
