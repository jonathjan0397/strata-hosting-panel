<template>
    <AppLayout title="Server DNS Zones">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-100">Server DNS Zones</h2>
                <p class="text-sm text-gray-400 mt-0.5">DNS zones not tied to a hosted account — server hostname, custom delegations, etc.</p>
            </div>
            <button @click="showCreate = !showCreate" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition-colors">
                + New Zone
            </button>
        </div>

        <!-- Create form -->
        <div v-if="showCreate" class="mb-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <h3 class="text-sm font-semibold text-gray-200 mb-4">Create Zone</h3>
            <form @submit.prevent="submitCreate" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-48">
                    <label class="block text-xs text-gray-400 mb-1">Zone name (domain)</label>
                    <input v-model="createForm.zone_name" type="text" placeholder="example.com" class="field w-full" required />
                </div>
                <div class="w-48">
                    <label class="block text-xs text-gray-400 mb-1">Node</label>
                    <select v-model.number="createForm.node_id" class="field" required>
                        <option value="" disabled>Select node</option>
                        <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }}</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 transition-colors">
                        Create Zone
                    </button>
                    <button type="button" @click="showCreate = false" class="text-sm text-gray-500 hover:text-gray-300">Cancel</button>
                </div>
            </form>
        </div>

        <!-- Zones table -->
        <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-800 text-left">
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Zone</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Node</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Records</th>
                        <th class="px-4 py-3 text-xs font-medium text-gray-400">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <tr v-for="z in zones" :key="z.id">
                        <td class="px-4 py-3 font-mono text-xs text-gray-200">{{ z.zone_name }}</td>
                        <td class="px-4 py-3 text-xs text-gray-400">{{ z.node }}</td>
                        <td class="px-4 py-3 text-xs text-gray-400">{{ z.records_count }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs"
                                :class="z.active ? 'bg-emerald-900/40 text-emerald-400' : 'bg-gray-800 text-gray-500'">
                                {{ z.active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right flex items-center justify-end gap-3">
                            <Link :href="route('admin.dns.server.show', z.id)" class="text-xs text-indigo-400 hover:text-indigo-300">
                                Manage
                            </Link>
                            <ConfirmButton
                                :href="route('admin.dns.server.destroy', z.id)"
                                method="delete"
                                label="Delete"
                                :confirm-message="`Delete zone ${z.zone_name}? All DNS records will be removed from the nameserver.`"
                                color="red"
                            />
                        </td>
                    </tr>
                    <tr v-if="zones.length === 0">
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">No server zones yet.</td>
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

defineProps({ zones: Array, nodes: Array });

const showCreate = ref(false);
const createForm = ref({ zone_name: '', node_id: '' });

function submitCreate() {
    router.post(route('admin.dns.server.store'), createForm.value, {
        onSuccess: () => { showCreate.value = false; createForm.value = { zone_name: '', node_id: '' }; },
    });
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100
           placeholder-gray-500 focus:border-indigo-500 focus:outline-none;
}
</style>
