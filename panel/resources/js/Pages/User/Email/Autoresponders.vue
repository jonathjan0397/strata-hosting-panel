<template>
    <AppLayout :title="`Autoresponders — ${domain.domain}`">
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('my.email.domain', domain.id)" class="text-gray-500 hover:text-gray-300 transition-colors">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </Link>
            <h2 class="text-lg font-semibold text-gray-100">Autoresponders — {{ domain.domain }}</h2>
        </div>

        <div v-if="$page.props.flash?.success" class="mb-4 rounded-lg bg-emerald-900/30 border border-emerald-800 px-4 py-3 text-sm text-emerald-400">{{ $page.props.flash.success }}</div>
        <div v-if="$page.props.flash?.error" class="mb-4 rounded-lg bg-red-900/30 border border-red-800 px-4 py-3 text-sm text-red-400">{{ $page.props.flash.error }}</div>

        <div class="space-y-3">
            <div v-for="mailbox in mailboxes" :key="mailbox.id" class="rounded-xl border border-gray-800 bg-gray-900">
                <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                    <div>
                        <p class="text-sm font-medium text-gray-200">{{ mailbox.email }}</p>
                        <p v-if="mailbox.autoresponder" class="text-xs text-emerald-400 mt-0.5">Autoresponder active</p>
                        <p v-else class="text-xs text-gray-500 mt-0.5">No autoresponder</p>
                    </div>
                    <button @click="toggleEdit(mailbox.id)" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
                        {{ editing === mailbox.id ? 'Cancel' : (mailbox.autoresponder ? 'Edit' : 'Set up') }}
                    </button>
                </div>

                <div v-if="editing === mailbox.id" class="px-5 py-4">
                    <form @submit.prevent="saveAutoresponder(mailbox)" class="space-y-3">
                        <input v-model="forms[mailbox.id].subject" type="text" placeholder="Subject (e.g. Out of Office)" class="field" required />
                        <textarea v-model="forms[mailbox.id].body" rows="4" placeholder="Auto-reply message…" class="field" required></textarea>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                                <input type="checkbox" v-model="forms[mailbox.id].active" class="rounded border-gray-600 bg-gray-800 text-indigo-600" />
                                Active
                            </label>
                            <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors">Save</button>
                            <ConfirmButton
                                v-if="mailbox.autoresponder"
                                :href="route('my.email.autoresponder.destroy', mailbox.id)"
                                method="delete"
                                label="Remove"
                                confirm-message="Remove autoresponder for this mailbox?"
                                color="red"
                            />
                        </div>
                    </form>
                </div>
            </div>

            <div v-if="mailboxes.length === 0" class="rounded-xl border border-gray-800 bg-gray-900 px-5 py-8 text-center text-sm text-gray-500">
                No mailboxes on this domain.
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({
    domain:    Object,
    mailboxes: Array,
});

const editing = ref(null);
const forms = reactive({});

props.mailboxes.forEach(m => {
    forms[m.id] = {
        subject: m.autoresponder?.subject ?? '',
        body:    m.autoresponder?.body ?? '',
        active:  m.autoresponder?.active ?? true,
    };
});

function toggleEdit(id) {
    editing.value = editing.value === id ? null : id;
}

function saveAutoresponder(mailbox) {
    router.post(route('my.email.autoresponder.store', mailbox.id), forms[mailbox.id], {
        onSuccess: () => { editing.value = null; },
    });
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none; }
</style>
