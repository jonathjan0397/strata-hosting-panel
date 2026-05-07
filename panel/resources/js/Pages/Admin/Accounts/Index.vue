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
                    <option value="provisioning">Provisioning</option>
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                    <option value="failed">Failed</option>
                </select>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <span class="text-xs text-gray-500">{{ selectedIds.length }} selected</span>
                    <select v-model="selectedPackageId" class="field text-xs" :disabled="selectedIds.length === 0">
                        <option value="">Choose package</option>
                        <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
                    </select>
                    <button type="button" class="rounded-lg border border-indigo-700 px-3 py-2 text-xs font-semibold text-indigo-300 transition-colors hover:bg-indigo-900/20 disabled:opacity-50" :disabled="selectedIds.length === 0 || !selectedPackageId" @click="bulkPackage">
                        Apply Package
                    </button>
                    <button type="button" class="rounded-lg border border-amber-700 px-3 py-2 text-xs font-semibold text-amber-300 transition-colors hover:bg-amber-900/20 disabled:opacity-50" :disabled="selectedIds.length === 0" @click="bulkStatus('suspend')">
                        Suspend
                    </button>
                    <button type="button" class="rounded-lg border border-emerald-700 px-3 py-2 text-xs font-semibold text-emerald-300 transition-colors hover:bg-emerald-900/20 disabled:opacity-50" :disabled="selectedIds.length === 0" @click="bulkStatus('unsuspend')">
                        Unsuspend
                    </button>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-700 bg-gray-800 text-indigo-500 focus:ring-indigo-500"
                                    :checked="allVisibleSelected"
                                    :disabled="accounts.data.length === 0"
                                    @change="toggleVisible($event.target.checked)"
                                />
                            </th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Username</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Email</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Node</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Package</th>
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
                            <td class="px-5 py-3.5">
                                <input
                                    v-model="selectedIds"
                                    type="checkbox"
                                    class="rounded border-gray-700 bg-gray-800 text-indigo-500 focus:ring-indigo-500"
                                    :value="account.id"
                                />
                            </td>
                            <td class="px-5 py-3.5 text-sm font-mono font-medium text-gray-100">{{ account.username }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ account.user?.email }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ account.node?.name }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ account.hosting_package?.name ?? 'Custom' }}</td>
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
                                <div class="flex items-center justify-end gap-3">
                                    <button
                                        v-if="account.status === 'active'"
                                        type="button"
                                        class="text-xs text-sky-400 transition-colors hover:text-sky-300"
                                        @click="accessPanel(account)"
                                    >
                                        Access Panel
                                    </button>
                                    <Link
                                        :href="route('admin.accounts.show', account.id)"
                                        class="text-xs text-indigo-400 transition-colors hover:text-indigo-300"
                                    >
                                        Manage
                                    </Link>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="accounts.data.length === 0">
                            <td colspan="9" class="px-5 py-8">
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
    packages: { type: Array, default: () => [] },
});

const search = ref(props.filters?.search ?? '');
const statusFilter = ref(props.filters?.status ?? '');
const selectedIds = ref([]);
const selectedPackageId = ref('');
const activeCount = computed(() => props.accounts.data.filter((account) => account.status === 'active').length);
const suspendedCount = computed(() => props.accounts.data.filter((account) => account.status === 'suspended').length);
const visibleIds = computed(() => props.accounts.data.map((account) => account.id));
const allVisibleSelected = computed(() => visibleIds.value.length > 0 && visibleIds.value.every((id) => selectedIds.value.includes(id)));

let debounceTimer;
function debouncedSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => applyFilters(), 350);
}

function applyFilters() {
    selectedIds.value = [];
    router.get(route('admin.accounts.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
    }, { preserveState: true, replace: true });
}

function toggleVisible(checked) {
    selectedIds.value = checked ? [...visibleIds.value] : [];
}

function bulkPackage() {
    if (selectedIds.value.length === 0 || !selectedPackageId.value) return;
    const selectedPackage = props.packages.find((pkg) => pkg.id === selectedPackageId.value);
    const packageName = selectedPackage?.name ?? 'selected package';
    if (!confirm(`Apply ${packageName} to ${selectedIds.value.length} selected account(s)? Existing domain vhost settings are not reprovisioned by this bulk action.`)) return;

    router.post(route('admin.accounts.bulk-package'), {
        account_ids: selectedIds.value,
        hosting_package_id: selectedPackageId.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = [];
            selectedPackageId.value = '';
        },
    });
}

function bulkStatus(action) {
    if (selectedIds.value.length === 0) return;
    const label = action === 'suspend' ? 'suspend' : 'unsuspend';
    if (!confirm(`${label.charAt(0).toUpperCase() + label.slice(1)} ${selectedIds.value.length} selected account(s)?`)) return;

    router.post(route('admin.accounts.bulk-status'), {
        account_ids: selectedIds.value,
        action,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = [];
        },
    });
}

function accessPanel(account) {
    router.post(route('admin.accounts.impersonate', account.id));
}
</script>
