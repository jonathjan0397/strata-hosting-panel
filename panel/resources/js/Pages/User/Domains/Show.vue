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
                            <span v-if="domain.ssl_enabled" class="text-xs text-emerald-400">Active</span>
                            <span v-else class="text-xs text-gray-500">Not configured</span>
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- SSL -->
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h3 class="text-sm font-semibold text-gray-300 mb-4">SSL Certificate</h3>
                <p class="text-sm text-gray-400 mb-4">
                    {{ domain.ssl_enabled
                        ? "Let's Encrypt certificate is active."
                        : "Issue a free Let's Encrypt certificate for this domain." }}
                </p>
                <ConfirmButton
                    v-if="!domain.ssl_enabled"
                    :href="route('my.domains.ssl', domain.id)"
                    method="post"
                    label="Issue SSL"
                    color="indigo"
                    :confirm-message="`Issue SSL certificate for ${domain.domain}?`"
                />
                <span v-else class="inline-flex items-center gap-1.5 text-sm text-emerald-400">
                    <span class="h-2 w-2 rounded-full bg-emerald-400"></span> Certificate active
                </span>
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
                    >
                        Update PHP
                    </button>
                </form>
            </div>
        </div>

        <!-- Quick links -->
        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <Link
                :href="route('my.email.domain', domain.id)"
                class="flex items-center gap-3 rounded-xl border border-gray-800 bg-gray-900 px-4 py-4 text-sm text-gray-300 hover:bg-gray-800 transition-colors"
            >
                <svg class="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                </svg>
                Email Accounts
            </Link>
            <Link
                :href="route('my.dns.show', domain.id)"
                class="flex items-center gap-3 rounded-xl border border-gray-800 bg-gray-900 px-4 py-4 text-sm text-gray-300 hover:bg-gray-800 transition-colors"
            >
                <svg class="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 8.25h15m-16.5 7.5h15m-1.8-13.5-3.9 19.5m-2.1-19.5-3.9 19.5" />
                </svg>
                DNS Records
            </Link>
            <Link
                :href="route('my.databases.index')"
                class="flex items-center gap-3 rounded-xl border border-gray-800 bg-gray-900 px-4 py-4 text-sm text-gray-300 hover:bg-gray-800 transition-colors"
            >
                <svg class="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                </svg>
                Databases
            </Link>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

const props = defineProps({
    domain:      Object,
    phpVersions: Array,
});

const phpForm = useForm({
    php_version: props.domain.php_version,
});

function submitPhp() {
    phpForm.put(route('my.domains.php', props.domain.id));
}
</script>
