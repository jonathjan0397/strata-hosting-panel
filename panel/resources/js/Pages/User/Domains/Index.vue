<template>
    <AppLayout title="My Domains">
        <PageHeader
            eyebrow="Websites"
            title="Domains"
            description="Manage hosted domains, SSL, PHP versions, redirects, directory privacy, and hotlink protection from one place."
        >
            <template #actions>
                <Link :href="route('my.domains.create')" class="btn-primary">Add Domain</Link>
            </template>
        </PageHeader>

        <div class="mb-5 grid gap-4 sm:grid-cols-3">
            <StatCard label="Total Domains" :value="domains.length" color="indigo" />
            <StatCard label="SSL Active" :value="sslActiveCount" color="emerald" />
            <StatCard label="Default PHP" :value="account.php_version" color="gray" />
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <table v-if="domains.length" class="min-w-full divide-y divide-gray-800">
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
                </tbody>
            </table>
            <EmptyState
                v-else
                title="No domains yet"
                description="Add your first domain to begin configuring SSL, email, DNS, redirects, and website security."
            >
                <template #actions>
                    <Link :href="route('my.domains.create')" class="btn-primary">Add Domain</Link>
                </template>
            </EmptyState>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    account: Object,
    domains: Array,
});

const sslActiveCount = computed(() => props.domains.filter((domain) => domain.ssl_enabled).length);
</script>
