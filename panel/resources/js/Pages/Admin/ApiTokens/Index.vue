<template>
    <AppLayout title="API Tokens">
        <div class="max-w-2xl space-y-6">

            <!-- New token banner -->
            <div
                v-if="new_token"
                class="rounded-xl border border-emerald-700/60 bg-emerald-900/20 p-4 space-y-2"
            >
                <p class="text-sm font-semibold text-emerald-300">Token created — copy it now, it won't be shown again.</p>
                <div class="flex items-center gap-2">
                    <code class="flex-1 rounded bg-gray-900 px-3 py-2 text-xs font-mono text-emerald-200 break-all select-all">{{ new_token }}</code>
                    <button @click="copy(new_token)" class="shrink-0 text-xs text-gray-400 hover:text-gray-200 transition-colors">Copy</button>
                </div>
            </div>

            <!-- Create token form -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h2 class="text-sm font-semibold text-gray-300 mb-4">Create API Token</h2>
                <form @submit.prevent="create" class="flex items-end gap-3">
                    <div class="flex-1">
                        <label class="block text-xs font-medium text-gray-400 mb-1.5">Token Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="e.g. Strata Billing, WHMCS"
                            class="field w-full"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-400">{{ form.errors.name }}</p>
                    </div>
                    <button type="submit" :disabled="form.processing" class="btn-primary shrink-0">
                        Create Token
                    </button>
                </form>
                <p class="mt-3 text-xs text-gray-500">
                    Tokens carry provisioning abilities: create, suspend, unsuspend, terminate, usage, and catalog read.
                </p>
            </div>

            <!-- Token list -->
            <div class="rounded-xl border border-gray-800 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-800 bg-gray-900/60">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Last Used</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-400 uppercase">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <tr v-if="tokens.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">No API tokens created yet.</td>
                        </tr>
                        <tr v-for="t in tokens" :key="t.id" class="hover:bg-gray-900/40">
                            <td class="px-4 py-3 font-medium text-gray-100">{{ t.name }}</td>
                            <td class="px-4 py-3 text-gray-400 text-xs">{{ t.last_used_at ? formatDate(t.last_used_at) : 'Never' }}</td>
                            <td class="px-4 py-3 text-gray-400 text-xs">{{ formatDate(t.created_at) }}</td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    @click="revoke(t)"
                                    class="text-xs text-red-400 hover:text-red-300 transition-colors"
                                >Revoke</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- API reference -->
            <div class="rounded-xl border border-gray-800 bg-gray-900/40 p-4 space-y-2">
                <p class="text-xs font-semibold text-gray-400">API Endpoint Reference</p>
                <div class="space-y-1 font-mono text-xs text-gray-500">
                    <p><span class="text-emerald-400">POST</span>   /api/v1/accounts — provision account</p>
                    <p><span class="text-yellow-400">POST</span>   /api/v1/accounts/{id}/suspend</p>
                    <p><span class="text-emerald-400">POST</span>   /api/v1/accounts/{id}/unsuspend</p>
                    <p><span class="text-red-400">DELETE</span> /api/v1/accounts/{id} — terminate</p>
                    <p><span class="text-indigo-400">GET</span>    /api/v1/accounts/{id}/usage</p>
                    <p><span class="text-indigo-400">GET</span>    /api/v1/packages - active package catalog</p>
                    <p><span class="text-indigo-400">GET</span>    /api/v1/feature-lists - feature-list catalog</p>
                </div>
                <p class="text-xs text-gray-600 pt-1">Send token as <code class="text-gray-500">Authorization: Bearer {token}</code></p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    tokens:    Array,
    new_token: { type: String, default: null },
});

const form = useForm({ name: '' });

function create() {
    form.post(route('admin.api-tokens.store'), {
        onSuccess: () => form.reset(),
    });
}

function revoke(token) {
    if (confirm(`Revoke token "${token.name}"?`)) {
        router.delete(route('admin.api-tokens.destroy', token.id));
    }
}

function copy(text) {
    navigator.clipboard.writeText(text).catch(() => {});
}

function formatDate(iso) {
    return new Date(iso).toLocaleString();
}
</script>

<style scoped>
@reference "tailwindcss";
.field      { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
.btn-primary { @apply rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors; }
</style>
