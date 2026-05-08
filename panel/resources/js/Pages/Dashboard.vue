<template>
    <AppLayout title="Dashboard">
        <PageHeader
            eyebrow="Manage Your Hosting Environment"
            title="Operate the hosting platform"
            description="Monitor node health, manage accounts and packages, review backups, and jump into common WHM-style operations."
        >
            <template #actions>
                <div class="inline-flex rounded-lg border border-gray-700 bg-gray-950 p-1">
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
                        :class="!guidedDashboard ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-gray-200'"
                        title="Use the original dashboard layout with summaries, operations, shortcuts, and node status."
                        @click="setDashboardMode(false)"
                    >
                        Default
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
                        :class="guidedDashboard ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-gray-200'"
                        title="Use a button-based dashboard organized around common web hosting tasks."
                        @click="setDashboardMode(true)"
                    >
                        Guided
                    </button>
                </div>
                <Link :href="route('admin.accounts.create')" class="btn-primary">
                    Create Account
                </Link>
                <Link :href="route('admin.nodes.create')" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">
                    Add Node
                </Link>
            </template>
        </PageHeader>

        <template v-if="guidedDashboard">
            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                <div class="space-y-5">
                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h2 class="text-base font-semibold text-gray-100">Start Here</h2>
                                <p class="mt-1 text-sm text-gray-500">The most common hosting jobs, grouped by what you are trying to do.</p>
                            </div>
                            <div class="grid grid-cols-2 gap-2 sm:flex">
                                <Link
                                    :href="route('admin.accounts.create')"
                                    class="rounded-lg bg-indigo-600 px-4 py-2 text-center text-sm font-semibold text-white transition-colors hover:bg-indigo-500"
                                    title="Create a new hosting login and reserve space for its websites, email, databases, and files."
                                >
                                    New Account
                                </Link>
                                <Link
                                    :href="route('admin.domains.create')"
                                    class="rounded-lg border border-gray-700 px-4 py-2 text-center text-sm font-semibold text-gray-300 transition-colors hover:bg-gray-800"
                                    title="Add a website name and point it at an existing hosting account."
                                >
                                    Add Website
                                </Link>
                            </div>
                        </div>
                    </div>

                    <section v-for="group in guidedGroups" :key="group.name" class="rounded-xl border border-gray-800 bg-gray-900">
                        <div class="border-b border-gray-800 px-5 py-4">
                            <div class="flex items-start gap-3">
                                <component :is="group.icon" class="mt-0.5 h-5 w-5 shrink-0 text-indigo-300" aria-hidden="true" />
                                <div>
                                    <h2 class="text-sm font-semibold text-gray-200">{{ group.name }}</h2>
                                    <p class="mt-1 text-xs leading-5 text-gray-500">{{ group.description }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                            <Link
                                v-for="item in group.items"
                                :key="item.label"
                                :href="item.href"
                                class="group min-h-28 rounded-lg border border-gray-800 bg-gray-950/60 p-4 transition-colors hover:border-indigo-500/60 hover:bg-gray-800/60"
                                :title="item.tooltip"
                            >
                                <div class="flex items-start gap-3">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-600/15 text-indigo-300 ring-1 ring-indigo-500/30">
                                        <component :is="item.icon" class="h-5 w-5" aria-hidden="true" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-100">{{ item.label }}</p>
                                        <p class="mt-1 text-xs leading-5 text-gray-500">{{ item.help }}</p>
                                        <p class="mt-3 text-xs font-semibold text-indigo-400 group-hover:text-indigo-300">Open</p>
                                    </div>
                                </div>
                            </Link>
                        </div>
                    </section>
                </div>

                <aside class="space-y-5">
                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <h2 class="text-sm font-semibold text-gray-200">Quick Health</h2>
                        <p class="mt-1 text-xs text-gray-500">Plain-English status for this panel server.</p>
                        <div v-if="operationsInfo" class="mt-4 space-y-4">
                            <div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-gray-400">Memory</span>
                                    <span :class="usageTextClass(operationsInfo.memory.used_pct)">{{ formatPercent(operationsInfo.memory.used_pct) }}</span>
                                </div>
                                <UsageBar class="mt-2" :percent="operationsInfo.memory.used_pct" />
                            </div>
                            <div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-gray-400">Processor</span>
                                    <span :class="usageTextClass(processorUsedPct)">{{ formatPercent(processorUsedPct) }}</span>
                                </div>
                                <UsageBar class="mt-2" :percent="processorUsedPct" />
                            </div>
                            <div v-for="disk in panelDisks.slice(0, 3)" :key="disk.path">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-mono text-gray-400">{{ disk.path }}</span>
                                    <span :class="usageTextClass(disk.used_pct)">{{ formatPercent(disk.used_pct) }}</span>
                                </div>
                                <UsageBar class="mt-2" :percent="disk.used_pct" />
                            </div>
                        </div>
                        <div v-else class="mt-4 rounded-lg border border-amber-800/50 bg-amber-900/10 px-4 py-3 text-xs text-amber-200">
                            {{ operations?.error ?? 'System health is not available yet.' }}
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <h2 class="text-sm font-semibold text-gray-200">Platform Totals</h2>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div v-for="stat in stats" :key="stat.label" class="rounded-lg border border-gray-800 bg-gray-950/60 p-3">
                                <p class="text-xs text-gray-500">{{ stat.label }}</p>
                                <p class="mt-1 text-xl font-semibold text-gray-100">{{ stat.value }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <h2 class="text-sm font-semibold text-gray-200">Need The Old View?</h2>
                        <p class="mt-2 text-sm leading-6 text-gray-500">Use the switch at the top any time. Your choice is saved in this browser.</p>
                        <button
                            type="button"
                            class="mt-4 w-full rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 transition-colors hover:bg-gray-800"
                            title="Return to the original admin dashboard layout."
                            @click="setDashboardMode(false)"
                        >
                            Use Default Dashboard
                        </button>
                    </div>
                </aside>
            </div>
        </template>

        <template v-else>
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
            <div class="mb-3 flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-gray-300">Operations Dashboard</h2>
                    <p class="mt-1 text-xs text-gray-500">
                        Primary panel resource usage for memory, processor load, and panel storage mountpoints.
                    </p>
                </div>
                <Link
                    v-if="operations?.node"
                    :href="route('admin.nodes.status', operations.node.id)"
                    class="text-xs font-semibold text-indigo-400 hover:text-indigo-300"
                >
                    Node status
                </Link>
            </div>

            <div v-if="operations?.error" class="rounded-xl border border-amber-800/50 bg-amber-900/10 px-5 py-4 text-sm text-amber-200">
                {{ operations.error }}
            </div>
            <div v-else-if="operationsInfo" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Memory Usage</p>
                                <p class="mt-2 text-2xl font-semibold text-gray-100">{{ formatPercent(operationsInfo.memory.used_pct) }}</p>
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="usageBadgeClass(operationsInfo.memory.used_pct)">
                                {{ usageLabel(operationsInfo.memory.used_pct) }}
                            </span>
                        </div>
                        <UsageBar class="mt-4" :percent="operationsInfo.memory.used_pct" />
                        <p class="mt-3 text-xs text-gray-500">
                            {{ formatMb(operationsInfo.memory.used_mb) }} / {{ formatMb(operationsInfo.memory.total_mb) }} used
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Processor Usage</p>
                                <p class="mt-2 text-2xl font-semibold text-gray-100">{{ formatPercent(processorUsedPct) }}</p>
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="usageBadgeClass(processorUsedPct)">
                                {{ operationsInfo.cpu.cores }} core{{ operationsInfo.cpu.cores === 1 ? '' : 's' }}
                            </span>
                        </div>
                        <UsageBar class="mt-4" :percent="processorUsedPct" />
                        <p class="mt-3 text-xs text-gray-500">
                            1m load {{ formatNumber(operationsInfo.load['1m']) }} · 5m {{ formatNumber(operationsInfo.load['5m']) }} · 15m {{ formatNumber(operationsInfo.load['15m']) }}
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Panel Node</p>
                                <p class="mt-2 text-lg font-semibold text-gray-100">{{ operations.node.name }}</p>
                            </div>
                            <span class="rounded-full bg-indigo-500/15 px-2.5 py-1 text-xs font-semibold text-indigo-300">Primary</span>
                        </div>
                        <p class="mt-4 font-mono text-xs text-gray-400">{{ operations.node.hostname }}</p>
                        <p class="mt-3 text-xs text-gray-500">Uptime {{ formatUptime(operationsInfo.uptime_seconds) }}</p>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-800 bg-gray-900">
                    <div class="border-b border-gray-800 px-5 py-4">
                        <h3 class="text-sm font-semibold text-gray-300">Panel Mountpoints</h3>
                        <p class="mt-1 text-xs text-gray-500">Hosting, backup, data, and system mountpoints sorted by operational priority.</p>
                    </div>
                    <div class="grid gap-0 divide-y divide-gray-800 md:grid-cols-2 md:divide-x md:divide-y-0 xl:grid-cols-3">
                        <div v-for="disk in panelDisks" :key="disk.path" class="p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-mono text-sm font-semibold text-gray-100">{{ disk.path }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ diskRoleLabel(disk.path) }}</p>
                                </div>
                                <span class="font-mono text-sm font-semibold" :class="usageTextClass(disk.used_pct)">
                                    {{ formatPercent(disk.used_pct) }}
                                </span>
                            </div>
                            <UsageBar class="mt-4" :percent="disk.used_pct" />
                            <div class="mt-3 flex items-center justify-between text-xs text-gray-500">
                                <span>{{ disk.used_gb }} GB used</span>
                                <span>{{ disk.free_gb }} GB free</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
        </template>
    </AppLayout>
</template>

<script setup>
import { computed, defineComponent, h, ref } from 'vue';
import { usePage, router, Link } from '@inertiajs/vue3';
import {
    ArrowPathIcon,
    CircleStackIcon,
    CloudArrowUpIcon,
    CodeBracketIcon,
    CommandLineIcon,
    CpuChipIcon,
    EnvelopeIcon,
    GlobeAltIcon,
    KeyIcon,
    LifebuoyIcon,
    LockClosedIcon,
    MagnifyingGlassCircleIcon,
    ServerIcon,
    ShieldCheckIcon,
    SparklesIcon,
    UserGroupIcon,
    UsersIcon,
    WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionCard from '@/Components/ActionCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

defineProps({
    nodes: Array,
    stats: Array,
    operations: Object,
});

const license = usePage().props.license;
const syncing = ref(false);
const page = usePage();
const dashboardModeKey = 'strata_admin_dashboard_guided';
const guidedDashboard = ref(typeof localStorage !== 'undefined' && localStorage.getItem(dashboardModeKey) === '1');
const operations = computed(() => page.props.operations ?? {});
const operationsInfo = computed(() => operations.value?.info ?? null);
const processorUsedPct = computed(() => {
    const info = operationsInfo.value;
    if (! info?.cpu?.cores) return 0;

    return Math.min((Number(info.load?.['1m'] ?? 0) / Number(info.cpu.cores)) * 100, 100);
});
const diskPriority = {
    '/opt/strata-panel': 120,
    '/var/www': 110,
    '/var/backups/strata': 100,
    '/srv': 90,
    '/home': 70,
    '/var': 40,
    '/': 10,
};
const panelDisks = computed(() => {
    const disks = operationsInfo.value?.disks ?? [];

    return [...disks]
        .sort((left, right) => {
            const priorityDiff = (diskPriority[right.path] ?? 0) - (diskPriority[left.path] ?? 0);
            if (priorityDiff !== 0) return priorityDiff;
            return right.total_gb - left.total_gb;
        })
        .slice(0, 6);
});

const UsageBar = defineComponent({
    props: {
        percent: { type: Number, default: 0 },
    },
    setup(props, { attrs }) {
        return () => h('div', { class: ['h-2 overflow-hidden rounded-full bg-gray-800', attrs.class] }, [
            h('div', {
                class: ['h-full rounded-full transition-all', usageBarClass(props.percent)],
                style: { width: `${Math.min(Math.max(Number(props.percent) || 0, 0), 100)}%` },
            }),
        ]);
    },
});

const guidedGroups = computed(() => [
    {
        name: 'Websites and Customers',
        description: 'Create hosting accounts, add domains, and choose what tools customers can use.',
        icon: GlobeAltIcon,
        items: [
            guidedItem('Accounts', route('admin.accounts.index'), UsersIcon, 'See every hosting account, open its control panel, suspend it, or delete it.', 'Use this when a customer calls or you need to manage their whole hosting account.'),
            guidedItem('Domains', route('admin.domains.index'), GlobeAltIcon, 'Manage website names, SSL, DNS, PHP version, redirects, and domain-level settings.', 'Use this when a website name is not working or needs a new setting.'),
            guidedItem('Hosting Packages', route('admin.packages.index'), ServerIcon, 'Create plans that control storage, email limits, databases, PHP defaults, and enabled tools.', 'Packages are templates for what a customer gets.'),
            guidedItem('Feature Lists', route('admin.feature-lists.index'), SparklesIcon, 'Choose which cPanel-style tools appear for package-backed customers.', 'Use this to keep simple plans simple and advanced plans full-featured.'),
            guidedItem('Resellers', route('admin.resellers.index'), UserGroupIcon, 'Manage users who can create and control their own customer accounts.', 'Use this when another person or company should manage their own clients.'),
            guidedItem('My Website', route('admin.my-website.index'), GlobeAltIcon, 'Provision and maintain the panel owner website hosted alongside the control panel.', 'Use this for the server owner site, not a regular customer site.'),
        ],
    },
    {
        name: 'Email and Deliverability',
        description: 'Create mailboxes, manage spam controls, and troubleshoot mail delivery.',
        icon: EnvelopeIcon,
        items: [
            guidedItem('Email Accounts', route('email-accounts.index'), EnvelopeIcon, 'Create and manage mailboxes for hosted domains.', 'Use this when someone needs a new email address or password reset.'),
            guidedItem('Mail Queue', route('admin.mail-queue.index'), ArrowPathIcon, 'View messages waiting to send and flush or remove stuck mail.', 'Use this when email is delayed or blocked.'),
            guidedItem('Spam Filter', route('admin.security.spam'), ShieldCheckIcon, 'Review Rspamd spam filtering status and statistics.', 'Use this to understand spam protection and filtering behavior.'),
            guidedItem('Deliverability', route('admin.deliverability.index'), LifebuoyIcon, 'Check DNS records that help mail reach inboxes instead of spam folders.', 'Use this when outgoing mail is rejected or lands in spam.'),
        ],
    },
    {
        name: 'Files, Data, and Apps',
        description: 'Handle backups, imported accounts, databases, and developer tools.',
        icon: CircleStackIcon,
        items: [
            guidedItem('Backups', route('admin.backups.index'), CloudArrowUpIcon, 'Create, restore, import, and delete account backups.', 'Use this before risky changes or when restoring customer data.'),
            guidedItem('Backup Schedules', route('admin.backups.schedules'), ArrowPathIcon, 'Set when accounts are backed up automatically.', 'Use this to make sure important accounts are protected on a schedule.'),
            guidedItem('Backup Destinations', route('admin.backups.destinations'), CloudArrowUpIcon, 'Send backup copies to remote storage.', 'Use this so backups are not only stored on the same server.'),
            guidedItem('Backup Imports', route('admin.backup-imports.index'), CloudArrowUpIcon, 'Convert cPanel or CWP archives into Strata backup jobs.', 'Use this when moving sites from another hosting panel.'),
            guidedItem('Account Migrations', route('admin.migrations.index'), ArrowPathIcon, 'Track transfer, restore, cutover, and source cleanup work.', 'Use this for planned migrations between servers or panels.'),
        ],
    },
    {
        name: 'Server Operations',
        description: 'Watch nodes, manage runtimes, inspect security controls, and run updates.',
        icon: ServerIcon,
        items: [
            guidedItem('Nodes', route('admin.nodes.index'), ServerIcon, 'Manage the servers that run websites, mail, DNS, backups, and agents.', 'Use this when checking whether a server is online or needs attention.'),
            guidedItem('PHP Versions', route('admin.php-versions.index'), CodeBracketIcon, 'Install and manage PHP runtimes available to hosted accounts.', 'Use this when a site needs a specific PHP version.'),
            guidedItem('Security Center', route('admin.security.index'), ShieldCheckIcon, 'Open firewall, Fail2Ban, spam, and host security tools.', 'Use this to protect the server and investigate suspicious activity.'),
            guidedItem('Firewall', route('admin.security.firewall'), LockClosedIcon, 'Allow or block network access with UFW rules.', 'Use this carefully when opening service ports or blocking abusive IPs.'),
            guidedItem('Fail2Ban', route('admin.security.fail2ban.index'), ShieldCheckIcon, 'View bans and protections for exposed services.', 'Use this when repeated login failures or attacks need investigation.'),
            guidedItem('Updates', route('admin.updates.index'), WrenchScrewdriverIcon, 'Run OS package updates and Strata panel upgrades.', 'Use this to keep the system patched and upgrade the control panel.'),
            guidedItem('Browser Shell', firstShellHref.value, CommandLineIcon, 'Open a browser terminal for the primary node when enabled.', 'Use this for advanced maintenance when the web shell feature is available.'),
            guidedItem('API Tokens', route('admin.api-tokens.index'), KeyIcon, 'Create tokens for scripts or outside systems to use the panel API.', 'Use this for automation, and keep tokens private.'),
        ],
    },
    {
        name: 'DNS, Diagnostics, and Automation',
        description: 'Repair name service, inspect audit trails, and connect outside systems.',
        icon: MagnifyingGlassCircleIcon,
        items: [
            guidedItem('DNS Zones', route('admin.dns.index'), GlobeAltIcon, 'Manage DNS records for hosted domains.', 'Use this when a domain, website, or email record needs changing.'),
            guidedItem('Server DNS', route('admin.dns.server.index'), ServerIcon, 'Manage authoritative server zones and backup DNS sync.', 'Use this for nameserver zones and DNS infrastructure.'),
            guidedItem('Troubleshooting', route('admin.troubleshooting.index'), LifebuoyIcon, 'Run DNS, mail, and certificate diagnostics in one place.', 'Use this first when something is not working and you need a plain report.'),
            guidedItem('Audit Log', route('admin.audit-log.index'), MagnifyingGlassCircleIcon, 'Review admin and system activity recorded by the panel.', 'Use this to answer who changed what and when.'),
            guidedItem('Webhooks', route('admin.webhooks.index'), ArrowPathIcon, 'Send panel events to outside systems.', 'Use this to notify billing, monitoring, or automation tools.'),
        ],
    },
]);

const firstShellHref = computed(() => {
    const node = page.props.nodes?.find((item) => item.is_primary) ?? page.props.nodes?.[0];
    return node ? route('admin.nodes.shell', node.id) : route('admin.nodes.index');
});

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

function setDashboardMode(enabled) {
    guidedDashboard.value = enabled;
    if (typeof localStorage !== 'undefined') {
        localStorage.setItem(dashboardModeKey, enabled ? '1' : '0');
    }
}

function guidedItem(label, href, icon, help, tooltip) {
    return { label, href, icon, help, tooltip };
}

function formatPercent(value) {
    return `${formatNumber(value)}%`;
}

function formatNumber(value) {
    return Number(value ?? 0).toFixed(1);
}

function formatMb(value) {
    return `${Number(value ?? 0).toLocaleString()} MB`;
}

function formatUptime(seconds) {
    const total = Number(seconds ?? 0);
    const days = Math.floor(total / 86400);
    const hours = Math.floor((total % 86400) / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m`;
}

function usageLabel(percent) {
    if (percent > 85) return 'High';
    if (percent > 70) return 'Elevated';
    return 'Normal';
}

function usageBadgeClass(percent) {
    if (percent > 85) return 'bg-red-500/15 text-red-300';
    if (percent > 70) return 'bg-amber-500/15 text-amber-300';
    return 'bg-emerald-500/15 text-emerald-300';
}

function usageTextClass(percent) {
    if (percent > 85) return 'text-red-300';
    if (percent > 70) return 'text-amber-300';
    return 'text-emerald-300';
}

function usageBarClass(percent) {
    if (percent > 85) return 'bg-red-500';
    if (percent > 70) return 'bg-amber-400';
    return 'bg-emerald-400';
}

function diskRoleLabel(path) {
    if (path === '/opt/strata-panel') return 'Panel Application';
    if (path === '/var/www') return 'Hosting Data';
    if (path === '/var/backups/strata') return 'Backups';
    if (path === '/srv') return 'Data Volume';
    if (path === '/home') return 'Home';
    if (path === '/var') return 'System Var';
    if (path === '/') return 'System Root';
    return 'Mounted Volume';
}
</script>
