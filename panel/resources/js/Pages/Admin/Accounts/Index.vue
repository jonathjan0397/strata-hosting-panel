<template>
    <AppLayout title="Accounts">
        <!-- Toolbar -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <input
                v-model="search"
                @input="debouncedSearch"
                type="text"
                placeholder="Search username or email…"
                class="rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none w-64"
            />
            <select
                v-model="statusFilter"
                @change="applyFilters"
                class="rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-indigo-500 focus:outline-none"
            >
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
            </select>
            <div class="ml-auto">
                <Link
                    :href="route('admin.accounts.create')"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
                >
                    New Account
                </Link>
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <table class="min-w-full divide-y divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Username</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Email</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Node</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">PHP</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Disk</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr
                        v-for="account in accounts.data"
                        :key="account.id"
                        class="hover:bg-gray-800/40 transition-colors"
                    >
                        <td class="px-5 py-3.5 text-sm font-mono font-medium text-gray-100">{{ account.username }}</td>
                        <td class="px-5 py-3.5 text-sm text-gray-400">{{ account.user?.email }}</td>
                        <td class="px-5 py-3.5 text-sm text-gray-400">{{ account.node?.name }}</td>
                        <td class="px-5 py-3.5 text-sm text-gray-400 font-mono">{{ account.php_version }}</td>
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
                                class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors"
                            >
                                Manage
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="accounts.data.length === 0">
                        <td colspan="7" class="px-5 py-8 text-center text-sm text-gray-500">
                            No accounts found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <Pagination :links="accounts.links" class="mt-4" />
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AccountStatusBadge from '@/Components/AccountStatusBadge.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    accounts: Object,
    filters:  Object,
});

const search       = ref(props.filters?.search ?? '');
const statusFilter = ref(props.filters?.status ?? '');

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
