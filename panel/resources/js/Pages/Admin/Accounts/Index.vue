<template>
    <AppLayout title="Accounts">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Admin"
                title="Accounts"
                description="Manage hosting accounts, package assignments, node placement, and lifecycle state."
            >
                <template #actions>
                    <Link :href="route('admin.accounts.create')" class="btn-primary">New Account</Link>
                </template>
            </PageHeader>

            <div class="grid gap-4 md:grid-cols-3">
                <StatCard label="Visible Accounts" :value="accounts.data.length" color="indigo" />
                <StatCard label="Active" :value="activeCount" color="emerald" />
                <StatCard label="Suspended" :value="suspendedCount" color="amber" />
            </div>

            <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-800 bg-gray-900 p-4">
                <input
                    v-model="search"
                    @input="debouncedSearch"
                    type="text"
                    placeholder="Search username or email..."
                    class="field w-64"
                />
                <select
                    v-model="statusFilter"
                    @change="applyFilters"
                    class="field"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Username</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Email</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Node</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">PHP</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Status</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Disk</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr
                            v-for="account in accounts.data"
                            :key="account.id"
                            class="transition-colors hover:bg-gray-800/40"
                        >
                            <td class="px-5 py-3.5 text-sm font-mono font-medium text-gray-100">{{ account.username }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ account.user?.email }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ account.node?.name }}</td>
                            <td class="px-5 py-3.5 text-sm font-mono text-gray-400">{{ account.php_version }}</td>
                            <td class="px-5 py-3.5 text-sm">
                                <AccountStatusBadge :status="account.status" />
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">
                                <template v-if="account.disk_limit_mb > 0">
                                    {{ account.disk_used_mb }} / {{ account.disk_limit_mb }} MB
                                </template>
                                <span v-else class="text-gray-600">Unlimited</span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <Link
                                    :href="route('admin.accounts.show', account.id)"
                                    class="text-xs text-indigo-400 transition-colors hover:text-indigo-300"
                                >
                                    Manage
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="accounts.data.length === 0">
                            <td colspan="7" class="px-5 py-8">
                                <EmptyState
                                    title="No accounts found"
                                    description="Adjust the filters or create the first hosting account."
                                >
                                    <template #actions>
                                        <Link :href="route('admin.accounts.create')" class="btn-primary">New Account</Link>
                                    </template>
                                </EmptyState>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="accounts.links" />
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AccountStatusBadge from '@/Components/AccountStatusBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    accounts: Object,
    filters: Object,
});

const search = ref(props.filters?.search ?? '');
const statusFilter = ref(props.filters?.status ?? '');
const activeCount = computed(() => props.accounts.data.filter((account) => account.status === 'active').length);
const suspendedCount = computed(() => props.accounts.data.filter((account) => account.status === 'suspended').length);

let debounceTimer;
function debouncedSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => applyFilters(), 350);
}

function applyFilters() {
    router.get(route('admin.accounts.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
    }, { preserveState: true, replace: true });
}
</script>
