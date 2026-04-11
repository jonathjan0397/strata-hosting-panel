<template>
    <AppLayout title="Nodes">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Platform"
                title="Nodes"
                description="Manage the servers that run agent services, account provisioning, mail, DNS, backups, and web traffic."
            >
                <template #actions>
                    <Link :href="route('admin.nodes.create')" class="btn-primary">Add Node</Link>
                </template>
            </PageHeader>

            <div class="grid gap-4 md:grid-cols-3">
                <StatCard label="Registered Nodes" :value="nodes.length" color="indigo" />
                <StatCard label="Online" :value="onlineCount" color="emerald" />
                <StatCard label="Primary Nodes" :value="primaryCount" color="gray" />
                <StatCard label="DNS Nodes" :value="dnsNodeCount" color="cyan" />
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Name</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Hostname</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Agent</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Last Seen</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="node in nodes" :key="node.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 text-sm font-medium text-gray-100">
                                {{ node.name }}
                                <span v-if="node.is_primary" class="ml-1.5 rounded-full bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-400">Primary</span>
                                <span v-if="node.hosts_dns" class="ml-1.5 rounded-full bg-cyan-900/40 px-2 py-0.5 text-xs text-cyan-300">DNS</span>
                            </td>
                            <td class="px-5 py-3.5 text-sm font-mono text-gray-400">{{ node.hostname }}</td>
                            <td class="px-5 py-3.5 text-sm">
                                <NodeStatusBadge :status="node.status" />
                            </td>
                            <td class="px-5 py-3.5 text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono" :class="agentVersionClass(node)">{{ node.agent_version ?? '-' }}</span>
                                    <span
                                        v-if="nodeVersionState(node).showWarning"
                                        class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-amber-500/15 text-xs font-bold text-amber-300"
                                        :title="nodeVersionState(node).label"
                                    >!</span>
                                    <span
                                        v-if="nodeVersionState(node).upgrading"
                                        class="rounded-full bg-sky-500/15 px-2 py-0.5 text-xs text-sky-300"
                                    >Upgrade in progress</span>
                                </div>
                                <div v-if="nodeVersionState(node).message" class="mt-1 text-xs text-gray-500">
                                    {{ nodeVersionState(node).message }}
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ node.last_seen_at ?? 'Never' }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <button
                                    v-if="nodeVersionState(node).canPushUpdate"
                                    type="button"
                                    class="mr-3 text-xs text-amber-300 transition-colors hover:text-amber-200"
                                    @click="pushAgentUpdate(node)"
                                >
                                    Push Update
                                </button>
                                <Link
                                    v-if="browserShellAvailable"
                                    :href="route('admin.nodes.shell', node.id)"
                                    class="mr-3 font-mono text-xs text-gray-400 transition-colors hover:text-gray-200"
                                >
                                    Shell
                                </Link>
                                <Link
                                    :href="route('admin.nodes.status', node.id)"
                                    class="mr-3 text-xs text-indigo-400 transition-colors hover:text-indigo-300"
                                >
                                    Status
                                </Link>
                                <Link
                                    :href="route('admin.nodes.show', node.id)"
                                    class="text-xs text-gray-500 transition-colors hover:text-gray-300"
                                >
                                    Details
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="nodes.length === 0">
                            <td colspan="6" class="px-5 py-8">
                                <EmptyState
                                    title="No nodes registered"
                                    description="Add a node before provisioning hosting accounts."
                                >
                                    <template #actions>
                                        <Link :href="route('admin.nodes.create')" class="btn-primary">Add Node</Link>
                                    </template>
                                </EmptyState>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import NodeStatusBadge from '@/Components/NodeStatusBadge.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({ nodes: Array, panelVersion: String });
const page = usePage();

const onlineCount = computed(() => props.nodes.filter((node) => node.status === 'online').length);
const primaryCount = computed(() => props.nodes.filter((node) => node.is_primary).length);
const dnsNodeCount = computed(() => props.nodes.filter((node) => node.hosts_dns).length);
const browserShellAvailable = computed(() => !!page.props.app?.browser_shell_available);

function normalizedVersion(version) {
    const value = (version || '').trim();
    if (!value || value.toLowerCase() === 'dev') return '';
    return value;
}

function nodeVersionState(node) {
    const panelVersion = normalizedVersion(props.panelVersion);
    const agentVersion = normalizedVersion(node.agent_version);
    const upgrading = node.status === 'upgrading';
    const mismatch = !!panelVersion && !!agentVersion && agentVersion !== panelVersion;
    const unknown = !agentVersion;

    return {
        upgrading,
        mismatch,
        unknown,
        showWarning: !upgrading && (mismatch || unknown),
        canPushUpdate: !node.is_primary && node.status === 'online' && !upgrading && (!!panelVersion && agentVersion !== panelVersion),
        label: upgrading ? 'Upgrade in progress' : mismatch ? 'Agent version differs from the primary panel version.' : 'Agent version is unknown.',
        message: upgrading
            ? `Targeting ${panelVersion || 'the current panel release'}.`
            : mismatch
                ? `Expected ${panelVersion}, found ${agentVersion}.`
                : unknown
                    ? 'Agent did not report a release version.'
                    : '',
    };
}

function agentVersionClass(node) {
    const state = nodeVersionState(node);
    if (state.upgrading) return 'text-sky-300';
    if (state.mismatch || state.unknown) return 'text-amber-300';
    return 'text-gray-400';
}

function pushAgentUpdate(node) {
    if (!confirm(`Push the current panel agent version to ${node.name} now?`)) return;

    router.post(route('admin.nodes.agent-upgrade', node.id), {
        source_type: 'version',
        source_value: props.panelVersion,
    }, {
        preserveScroll: true,
    });
}
</script>
