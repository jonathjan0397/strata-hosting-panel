<template>
    <AppLayout title="Dashboard">
        <PageHeader
            eyebrow="Admin Workspace"
            title="Operate the hosting platform"
            description="Monitor node health, manage accounts and packages, review backups, and jump into common WHM-style operations."
        >
            <template #actions>
                <Link :href="route('admin.accounts.create')" class="btn-primary">
                    Create Account
                </Link>
                <Link :href="route('admin.nodes.create')" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">
                    Add Node
                </Link>
            </template>
        </PageHeader>

        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                v-for="stat in stats"
                :key="stat.label"
                :label="stat.label"
                :value="stat.value"
                :color="stat.color"
            />
        </div>

        <div class="mt-6">
            <div class="mb-3">
                <h2 class="text-sm font-semibold text-gray-300">Admin Shortcuts</h2>
                <p class="mt-1 text-xs text-gray-500">Common operational paths for account, infrastructure, security, and backup work.</p>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <ActionCard
                    :href="route('admin.accounts.index')"
                    title="Accounts"
                    description="Create, suspend, deprovision, and inspect hosted accounts."
                    cta="Manage accounts"
                />
                <ActionCard
                    :href="route('admin.packages.index')"
                    title="Packages"
                    description="Maintain package defaults and feature-list assignments."
                    cta="Manage packages"
                />
                <ActionCard
                    :href="route('admin.security.firewall')"
                    title="Security"
                    description="Review firewall rules, block IPs, and inspect fail2ban state."
                    cta="Open security"
                />
                <ActionCard
                    :href="route('admin.backups.index')"
                    title="Backups"
                    description="Check backup jobs, restores, schedules, and remote destinations."
                    cta="Open backups"
                />
            </div>
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
                        Strata Hosting Panel — {{ license.status === 'active' ? 'Licensed' : license.status }}
                    </span>
                    <div v-if="license.features.length" class="flex items-center gap-1.5 ml-2">
                        <span
                            v-for="feat in license.features"
                            :key="feat"
                            class="rounded-full bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-300 font-mono"
                        >{{ feat }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span v-if="license.synced_at" class="text-xs text-gray-600">
                        Synced {{ formatDate(license.synced_at) }}
                    </span>
                    <button
                        @click="forceSync"
                        :disabled="syncing"
                        class="text-xs text-gray-500 hover:text-gray-300 disabled:opacity-40 flex items-center gap-1 transition-colors"
                        title="Force license sync now"
                    >
                        <svg :class="['h-3.5 w-3.5', syncing ? 'animate-spin' : '']" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        {{ syncing ? 'Syncing…' : 'Sync now' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Nodes status -->
        <div class="mt-8">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-gray-300">Nodes</h2>
                    <p class="mt-1 text-xs text-gray-500">Agent status and last-seen telemetry across the fleet.</p>
                </div>
                <Link :href="route('admin.nodes.index')" class="text-xs font-semibold text-indigo-400 hover:text-indigo-300">
                    View all
                </Link>
            </div>
            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table v-if="nodes.length" class="min-w-full divide-y divide-gray-800">
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
                    </tbody>
                </table>
                <EmptyState
                    v-else
                    title="No nodes configured"
                    description="Add the first node so Strata Hosting Panel can provision accounts and domains."
                >
                    <template #actions>
                        <Link :href="route('admin.nodes.create')" class="btn-primary">Add Node</Link>
                    </template>
                </EmptyState>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { usePage, router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionCard from '@/Components/ActionCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

defineProps({
    nodes: Array,
    stats: Array,
});

const license = usePage().props.license;
const syncing = ref(false);

function formatDate(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function forceSync() {
    syncing.value = true;
    router.post(route('admin.license.sync'), {}, {
        preserveScroll: true,
        onFinish: () => { syncing.value = false; },
    });
}
</script>
