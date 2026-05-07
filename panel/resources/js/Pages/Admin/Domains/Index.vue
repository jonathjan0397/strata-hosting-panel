<template>
    <AppLayout title="Domains">
        <div class="space-y-6 p-6">
            <PageHeader
                eyebrow="Admin"
                title="Domains"
                description="Audit hosted domains, SSL state, account ownership, and node placement."
            >
                <template #actions>
                    <Link :href="route('admin.domains.create')" class="btn-primary">Add Domain</Link>
                </template>
            </PageHeader>

            <div class="grid gap-4 md:grid-cols-3">
                <StatCard label="Visible Domains" :value="domains.data.length" color="indigo" />
                <StatCard label="SSL Active" :value="sslActiveCount" color="emerald" />
                <StatCard label="Without SSL" :value="withoutSslCount" color="gray" />
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
                <div class="flex flex-col gap-3 md:flex-row md:items-center">
                    <input
                        v-model="search"
                        @input="debouncedSearch"
                        type="text"
                        placeholder="Search domain..."
                        class="field w-64"
                    />
                    <div class="md:ml-auto flex items-center gap-3">
                        <span class="text-xs text-gray-500">{{ selectedIds.length }} selected</span>
                        <button
                            type="button"
                            :disabled="selectedIds.length === 0"
                            class="rounded-lg border border-red-700 px-3 py-2 text-xs font-semibold text-red-300 transition-colors hover:bg-red-900/20 disabled:opacity-50"
                            @click="bulkDelete"
                        >
                            Delete Selected
                        </button>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-700 bg-gray-800 text-indigo-500 focus:ring-indigo-500"
                                    :checked="allVisibleSelected"
                                    :disabled="domains.data.length === 0"
                                    @change="toggleVisible($event.target.checked)"
                                />
                            </th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Domain</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Account</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Type</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">SSL</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Node</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr
                            v-for="domain in domains.data"
                            :key="domain.id"
                            class="transition-colors hover:bg-gray-800/40"
                        >
                            <td class="px-5 py-3.5">
                                <input
                                    v-model="selectedIds"
                                    type="checkbox"
                                    class="rounded border-gray-700 bg-gray-800 text-indigo-500 focus:ring-indigo-500"
                                    :value="domain.id"
                                />
                            </td>
                            <td class="px-5 py-3.5 text-sm font-mono text-gray-100">{{ domain.domain }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">
                                <Link :href="route('admin.accounts.show', domain.account?.id)" class="transition-colors hover:text-indigo-400">
                                    {{ domain.account?.username }}
                                </Link>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ domain.type }}</td>
                            <td class="px-5 py-3.5 text-sm">
                                <span v-if="domain.ssl_enabled" class="text-xs text-emerald-400">Active</span>
                                <span v-else class="text-xs text-gray-600">None</span>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ domain.node?.name }}</td>
                            <td class="px-5 py-3.5 text-right">
                                <Link :href="route('admin.domains.show', domain.id)" class="text-xs text-indigo-400 transition-colors hover:text-indigo-300">
                                    Manage
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="domains.data.length === 0">
                            <td colspan="7" class="px-5 py-8">
                                <EmptyState
                                    title="No domains found"
                                    description="Adjust the search or add a domain to an existing account."
                                >
                                    <template #actions>
                                        <Link :href="route('admin.domains.create')" class="btn-primary">Add Domain</Link>
                                    </template>
                                </EmptyState>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :links="domains.links" />
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    domains: Object,
    filters: Object,
});

const search = ref(props.filters?.search ?? '');
const selectedIds = ref([]);
const sslActiveCount = computed(() => props.domains.data.filter((domain) => domain.ssl_enabled).length);
const withoutSslCount = computed(() => props.domains.data.length - sslActiveCount.value);
const visibleIds = computed(() => props.domains.data.map((domain) => domain.id));
const allVisibleSelected = computed(() => visibleIds.value.length > 0 && visibleIds.value.every((id) => selectedIds.value.includes(id)));

let debounceTimer;
function debouncedSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        selectedIds.value = [];
        router.get(route('admin.domains.index'), { search: search.value || undefined }, {
            preserveState: true, replace: true,
        });
    }, 350);
}

function toggleVisible(checked) {
    selectedIds.value = checked ? [...visibleIds.value] : [];
}

function bulkDelete() {
    if (selectedIds.value.length === 0) return;
    if (!confirm(`Delete ${selectedIds.value.length} selected domain(s)? Failed server cleanup will keep panel records.`)) return;

    router.delete(route('admin.domains.bulk-destroy'), {
        data: { domain_ids: selectedIds.value },
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = [];
        },
    });
}
</script>
