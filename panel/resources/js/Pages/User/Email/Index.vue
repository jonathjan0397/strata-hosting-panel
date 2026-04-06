<template>
    <AppLayout :title="`Email - ${domain.domain}`">
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('my.domains.show', domain.id)" class="text-gray-500 transition-colors hover:text-gray-300">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </Link>
            <div>
                <h2 class="text-base font-semibold text-gray-100">Email - {{ domain.domain }}</h2>
            </div>
            <Link :href="route('my.email.spam')" class="ml-auto text-sm text-indigo-400 transition-colors hover:text-indigo-300">
                Spam Overview
            </Link>
        </div>

        <div v-if="!domain.mail_enabled" class="mb-6 rounded-xl border border-amber-700 bg-amber-900/20 px-4 py-3 text-sm text-amber-300">
            Mail is not enabled for this domain. Contact your administrator to enable it.
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-300">Mailboxes ({{ mailboxes.length }})</h3>
                </div>

                <div v-if="domain.mail_enabled" class="mb-4 rounded-xl border border-gray-800 bg-gray-900 p-4">
                    <p class="mb-3 text-xs font-medium text-gray-400">New mailbox</p>
                    <form @submit.prevent="submitMailbox" class="space-y-3">
                        <div class="flex gap-2">
                            <input v-model="mbForm.local_part" type="text" placeholder="user" class="flex-1 rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none" />
                            <span class="flex items-center text-sm text-gray-500">@{{ domain.domain }}</span>
                        </div>
                        <p v-if="mbForm.errors.local_part" class="text-xs text-red-400">{{ mbForm.errors.local_part }}</p>
                        <input v-model="mbForm.password" type="password" placeholder="Password (min. 8 chars)" class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none" />
                        <p v-if="mbForm.errors.password" class="text-xs text-red-400">{{ mbForm.errors.password }}</p>
                        <button type="submit" :disabled="mbForm.processing" class="w-full rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500 disabled:opacity-50">
                            Create Mailbox
                        </button>
                    </form>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                    <table class="min-w-full divide-y divide-gray-800">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Email</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="mb in mailboxes" :key="mb.id" class="hover:bg-gray-800/40">
                                <td class="px-4 py-3 text-sm font-mono text-gray-200">{{ mb.email }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-4">
                                        <a href="/webmail/" class="text-xs text-indigo-400 transition-colors hover:text-indigo-300">Open Webmail -></a>
                                        <Link :href="route('my.email.filters.index', mb.id)" class="text-xs text-gray-400 transition-colors hover:text-gray-200">Filters</Link>
                                        <ConfirmButton
                                            :href="route('my.email.mailbox.destroy', mb.id)"
                                            method="delete"
                                            label="Delete"
                                            color="red"
                                            :confirm-message="`Delete mailbox ${mb.email}?`"
                                        />
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="mailboxes.length === 0">
                                <td colspan="2" class="px-4 py-6 text-center text-sm text-gray-500">No mailboxes.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <div class="mb-3">
                    <h3 class="text-sm font-semibold text-gray-300">Forwarders ({{ forwarders.length }})</h3>
                </div>

                <div v-if="domain.mail_enabled" class="mb-4 rounded-xl border border-gray-800 bg-gray-900 p-4">
                    <p class="mb-3 text-xs font-medium text-gray-400">New forwarder</p>
                    <form @submit.prevent="submitForwarder" class="space-y-3">
                        <input v-model="fwdForm.source" type="email" :placeholder="`from@${domain.domain}`" class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none" />
                        <p v-if="fwdForm.errors.source" class="text-xs text-red-400">{{ fwdForm.errors.source }}</p>
                        <input v-model="fwdForm.destination" type="email" placeholder="to@example.com" class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none" />
                        <p v-if="fwdForm.errors.destination" class="text-xs text-red-400">{{ fwdForm.errors.destination }}</p>
                        <button type="submit" :disabled="fwdForm.processing" class="w-full rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500 disabled:opacity-50">
                            Create Forwarder
                        </button>
                    </form>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
                    <table class="min-w-full divide-y divide-gray-800">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Source -> Destination</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="fwd in forwarders" :key="fwd.id" class="hover:bg-gray-800/40">
                                <td class="px-4 py-3 text-sm text-gray-300">
                                    <span class="font-mono text-gray-200">{{ fwd.source }}</span>
                                    <span class="mx-2 text-gray-600">-></span>
                                    <span class="font-mono text-gray-400">{{ fwd.destination }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <ConfirmButton
                                        :href="route('my.email.forwarder.destroy', fwd.id)"
                                        method="delete"
                                        label="Delete"
                                        color="red"
                                        :confirm-message="`Delete forwarder ${fwd.source}?`"
                                    />
                                </td>
                            </tr>
                            <tr v-if="forwarders.length === 0">
                                <td colspan="2" class="px-4 py-6 text-center text-sm text-gray-500">No forwarders.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({
    domain: Object,
    mailboxes: Array,
    forwarders: Array,
});

const mbForm = useForm({ local_part: '', password: '' });
const fwdForm = useForm({ source: '', destination: '' });

function submitMailbox() {
    mbForm.post(route('my.email.mailbox.store', props.domain.id), {
        onSuccess: () => mbForm.reset(),
    });
}

function submitForwarder() {
    fwdForm.post(route('my.email.forwarder.store', props.domain.id), {
        onSuccess: () => fwdForm.reset(),
    });
}
</script>
