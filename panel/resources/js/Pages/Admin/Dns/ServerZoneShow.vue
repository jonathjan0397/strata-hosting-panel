<template>
    <AppLayout :title="`DNS - ${zone.zone_name}`">
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('admin.dns.server.index')" class="text-gray-500 transition-colors hover:text-gray-300">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </Link>
            <div class="flex items-center gap-2">
                <h2 class="font-mono text-lg font-semibold text-gray-100">{{ zone.zone_name }}</h2>
                <span class="rounded-full bg-gray-700 px-2.5 py-0.5 text-xs text-gray-300">{{ zone.type }} zone</span>
            </div>
            <button type="button" @click="syncBackups" class="ml-auto rounded-lg border border-gray-700 px-3 py-2 text-xs font-semibold text-gray-200 transition-colors hover:border-indigo-500 hover:text-white">
                Repair DNS Sync
            </button>
        </div>

        <div class="mb-6 overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-800 px-5 py-3.5">
                <h3 class="text-sm font-semibold text-gray-200">
                    DNS Records
                    <span class="ml-2 text-xs font-normal text-gray-500">{{ records.length }}</span>
                </h3>
                <button @click="showAdd = !showAdd" class="text-xs text-indigo-400 transition-colors hover:text-indigo-300">
                    + Add Record
                </button>
            </div>

            <div v-if="showAdd" class="border-b border-gray-800 bg-gray-800/30 px-5 py-4">
                <form @submit.prevent="submitRecord" class="space-y-3">
                    <div class="grid grid-cols-4 gap-2">
                        <input v-model="recForm.name" type="text" placeholder="Name (@, www, _dmarc)" class="field col-span-2 font-mono text-xs" required />
                        <select v-model="recForm.type" class="field text-xs">
                            <option v-for="t in recordTypes" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <input v-model.number="recForm.ttl" type="number" min="60" max="86400" placeholder="TTL" class="field text-xs" />
                    </div>
                    <input
                        v-if="['MX', 'SRV'].includes(recForm.type)"
                        v-model.number="recForm.priority"
                        type="number"
                        min="0"
                        max="65535"
                        placeholder="Priority"
                        class="field w-40 text-xs"
                    />
                    <textarea v-model="recForm.value" rows="2" placeholder="Record value" class="field w-full font-mono text-xs" required></textarea>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-indigo-500">
                            Add Record
                        </button>
                        <button type="button" @click="showAdd = false" class="text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="divide-y divide-gray-800">
                <div v-for="rec in records" :key="rec.id" class="flex items-center gap-3 px-5 py-3">
                    <span class="w-12 shrink-0 rounded px-1.5 py-0.5 text-center font-mono text-xs font-semibold" :class="typeClass(rec.type)">
                        {{ rec.type }}
                    </span>
                    <span class="w-40 shrink-0 truncate font-mono text-xs text-gray-300">{{ rec.name }}</span>
                    <span class="shrink-0 text-xs text-gray-500">{{ rec.ttl }}s</span>
                    <span class="flex-1 truncate font-mono text-xs text-gray-400">{{ rec.value }}</span>
                    <ConfirmButton
                        :href="route('admin.dns.server.records.destroy', rec.id)"
                        method="delete"
                        label="Delete"
                        :confirm-message="`Delete ${rec.type} record for ${rec.name}?`"
                        color="red"
                    />
                </div>
                <div v-if="records.length === 0" class="px-5 py-6 text-center text-sm text-gray-500">
                    No records yet.
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-700/50 bg-gray-800/40 p-4">
            <p class="text-xs text-gray-500">
                Nameserver for this zone:
                <span class="font-mono text-gray-300">{{ node?.hostname }}</span>
                <span v-if="zone.owner" class="ml-3">Owner: <span class="font-mono text-gray-300">{{ zone.owner }}</span></span>
            </p>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({ zone: Object, node: Object, records: Array });

const showAdd = ref(false);
const recForm = ref({ name: '', type: 'A', ttl: 300, value: '', priority: null });
const recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

function submitRecord() {
    router.post(route('admin.dns.server.records.store', props.zone.id), recForm.value, {
        onSuccess: () => {
            recForm.value = { name: '', type: 'A', ttl: 300, value: '', priority: null };
            showAdd.value = false;
        },
    });
}

function syncBackups() {
    if (!confirm(`Verify and repair ${props.zone.zone_name} on the authoritative node and backup DNS nodes now?`)) return;
    router.post(route('admin.dns.server.sync-zone-backups', props.zone.id), {}, { preserveScroll: true });
}

function typeClass(type) {
    const map = {
        A: 'bg-blue-900/50 text-blue-300',
        AAAA: 'bg-blue-900/50 text-blue-300',
        CNAME: 'bg-purple-900/50 text-purple-300',
        MX: 'bg-amber-900/50 text-amber-300',
        TXT: 'bg-emerald-900/50 text-emerald-300',
        SRV: 'bg-indigo-900/50 text-indigo-300',
        CAA: 'bg-rose-900/50 text-rose-300',
        NS: 'bg-gray-700 text-gray-300',
    };
    return map[type] ?? 'bg-gray-700 text-gray-300';
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none;
}
</style>
