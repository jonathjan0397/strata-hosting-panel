<template>
    <AppLayout title="Security">
        <div class="space-y-6">
            <!-- Node selector -->
            <div class="flex items-center gap-3">
                <label class="text-sm text-gray-400">Node:</label>
                <select v-model="selectedNodeId" class="field w-48" @change="loadStatus">
                    <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }}</option>
                </select>
                <button @click="loadStatus" :disabled="loading" class="btn-secondary">
                    {{ loading ? 'Loading…' : 'Refresh' }}
                </button>
            </div>

            <!-- Error -->
            <div v-if="error" class="rounded-xl border border-red-700/50 bg-red-900/20 px-4 py-3 text-sm text-red-300">
                {{ error }}
            </div>

            <!-- Jails -->
            <div v-if="jails.length > 0" class="space-y-4">
                <div
                    v-for="jail in jails"
                    :key="jail.name"
                    class="rounded-xl border border-gray-800 overflow-hidden"
                >
                    <div class="flex items-center justify-between px-4 py-3 bg-gray-900/60 border-b border-gray-800">
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-sm font-medium text-gray-100">{{ jail.name }}</span>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="jail.total_banned > 0 ? 'bg-red-900/40 text-red-300' : 'bg-gray-800 text-gray-400'">
                                {{ jail.total_banned }} banned
                            </span>
                        </div>
                    </div>

                    <div v-if="jail.banned_ips.length === 0" class="px-4 py-6 text-center text-sm text-gray-500">
                        No banned IPs in this jail.
                    </div>

                    <table v-else class="w-full text-sm">
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="ip in jail.banned_ips" :key="ip" class="hover:bg-gray-900/40">
                                <td class="px-4 py-2.5 font-mono text-gray-200">{{ ip }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <button
                                        @click="unban(jail.name, ip)"
                                        class="text-xs text-emerald-400 hover:text-emerald-300 transition-colors"
                                    >Unban</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-else-if="!loading && !error && hasLoaded" class="rounded-xl border border-gray-800 px-4 py-8 text-center text-gray-500 text-sm">
                No active bans found.
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    nodes: Array,
});

const selectedNodeId = ref(props.nodes[0]?.id ?? null);
const jails    = ref([]);
const loading  = ref(false);
const error    = ref(null);
const hasLoaded = ref(false);

async function loadStatus() {
    if (!selectedNodeId.value) return;
    loading.value = true;
    error.value   = null;
    try {
        const res = await fetch(route('admin.security.fail2ban') + '?node_id=' + selectedNodeId.value, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        if (!res.ok) {
            error.value = data.error ?? 'Failed to fetch fail2ban status.';
            jails.value = [];
        } else {
            jails.value = data.jails ?? [];
        }
    } catch (e) {
        error.value = 'Network error: ' + e.message;
    } finally {
        loading.value  = false;
        hasLoaded.value = true;
    }
}

function unban(jail, ip) {
    if (!confirm(`Unban ${ip} from ${jail}?`)) return;
    router.post(route('admin.security.unban'), {
        node_id: selectedNodeId.value,
        jail,
        ip,
    }, {
        onSuccess: () => loadStatus(),
    });
}

onMounted(() => {
    if (selectedNodeId.value) loadStatus();
});
</script>

<style scoped>
@reference "tailwindcss";
.field       { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
.btn-secondary { @apply rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-300 hover:bg-gray-700 disabled:opacity-50 transition-colors; }
</style>
