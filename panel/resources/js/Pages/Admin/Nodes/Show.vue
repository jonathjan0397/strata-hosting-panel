<template>
    <AppLayout :title="`${node.name} — Details`">
        <div class="max-w-2xl">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('admin.nodes.index')" class="text-gray-500 hover:text-gray-300 transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                        </svg>
                    </Link>
                    <h2 class="text-lg font-semibold text-gray-100">{{ node.name }}</h2>
                    <NodeStatusBadge :status="node.status" />
                    <span v-if="node.is_primary" class="rounded-full bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-400">Primary</span>
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="route('admin.nodes.shell', node.id)"
                        class="flex items-center gap-1.5 rounded-lg border border-gray-700 bg-gray-900 px-3 py-1.5 text-sm font-medium text-gray-300 hover:bg-gray-800 transition-colors"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6.75 7.5 3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0 0 21 18V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v12a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        Shell
                    </Link>
                    <Link
                        :href="route('admin.nodes.status', node.id)"
                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
                    >
                        Live Status
                    </Link>
                </div>
            </div>

            <!-- Health check result -->
            <div v-if="health" class="mb-5 flex items-center gap-2 rounded-xl border border-emerald-700/50 bg-emerald-900/20 px-4 py-3 text-sm text-emerald-400">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                Agent reachable — {{ health.status }} · {{ health.time }}
            </div>
            <div v-else class="mb-5 flex items-center gap-2 rounded-xl border border-red-700/50 bg-red-900/20 px-4 py-3 text-sm text-red-400">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                Agent unreachable. Check that strata-agent is running on this node.
            </div>

            <!-- Node details -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 divide-y divide-gray-800 mb-5">
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Hostname</span>
                    <span class="col-span-2 font-mono text-gray-200">{{ node.hostname }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">IP Address</span>
                    <span class="col-span-2 font-mono text-gray-200">{{ node.ip_address }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Agent Port</span>
                    <span class="col-span-2 font-mono text-gray-200">{{ node.port }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Node ID</span>
                    <span class="col-span-2 font-mono text-xs text-gray-400">{{ node.node_id }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Agent Version</span>
                    <span class="col-span-2 text-gray-200">{{ node.agent_version ?? '—' }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Web Server</span>
                    <span class="col-span-2 font-mono text-gray-200 capitalize">{{ node.web_server ?? 'nginx' }}</span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Accelerators</span>
                    <span class="col-span-2 text-gray-200">
                        <template v-if="node.accelerators?.length">
                            <span v-for="acc in node.accelerators" :key="acc"
                                class="mr-1.5 inline-flex rounded-full bg-gray-800 px-2 py-0.5 text-xs font-mono text-emerald-400">
                                {{ acc }}
                            </span>
                        </template>
                        <span v-else class="text-gray-500">None</span>
                    </span>
                </div>
                <div class="grid grid-cols-3 px-5 py-3.5 text-sm">
                    <span class="text-gray-500">Last Seen</span>
                    <span class="col-span-2 text-gray-200">{{ node.last_seen_at ?? 'Never' }}</span>
                </div>
            </div>

            <!-- Agent install instructions -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5 mb-5">
                <h3 class="text-sm font-semibold text-gray-200 mb-3">Agent Install Command</h3>
                <p class="text-xs text-gray-400 mb-3">Run this on the target server as root:</p>
                <pre class="rounded-lg bg-gray-950 px-4 py-3 text-xs font-mono text-emerald-400 overflow-x-auto whitespace-pre-wrap break-all">{{ installCommand }}</pre>
            </div>

            <!-- Danger zone -->
            <div v-if="!node.is_primary" class="rounded-xl border border-red-900/50 bg-red-950/20 p-5">
                <h3 class="text-sm font-semibold text-red-400 mb-3">Remove Node</h3>
                <p class="text-xs text-gray-400 mb-4">Removes this node from the panel. Does not uninstall the agent.</p>
                <ConfirmButton
                    :href="route('admin.nodes.destroy', node.id)"
                    method="delete"
                    label="Remove Node"
                    :confirm-message="`Remove node ${node.name}? This cannot be undone.`"
                    color="red"
                />
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NodeStatusBadge from '@/Components/NodeStatusBadge.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({
    node:   Object,
    health: Object,
});

const installCommand = computed(() => {
    return `bash <(curl -fsSL https://raw.githubusercontent.com/jonathjan0397/strata-panel/main/installer/agent.sh) \\
  --node-id "${props.node.node_id}" \\
  --hmac-secret "<shown once — check your panel DB>" \\
  --port ${props.node.port}`;
});
</script>
