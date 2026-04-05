<template>
    <AppLayout :title="`DNS — ${zone.zone_name}`">
        <!-- Header -->
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('admin.dns.server.index')" class="text-gray-500 hover:text-gray-300 transition-colors">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </Link>
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold font-mono text-gray-100">{{ zone.zone_name }}</h2>
                <span class="rounded-full bg-gray-700 px-2.5 py-0.5 text-xs text-gray-300">Server zone</span>
            </div>
        </div>

        <!-- Records table -->
        <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden mb-6">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                <h3 class="text-sm font-semibold text-gray-200">
                    DNS Records
                    <span class="ml-2 text-xs font-normal text-gray-500">{{ records.length }}</span>
                </h3>
                <button @click="showAdd = !showAdd" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                    + Add Record
                </button>
            </div>

            <!-- Add record form -->
            <div v-if="showAdd" class="border-b border-gray-800 px-5 py-4 bg-gray-800/30">
                <form @submit.prevent="submitRecord" class="space-y-3">
                    <div class="grid grid-cols-4 gap-2">
                        <input v-model="recForm.name" type="text" placeholder="Name (@, www, _dmarc…)" class="field col-span-2 font-mono text-xs" required />
                        <select v-model="recForm.type" class="field text-xs">
                            <option v-for="t in recordTypes" :key="t" :value="t">{{ t }}</option>
                        </select>
                        <input v-model.number="recForm.ttl" type="number" min="60" max="86400" placeholder="TTL" class="field text-xs" />
                    </div>
                    <textarea v-model="recForm.value" rows="2" placeholder="Record value" class="field w-full font-mono text-xs" required></textarea>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors">
                            Add Record
                        </button>
                        <button type="button" @click="showAdd = false" class="text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                    </div>
                </form>
            </div>

            <!-- Records list -->
            <div class="divide-y divide-gray-800">
                <div v-for="rec in records" :key="rec.id" class="flex items-center gap-3 px-5 py-3">
                    <span class="w-12 shrink-0 rounded px-1.5 py-0.5 text-xs font-mono font-semibold text-center" :class="typeClass(rec.type)">
                        {{ rec.type }}
                    </span>
                    <span class="w-40 shrink-0 font-mono text-xs text-gray-300 truncate">{{ rec.name }}</span>
                    <span class="text-xs text-gray-500 shrink-0">{{ rec.ttl }}s</span>
                    <span class="flex-1 font-mono text-xs text-gray-400 truncate">{{ rec.value }}</span>
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

        <!-- NS hint -->
        <div class="rounded-xl border border-gray-700/50 bg-gray-800/40 p-4">
            <p class="text-xs text-gray-500">
                Nameserver for this zone:
                <span class="font-mono text-gray-300">{{ node?.hostname }}</span>
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
const recForm = ref({ name: '', type: 'A', ttl: 300, value: '' });
const recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

function submitRecord() {
    router.post(route('admin.dns.server.records.store', props.zone.id), recForm.value, {
        onSuccess: () => { recForm.value = { name: '', type: 'A', ttl: 300, value: '' }; showAdd.value = false; },
    });
}

function typeClass(type) {
    const map = {
        A: 'bg-blue-900/50 text-blue-300', AAAA: 'bg-blue-900/50 text-blue-300',
        CNAME: 'bg-purple-900/50 text-purple-300', MX: 'bg-amber-900/50 text-amber-300',
        TXT: 'bg-emerald-900/50 text-emerald-300', SRV: 'bg-indigo-900/50 text-indigo-300',
        CAA: 'bg-rose-900/50 text-rose-300', NS: 'bg-gray-700 text-gray-300',
    };
    return map[type] ?? 'bg-gray-700 text-gray-300';
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100
           placeholder-gray-500 focus:border-indigo-500 focus:outline-none;
}
</style>
