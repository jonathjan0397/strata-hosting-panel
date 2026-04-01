<template>
    <AppLayout :title="`Email — ${domain.domain}`">

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
                        <span v-if="domain.mail_enabled" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-900/40 px-2.5 py-0.5 text-xs text-emerald-400">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>Mail active
                        </span>
                        <span v-else class="rounded-full bg-gray-800 px-2.5 py-0.5 text-xs text-gray-500">Mail disabled</span>
                    </div>
                    <p class="text-sm text-gray-400">{{ domain.account?.username }} · {{ domain.node?.name }}</p>
                </div>
            </div>
        </div>

        <!-- Mail not enabled — enable prompt -->
        <template v-if="!domain.mail_enabled">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-8 text-center max-w-lg mx-auto">
                <div class="h-12 w-12 rounded-full bg-indigo-900/50 flex items-center justify-center mx-auto mb-4">
                    <svg class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold text-gray-100 mb-2">Email not configured</h3>
                <p class="text-sm text-gray-400 mb-5">
                    Enabling mail will provision Postfix, Dovecot, and generate a 2048-bit DKIM key.
                    You'll receive DNS records to add after setup.
                </p>
                <Link
                    :href="route('admin.email.enable', domain.id)"
                    method="post"
                    as="button"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 transition-colors"
                >
                    Enable Mail for {{ domain.domain }}
                </Link>
            </div>
        </template>

        <template v-else>
            <!-- DNS Records panel -->
            <div v-if="domain.dkim_dns_record" class="mb-6 rounded-xl border border-amber-800/50 bg-amber-900/10 p-5">
                <h3 class="text-sm font-semibold text-amber-300 mb-3 flex items-center gap-2">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    Add these DNS records
                </h3>
                <div class="space-y-3">
                    <DnsRecordRow label="DKIM" type="TXT" :host="`default._domainkey`" :value="domain.dkim_dns_record" />
                    <DnsRecordRow label="SPF" type="TXT" host="@" :value="domain.spf_dns_record" />
                    <DnsRecordRow label="DMARC" type="TXT" host="_dmarc" :value="domain.dmarc_dns_record" />
                    <DnsRecordRow label="MX" type="MX" host="@" :value="domain.node?.hostname" extra="Priority 10" />
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-2">
                <!-- Mailboxes -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                        <h3 class="text-sm font-semibold text-gray-200">
                            Mailboxes
                            <span class="ml-2 text-xs font-normal text-gray-500">{{ mailboxes.length }}</span>
                        </h3>
                        <button @click="showAddMailbox = !showAddMailbox" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                            + Add
                        </button>
                    </div>

                    <!-- Add mailbox form -->
                    <div v-if="showAddMailbox" class="border-b border-gray-800 px-5 py-4 bg-gray-800/30">
                        <form @submit.prevent="submitMailbox" class="space-y-3">
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="mboxForm.local_part"
                                    type="text"
                                    placeholder="username"
                                    class="field flex-1 font-mono"
                                    required
                                />
                                <span class="text-sm text-gray-500 font-mono">@{{ domain.domain }}</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <input v-model="mboxForm.password" type="password" placeholder="Password (min 8)" class="field" required />
                                <input v-model.number="mboxForm.quota_mb" type="number" min="0" placeholder="Quota MB (0=unlimited)" class="field" />
                            </div>
                            <p v-if="mboxErrors.local_part" class="text-xs text-red-400">{{ mboxErrors.local_part }}</p>
                            <div class="flex gap-2">
                                <button type="submit" :disabled="mboxForm.processing" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors">
                                    Create Mailbox
                                </button>
                                <button type="button" @click="showAddMailbox = false" class="text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <!-- Mailbox list -->
                    <div class="divide-y divide-gray-800">
                        <div v-for="mbox in mailboxes" :key="mbox.id" class="flex items-center justify-between px-5 py-3">
                            <div>
                                <p class="text-sm font-mono text-gray-100">{{ mbox.email }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ mbox.quota_mb > 0 ? `${mbox.used_mb} / ${mbox.quota_mb} MB` : 'Unlimited' }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    @click="openPasswordModal(mbox)"
                                    class="text-xs text-gray-500 hover:text-gray-300 transition-colors"
                                    title="Change password"
                                >
                                    Password
                                </button>
                                <ConfirmButton
                                    :href="route('admin.email.mailbox.destroy', mbox.id)"
                                    method="delete"
                                    label="Delete"
                                    :confirm-message="`Permanently delete ${mbox.email} and all its mail?`"
                                    color="red"
                                />
                            </div>
                        </div>
                        <div v-if="mailboxes.length === 0" class="px-5 py-6 text-center text-sm text-gray-500">
                            No mailboxes yet.
                        </div>
                    </div>
                </div>

                <!-- Forwarders -->
                <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                        <h3 class="text-sm font-semibold text-gray-200">
                            Forwarders
                            <span class="ml-2 text-xs font-normal text-gray-500">{{ forwarders.length }}</span>
                        </h3>
                        <button @click="showAddForwarder = !showAddForwarder" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                            + Add
                        </button>
                    </div>

                    <!-- Add forwarder form -->
                    <div v-if="showAddForwarder" class="border-b border-gray-800 px-5 py-4 bg-gray-800/30">
                        <form @submit.prevent="submitForwarder" class="space-y-3">
                            <div class="grid grid-cols-2 gap-2 items-center">
                                <div class="flex items-center gap-1.5">
                                    <input v-model="fwdForm.local_part" type="text" placeholder="from" class="field flex-1 font-mono text-xs" />
                                    <span class="text-xs text-gray-500 font-mono whitespace-nowrap">@{{ domain.domain }}</span>
                                </div>
                                <input v-model="fwdForm.destination" type="email" placeholder="to@example.com" class="field font-mono text-xs" />
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" :disabled="fwdForm.processing" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors">
                                    Add Forwarder
                                </button>
                                <button type="button" @click="showAddForwarder = false" class="text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <!-- Forwarder list -->
                    <div class="divide-y divide-gray-800">
                        <div v-for="fwd in forwarders" :key="fwd.id" class="flex items-center justify-between px-5 py-3">
                            <div class="flex items-center gap-2 text-sm font-mono min-w-0">
                                <span class="text-gray-100 truncate">{{ fwd.source }}</span>
                                <svg class="h-3.5 w-3.5 text-gray-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                </svg>
                                <span class="text-gray-400 truncate">{{ fwd.destination }}</span>
                            </div>
                            <ConfirmButton
                                :href="route('admin.email.forwarder.destroy', fwd.id)"
                                method="delete"
                                label="Delete"
                                :confirm-message="`Delete forwarder ${fwd.source} → ${fwd.destination}?`"
                                color="red"
                            />
                        </div>
                        <div v-if="forwarders.length === 0" class="px-5 py-6 text-center text-sm text-gray-500">
                            No forwarders yet.
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Change password modal -->
        <Teleport to="body">
            <div
                v-if="pwdModal.show"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
                @click.self="pwdModal.show = false"
            >
                <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                    <h3 class="text-base font-semibold text-gray-100 mb-1">Change Password</h3>
                    <p class="text-sm text-gray-400 mb-4 font-mono">{{ pwdModal.email }}</p>
                    <form @submit.prevent="submitPassword">
                        <input
                            v-model="pwdModal.password"
                            type="password"
                            placeholder="New password (min 8)"
                            class="block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none mb-4"
                            required
                        />
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="pwdModal.show = false" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 hover:bg-gray-800 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" :disabled="pwdModal.busy" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors">
                                Update
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import DnsRecordRow from '@/Components/DnsRecordRow.vue';

const props = defineProps({
    domain:     Object,
    mailboxes:  Array,
    forwarders: Array,
});

// ── Mailbox form ──────────────────────────────────────────────────────────────
const showAddMailbox = ref(false);
const mboxForm = useForm({ local_part: '', password: '', quota_mb: 0 });
const mboxErrors = ref({});

function submitMailbox() {
    mboxForm.post(route('admin.email.mailbox.store', props.domain.id), {
        onSuccess: () => {
            mboxForm.reset();
            showAddMailbox.value = false;
        },
        onError: (errs) => { mboxErrors.value = errs; },
    });
}

// ── Forwarder form ────────────────────────────────────────────────────────────
const showAddForwarder = ref(false);
const fwdForm = useForm({ local_part: '', destination: '' });

function submitForwarder() {
    const payload = {
        source:      `${fwdForm.local_part}@${props.domain.domain}`,
        destination: fwdForm.destination,
    };
    router.post(route('admin.email.forwarder.store', props.domain.id), payload, {
        onSuccess: () => {
            fwdForm.reset();
            showAddForwarder.value = false;
        },
    });
}

// ── Password modal ────────────────────────────────────────────────────────────
const pwdModal = reactive({ show: false, email: '', mailboxId: null, password: '', busy: false });

function openPasswordModal(mbox) {
    pwdModal.email      = mbox.email;
    pwdModal.mailboxId  = mbox.id;
    pwdModal.password   = '';
    pwdModal.show       = true;
}

function submitPassword() {
    pwdModal.busy = true;
    router.put(route('admin.email.mailbox.password', pwdModal.mailboxId), {
        password: pwdModal.password,
    }, {
        onFinish: () => {
            pwdModal.busy = false;
            pwdModal.show = false;
        },
    });
}
</script>

<style scoped>
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none;
}
</style>
