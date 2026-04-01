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
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    nodes: Array,
    stats: Array,
});
</script>
