<template>
    <AppLayout :title="`Client — ${account.username}`">
        <div class="max-w-2xl space-y-6 p-6">

            <!-- Header -->
            <div class="flex items-center gap-3">
                <Link :href="route('reseller.accounts.index')" class="text-gray-500 hover:text-gray-300 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </Link>
                <div>
                    <h1 class="text-lg font-semibold text-gray-100 font-mono">{{ account.username }}</h1>
                    <p class="text-sm text-gray-400">{{ account.user?.name }} · {{ account.user?.email }}</p>
                </div>
                <span :class="statusClass(account.status)" class="ml-auto rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize">
                    {{ account.status }}
                </span>
            </div>

            <!-- Summary cards -->
            <div class="grid grid-cols-4 gap-3">
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-3 text-center">
                    <p class="text-2xl font-bold text-gray-100">{{ account.domain_count }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Domains</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-3 text-center">
                    <p class="text-2xl font-bold text-gray-100">{{ account.email_count }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Mailboxes</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-3 text-center">
                    <p class="text-2xl font-bold text-gray-100">{{ account.database_count }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Databases</p>
                </div>
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-3 text-center">
                    <p class="text-2xl font-bold text-gray-100">{{ account.ftp_count }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">FTP</p>
                </div>
            </div>

            <!-- Server info -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-3">Server Details</h3>
                <dl class="grid grid-cols-2 gap-y-2 text-sm">
                    <dt class="text-gray-500">Node</dt>
                    <dd class="text-gray-200">{{ account.node?.name ?? '—' }}</dd>
                    <dt class="text-gray-500">PHP Version</dt>
                    <dd class="text-gray-200 font-mono">{{ account.php_version }}</dd>
                    <dt class="text-gray-500">Created</dt>
                    <dd class="text-gray-200">{{ account.created_at }}</dd>
                </dl>
            </div>

            <!-- Resource limits form -->
            <form @submit.prevent="save" class="rounded-xl border border-gray-800 bg-gray-900 p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-300">Resource Limits</h3>
                    <p class="text-xs text-gray-500">0 = unlimited</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div v-for="field in fields" :key="field.key">
                        <label class="block text-xs font-medium text-gray-400 mb-1">{{ field.label }}</label>
                        <input
                            v-model.number="form[field.key]"
                            type="number"
                            min="0"
                            class="field w-full"
                        />
                        <p v-if="form.errors[field.key]" class="mt-1 text-xs text-red-400">{{ form.errors[field.key] }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-1">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors"
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
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ account: Object });

const saved = ref(false);

const form = useForm({
    disk_limit_mb:       props.account.disk_limit_mb       ?? 0,
    bandwidth_limit_mb:  props.account.bandwidth_limit_mb  ?? 0,
    max_domains:         props.account.max_domains         ?? 0,
    max_email_accounts:  props.account.max_email_accounts  ?? 0,
    max_databases:       props.account.max_databases       ?? 0,
});

const fields = [
    { key: 'disk_limit_mb',      label: 'Disk (MB)' },
    { key: 'bandwidth_limit_mb', label: 'Bandwidth (MB)' },
    { key: 'max_domains',        label: 'Max Domains' },
    { key: 'max_email_accounts', label: 'Max Email Accounts' },
    { key: 'max_databases',      label: 'Max Databases' },
];

function save() {
    form.put(route('reseller.clients.update', props.account.id), {
        onSuccess: () => { saved.value = true; setTimeout(() => { saved.value = false; }, 3000); },
    });
}

function statusClass(status) {
    return {
        active:     'bg-emerald-900/40 text-emerald-300',
        suspended:  'bg-yellow-900/40 text-yellow-300',
        terminated: 'bg-red-900/40 text-red-300',
    }[status] ?? 'bg-gray-800 text-gray-400';
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
</style>
