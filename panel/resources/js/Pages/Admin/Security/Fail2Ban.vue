<template>
    <AppLayout title="Fail2Ban Administration">
        <div class="space-y-6 p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h1 class="text-lg font-semibold text-gray-100">Fail2Ban Administration</h1>
                    <p class="mt-0.5 text-sm text-gray-400">Manage Fail2Ban service state, jails, manual bans, and unbans per node.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button @click="serviceAction('start')" :disabled="serviceBusy || !selectedNodeId" class="btn-primary">Start</button>
                    <button @click="serviceAction('stop')" :disabled="serviceBusy || !selectedNodeId" class="rounded-lg border border-gray-700 px-3 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-50">Stop</button>
                    <button @click="serviceAction('restart')" :disabled="serviceBusy || !selectedNodeId" class="rounded-lg border border-gray-700 px-3 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-50">Restart</button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <select v-model="selectedNodeId" class="field w-72" @change="loadStatus">
                    <option value="">Select a node</option>
                    <option v-for="n in nodes" :key="n.id" :value="n.id">{{ n.name }} ({{ n.hostname }})</option>
                </select>
                <button @click="loadStatus" :disabled="loading || !selectedNodeId" class="rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-300 hover:bg-gray-800 disabled:opacity-50">
                    {{ loading ? 'Loading...' : 'Refresh' }}
                </button>
                <span v-if="service" :class="service.active ? 'bg-emerald-900/40 text-emerald-300' : 'bg-red-900/40 text-red-300'" class="rounded-full px-2.5 py-1 text-xs font-semibold">
                    {{ service.active ? 'Active' : 'Inactive' }}
                </span>
                <span v-if="service" class="rounded-full bg-gray-800 px-2.5 py-1 text-xs text-gray-400">
                    {{ service.enabled ? 'Enabled' : 'Disabled' }}
                </span>
            </div>

            <div v-if="message" class="rounded-xl border border-emerald-700/40 bg-emerald-900/20 px-4 py-2.5 text-sm text-emerald-300">{{ message }}</div>
            <div v-if="error" class="rounded-xl border border-red-700/40 bg-red-900/20 px-4 py-2.5 text-sm text-red-300">{{ error }}</div>

            <section v-if="selectedNodeId" class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <div class="mb-4">
                    <h2 class="text-sm font-semibold text-gray-300">Manual Ban</h2>
                    <p class="mt-1 text-xs text-gray-500">Add an IP address to a Fail2Ban jail immediately.</p>
                </div>
                <div class="grid gap-3 md:grid-cols-[minmax(12rem,1fr)_minmax(12rem,1fr)_auto] md:items-end">
                    <div>
                        <label class="mb-1 block text-xs text-gray-500">Jail</label>
                        <input v-model="banForm.jail" list="fail2ban-jails" class="field w-full" placeholder="sshd" />
                        <datalist id="fail2ban-jails">
                            <option v-for="jail in jails" :key="jail.name" :value="jail.name" />
                        </datalist>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-gray-500">IP address</label>
                        <input v-model="banForm.ip" class="field w-full" placeholder="203.0.113.10" @keyup.enter="banIp" />
                    </div>
                    <button @click="banIp" :disabled="banBusy || !banForm.jail || !banForm.ip" class="btn-primary">
                        {{ banBusy ? 'Banning...' : 'Ban IP' }}
                    </button>
                </div>
            </section>

            <section v-if="selectedNodeId" class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                <div class="border-b border-gray-800 px-5 py-4">
                    <h2 class="text-sm font-semibold text-gray-300">Jails</h2>
                    <p class="mt-1 text-xs text-gray-500">Review current failures and remove banned IPs.</p>
                </div>

                <div v-if="loading" class="px-5 py-10 text-center text-sm text-gray-500">Loading jails...</div>
                <div v-else-if="!jails.length" class="px-5 py-10 text-center text-sm text-gray-500">No jails found.</div>
                <div v-else class="divide-y divide-gray-800">
                    <article v-for="jail in jails" :key="jail.name" class="p-5">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h3 class="font-mono text-sm font-semibold text-gray-100">{{ jail.name }}</h3>
                                <p class="mt-1 text-xs text-gray-500">{{ jail.currently_failed ?? 0 }} currently failed, {{ jail.total_banned ?? 0 }} total banned</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <input v-model="unbanForm[jail.name]" class="field w-48 text-xs" placeholder="IP to remove" @keyup.enter="unbanIp(jail.name, unbanForm[jail.name])" />
                                <button @click="unbanIp(jail.name, unbanForm[jail.name])" :disabled="!unbanForm[jail.name]" class="rounded-lg border border-gray-700 px-3 py-2 text-xs font-semibold text-gray-300 hover:bg-gray-800 disabled:opacity-50">
                                    Remove
                                </button>
                            </div>
                        </div>

                        <div v-if="jail.banned_ips?.length" class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                            <div v-for="ip in jail.banned_ips" :key="ip" class="flex items-center justify-between rounded-lg border border-gray-800 bg-gray-950/40 px-3 py-2">
                                <span class="font-mono text-xs text-gray-300">{{ ip }}</span>
                                <button @click="unbanIp(jail.name, ip)" class="text-xs font-semibold text-amber-400 hover:text-amber-300">Remove</button>
                            </div>
                        </div>
                        <p v-else class="mt-4 text-xs text-gray-500">No banned IPs in this jail.</p>
                    </article>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    nodes: { type: Array, default: () => [] },
});

const selectedNodeId = ref(props.nodes[0]?.id ?? '');
const jails = ref([]);
const service = ref(null);
const loading = ref(false);
const serviceBusy = ref(false);
const banBusy = ref(false);
const error = ref('');
const message = ref('');
const banForm = ref({ jail: '', ip: '' });
const unbanForm = ref({});

async function loadStatus() {
    if (!selectedNodeId.value) return;
    loading.value = true;
    error.value = '';
    try {
        const res = await fetch(route('admin.security.fail2ban.status') + '?node_id=' + selectedNodeId.value, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        if (!res.ok || data.error) {
            error.value = data.error ?? 'Failed to fetch Fail2Ban status.';
            jails.value = [];
            service.value = data.service ?? null;
        } else {
            jails.value = data.jails ?? [];
            service.value = data.service ?? null;
            if (!banForm.value.jail && jails.value[0]) {
                banForm.value.jail = jails.value[0].name;
            }
        }
    } catch (e) {
        error.value = 'Network error: ' + e.message;
    } finally {
        loading.value = false;
    }
}

async function banIp() {
    if (!banForm.value.jail || !banForm.value.ip) return;
    banBusy.value = true;
    await postJson(route('admin.security.fail2ban.ban'), {
        node_id: selectedNodeId.value,
        jail: banForm.value.jail,
        ip: banForm.value.ip,
    }, () => {
        message.value = `${banForm.value.ip} banned in ${banForm.value.jail}.`;
        banForm.value.ip = '';
    });
    banBusy.value = false;
}

async function unbanIp(jail, ip) {
    if (!ip) return;
    await postJson(route('admin.security.fail2ban.unban'), {
        node_id: selectedNodeId.value,
        jail,
        ip,
    }, () => {
        message.value = `${ip} removed from ${jail}.`;
        unbanForm.value[jail] = '';
    });
}

async function serviceAction(action) {
    if (!selectedNodeId.value) return;
    serviceBusy.value = true;
    await postJson(route('admin.security.fail2ban.service'), {
        node_id: selectedNodeId.value,
        action,
    }, () => {
        message.value = `Fail2Ban ${action} completed.`;
    });
    serviceBusy.value = false;
}

async function postJson(url, payload, onSuccess) {
    error.value = '';
    message.value = '';
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                Accept: 'application/json',
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            error.value = data.message ?? 'Request failed.';
            return;
        }
        onSuccess?.(data);
        await loadStatus();
    } catch (e) {
        error.value = 'Network error: ' + e.message;
    }
}

onMounted(() => {
    if (selectedNodeId.value) loadStatus();
});
</script>
