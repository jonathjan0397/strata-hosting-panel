<template>
    <AppLayout title="Domains">
        <div class="mb-5 flex items-center gap-3">
            <input
                v-model="search"
                @input="debouncedSearch"
                type="text"
                placeholder="Search domain…"
                class="rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2 text-sm text-gray-200 placeholder-gray-500 focus:border-indigo-500 focus:outline-none w-64"
            />
            <div class="ml-auto">
                <Link
                    :href="route('admin.domains.create')"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
                >
                    Add Domain
                </Link>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <table class="min-w-full divide-y divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Domain</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Account</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Type</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">SSL</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Node</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr
                        v-for="domain in domains.data"
                        :key="domain.id"
                        class="hover:bg-gray-800/40 transition-colors"
                    >
                        <td class="px-5 py-3.5 text-sm font-mono text-gray-100">{{ domain.domain }}</td>
                        <td class="px-5 py-3.5 text-sm text-gray-400">
                            <Link :href="route('admin.accounts.show', domain.account?.id)" class="hover:text-indigo-400 transition-colors">
                                {{ domain.account?.username }}
                            </Link>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-gray-400">{{ domain.type }}</td>
                        <td class="px-5 py-3.5 text-sm">
                            <span v-if="domain.ssl_enabled" class="text-emerald-400 text-xs">Active</span>
                            <span v-else class="text-gray-600 text-xs">None</span>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-gray-400">{{ domain.node?.name }}</td>
                        <td class="px-5 py-3.5 text-right">
                            <Link :href="route('admin.domains.show', domain.id)" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                                Manage
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="domains.data.length === 0">
                        <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">No domains found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="domains.links" class="mt-4" />
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    domains: Object,
    filters: Object,
});

const search = ref(props.filters?.search ?? '');

let debounceTimer;
function debouncedSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        router.get(route('admin.domains.index'), { search: search.value || undefined }, {
            preserveState: true, replace: true,
        });
    }, 350);
}
</script>
