<template>
    <AppLayout :title="`FTP - ${account.username}`">
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('admin.accounts.show', account.id)" class="text-gray-500 transition-colors hover:text-gray-300">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </Link>
            <div>
                <h2 class="text-lg font-semibold text-gray-100">FTP Accounts</h2>
                <p class="font-mono text-sm text-gray-400">{{ account.username }}</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-800 px-5 py-3.5">
                <h3 class="text-sm font-semibold text-gray-200">
                    FTP Accounts
                    <span class="ml-2 text-xs font-normal text-gray-500">{{ ftpAccounts.length }}</span>
                </h3>
                <button @click="showCreate = !showCreate" class="text-xs text-indigo-400 transition-colors hover:text-indigo-300">
                    + Add
                </button>
            </div>

            <div v-if="showCreate" class="border-b border-gray-800 bg-gray-800/30 px-5 py-4">
                <form @submit.prevent="submitCreate" class="space-y-3">
                    <div class="grid grid-cols-3 gap-2">
                        <input v-model="form.username" type="text" placeholder="FTP username" class="field font-mono text-xs" required />
                        <input v-model="form.password" type="password" placeholder="Password (min 8)" class="field" required />
                        <input v-model.number="form.quota_mb" type="number" min="0" placeholder="Quota MB (0=unlimited)" class="field text-xs" />
                    </div>
                    <p class="text-xs text-gray-500">
                        Default access starts at <span class="font-mono">/var/www/{{ account.username }}</span> so FTP users can reach the account home and any custom web roots.
                    </p>
                    <p v-if="errors.username" class="text-xs text-red-400">{{ errors.username }}</p>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-indigo-500">
                            Create Account
                        </button>
                        <button type="button" @click="showCreate = false" class="text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="divide-y divide-gray-800">
                <div v-for="ftp in ftpAccounts" :key="ftp.id" class="flex items-center justify-between px-5 py-3">
                    <div>
                        <p class="text-sm font-mono text-gray-100">{{ ftp.username }}</p>
                        <p v-if="ftp.migration_reset_required" class="mt-1 w-fit rounded-full bg-amber-900/40 px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-wide text-amber-200">
                            Reset required after migration
                        </p>
                        <p class="text-xs text-gray-500">
                            {{ ftp.home_dir }}
                            <span v-if="ftp.quota_mb > 0"> | {{ ftp.quota_mb }} MB quota</span>
                            <span v-else> | Unlimited</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            @click="openPwdModal(ftp)"
                            class="text-xs text-gray-500 transition-colors hover:text-gray-300"
                        >
                            {{ ftp.migration_reset_required ? 'Set password' : 'Password' }}
                        </button>
                        <ConfirmButton
                            :href="route('admin.ftp.destroy', ftp.id)"
                            method="delete"
                            label="Delete"
                            :confirm-message="`Delete FTP account ${ftp.username}?`"
                            color="red"
                        />
                    </div>
                </div>
                <div v-if="ftpAccounts.length === 0" class="px-5 py-6 text-center text-sm text-gray-500">
                    No FTP accounts yet.
                </div>
            </div>
        </div>

        <div class="mt-4 rounded-xl border border-gray-700/50 bg-gray-800/40 p-4">
            <p class="text-xs text-gray-500">
                FTP host: <span class="font-mono text-gray-300">{{ account.node?.hostname ?? account.username }}</span>
                | Port: <span class="font-mono text-gray-300">21</span>
                | Protocol: <span class="font-mono text-gray-300">FTP+TLS</span>
            </p>
            <p class="mt-2 text-xs text-gray-500">
                New FTP accounts start at <span class="font-mono text-gray-300">/var/www/{{ account.username }}</span> so they can reach the full account home and custom web roots.
            </p>
        </div>

        <Teleport to="body">
            <div
                v-if="pwdModal.show"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
                @click.self="pwdModal.show = false"
            >
                <div class="w-full max-w-sm rounded-xl border border-gray-700 bg-gray-900 p-6 shadow-2xl">
                    <h3 class="mb-1 text-base font-semibold text-gray-100">Change FTP Password</h3>
                    <p class="mb-4 font-mono text-sm text-gray-400">{{ pwdModal.username }}</p>
                    <form @submit.prevent="submitPassword">
                        <input
                            v-model="pwdModal.password"
                            type="password"
                            placeholder="New password (min 8)"
                            class="mb-4 block w-full rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2.5 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                            required
                        />
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="pwdModal.show = false" class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-400 transition-colors hover:bg-gray-800">
                                Cancel
                            </button>
                            <button type="submit" :disabled="pwdModal.busy" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-indigo-500 disabled:opacity-50">
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
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({
    account: Object,
    ftpAccounts: Array,
});

const showCreate = ref(false);
const form = ref({ username: '', password: '', quota_mb: 0 });
const errors = ref({});

function submitCreate() {
    router.post(route('admin.accounts.ftp.store', props.account.id), form.value, {
        onSuccess: () => {
            form.value = { username: '', password: '', quota_mb: 0 };
            errors.value = {};
            showCreate.value = false;
        },
        onError: (errs) => {
            errors.value = errs;
        },
    });
}

const pwdModal = reactive({ show: false, ftpId: null, username: '', password: '', busy: false });

function openPwdModal(ftp) {
    pwdModal.ftpId = ftp.id;
    pwdModal.username = ftp.username;
    pwdModal.password = '';
    pwdModal.show = true;
}

function submitPassword() {
    pwdModal.busy = true;
    router.put(route('admin.ftp.password', pwdModal.ftpId), {
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
@reference "tailwindcss";
.field {
    @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none;
}
</style>
