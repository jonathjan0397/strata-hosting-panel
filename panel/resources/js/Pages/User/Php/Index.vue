<template>
    <AppLayout title="PHP Settings">
        <div class="max-w-3xl space-y-6 p-6">
            <PageHeader
                eyebrow="Websites"
                title="PHP Settings"
                :description="`Tune resource limits for PHP ${account.php_version} across this hosting account.`"
            />

            <div class="rounded-xl border border-gray-800 bg-gray-900 p-5">
                <h2 class="mb-4 text-sm font-semibold text-gray-300">
                    PHP {{ account.php_version }} - Resource Limits
                </h2>

                <form @submit.prevent="save" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="label">Upload Max Filesize</label>
                            <input v-model="form.php_upload_max" type="text" placeholder="64M" class="field w-full" />
                            <p v-if="form.errors.php_upload_max" class="mt-1 text-xs text-red-400">{{ form.errors.php_upload_max }}</p>
                        </div>
                        <div>
                            <label class="label">Post Max Size</label>
                            <input v-model="form.php_post_max" type="text" placeholder="64M" class="field w-full" />
                            <p v-if="form.errors.php_post_max" class="mt-1 text-xs text-red-400">{{ form.errors.php_post_max }}</p>
                        </div>
                        <div>
                            <label class="label">Memory Limit</label>
                            <input v-model="form.php_memory_limit" type="text" placeholder="256M" class="field w-full" />
                            <p v-if="form.errors.php_memory_limit" class="mt-1 text-xs text-red-400">{{ form.errors.php_memory_limit }}</p>
                        </div>
                        <div>
                            <label class="label">Max Execution Time (s)</label>
                            <input v-model.number="form.php_max_exec_time" type="number" min="1" max="300" placeholder="30" class="field w-full" />
                            <p v-if="form.errors.php_max_exec_time" class="mt-1 text-xs text-red-400">{{ form.errors.php_max_exec_time }}</p>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500">
                        Use M for megabytes (e.g. <code class="text-gray-400">128M</code>) or G for gigabytes.
                        Maximum execution time is capped at 300 seconds.
                    </p>

                    <div class="flex items-center gap-3 pt-1">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="btn-primary"
                        >Save Settings</button>
                        <span v-if="$page.props.flash?.success" class="text-xs text-emerald-400">
                            {{ $page.props.flash.success }}
                        </span>
                    </div>
                </form>
            </div>

            <div class="rounded-xl border border-gray-800 bg-gray-900/40 p-4">
                <p class="text-xs leading-relaxed text-gray-500">
                    These limits apply to all PHP scripts running under your account.
                    Changes take effect immediately after saving.
                    Your PHP version is managed by your administrator.
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    account: Object,
});

const form = useForm({
    php_upload_max: props.account.php_upload_max ?? '64M',
    php_post_max: props.account.php_post_max ?? '64M',
    php_memory_limit: props.account.php_memory_limit ?? '256M',
    php_max_exec_time: props.account.php_max_exec_time ?? 30,
});

function save() {
    form.put(route('my.php.update'));
}
</script>

<style scoped>
@reference "tailwindcss";
.label { @apply mb-1.5 block text-sm font-medium text-gray-300; }
</style>
