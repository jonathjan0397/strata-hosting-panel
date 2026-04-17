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

        <div v-if="license.managed" class="mt-5">
            <div v-if="license.first_run" class="mb-3 rounded-xl border border-sky-700/60 bg-sky-900/20 px-5 py-3 text-sm text-sky-100">
                The panel has not completed its first license ping yet. Run a manual sync now or wait for the 3-hour scheduler.
            </div>
            <div v-else-if="license.stale" class="mb-3 rounded-xl border border-amber-700/60 bg-amber-900/20 px-5 py-3 text-sm text-amber-100">
                The cached license data is more than 48 hours old. The panel will keep using the last successful response until sync recovers.
            </div>

            <div
                class="rounded-xl border px-5 py-3.5 flex items-center justify-between"
                :class="{
                    'border-emerald-800/50 bg-emerald-900/10': license.status === 'active',
                    'border-amber-800/50 bg-amber-900/10': license.status === 'inactive',
                    'border-gray-700/50 bg-gray-800/30': !['active', 'inactive'].includes(license.status),
                }"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="h-2.5 w-2.5 rounded-full"
                        :class="{
                            'bg-emerald-400': license.status === 'active',
                            'bg-amber-400': license.status === 'inactive',
                            'bg-gray-500': !['active', 'inactive'].includes(license.status),
                        }"
                    ></span>
                    <span
                        class="text-sm font-medium"
                        :class="{
                            'text-emerald-300': license.status === 'active',
                            'text-amber-300': license.status === 'inactive',
                            'text-gray-400': !['active', 'inactive'].includes(license.status),
                        }"
                    >
                        Strata Hosting Panel - {{ license.status === 'active' ? 'Licensed' : license.status }}
                    </span>
                    <div v-if="license.features.length" class="ml-2 flex items-center gap-1.5">
                        <span
                            v-for="feat in license.features"
                            :key="feat"
                            class="rounded-full bg-indigo-900/50 px-2 py-0.5 text-xs font-mono text-indigo-300"
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
                        class="flex items-center gap-1 text-xs text-gray-500 transition-colors hover:text-gray-300 disabled:opacity-40"
                        title="Force license sync now"
                    >
                        <svg :class="['h-3.5 w-3.5', syncing ? 'animate-spin' : '']" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        {{ syncing ? 'Syncing...' : 'Sync now' }}
                    </button>
                </div>
            </div>
        </div>

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
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Name</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Hostname</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Agent</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Last seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="node in nodes" :key="node.id" class="transition-colors hover:bg-gray-800/50">
                            <td class="px-5 py-3.5 text-sm font-medium text-gray-100">
                                {{ node.name }}
                                <span v-if="node.is_primary" class="ml-2 rounded-full bg-indigo-900/50 px-2 py-0.5 text-xs text-indigo-400">Primary</span>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-400">{{ node.hostname }}</td>
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
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
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
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-400">{{ node.agent_version ?? '-' }}</td>
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

    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function forceSync() {
    syncing.value = true;
    router.post(route('admin.license.sync'), {}, {
        preserveScroll: true,
        onFinish: () => { syncing.value = false; },
    });
}
</script>
