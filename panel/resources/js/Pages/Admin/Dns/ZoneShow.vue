<template>
    <AppLayout :title="`DNS — ${domain.domain}`">

        <!-- Header -->
        <div class="mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Link :href="route('admin.domains.show', domain.id)" class="text-gray-500 hover:text-gray-300 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                </Link>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold font-mono text-gray-100">{{ domain.domain }}</h2>
                        <span v-if="zone" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-900/40 px-2.5 py-0.5 text-xs text-emerald-400">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>Zone active
                        </span>
                        <span v-else class="rounded-full bg-gray-800 px-2.5 py-0.5 text-xs text-gray-500">No zone</span>
                    </div>
                    <p class="text-sm text-gray-400">{{ domain.account?.username }} · {{ domain.node?.name }}</p>
                </div>
            </div>
        </div>

        <!-- No zone — provision prompt -->
        <template v-if="!zone">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-8 text-center max-w-lg mx-auto">
                <div class="h-12 w-12 rounded-full bg-indigo-900/50 flex items-center justify-center mx-auto mb-4">
                    <svg class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-gray-100 mb-2">DNS not configured</h3>
                <p class="text-sm text-gray-400 mb-5">
                    Provisioning will create an authoritative PowerDNS zone with default A and CNAME records.
                </p>
                <Link
                    :href="route('admin.dns.provision', domain.id)"
                    method="post"
                    as="button"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 transition-colors"
                >
                    Create DNS Zone
                </Link>
            </div>
        </template>

        <template v-else>
            <!-- Records table -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden mb-6">
                <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-200">
                        DNS Records
                        <span class="ml-2 text-xs font-normal text-gray-500">{{ records.length }}</span>
                    </h3>
                    <button @click="showAddRecord = !showAddRecord" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                        + Add Record
                    </button>
                </div>

                <!-- Add record form -->
                <div v-if="showAddRecord" class="border-b border-gray-800 px-5 py-4 bg-gray-800/30">
                    <form @submit.prevent="submitRecord" class="space-y-3">
                        <div class="grid grid-cols-4 gap-2">
                            <input v-model="recForm.name" type="text" placeholder="Name (@, www, _dmarc…)" class="field col-span-2 font-mono text-xs" required />
                            <select v-model="recForm.type" class="field text-xs">
                                <option v-for="t in recordTypes" :key="t" :value="t">{{ t }}</option>
                            </select>
                            <input v-model.number="recForm.ttl" type="number" min="60" max="86400" placeholder="TTL" class="field text-xs" />
                        </div>
                        <textarea
                            v-model="recForm.value"
                            rows="2"
                            placeholder="Record value"
                            class="field w-full font-mono text-xs"
                            required
                        ></textarea>
                        <div class="flex gap-2">
                            <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors">
                                Add Record
                            </button>
                            <button type="button" @click="showAddRecord = false" class="text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                        </div>
                    </form>
                </div>

                <!-- Records list -->
                <div class="divide-y divide-gray-800">
                    <div v-for="rec in records" :key="rec.id" class="flex items-center gap-3 px-5 py-3 group">
                        <span class="w-12 shrink-0 rounded px-1.5 py-0.5 text-xs font-mono font-semibold text-center"
                            :class="typeClass(rec.type)">{{ rec.type }}</span>
                        <span class="w-40 shrink-0 font-mono text-xs text-gray-300 truncate">{{ rec.name }}</span>
                        <span class="text-xs text-gray-500 shrink-0">{{ rec.ttl }}s</span>
                        <span class="flex-1 font-mono text-xs text-gray-400 truncate">{{ rec.value }}</span>
                        <span v-if="rec.managed" class="text-xs text-gray-600 shrink-0">managed</span>
                        <ConfirmButton
                            v-if="!rec.managed"
                            :href="route('admin.dns.records.destroy', rec.id)"
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

            <!-- Nameserver hint -->
            <div class="rounded-xl border border-gray-700/50 bg-gray-800/40 p-4">
                <p class="text-xs text-gray-500">
                    Point your domain's NS records to this server to use Strata DNS. Nameserver:
                    <span class="font-mono text-gray-300">{{ domain.node?.hostname }}</span>
                </p>
            </div>

            <!-- DNS Import -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden mt-4">
                <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                    <h3 class="text-sm font-semibold text-gray-200">Import Zone File</h3>
                    <button @click="showImport = !showImport" class="text-xs text-gray-400 hover:text-gray-300 transition-colors">{{ showImport ? 'Cancel' : 'Import BIND zone' }}</button>
                </div>
                <div v-if="showImport" class="px-5 py-4">
                    <p class="text-xs text-gray-500 mb-3">Paste a BIND-format zone file. SOA and NS records are skipped.</p>
                    <form @submit.prevent="submitImport" class="space-y-3">
                        <textarea v-model="importText" rows="10" placeholder="; Paste BIND zone file..." class="field font-mono text-xs" required></textarea>
                        <div v-if="$page.props.flash?.success" class="rounded-lg bg-emerald-900/30 border border-emerald-800 px-3 py-2 text-xs text-emerald-400">{{ $page.props.flash.success }}</div>
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors">Import Records</button>
                    </form>
                </div>
            </div>
        </template>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({
    domain:  Object,
    zone:    Object,
    records: Array,
});

const showAddRecord = ref(false);
const recForm = ref({ name: '', type: 'A', ttl: 300, value: '' });
const showImport = ref(false);
const importText = ref('');

function submitImport() {
    router.post(route('admin.dns.import', props.domain.id), { zone_text: importText.value }, {
        onSuccess: () => { importText.value = ''; showImport.value = false; },
    });
}

const recordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

function typeClass(type) {
    const map = {
        A:     'bg-blue-900/50 text-blue-300',
        AAAA:  'bg-blue-900/50 text-blue-300',
        CNAME: 'bg-purple-900/50 text-purple-300',
        MX:    'bg-amber-900/50 text-amber-300',
        TXT:   'bg-emerald-900/50 text-emerald-300',
        SRV:   'bg-indigo-900/50 text-indigo-300',
        CAA:   'bg-rose-900/50 text-rose-300',
        NS:    'bg-gray-700 text-gray-300',
    };
    return map[type] ?? 'bg-gray-700 text-gray-300';
}

function submitRecord() {
    router.post(route('admin.dns.records.store', props.zone.id), recForm.value, {
        onSuccess: () => {
            recForm.value = { name: '', type: 'A', ttl: 300, value: '' };
            showAddRecord.value = false;
        },
    });
}
</script>

<style scoped>
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none;
}
</style>
