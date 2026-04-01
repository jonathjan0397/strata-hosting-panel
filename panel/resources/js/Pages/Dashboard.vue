<template>
    <AppLayout title="Dashboard">
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                v-for="stat in stats"
                :key="stat.label"
                :label="stat.label"
                :value="stat.value"
                :color="stat.color"
            />
        </div>

        <!-- License status (only shown when managed / license server configured) -->
        <div v-if="license.managed" class="mt-5">
            <div
                class="rounded-xl border px-5 py-3.5 flex items-center justify-between"
                :class="{
                    'border-emerald-800/50 bg-emerald-900/10': license.status === 'active',
                    'border-red-800/50 bg-red-900/10':         license.status === 'suspended',
                    'border-gray-700/50 bg-gray-800/30':       !['active','suspended'].includes(license.status),
                }"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="h-2.5 w-2.5 rounded-full"
                        :class="{
                            'bg-emerald-400': license.status === 'active',
                            'bg-red-400':     license.status === 'suspended',
                            'bg-gray-500':    !['active','suspended'].includes(license.status),
                        }"
                    ></span>
                    <span class="text-sm font-medium"
                        :class="{
                            'text-emerald-300': license.status === 'active',
                            'text-red-300':     license.status === 'suspended',
                            'text-gray-400':    !['active','suspended'].includes(license.status),
                        }"
                    >
                        Strata Panel — {{ license.status === 'active' ? 'Licensed' : license.status }}
                    </span>
                    <div v-if="license.features.length" class="flex items-center gap-1.5 ml-2">
                        <span
                            v-for="feat in license.features"
                            :key="feat"
                            class="rounded-full bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-300 font-mono"
                        >{{ feat }}</span>
                    </div>
                </div>
                <span v-if="license.synced_at" class="text-xs text-gray-600">
                    Synced {{ formatDate(license.synced_at) }}
                </span>
            </div>
        </div>

        <!-- Nodes status -->
        <div class="mt-8">
            <h2 class="mb-4 text-sm font-semibold text-gray-400 uppercase tracking-wide">Nodes</h2>
            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Name</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Hostname</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Status</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Agent</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Last seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="node in nodes" :key="node.id" class="hover:bg-gray-800/50 transition-colors">
                            <td class="px-5 py-3.5 text-sm font-medium text-gray-100">
                                {{ node.name }}
                                <span v-if="node.is_primary" class="ml-2 rounded-full bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-400">Primary</span>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-400 font-mono">{{ node.hostname }}</td>
                            <td class="px-5 py-3.5 text-sm">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="{
                                        'bg-emerald-900/40 text-emerald-400': node.status === 'online',
                                        'bg-red-900/40 text-red-400': node.status === 'offline',
                                        'bg-amber-900/40 text-amber-400': node.status === 'upgrading',
                                        'bg-gray-800 text-gray-400': node.status === 'unknown',
                                    }"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full"
                                        :class="{
                                            'bg-emerald-400': node.status === 'online',
                                            'bg-red-400': node.status === 'offline',
                                            'bg-amber-400': node.status === 'upgrading',
                                            'bg-gray-500': node.status === 'unknown',
                                        }"
                                    ></span>
                                    {{ node.status }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-400 font-mono">{{ node.agent_version ?? '—' }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ node.last_seen_at ?? 'Never' }}</td>
                        </tr>
                        <tr v-if="nodes.length === 0">
                            <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500">
                                No nodes configured yet.
                                <Link :href="route('admin.nodes.create')" class="text-indigo-400 hover:underline ml-1">Add a node</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    nodes: Array,
    stats: Array,
});

const license = usePage().props.license;

function formatDate(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>
