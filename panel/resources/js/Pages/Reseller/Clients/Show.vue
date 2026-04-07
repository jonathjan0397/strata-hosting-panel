<template>
    <AppLayout :title="`Client - ${account.username}`">
        <div class="max-w-2xl space-y-6 p-6">
            <div class="flex items-center gap-3">
                <Link :href="route('reseller.accounts.index')" class="text-gray-500 transition-colors hover:text-gray-300">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </Link>
                <div>
                    <h1 class="font-mono text-lg font-semibold text-gray-100">{{ account.username }}</h1>
                    <p class="text-sm text-gray-400">{{ account.user?.name }} · {{ account.user?.email }}</p>
                </div>
                <span :class="statusClass(account.status)" class="ml-auto rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize">
                    {{ account.status }}
                </span>
                <button
                    v-if="account.status === 'active'"
                    type="button"
                    class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-sky-500"
                    @click="accessPanel"
                >
                    Access Panel
                </button>
            </div>

            <div class="grid grid-cols-4 gap-3">
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-3 text-center">
                    <p class="text-2xl font-bold text-gray-100">{{ account.domain_count }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">Domains</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-3 text-center">
                    <p class="text-2xl font-bold text-gray-100">{{ account.email_count }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">Mailboxes</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-3 text-center">
                    <p class="text-2xl font-bold text-gray-100">{{ account.database_count }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">Databases</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-3 text-center">
                    <p class="text-2xl font-bold text-gray-100">{{ account.ftp_count }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">FTP</p>
                </div>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="mb-3 text-sm font-semibold text-gray-300">Server Details</h3>
                <dl class="grid grid-cols-2 gap-y-2 text-sm">
                    <dt class="text-gray-500">Node</dt>
                    <dd class="text-gray-200">{{ account.node?.name ?? '-' }}</dd>
                    <dt class="text-gray-500">Package</dt>
                    <dd class="text-gray-200">{{ account.hosting_package?.name ?? 'Custom' }}</dd>
                    <dt class="text-gray-500">PHP Version</dt>
                    <dd class="font-mono text-gray-200">{{ account.php_version }}</dd>
                    <dt class="text-gray-500">Created</dt>
                    <dd class="text-gray-200">{{ account.created_at }}</dd>
                </dl>
            </div>

            <form @submit.prevent="save" class="space-y-4 rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-300">Resource Limits</h3>
                    <p class="text-xs text-gray-500">0 = unlimited</p>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-400">Hosting Package</label>
                    <select v-model="form.hosting_package_id" class="field w-full">
                        <option :value="null">Custom limits</option>
                        <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Selecting a package reapplies its defaults to this client account.</p>
                    <p v-if="form.errors.hosting_package_id" class="mt-1 text-xs text-red-400">{{ form.errors.hosting_package_id }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div v-for="field in fields" :key="field.key">
                        <label class="mb-1 block text-xs font-medium text-gray-400">{{ field.label }}</label>
                        <input v-model.number="form[field.key]" type="number" min="0" class="field w-full" />
                        <p v-if="form.errors[field.key]" class="mt-1 text-xs text-red-400">{{ form.errors[field.key] }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-1">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-500 disabled:opacity-60"
                    >
                        Save Limits
                    </button>
                    <span v-if="saved" class="text-sm text-green-400">Saved.</span>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    account: Object,
    packages: Array,
});

const saved = ref(false);

const form = useForm({
    hosting_package_id: props.account.hosting_package_id ?? null,
    disk_limit_mb: props.account.disk_limit_mb ?? 0,
    bandwidth_limit_mb: props.account.bandwidth_limit_mb ?? 0,
    max_domains: props.account.max_domains ?? 0,
    max_email_accounts: props.account.max_email_accounts ?? 0,
    max_databases: props.account.max_databases ?? 0,
});

const fields = [
    { key: 'disk_limit_mb', label: 'Disk (MB)' },
    { key: 'bandwidth_limit_mb', label: 'Bandwidth (MB)' },
    { key: 'max_domains', label: 'Max Domains' },
    { key: 'max_email_accounts', label: 'Max Email Accounts' },
    { key: 'max_databases', label: 'Max Databases' },
];

watch(() => form.hosting_package_id, (packageId) => {
    const selected = props.packages.find((pkg) => pkg.id === packageId);

    if (!selected) {
        return;
    }

    form.disk_limit_mb = selected.disk_limit_mb ?? 0;
    form.bandwidth_limit_mb = selected.bandwidth_limit_mb ?? 0;
    form.max_domains = selected.max_domains ?? 0;
    form.max_email_accounts = selected.max_email_accounts ?? 0;
    form.max_databases = selected.max_databases ?? 0;
});

function save() {
    form.put(route('reseller.clients.update', props.account.id), {
        onSuccess: () => {
            saved.value = true;
            setTimeout(() => {
                saved.value = false;
            }, 3000);
        },
    });
}

function accessPanel() {
    router.post(route('reseller.accounts.impersonate', props.account.id));
}

function statusClass(status) {
    return {
        active: 'bg-emerald-900/40 text-emerald-300',
        suspended: 'bg-yellow-900/40 text-yellow-300',
        terminated: 'bg-red-900/40 text-red-300',
    }[status] ?? 'bg-gray-800 text-gray-400';
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
