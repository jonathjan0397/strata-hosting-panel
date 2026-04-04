<template>
    <AppLayout :title="reseller.name">
        <div class="space-y-6 max-w-4xl">
            <div class="flex items-center justify-between">
                <Link :href="route('admin.resellers.index')" class="text-sm text-gray-500 hover:text-gray-300 transition-colors">
                    ← Back to resellers
                </Link>
                <button
                    @click="confirmDelete"
                    class="text-xs text-red-400 hover:text-red-300 transition-colors"
                >
                    Delete Reseller
                </button>
            </div>

            <!-- Info + quota edit -->
            <div class="grid grid-cols-2 gap-5">
                <!-- Reseller info -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="text-sm font-semibold text-gray-200 mb-3">Account</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-400">Name</dt>
                            <dd class="text-gray-100">{{ reseller.name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-400">Email</dt>
                            <dd class="text-gray-100">{{ reseller.email }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-400">Clients</dt>
                            <dd class="text-gray-100">{{ used.accounts }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-400">Joined</dt>
                            <dd class="text-gray-100">{{ formatDate(reseller.created_at) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Quota usage -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                    <h3 class="text-sm font-semibold text-gray-200 mb-3">Quota Usage</h3>
                    <div class="space-y-2">
                        <QuotaRow label="Accounts"     :used="used.accounts"       :quota="reseller.quota_accounts" />
                        <QuotaRow label="Disk"         :used="used.disk_mb"         :quota="reseller.quota_disk_mb"       suffix="MB" />
                        <QuotaRow label="Bandwidth"    :used="used.bandwidth_mb"    :quota="reseller.quota_bandwidth_mb"  suffix="MB" />
                        <QuotaRow label="Domains"      :used="used.domains"         :quota="reseller.quota_domains" />
                        <QuotaRow label="Email"        :used="used.email_accounts"  :quota="reseller.quota_email_accounts" />
                        <QuotaRow label="Databases"    :used="used.databases"       :quota="reseller.quota_databases" />
                    </div>
                </div>
            </div>

            <!-- Edit quotas -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-200 mb-4">Update Quotas</h3>
                <form @submit.prevent="updateQuotas" class="grid grid-cols-3 gap-4">
                    <FormField label="Max Accounts" :error="quotaForm.errors.quota_accounts">
                        <input v-model.number="quotaForm.quota_accounts" type="number" min="1" class="field" placeholder="∞" />
                    </FormField>
                    <FormField label="Disk Pool (MB)" :error="quotaForm.errors.quota_disk_mb">
                        <input v-model.number="quotaForm.quota_disk_mb" type="number" min="0" class="field" placeholder="∞" />
                    </FormField>
                    <FormField label="Bandwidth Pool (MB)" :error="quotaForm.errors.quota_bandwidth_mb">
                        <input v-model.number="quotaForm.quota_bandwidth_mb" type="number" min="0" class="field" placeholder="∞" />
                    </FormField>
                    <FormField label="Max Domains" :error="quotaForm.errors.quota_domains">
                        <input v-model.number="quotaForm.quota_domains" type="number" min="0" class="field" placeholder="∞" />
                    </FormField>
                    <FormField label="Max Email Accounts" :error="quotaForm.errors.quota_email_accounts">
                        <input v-model.number="quotaForm.quota_email_accounts" type="number" min="0" class="field" placeholder="∞" />
                    </FormField>
                    <FormField label="Max Databases" :error="quotaForm.errors.quota_databases">
                        <input v-model.number="quotaForm.quota_databases" type="number" min="0" class="field" placeholder="∞" />
                    </FormField>
                    <div class="col-span-3">
                        <button
                            type="submit"
                            :disabled="quotaForm.processing"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors"
                        >
                            Save Quotas
                        </button>
                    </div>
                </form>
            </div>

            <!-- Client list -->
            <div class="rounded-xl border border-gray-800 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-800 bg-gray-900/60">
                    <h3 class="text-sm font-semibold text-gray-200">Clients ({{ clients.length }})</h3>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 bg-gray-900/30">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Name / Email</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Username</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Joined</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="clients.length === 0">
                            <td colspan="4" class="px-4 py-6 text-center text-gray-500">No clients yet.</td>
                        </tr>
                        <tr v-for="c in clients" :key="c.id" class="hover:bg-gray-900/40">
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-100">{{ c.name }}</p>
                                <p class="text-xs text-gray-400">{{ c.email }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-gray-300">{{ c.account?.username ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="statusClass(c.account?.status)"
                                >
                                    {{ c.account?.status ?? 'no account' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-400">{{ formatDate(c.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { useForm, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/FormField.vue';

const props = defineProps({
    reseller: Object,
    clients:  Array,
    used:     Object,
});

const quotaForm = useForm({
    quota_accounts:       props.reseller.quota_accounts,
    quota_disk_mb:        props.reseller.quota_disk_mb,
    quota_bandwidth_mb:   props.reseller.quota_bandwidth_mb,
    quota_domains:        props.reseller.quota_domains,
    quota_email_accounts: props.reseller.quota_email_accounts,
    quota_databases:      props.reseller.quota_databases,
});

function updateQuotas() {
    quotaForm.put(route('admin.resellers.update', props.reseller.id));
}

function confirmDelete() {
    if (confirm(`Delete reseller ${props.reseller.name}? Their client accounts will be detached but not deleted.`)) {
        router.delete(route('admin.resellers.destroy', props.reseller.id));
    }
}

function formatDate(iso) {
    return new Date(iso).toLocaleDateString();
}

function statusClass(status) {
    const map = {
        active:     'bg-emerald-900/40 text-emerald-300',
        suspended:  'bg-yellow-900/40 text-yellow-300',
        terminated: 'bg-red-900/40 text-red-300',
    };
    return map[status] ?? 'bg-gray-800 text-gray-400';
}
</script>

<!-- QuotaRow component inline -->
<script>
import { defineComponent, h } from 'vue';

const QuotaRow = defineComponent({
    props: { label: String, used: Number, quota: Number, suffix: { default: '' } },
    setup(props) {
        return () => {
            const pct = props.quota ? Math.min(100, Math.round((props.used / props.quota) * 100)) : 0;
            const color = pct >= 90 ? 'bg-red-500' : pct >= 70 ? 'bg-yellow-500' : 'bg-indigo-500';
            const right = props.quota !== null
                ? `${props.used}${props.suffix} / ${props.quota}${props.suffix}`
                : `${props.used}${props.suffix} / ∞`;

            return h('div', { class: 'text-xs' }, [
                h('div', { class: 'flex justify-between text-gray-400 mb-0.5' }, [
                    h('span', props.label),
                    h('span', right),
                ]),
                h('div', { class: 'h-1.5 rounded-full bg-gray-700 overflow-hidden' }, [
                    h('div', { class: `h-full rounded-full ${color} transition-all`, style: { width: `${pct}%` } }),
                ]),
            ]);
        };
    },
});

export default { components: { QuotaRow } };
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500;
}
</style>
