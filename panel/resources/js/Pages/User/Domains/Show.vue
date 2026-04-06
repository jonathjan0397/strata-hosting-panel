<template>
    <AppLayout :title="domain.domain">
        <!-- Header -->
        <div class="mb-6 flex items-center gap-3">
            <Link :href="route('my.domains.index')" class="text-gray-500 hover:text-gray-300 transition-colors">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </Link>
            <div>
                <h2 class="text-lg font-semibold font-mono text-gray-100">{{ domain.domain }}</h2>
                <p class="text-sm text-gray-400 capitalize">{{ domain.type }} · PHP {{ domain.php_version }}</p>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-3">
            <!-- Domain info -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">Details</h3>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Document root</dt>
                        <dd class="font-mono text-xs text-gray-400">{{ domain.document_root }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Web server</dt>
                        <dd class="text-gray-200 capitalize">{{ domain.web_server }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">SSL</dt>
                        <dd>
                            <span v-if="domain.ssl_enabled" class="text-xs text-emerald-400">Active
                                <span v-if="domain.ssl_provider" class="text-gray-500">({{ domain.ssl_provider }})</span>
                            </span>
                            <span v-else class="text-xs text-gray-500">Not configured</span>
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- SSL -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">SSL Certificate</h3>

                <!-- Active cert -->
                <div v-if="domain.ssl_enabled" class="mb-4">
                    <span class="inline-flex items-center gap-1.5 text-sm text-emerald-400">
                        <span class="h-2 w-2 rounded-full bg-emerald-400"></span> Certificate active
                    </span>
                    <p v-if="domain.ssl_expires_at" class="mt-1 text-xs text-gray-500">
                        Expires {{ new Date(domain.ssl_expires_at).toLocaleDateString() }}
                    </p>
                </div>

                <!-- Let's Encrypt -->
                <div v-if="!domain.ssl_enabled" class="mb-4">
                    <p class="text-sm text-gray-400 mb-3">Issue a free Let's Encrypt certificate.</p>
                    <ConfirmButton
                        :href="route('my.domains.ssl', domain.id)"
                        method="post"
                        label="Issue SSL"
                        color="indigo"
                        :confirm-message="`Issue SSL certificate for ${domain.domain}?`"
                    />
                </div>

                <!-- Custom cert upload -->
                <div class="mt-4 border-t border-gray-800 pt-4">
                    <button @click="showCertUpload = !showCertUpload" class="text-xs text-gray-500 hover:text-gray-300 transition-colors">
                        {{ showCertUpload ? 'Cancel' : '↑ Upload custom certificate' }}
                    </button>
                    <form v-if="showCertUpload" @submit.prevent="certForm.post(route('my.domains.ssl.custom', domain.id))" class="mt-3 space-y-3">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Certificate PEM (fullchain)</label>
                            <textarea v-model="certForm.cert_pem" rows="4" placeholder="-----BEGIN CERTIFICATE-----" class="field w-full font-mono text-xs" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Private Key PEM</label>
                            <textarea v-model="certForm.key_pem" rows="4" placeholder="-----BEGIN PRIVATE KEY-----" class="field w-full font-mono text-xs" />
                        </div>
                        <button type="submit" :disabled="certForm.processing" class="btn-primary text-xs">
                            {{ certForm.processing ? 'Uploading…' : 'Upload Certificate' }}
                        </button>
                        <p v-if="certForm.errors.cert_pem || certForm.errors.key_pem" class="text-xs text-red-400">
                            {{ certForm.errors.cert_pem || certForm.errors.key_pem }}
                        </p>
                    </form>
                </div>
            </div>

            <!-- PHP version -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">PHP Version</h3>
                <form @submit.prevent="submitPhp" class="space-y-3">
                    <select
                        v-model="phpForm.php_version"
                        class="w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none"
                    >
                        <option v-for="v in phpVersions" :key="v" :value="v">PHP {{ v }}</option>
                    </select>
                    <button
                        type="submit"
                        :disabled="phpForm.processing || phpForm.php_version === domain.php_version"
                        class="w-full rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50 transition-colors"
                    >Update PHP</button>
                </form>
            </div>
        </div>

        <!-- Quick links -->
        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <Link :href="route('my.email.domain', domain.id)"
                class="flex items-center gap-3 rounded-xl border border-gray-800 bg-gray-900 px-4 py-4 text-sm text-gray-300 hover:bg-gray-800 transition-colors">
                <svg class="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                </svg>
                Email Accounts
            </Link>
            <Link :href="route('my.dns.show', domain.id)"
                class="flex items-center gap-3 rounded-xl border border-gray-800 bg-gray-900 px-4 py-4 text-sm text-gray-300 hover:bg-gray-800 transition-colors">
                <svg class="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 8.25h15m-16.5 7.5h15m-1.8-13.5-3.9 19.5m-2.1-19.5-3.9 19.5" />
                </svg>
                DNS Records
            </Link>
            <Link :href="route('my.databases.index')"
                class="flex items-center gap-3 rounded-xl border border-gray-800 bg-gray-900 px-4 py-4 text-sm text-gray-300 hover:bg-gray-800 transition-colors">
                <svg class="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                </svg>
                Databases
            </Link>
        </div>

        <!-- Redirects -->
        <div class="mt-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <h3 class="text-sm font-semibold text-gray-300 mb-4">Redirects (301/302)</h3>

            <!-- Existing redirects -->
            <div v-if="domain.redirects && domain.redirects.length" class="mb-4 space-y-2">
                <div v-for="(r, i) in domain.redirects" :key="i"
                    class="flex items-center justify-between rounded-lg bg-gray-800 px-3 py-2 text-sm">
                    <div class="font-mono text-xs">
                        <span class="text-gray-400">{{ r.source }}</span>
                        <span class="mx-2 text-gray-600">→</span>
                        <span class="text-indigo-300">{{ r.destination }}</span>
                        <span class="ml-2 text-yellow-400">{{ r.type }}</span>
                    </div>
                    <button @click="deleteRedirect(i)" class="text-xs text-red-500 hover:text-red-400 transition-colors">Remove</button>
                </div>
            </div>
            <p v-else class="mb-4 text-xs text-gray-500">No redirects configured.</p>

            <!-- Add redirect form -->
            <form @submit.prevent="redirectForm.post(route('my.domains.redirects.store', domain.id))" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Source path</label>
                    <input v-model="redirectForm.source" type="text" placeholder="/old-path" class="field w-40" />
                    <p v-if="redirectForm.errors.source" class="mt-0.5 text-xs text-red-400">{{ redirectForm.errors.source }}</p>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Destination URL</label>
                    <input v-model="redirectForm.destination" type="text" placeholder="https://example.com/new" class="field w-64" />
                    <p v-if="redirectForm.errors.destination" class="mt-0.5 text-xs text-red-400">{{ redirectForm.errors.destination }}</p>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Type</label>
                    <select v-model="redirectForm.type" class="field">
                        <option value="301">301 Permanent</option>
                        <option value="302">302 Temporary</option>
                    </select>
                </div>
                <button type="submit" :disabled="redirectForm.processing" class="btn-primary">
                    {{ redirectForm.processing ? 'Adding…' : 'Add Redirect' }}
                </button>
            </form>
        </div>

        <div v-if="canManagePrivacy" class="mt-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <h3 class="text-sm font-semibold text-gray-300 mb-1">Directory Privacy</h3>
            <p class="mb-4 text-xs text-gray-500">
                Protect a directory with HTTP basic authentication. Passwords are stored as hashes and applied during vhost reprovisioning.
            </p>

            <div v-if="domain.directory_privacy && domain.directory_privacy.length" class="mb-4 space-y-2">
                <div
                    v-for="(rule, index) in domain.directory_privacy"
                    :key="`${rule.path}-${index}`"
                    class="flex items-center justify-between rounded-lg bg-gray-800 px-3 py-2 text-sm"
                >
                    <div>
                        <p class="font-mono text-xs text-gray-200">{{ rule.path }}</p>
                        <p class="mt-1 text-xs text-gray-500">User {{ rule.username }}</p>
                    </div>
                    <button @click="deletePrivacy(index)" class="text-xs text-red-500 hover:text-red-400 transition-colors">Remove</button>
                </div>
            </div>
            <p v-else class="mb-4 text-xs text-gray-500">No protected directories configured.</p>

            <form @submit.prevent="privacyForm.post(route('my.domains.privacy.store', domain.id))" class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Directory path</label>
                    <input v-model="privacyForm.path" type="text" placeholder="/members" class="field w-44" />
                    <p v-if="privacyForm.errors.path" class="mt-0.5 text-xs text-red-400">{{ privacyForm.errors.path }}</p>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Username</label>
                    <input v-model="privacyForm.username" type="text" placeholder="member" class="field w-40" />
                    <p v-if="privacyForm.errors.username" class="mt-0.5 text-xs text-red-400">{{ privacyForm.errors.username }}</p>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">Password</label>
                    <input v-model="privacyForm.password" type="password" placeholder="Minimum 8 characters" class="field w-56" />
                    <p v-if="privacyForm.errors.password" class="mt-0.5 text-xs text-red-400">{{ privacyForm.errors.password }}</p>
                </div>
                <button type="submit" :disabled="privacyForm.processing" class="btn-primary">
                    {{ privacyForm.processing ? 'Protecting...' : 'Protect Directory' }}
                </button>
            </form>
        </div>

        <!-- Custom Nginx/Apache Directives -->
        <div class="mt-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <h3 class="text-sm font-semibold text-gray-300 mb-1">Custom Directives</h3>
            <p class="mb-4 text-xs text-gray-500">
                Raw {{ domain.web_server === 'apache' ? 'Apache' : 'Nginx' }} directives injected into the vhost server block. Use with care.
            </p>
            <form @submit.prevent="directivesForm.put(route('my.domains.directives', domain.id))">
                <textarea
                    v-model="directivesForm.custom_directives"
                    rows="6"
                    placeholder="# e.g. add_header X-Custom-Header &quot;value&quot;;"
                    class="field w-full font-mono text-xs"
                ></textarea>
                <div class="mt-3 flex justify-end">
                    <button type="submit" :disabled="directivesForm.processing" class="btn-primary">
                        {{ directivesForm.processing ? 'Saving…' : 'Save & Apply' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({
    domain:      Object,
    phpVersions: Array,
    canManagePrivacy: Boolean,
});

const showCertUpload = ref(false);

const phpForm = useForm({
    php_version: props.domain.php_version,
});

const certForm = useForm({
    cert_pem: '',
    key_pem:  '',
});

const redirectForm = useForm({
    source:      '',
    destination: '',
    type:        '301',
});

const privacyForm = useForm({
    path: '',
    username: '',
    password: '',
});

const directivesForm = useForm({
    custom_directives: props.domain.custom_directives ?? '',
});

function submitPhp() {
    phpForm.put(route('my.domains.php', props.domain.id));
}

function deleteRedirect(index) {
    if (!confirm('Remove this redirect?')) return;
    router.delete(route('my.domains.redirects.destroy', [props.domain.id, index]));
}

function deletePrivacy(index) {
    if (!confirm('Remove directory privacy from this path?')) return;
    router.delete(route('my.domains.privacy.destroy', [props.domain.id, index]));
}
</script>

<style scoped>
@reference "tailwindcss";
.field    { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
.btn-primary { @apply rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors; }
</style>
