<template>
    <AppLayout title="Available Packages">
        <div class="space-y-6">
            <div class="flex items-start justify-between gap-4 rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div>
                    <h2 class="text-sm font-semibold text-gray-200">Packages You Can Assign</h2>
                    <p class="mt-1 text-sm text-gray-400">
                        These plans are available to your reseller account for new and existing clients.
                    </p>
                </div>
                <Link :href="route('reseller.accounts.create')" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500">
                    New Client Account
                </Link>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <table class="min-w-full divide-y divide-gray-800">
                    <thead>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Package</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Feature List</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">PHP</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Limits</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Usage</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="packages.length === 0">
                            <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500">No reseller packages are available yet.</td>
                        </tr>
                        <tr v-for="pkg in packages" :key="pkg.id" class="transition-colors hover:bg-gray-800/40">
                            <td class="px-5 py-3.5 text-sm text-gray-100">
                                <div class="font-medium">{{ pkg.name }}</div>
                                <div class="mt-1 font-mono text-xs text-gray-500">{{ pkg.slug }}</div>
                                <div v-if="pkg.description" class="mt-1 text-xs text-gray-500">{{ pkg.description }}</div>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ pkg.feature_list ?? 'None' }}</td>
                            <td class="px-5 py-3.5 font-mono text-sm text-gray-300">{{ pkg.php_version }}</td>
                            <td class="px-5 py-3.5 text-xs text-gray-400">
                                <div>Disk: {{ displayLimit(pkg.disk_limit_mb, 'MB') }}</div>
                                <div>Bandwidth: {{ displayLimit(pkg.bandwidth_limit_mb, 'MB') }}</div>
                                <div>Domains: {{ displayCount(pkg.max_domains) }}</div>
                                <div>Email: {{ displayCount(pkg.max_email_accounts) }}</div>
                                <div>Databases: {{ displayCount(pkg.max_databases) }}</div>
                                <div>FTP: {{ displayCount(pkg.max_ftp_accounts) }}</div>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-400">{{ pkg.accounts_count }} assigned</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    packages: Array,
});

function displayLimit(value, unit) {
    if (value === null || value === undefined) {
        return `Unlimited ${unit}`;
    }

    return `${value} ${unit}`;
}

function displayCount(value) {
    if (value === null || value === undefined) {
        return 'Unlimited';
    }

    return value;
}
</script>
