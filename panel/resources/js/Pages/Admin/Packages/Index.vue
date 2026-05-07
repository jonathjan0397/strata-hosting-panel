<template>
    <AppLayout title="Hosting Packages">
        <div class="mb-5 flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-400">{{ packages.length }} package{{ packages.length !== 1 ? 's' : '' }}</p>
                <p class="mt-1 text-xs text-gray-500">Reusable account plans with quotas, PHP defaults, and feature-list assignments.</p>
            </div>
            <Link
                :href="route('admin.packages.create')"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500"
            >
                New Package
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
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-if="packages.length === 0">
                        <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">No hosting packages yet.</td>
                    </tr>
                    <tr v-for="pkg in packages" :key="pkg.id" class="transition-colors hover:bg-gray-800/40">
                        <td class="px-5 py-3.5 text-sm text-gray-100">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ pkg.name }}</span>
                                <span v-if="!pkg.is_active" class="rounded-full bg-gray-800 px-2 py-0.5 text-xs text-gray-400">Inactive</span>
                                <span v-if="pkg.available_to_resellers" class="rounded-full bg-emerald-900/40 px-2 py-0.5 text-xs text-emerald-300">Reseller</span>
                            </div>
                            <div class="mt-1 font-mono text-xs text-gray-500">{{ pkg.slug }}</div>
                            <div v-if="pkg.description" class="mt-1 text-xs text-gray-500">{{ pkg.description }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-gray-400">{{ pkg.feature_list ?? 'None' }}</td>
                        <td class="px-5 py-3.5 text-sm font-mono text-gray-300">{{ pkg.php_version }}</td>
                        <td class="px-5 py-3.5 text-xs text-gray-400">
                            <div>Disk: {{ displayLimit(pkg.disk_limit_mb, 'MB') }}</div>
                            <div>Bandwidth: {{ displayLimit(pkg.bandwidth_limit_mb, 'MB') }}</div>
                            <div>Domains: {{ displayCount(pkg.max_domains) }}</div>
                            <div>Email: {{ displayCount(pkg.max_email_accounts) }}</div>
                            <div>Databases: {{ displayCount(pkg.max_databases) }}</div>
                            <div>FTP: {{ displayCount(pkg.max_ftp_accounts) }}</div>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-gray-400">{{ pkg.accounts_count }} assigned</td>
                        <td class="px-5 py-3.5 text-right">
                            <Link
                                :href="route('admin.packages.edit', pkg.id)"
                                class="mr-3 text-xs text-indigo-400 transition-colors hover:text-indigo-300"
                            >
                                Edit
                            </Link>
                            <button
                                type="button"
                                class="text-xs text-rose-400 transition-colors hover:text-rose-300"
                                @click="destroyPackage(pkg)"
                            >
                                Delete
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';

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

function destroyPackage(pkg) {
    if (!window.confirm(`Delete hosting package "${pkg.name}"?`)) {
        return;
    }

    router.delete(route('admin.packages.destroy', pkg.id));
}
</script>
