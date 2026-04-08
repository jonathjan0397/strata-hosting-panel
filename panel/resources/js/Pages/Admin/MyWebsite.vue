<template>
    <AppLayout>
        <div class="max-w-3xl mx-auto py-8 px-4 space-y-6">
            <h1 class="text-2xl font-semibold text-gray-100">My Website</h1>

            <div v-if="$page.props.flash?.success" class="rounded-lg bg-green-900/40 border border-green-700 px-4 py-3 text-green-300 text-sm">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash?.error" class="rounded-lg bg-red-900/40 border border-red-700 px-4 py-3 text-red-300 text-sm">
                {{ $page.props.flash.error }}
            </div>

            <template v-if="account">
                <div class="rounded-xl bg-gray-900 border border-gray-800 p-6 space-y-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-lg font-medium text-gray-100">{{ account.domain?.domain ?? '(no domain)' }}</p>
                            <p class="text-sm text-gray-400 mt-0.5">System user: <code class="text-indigo-400">{{ account.username }}</code> &middot; PHP {{ account.php_version }} &middot; {{ account.node?.name }}</p>
                            <p v-if="account.status === 'provisioning'" class="mt-1 text-xs text-sky-300">Website provisioning is running in the background. Refresh this page in a moment.</p>
                            <p v-else-if="account.status === 'failed'" class="mt-1 text-xs text-red-300">Provisioning failed: {{ account.provisioning_error ?? 'Unknown error' }}</p>
                            <p v-else-if="!account.domain" class="mt-1 text-xs text-amber-300">The server account exists, but no domain is attached yet. Complete setup below to finish the website.</p>
                        </div>
                        <span
                            :class="account.status === 'active' ? 'bg-green-900/50 text-green-400 border-green-700' : 'bg-yellow-900/50 text-yellow-400 border-yellow-700'"
                            class="text-xs px-2.5 py-1 rounded-full border font-medium"
                        >
                            {{ account.status }}
                        </span>
                    </div>

                    <div v-if="account.domain" class="flex items-center gap-2 text-sm">
                        <svg v-if="account.domain.ssl_enabled" class="h-4 w-4 text-green-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        <svg v-else class="h-4 w-4 text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 1 1 9 0v3.75M3.75 21.75h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        <span class="text-gray-400">
                            SSL: {{ account.domain.ssl_enabled ? 'enabled' : 'not issued' }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 pt-2">
                        <Link :href="route('my.files.index')" class="flex flex-col items-center gap-1.5 rounded-lg bg-gray-800 hover:bg-gray-750 border border-gray-700 px-3 py-3 text-sm text-gray-300 hover:text-gray-100 transition-colors">
                            <svg class="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v8.25m19.5 0v-5.625c0-.621-.504-1.125-1.125-1.125H4.875c-.621 0-1.125.504-1.125 1.125v5.625m19.5 0v2.625c0 .621-.504 1.125-1.125 1.125H4.875c-.621 0-1.125-.504-1.125-1.125v-2.625" />
                            </svg>
                            File Manager
                        </Link>
                        <Link :href="route('my.ftp.index')" class="flex flex-col items-center gap-1.5 rounded-lg bg-gray-800 hover:bg-gray-750 border border-gray-700 px-3 py-3 text-sm text-gray-300 hover:text-gray-100 transition-colors">
                            <svg class="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 7.5h-.75A2.25 2.25 0 0 0 4.5 9.75v7.5a2.25 2.25 0 0 0 2.25 2.25h7.5a2.25 2.25 0 0 0 2.25-2.25v-7.5a2.25 2.25 0 0 0-2.25-2.25h-.75m-6 3.75 3 3m0 0 3-3m-3 3V1.5m6 9h.75a2.25 2.25 0 0 1 2.25 2.25v7.5a2.25 2.25 0 0 1-2.25 2.25h-7.5a2.25 2.25 0 0 1-2.25-2.25v-.75" />
                            </svg>
                            FTP Accounts
                        </Link>
                        <Link v-if="account.domain" :href="route('my.domains.show', account.domain.id)" class="flex flex-col items-center gap-1.5 rounded-lg bg-gray-800 hover:bg-gray-750 border border-gray-700 px-3 py-3 text-sm text-gray-300 hover:text-gray-100 transition-colors">
                            <svg class="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                            </svg>
                            SSL / Domain
                        </Link>
                        <Link :href="route('my.databases.index')" class="flex flex-col items-center gap-1.5 rounded-lg bg-gray-800 hover:bg-gray-750 border border-gray-700 px-3 py-3 text-sm text-gray-300 hover:text-gray-100 transition-colors">
                            <svg class="h-5 w-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                            </svg>
                            Databases
                        </Link>
                    </div>
                </div>

                <div v-if="!account.domain" class="rounded-xl bg-gray-900 border border-gray-800 p-6 space-y-5">
                    <div>
                        <h2 class="text-base font-medium text-gray-100">Complete website setup</h2>
                        <p class="text-sm text-gray-400 mt-1">
                            Attach your main domain to the existing website account. This will finish vhost and DNS provisioning on
                            <span class="text-gray-300">{{ account.node?.name ?? primaryNode?.name }}</span>.
                        </p>
                    </div>

                    <form v-if="account.status !== 'provisioning'" @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Domain</label>
                            <input
                                v-model="form.domain"
                                type="text"
                                placeholder="example.com"
                                class="w-full rounded-lg bg-gray-800 border border-gray-700 text-gray-100 placeholder-gray-500 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                :class="{ 'border-red-500': errors.domain }"
                            />
                            <p v-if="errors.domain" class="mt-1 text-xs text-red-400">{{ errors.domain }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">PHP Version</label>
                            <select
                                v-model="form.php_version"
                                class="w-full rounded-lg bg-gray-800 border border-gray-700 text-gray-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                <option v-for="v in phpVersions" :key="v" :value="v">PHP {{ v }}</option>
                            </select>
                            <p v-if="account.status === 'active'" class="mt-1 text-xs text-gray-500">The existing system account keeps its current PHP pool version unless you remove and recreate it.</p>
                        </div>

                        <button
                            type="submit"
                            :disabled="loading"
                            class="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium transition-colors"
                        >
                            {{ loading ? 'Queueing...' : account.status === 'failed' ? 'Retry Website Setup' : 'Complete Website Setup' }}
                        </button>
                    </form>
                </div>

                <div class="rounded-xl bg-gray-900 border border-red-900/50 p-6">
                    <h2 class="text-base font-medium text-gray-100 mb-1">Remove Website</h2>
                    <p class="text-sm text-gray-400 mb-4">Removes the system account, vhost, and all files from the server. This cannot be undone.</p>
                    <button @click="confirmRemove = true" class="px-4 py-2 rounded-lg bg-red-700 hover:bg-red-600 text-white text-sm font-medium transition-colors">
                        Remove Website
                    </button>
                </div>

                <div v-if="confirmRemove" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div class="bg-gray-900 border border-gray-700 rounded-xl p-6 w-full max-w-md space-y-4">
                        <h3 class="text-lg font-semibold text-gray-100">Remove website?</h3>
                        <p class="text-sm text-gray-400">All files under <code class="text-indigo-400">/home/{{ account.username }}/</code> will be permanently deleted.</p>
                        <div class="flex justify-end gap-3">
                            <button @click="confirmRemove = false" class="px-4 py-2 rounded-lg bg-gray-700 hover:bg-gray-600 text-gray-200 text-sm transition-colors">Cancel</button>
                            <form :action="route('admin.my-website.deprovision')" method="POST" @submit.prevent="submitRemove">
                                <button type="submit" class="px-4 py-2 rounded-lg bg-red-700 hover:bg-red-600 text-white text-sm font-medium transition-colors">
                                    Yes, remove
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="rounded-xl bg-gray-900 border border-gray-800 p-6 space-y-5">
                    <div>
                        <h2 class="text-base font-medium text-gray-100">Set up your website</h2>
                        <p class="text-sm text-gray-400 mt-1">
                            Host your main domain (e.g. <code class="text-indigo-400">example.com</code>) on this server alongside the panel.
                            A system account and web server vhost will be created automatically.
                        </p>
                        <p v-if="primaryNode" class="text-sm text-gray-500 mt-1">Will be provisioned on <span class="text-gray-300">{{ primaryNode.name }}</span> ({{ primaryNode.hostname }}).</p>
                        <p v-else class="text-sm text-red-400 mt-1">No online server node found. Bring a node online before continuing.</p>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Domain</label>
                            <input
                                v-model="form.domain"
                                type="text"
                                placeholder="example.com"
                                class="w-full rounded-lg bg-gray-800 border border-gray-700 text-gray-100 placeholder-gray-500 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                :class="{ 'border-red-500': errors.domain }"
                            />
                            <p v-if="errors.domain" class="mt-1 text-xs text-red-400">{{ errors.domain }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">PHP Version</label>
                            <select
                                v-model="form.php_version"
                                class="w-full rounded-lg bg-gray-800 border border-gray-700 text-gray-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                <option v-for="v in phpVersions" :key="v" :value="v">PHP {{ v }}</option>
                            </select>
                        </div>

                        <button
                            type="submit"
                            :disabled="!primaryNode || loading"
                            class="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium transition-colors"
                        >
                            {{ loading ? 'Queueing...' : 'Provision Website' }}
                        </button>
                    </form>
                </div>
            </template>
        </div>
    </AppLayout>
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    account: Object,
    phpVersions: Array,
    primaryNode: Object,
})

const form = reactive({
    domain: props.account?.domain?.domain ?? '',
    php_version: props.account?.php_version ?? props.phpVersions?.at(-1) ?? '8.4',
})
const errors = reactive({})
const loading = ref(false)
const confirmRemove = ref(false)
let refreshTimer = null

function stopPolling() {
    if (refreshTimer) {
        clearTimeout(refreshTimer)
        refreshTimer = null
    }
}

function scheduleRefresh() {
    stopPolling()

    if (props.account?.status !== 'provisioning') {
        return
    }

    refreshTimer = setTimeout(() => {
        router.reload({
            only: ['account', 'primaryNode'],
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                scheduleRefresh()
            },
        })
    }, 3000)
}

watch(() => props.account?.status, () => {
    scheduleRefresh()
})

onMounted(() => {
    scheduleRefresh()
})

onBeforeUnmount(() => {
    stopPolling()
})

function submit() {
    errors.domain = null
    loading.value = true
    router.post(route('admin.my-website.provision'), form, {
        preserveScroll: true,
        onError: (e) => Object.assign(errors, e),
        onFinish: () => {
            loading.value = false
            scheduleRefresh()
        },
    })
}

function submitRemove() {
    router.delete(route('admin.my-website.deprovision'))
    confirmRemove.value = false
}
</script>
