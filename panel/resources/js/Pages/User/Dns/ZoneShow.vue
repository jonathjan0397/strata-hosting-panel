<template>
    <AppLayout :title="`DNS — ${domain.domain}`">
        <div class="space-y-6 p-6">
        <PageHeader
            eyebrow="DNS"
            :title="domain.domain"
            description="Manage DNS records and import BIND zone data for this domain."
        >
            <template #actions>
                <Link :href="route('my.domains.show', domain.id)" class="text-sm font-medium text-indigo-400 transition-colors hover:text-indigo-300">
                    Back to Domain
                </Link>
            </template>
        </PageHeader>

        <!-- Header -->


        <!-- No zone -->
        <template v-if="!zone">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-8 text-center max-w-lg mx-auto">
                <h3 class="text-base font-semibold text-gray-200 mb-2">DNS zone not configured</h3>
                <p class="text-sm text-gray-400">Your administrator needs to provision a DNS zone for this domain.</p>
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
                            <input v-model="recForm.name" type="text" placeholder="Name (@, www…)" class="field col-span-2 font-mono text-xs" required />
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
                        <span class="w-12 shrink-0 rounded px-1.5 py-0.5 text-xs font-mono font-semibold text-center" :class="typeClass(rec.type)">{{ rec.type }}</span>
                        <span class="w-40 shrink-0 font-mono text-xs text-gray-300 truncate">{{ rec.name }}</span>
                        <span class="text-xs text-gray-500 shrink-0">{{ rec.ttl }}s</span>
                        <span class="flex-1 font-mono text-xs text-gray-400 truncate">{{ rec.value }}</span>
                        <span v-if="rec.managed" class="text-xs text-gray-600 shrink-0">managed</span>
                        <ConfirmButton
                            v-if="!rec.managed"
                            :href="route('my.dns.records.destroy', rec.id)"
                            method="delete"
                            label="Delete"
                            :confirm-message="`Delete ${rec.type} record for ${rec.name}?`"
                            color="red"
                        />
                    </div>
                    <div v-if="records.length === 0" class="px-5 py-6 text-center text-sm text-gray-500">
                        No records yet. Use the button above to add one.
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
                    <p class="text-xs text-gray-500 mb-3">Paste a BIND-format zone file. SOA and NS records are skipped. Existing records with the same name+type will be replaced.</p>
                    <form @submit.prevent="submitImport" class="space-y-3">
                        <textarea v-model="importText" rows="10" placeholder="; Paste zone file here..." class="field font-mono text-xs" required></textarea>
                        <div v-if="$page.props.flash?.success" class="rounded-lg bg-emerald-900/30 border border-emerald-800 px-3 py-2 text-xs text-emerald-400">{{ $page.props.flash.success }}</div>
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors">Import Records</button>
                    </form>
                </div>
            </div>
        </template>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import PageHeader from '@/Components/PageHeader.vue';

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
    router.post(route('my.dns.import', props.domain.id), { zone_text: importText.value }, {
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
    router.post(route('my.dns.records.store', props.zone.id), recForm.value, {
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
