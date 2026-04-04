<template>
    <AppLayout title="Nodes">
        <div class="mb-5 flex items-center justify-between">
            <p class="text-sm text-gray-400">{{ nodes.length }} node{{ nodes.length !== 1 ? 's' : '' }} registered</p>
            <Link
                :href="route('admin.nodes.create')"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
            >
                Add Node
            </Link>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <table class="min-w-full divide-y divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Name</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Hostname</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Agent</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Last Seen</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="node in nodes" :key="node.id" class="hover:bg-gray-800/40 transition-colors">
                        <td class="px-5 py-3.5 text-sm font-medium text-gray-100">
                            {{ node.name }}
                            <span v-if="node.is_primary" class="ml-1.5 rounded-full bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-400">Primary</span>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-gray-400 font-mono">{{ node.hostname }}</td>
                        <td class="px-5 py-3.5 text-sm">
                            <NodeStatusBadge :status="node.status" />
                        </td>
                        <td class="px-5 py-3.5 text-sm text-gray-400 font-mono">{{ node.agent_version ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-sm text-gray-400">{{ node.last_seen_at ?? 'Never' }}</td>
                        <td class="px-5 py-3.5 text-right">
                            <Link
                                :href="route('admin.nodes.shell', node.id)"
                                class="mr-3 text-xs text-gray-400 hover:text-gray-200 transition-colors font-mono"
                            >
                                Shell
                            </Link>
                            <Link
                                :href="route('admin.nodes.status', node.id)"
                                class="mr-3 text-xs text-indigo-400 hover:text-indigo-300 transition-colors"
                            >
                                Status
                            </Link>
                            <Link
                                :href="route('admin.nodes.show', node.id)"
                                class="text-xs text-gray-500 hover:text-gray-300 transition-colors"
                            >
                                Details
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="nodes.length === 0">
                        <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">
                            No nodes yet.
                            <Link :href="route('admin.nodes.create')" class="text-indigo-400 hover:underline ml-1">Add one</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import NodeStatusBadge from '@/Components/NodeStatusBadge.vue';
import { Link } from '@inertiajs/vue3';

defineProps({ nodes: Array });
</script>
