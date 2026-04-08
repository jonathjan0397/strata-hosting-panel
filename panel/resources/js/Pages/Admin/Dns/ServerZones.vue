<template>
    <AppLayout title="All DNS Zones">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-100">All DNS Zones</h2>
                <p class="mt-0.5 text-sm text-gray-400">Hosted and standalone DNS zones across every online nameserver.</p>
            </div>
            <div class="flex items-center gap-2">
                <button @click="syncBackups" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-medium text-gray-200 transition-colors hover:border-indigo-500 hover:text-white">
                    Sync Backup DNS
                </button>
                <button @click="showCreate = !showCreate" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500">
                    + New Zone
                </button>
            </div>
        </div>

        <div class="mb-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="node in cluster" :key="node.id" class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-semibold text-gray-100">{{ node.name }}</h3>
                            <span class="rounded-full px-2 py-0.5 text-[11px]" :class="node.is_primary ? 'bg-indigo-900/50 text-indigo-200' : 'bg-cyan-900/40 text-cyan-200'">
                                {{ node.is_primary ? 'Primary DNS' : 'Backup DNS' }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">{{ node.hostname }}</p>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs" :class="node.status === 'ok' ? 'bg-emerald-900/40 text-emerald-300' : 'bg-red-900/40 text-red-300'">
                        {{ node.status === 'ok' ? 'Healthy' : 'Error' }}
                    </span>
                </div>
                <div class="mt-4 text-2xl font-semibold text-gray-100">{{ node.live_zones }}</div>
                <p class="text-xs text-gray-500">Live zones reported by PowerDNS</p>
                <p v-if="node.error" class="mt-3 rounded-lg border border-red-900/60 bg-red-950/40 p-3 text-xs text-red-200">{{ node.error }}</p>
            </div>
        </div>

        <div v-if="showCreate" class="mb-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <h3 class="mb-4 text-sm font-semibold text-gray-200">Create Standalone Zone</h3>
            <form @submit.prevent="submitCreate" class="flex flex-wrap items-end gap-3">
                <div class="min-w-48 flex-1">
                    <label class="mb-1 block text-xs text-gray-400">Zone name</label>
                    <input v-model="createForm.zone_name" type="text" placeholder="example.com" class="field w-full" required />
                </div>
                <div class="w-48">
                    <label class="mb-1 block text-xs text-gray-400">Node</label>
                    <select v-model.number="createForm.node_id" class="field" required>
                        <option value="" disabled>Select node</option>
                        <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }}</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500">
                        Create Zone
                    </button>
                    <button type="button" @click="showCreate = false" class="text-sm text-gray-500 hover:text-gray-300">Cancel</button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-800 text-left">
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Zone</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Node</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Type</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Owner</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Records</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="z in zones" :key="`${z.zone_name}-${z.node_id}`">
                        <td class="px-4 py-3 font-mono text-xs text-gray-200">{{ z.zone_name }}</td>
                        <td class="px-4 py-3 text-xs text-gray-400">{{ z.node }}</td>
                        <td class="px-4 py-3 text-xs text-gray-400">{{ z.type }}</td>
                        <td class="px-4 py-3 text-xs text-gray-400">{{ z.owner ?? '-' }}</td>
                        <td class="px-4 py-3 text-xs text-gray-400">{{ z.records_count }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs"
                                :class="z.active ? 'bg-emerald-900/40 text-emerald-400' : 'bg-gray-800 text-gray-500'"
                            >
                                {{ z.active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="flex items-center justify-end gap-3 px-4 py-3 text-right">
                            <Link v-if="z.id" :href="route('admin.dns.server.show', z.id)" class="text-xs text-indigo-400 hover:text-indigo-300">
                                Manage
                            </Link>
                            <ConfirmButton
                                v-if="z.id"
                                :href="route('admin.dns.server.destroy', z.id)"
                                method="delete"
                                label="Delete"
                                :confirm-message="`Delete zone ${z.zone_name}? All DNS records will be removed from managed nameservers. Hosted domains will remain, but DNS will be disabled until reprovisioned.`"
                                color="red"
                            />
                            <span v-else class="text-xs text-amber-400">Live only</span>
                        </td>
                    </tr>
                    <tr v-if="zones.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">No DNS zones yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

defineProps({ zones: Array, nodes: Array, cluster: Array });

const showCreate = ref(false);
const createForm = ref({ zone_name: '', node_id: '' });

function submitCreate() {
    router.post(route('admin.dns.server.store'), createForm.value, {
        onSuccess: () => {
            showCreate.value = false;
            createForm.value = { zone_name: '', node_id: '' };
        },
    });
}

function syncBackups() {
    if (!confirm('Run backup DNS zone sync now?')) return;
    router.post(route('admin.dns.server.sync-backups'), {}, { preserveScroll: true });
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none;
}
</style>
