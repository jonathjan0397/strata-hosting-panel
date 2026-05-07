<template>
    <AppLayout title="Reseller Dashboard">
        <div class="space-y-6">
            <PageHeader
                eyebrow="Reseller Workspace"
                title="Manage clients and packages"
                description="Track your resource pool, create client accounts, and review the hosting packages available for resale."
            >
                <template #actions>
                    <Link :href="route('reseller.accounts.create')" class="btn-primary">
                        New Client
                    </Link>
                    <Link :href="route('reseller.accounts.index')" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">
                        View Clients
                    </Link>
                </template>
            </PageHeader>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-200">Your Resource Pool</h3>
                        <p class="mt-1 text-xs text-gray-500">Capacity currently allocated across your client accounts.</p>
                    </div>
                    <span class="rounded-full bg-indigo-900/40 px-3 py-1 text-xs font-semibold text-indigo-300">
                        {{ packageCount }} packages
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-x-8 gap-y-4">
                    <QuotaMeter label="Accounts" :used="used.accounts" :quota="quota.accounts" />
                    <QuotaMeter label="Domains" :used="used.domains" :quota="quota.domains" />
                    <QuotaMeter label="Disk" :used="used.disk_mb" :quota="quota.disk_mb" suffix=" MB" />
                    <QuotaMeter label="Email" :used="used.email_accounts" :quota="quota.email_accounts" />
                    <QuotaMeter label="Bandwidth" :used="used.bandwidth_mb" :quota="quota.bandwidth_mb" suffix=" MB" />
                    <QuotaMeter label="Databases" :used="used.databases" :quota="quota.databases" />
                </div>
            </div>

            <div>
                <div class="mb-3">
                    <h3 class="text-sm font-semibold text-gray-300">Reseller Shortcuts</h3>
                    <p class="mt-1 text-xs text-gray-500">Common actions for client and package management.</p>
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <ActionCard
                        :href="route('reseller.accounts.create')"
                        title="Create Client"
                        description="Provision a new account using reseller-safe packages and quota limits."
                        cta="Start account"
                    />
                    <ActionCard
                        :href="route('reseller.accounts.index')"
                        title="Client Accounts"
                        description="Review clients, suspend accounts, and open client detail pages."
                        cta="Manage clients"
                    />
                    <ActionCard
                        :href="route('reseller.packages.index')"
                        title="Packages"
                        description="Browse the packages available for assignment to your clients."
                        cta="View packages"
                    />
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-800 bg-gray-900/60 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-200">Recent Clients</h3>
                        <p class="mt-1 text-xs text-gray-500">Newest client accounts under your reseller profile.</p>
                    </div>
                    <Link :href="route('reseller.accounts.index')" class="text-xs text-indigo-400 hover:text-indigo-300">
                        View all
                    </Link>
                </div>
                <table v-if="clients.length" class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 bg-gray-900/30">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Client</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Username</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Added</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-for="c in clients" :key="c.id" class="hover:bg-gray-900/40">
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-100">{{ c.name }}</p>
                                <p class="text-xs text-gray-400">{{ c.email }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-gray-300">{{ c.account?.username ?? '-' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(c.account?.status)">
                                    {{ c.account?.status ?? 'no account' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-400">{{ formatDate(c.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState
                    v-else
                    title="No clients yet"
                    description="Create your first client account to begin allocating packages and resources."
                >
                    <template #actions>
                        <Link :href="route('reseller.accounts.create')" class="btn-primary">Create Client</Link>
                    </template>
                </EmptyState>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { defineComponent, h } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionCard from '@/Components/ActionCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    quota: Object,
    used: Object,
    clients: Array,
    packageCount: Number,
});

const QuotaMeter = defineComponent({
    props: { label: String, used: Number, quota: Number, suffix: { default: '' } },
    setup(props) {
        return () => {
            const pct = props.quota ? Math.min(100, Math.round((props.used / props.quota) * 100)) : 0;
            const color = pct >= 90 ? 'bg-red-500' : pct >= 70 ? 'bg-yellow-500' : 'bg-indigo-500';
            const right = props.quota !== null
                ? `${props.used}${props.suffix} / ${props.quota}${props.suffix}`
                : `${props.used}${props.suffix} / unlimited`;

            return h('div', [
                h('div', { class: 'flex justify-between text-xs mb-1' }, [
                    h('span', { class: 'text-gray-400' }, props.label),
                    h('span', { class: 'text-gray-300' }, right),
                ]),
                h('div', { class: 'h-2 rounded-full bg-gray-700 overflow-hidden' }, [
                    h('div', { class: `h-full rounded-full ${color} transition-all`, style: { width: `${pct}%` } }),
                ]),
            ]);
        };
    },
});

function statusClass(status) {
    const map = {
        active: 'bg-emerald-900/40 text-emerald-300',
        suspended: 'bg-yellow-900/40 text-yellow-300',
        terminated: 'bg-red-900/40 text-red-300',
    };
    return map[status] ?? 'bg-gray-800 text-gray-400';
}

function formatDate(iso) {
    return new Date(iso).toLocaleDateString();
}
</script>
