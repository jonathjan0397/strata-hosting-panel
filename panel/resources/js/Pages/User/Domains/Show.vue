<template>
    <AppLayout :title="domain.domain">
        <!-- Header -->
        <div class="space-y-6 p-6">
        <PageHeader
            eyebrow="Websites"
            :title="domain.domain"
            :description="`${domain.type} - PHP ${domain.php_version}`"
        >
            <template #actions>
                <Link :href="route('my.domains.index')" class="text-sm font-medium text-indigo-400 transition-colors hover:text-indigo-300">
                    Back to Domains
                </Link>
            </template>
        </PageHeader>



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

                <form v-if="domain.ssl_enabled" @submit.prevent="submitForceHttps" class="mb-4 rounded-lg border border-gray-800 bg-gray-950 px-3 py-3">
                    <label class="flex items-start gap-3 text-sm text-gray-300">
                        <input v-model="forceHttpsForm.force_https" type="checkbox" class="mt-1 rounded border-gray-700 bg-gray-800 text-indigo-600" />
                        <span>
                            <span class="block font-semibold text-gray-200">Force HTTPS</span>
                            <span class="mt-1 block text-xs text-gray-500">Redirect all plain HTTP requests to the secure HTTPS version of this site.</span>
                        </span>
                    </label>
                    <button
                        type="submit"
                        :disabled="forceHttpsForm.processing || forceHttpsForm.force_https === domain.force_https"
                        class="mt-3 w-full rounded-lg border border-gray-700 px-3 py-2 text-xs font-semibold text-gray-200 hover:bg-gray-800 disabled:opacity-50"
                    >
                        {{ forceHttpsForm.processing ? 'Saving...' : 'Save HTTPS Redirect' }}
                    </button>
                    <p v-if="forceHttpsForm.errors.force_https" class="mt-2 text-xs text-red-400">{{ forceHttpsForm.errors.force_https }}</p>
                </form>

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

        <div v-if="canManageHotlinkProtection" class="mt-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-300 mb-1">Hotlink Protection</h3>
                    <p class="text-xs text-gray-500">
                        Block other sites from embedding your image and static asset files while allowing trusted referrers.
                    </p>
                </div>
                <span
                    :class="domain.hotlink_protection?.enabled ? 'border-emerald-700 bg-emerald-900/30 text-emerald-300' : 'border-gray-700 bg-gray-800 text-gray-400'"
                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                >
                    {{ domain.hotlink_protection?.enabled ? 'Enabled' : 'Disabled' }}
                </span>
            </div>

            <form @submit.prevent="hotlinkForm.post(route('my.domains.hotlink.update', domain.id))" class="grid gap-4 lg:grid-cols-3">
                <label class="flex items-center gap-2 rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 text-sm text-gray-300 lg:col-span-3">
                    <input v-model="hotlinkForm.allow_direct" type="checkbox" class="rounded border-gray-700 bg-gray-800 text-indigo-600" />
                    Allow direct browser access when no referrer is sent
                </label>

                <div class="lg:col-span-2">
                    <label class="mb-1 block text-xs text-gray-400">Additional allowed domains</label>
                    <textarea
                        v-model="hotlinkForm.allowed_domains"
                        rows="4"
                        placeholder="cdn.example.com&#10;partner.example.net"
                        class="field w-full font-mono text-xs"
                    />
                    <p class="mt-1 text-xs text-gray-500">The current domain is always allowed. Use one domain per line or comma-separated values.</p>
                    <p v-if="hotlinkForm.errors.allowed_domains" class="mt-0.5 text-xs text-red-400">{{ hotlinkForm.errors.allowed_domains }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-gray-400">Protected extensions</label>
                    <textarea
                        v-model="hotlinkForm.extensions"
                        rows="4"
                        placeholder="jpg, jpeg, png, gif, webp, svg, ico"
                        class="field w-full font-mono text-xs"
                    />
                    <p class="mt-1 text-xs text-gray-500">Leave blank for the default image/static set.</p>
                    <p v-if="hotlinkForm.errors.extensions" class="mt-0.5 text-xs text-red-400">{{ hotlinkForm.errors.extensions }}</p>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3 lg:col-span-3">
                    <button
                        v-if="domain.hotlink_protection?.enabled"
                        type="button"
                        @click="disableHotlink"
                        class="rounded-lg border border-red-800 px-4 py-2 text-sm font-semibold text-red-300 hover:bg-red-950/50"
                    >
                        Disable
                    </button>
                    <button type="submit" :disabled="hotlinkForm.processing" class="btn-primary">
                        {{ hotlinkForm.processing ? 'Saving...' : 'Save & Apply' }}
                    </button>
                </div>
            </form>
        </div>

        <div v-if="canManageModSecurity" class="mt-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-300 mb-1">ModSecurity WAF</h3>
                    <p class="text-xs text-gray-500">
                        Enable per-domain ModSecurity directives. The node must already have the appropriate Apache or Nginx ModSecurity module and rule set installed.
                    </p>
                </div>
                <span
                    :class="domain.modsecurity?.enabled ? 'border-emerald-700 bg-emerald-900/30 text-emerald-300' : 'border-gray-700 bg-gray-800 text-gray-400'"
                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                >
                    {{ domain.modsecurity?.enabled ? 'Enabled' : 'Disabled' }}
                </span>
            </div>

            <form @submit.prevent="modSecurityForm.put(route('my.domains.modsecurity.update', domain.id), { preserveScroll: true })" class="grid gap-4 lg:grid-cols-[1fr_1fr_auto]">
                <label class="flex items-center gap-2 rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 text-sm text-gray-300">
                    <input v-model="modSecurityForm.enabled" type="checkbox" class="rounded border-gray-700 bg-gray-800 text-indigo-600" />
                    Enable ModSecurity for this domain
                </label>
                <div>
                    <label class="mb-1 block text-xs text-gray-400">Rule engine mode</label>
                    <select v-model="modSecurityForm.mode" class="field w-full" :disabled="!modSecurityForm.enabled">
                        <option value="on">Block matching requests</option>
                        <option value="detection_only">Detection only</option>
                    </select>
                    <p v-if="modSecurityForm.errors.mode" class="mt-0.5 text-xs text-red-400">{{ modSecurityForm.errors.mode }}</p>
                </div>
                <div class="flex items-end">
                    <button type="submit" :disabled="modSecurityForm.processing" class="btn-primary w-full">
                        {{ modSecurityForm.processing ? 'Saving...' : 'Save WAF' }}
                    </button>
                </div>
            </form>
        </div>

        <div v-if="canManageLeechProtection" class="mt-6 rounded-xl border border-gray-800 bg-gray-900 p-5">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-300 mb-1">Leech Protection</h3>
                    <p class="text-xs text-gray-500">
                        Rate-limit repeated access to a protected path. Nginx uses per-IP request limiting; Apache emits block/redirect rules and should be paired with a WAF/proxy for precise throttling.
                    </p>
                </div>
                <span
                    :class="domain.leech_protection?.enabled ? 'border-emerald-700 bg-emerald-900/30 text-emerald-300' : 'border-gray-700 bg-gray-800 text-gray-400'"
                    class="rounded-full border px-2.5 py-1 text-xs font-semibold"
                >
                    {{ domain.leech_protection?.enabled ? 'Enabled' : 'Disabled' }}
                </span>
            </div>

            <form @submit.prevent="leechForm.put(route('my.domains.leech.update', domain.id), { preserveScroll: true })" class="grid gap-4 lg:grid-cols-4">
                <label class="flex items-center gap-2 rounded-lg border border-gray-800 bg-gray-950 px-3 py-2 text-sm text-gray-300 lg:col-span-4">
                    <input v-model="leechForm.enabled" type="checkbox" class="rounded border-gray-700 bg-gray-800 text-indigo-600" />
                    Enable leech protection for this domain
                </label>
                <div>
                    <label class="mb-1 block text-xs text-gray-400">Protected path</label>
                    <input v-model="leechForm.path" type="text" class="field w-full font-mono" placeholder="/members" :disabled="!leechForm.enabled" />
                    <p v-if="leechForm.errors.path" class="mt-0.5 text-xs text-red-400">{{ leechForm.errors.path }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-gray-400">Requests/minute</label>
                    <input v-model.number="leechForm.requests_per_minute" type="number" min="1" max="120" class="field w-full" :disabled="!leechForm.enabled" />
                    <p v-if="leechForm.errors.requests_per_minute" class="mt-0.5 text-xs text-red-400">{{ leechForm.errors.requests_per_minute }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-gray-400">Redirect URL</label>
                    <input v-model="leechForm.redirect_url" type="url" class="field w-full" placeholder="Optional" :disabled="!leechForm.enabled" />
                    <p v-if="leechForm.errors.redirect_url" class="mt-0.5 text-xs text-red-400">{{ leechForm.errors.redirect_url }}</p>
                </div>
                <div class="flex items-end">
                    <button type="submit" :disabled="leechForm.processing" class="btn-primary w-full">
                        {{ leechForm.processing ? 'Saving...' : 'Save Protection' }}
                    </button>
                </div>
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

        <div class="mt-6 rounded-xl border border-red-800/70 bg-red-950/30 p-5">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-red-200">Danger Zone</h3>
                    <p class="mt-1 max-w-3xl text-sm text-red-100/80">
                        Deleting this domain permanently removes the domain, managed DNS zone, vhost settings,
                        security settings, redirects, and any dedicated document-root files for addon/subdomain sites.
                        This cannot be undone.
                    </p>
                </div>
                <ConfirmButton
                    :href="route('my.domains.destroy', domain.id)"
                    method="delete"
                    label="Delete Domain"
                    color="red"
                    :confirm-message="`Permanently delete ${domain.domain}? This will remove the managed DNS zone, vhost settings, and dedicated files/settings for this domain. This cannot be undone.`"
                />
            </div>
        </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    domain:      Object,
    phpVersions: Array,
    canManagePrivacy: Boolean,
    canManageHotlinkProtection: Boolean,
    canManageModSecurity: Boolean,
    canManageLeechProtection: Boolean,
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

const forceHttpsForm = useForm({
    force_https: props.domain.force_https ?? false,
});

const privacyForm = useForm({
    path: '',
    username: '',
    password: '',
});

const hotlinkConfig = props.domain.hotlink_protection ?? {};
const hotlinkForm = useForm({
    allow_direct: hotlinkConfig.allow_direct ?? true,
    allowed_domains: (hotlinkConfig.allowed_domains ?? []).join('\n'),
    extensions: (hotlinkConfig.extensions ?? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico']).join(', '),
});

const modSecurityConfig = props.domain.modsecurity ?? {};
const modSecurityForm = useForm({
    enabled: modSecurityConfig.enabled ?? false,
    mode: modSecurityConfig.mode ?? 'on',
});

const leechConfig = props.domain.leech_protection ?? {};
const leechForm = useForm({
    enabled: leechConfig.enabled ?? false,
    path: leechConfig.path ?? '/members',
    requests_per_minute: leechConfig.requests_per_minute ?? 30,
    redirect_url: leechConfig.redirect_url ?? '',
});

const directivesForm = useForm({
    custom_directives: props.domain.custom_directives ?? '',
});

function submitPhp() {
    phpForm.put(route('my.domains.php', props.domain.id));
}

function submitForceHttps() {
    forceHttpsForm.put(route('my.domains.force-https', props.domain.id), {
        preserveScroll: true,
    });
}

function deleteRedirect(index) {
    if (!confirm('Remove this redirect?')) return;
    router.delete(route('my.domains.redirects.destroy', [props.domain.id, index]));
}

function deletePrivacy(index) {
    if (!confirm('Remove directory privacy from this path?')) return;
    router.delete(route('my.domains.privacy.destroy', [props.domain.id, index]));
}

function disableHotlink() {
    if (!confirm('Disable hotlink protection for this domain?')) return;
    router.delete(route('my.domains.hotlink.destroy', props.domain.id));
}
</script>

<style scoped>
@reference "tailwindcss";
.field    { @apply block rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500; }
.btn-primary { @apply rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60 transition-colors; }
</style>
