<template>
    <AppLayout title="My Domains">
        <div class="mb-5 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-300">Domains ({{ domains.length }})</h2>
            <Link
                :href="route('my.domains.create')"
                class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition-colors"
            >
                + Add Domain
            </Link>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <table class="min-w-full divide-y divide-gray-800">
                <thead>
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Domain</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Type</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">PHP</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">SSL</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="domain in domains" :key="domain.id" class="hover:bg-gray-800/40 transition-colors">
                        <td class="px-5 py-3.5 text-sm font-mono text-gray-100">{{ domain.domain }}</td>
                        <td class="px-5 py-3.5 text-sm text-gray-400 capitalize">{{ domain.type }}</td>
                        <td class="px-5 py-3.5 text-sm font-mono text-gray-400">{{ domain.php_version ?? account.php_version }}</td>
                        <td class="px-5 py-3.5 text-sm">
                            <span v-if="domain.ssl_enabled" class="inline-flex items-center gap-1 text-xs text-emerald-400">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span> Active
                            </span>
                            <span v-else class="text-xs text-gray-500">None</span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <Link :href="route('my.domains.show', domain.id)" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                                Manage
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="domains.length === 0">
                        <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-500">
                            No domains yet.
                            <Link :href="route('my.domains.create')" class="text-indigo-400 hover:underline ml-1">Add one</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    account: Object,
    domains: Array,
});
</script>
