<template>
    <AppLayout title="Add Domain">
        <PageHeader
            eyebrow="Websites"
            title="Add Domain"
            description="Create a hosted domain, choose the site type, and assign the PHP runtime used by the vhost."
        >
            <template #actions>
                <Link :href="route('my.domains.index')" class="rounded-lg border border-gray-700 px-4 py-2 text-sm font-semibold text-gray-300 hover:bg-gray-800">
                    Back to Domains
                </Link>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,32rem)_1fr]">
            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <form @submit.prevent="submit" class="space-y-5">
                    <FormField label="Domain name" :error="form.errors.domain">
                        <input
                            v-model="form.domain"
                            type="text"
                            placeholder="example.com"
                            class="field w-full"
                        />
                    </FormField>

                    <FormField label="Type" :error="form.errors.type">
                        <select v-model="form.type" class="field w-full">
                            <option value="addon">Addon domain</option>
                            <option value="subdomain">Subdomain</option>
                            <option value="parked">Parked (alias)</option>
                        </select>
                    </FormField>

                    <FormField label="PHP version" :error="form.errors.php_version">
                        <select v-model="form.php_version" class="field w-full">
                            <option v-for="v in phpVersions" :key="v" :value="v">PHP {{ v }}</option>
                        </select>
                    </FormField>

                    <div class="flex justify-end gap-3 pt-2">
                        <Link
                            :href="route('my.domains.index')"
                            class="rounded-lg border border-gray-700 px-4 py-2 text-sm text-gray-300 hover:bg-gray-800 transition-colors"
                        >
                            Cancel
                        </Link>
                        <button type="submit" :disabled="form.processing" class="btn-primary">
                            {{ form.processing ? 'Adding...' : 'Add Domain' }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-6">
                <h3 class="text-sm font-semibold text-gray-200">Domain Types</h3>
                <div class="mt-4 space-y-4 text-sm text-gray-400">
                    <div>
                        <p class="font-semibold text-gray-200">Addon domain</p>
                        <p class="mt-1">Hosts a separate website under this account.</p>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-200">Subdomain</p>
                        <p class="mt-1">Creates a child host like <span class="font-mono">blog.example.com</span>.</p>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-200">Parked alias</p>
                        <p class="mt-1">Points another domain name at an existing site.</p>
                    </div>
                </div>
                <div class="mt-5 rounded-lg border border-indigo-800/50 bg-indigo-900/20 p-4 text-sm text-indigo-200">
                    After creation, manage SSL, redirects, directory privacy, and hotlink protection from the domain detail page.
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/FormField.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    phpVersions: Array,
});

const form = useForm({
    domain:      '',
    type:        'addon',
    php_version: props.phpVersions?.[1] ?? '8.2',
});

function submit() {
    form.post(route('my.domains.store'));
}
</script>
