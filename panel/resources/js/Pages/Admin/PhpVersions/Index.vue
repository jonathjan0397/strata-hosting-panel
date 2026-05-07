<template>
    <AppLayout title="PHP Versions">
        <div class="space-y-6 p-6">
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-gray-100">PHP Versions</h1>
                <p class="mt-0.5 text-sm text-gray-400">
                    Manage which PHP runtimes are available to hosting accounts.
                </p>
            </div>

            <div v-if="!canManage" class="rounded-xl border border-amber-700/40 bg-amber-900/20 px-4 py-3 text-sm text-amber-200">
                You do not have permission to manage PHP versions.
            </div>

            <section class="overflow-hidden rounded-2xl border border-gray-800 bg-gray-900/70 backdrop-blur">
                <div class="flex flex-col gap-3 border-b border-gray-800 px-5 py-4 md:flex-row md:items-center md:justify-between">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-100">Installed Runtimes</h2>
                        <p class="mt-1 text-sm text-gray-400">Versions detected on this server and their current account usage.</p>
                    </div>
                    <div class="shrink-0 rounded-lg border border-gray-800 bg-gray-950/70 px-3 py-2 text-xs text-gray-400">
                        {{ installedVersions.length }} installed / {{ allVersions.length }} supported
                    </div>
                </div>

                <div v-if="allVersions.length > 0" class="min-w-0 overflow-x-auto">
                    <table class="min-w-[640px] w-full divide-y divide-gray-800">
                        <thead class="bg-gray-950/70">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                    PHP Version
                                </th>
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                    Status
                                </th>
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                    Accounts Using
                                </th>
                                <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">
                                    Action
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <tr v-for="version in allVersions" :key="version" class="transition-colors hover:bg-gray-800/40">
                                <td class="whitespace-nowrap px-5 py-4 text-sm font-medium text-gray-100">
                                    PHP {{ version }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-sm">
                                    <span
                                        v-if="isInstalled(version)"
                                        class="inline-flex rounded-full border border-emerald-700/50 bg-emerald-900/25 px-2.5 py-1 text-xs font-medium text-emerald-300"
                                    >
                                        Installed
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex rounded-full border border-gray-700 bg-gray-950/60 px-2.5 py-1 text-xs font-medium text-gray-400"
                                    >
                                        Not installed
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-300">
                                    {{ usageCount(version) }} account{{ usageCount(version) === 1 ? '' : 's' }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-right text-sm">
                                    <button
                                        v-if="!isInstalled(version) && canManage"
                                        type="button"
                                        :disabled="processingVersion === version"
                                        @click="installVersion(version)"
                                        class="rounded-lg border border-indigo-600/60 bg-indigo-600/15 px-3 py-1.5 text-xs font-medium text-indigo-200 transition-colors hover:bg-indigo-600/25 disabled:cursor-wait disabled:opacity-60"
                                    >
                                        {{ processingVersion === version ? 'Installing...' : 'Install' }}
                                    </button>
                                    <button
                                        v-else-if="usageCount(version) === 0 && canManage"
                                        type="button"
                                        @click="disableVersion(version)"
                                        class="rounded-lg border border-red-700/50 px-3 py-1.5 text-xs font-medium text-red-300 transition-colors hover:bg-red-950/50"
                                    >
                                        Disable
                                    </button>
                                    <span v-else-if="usageCount(version) > 0" class="text-xs text-gray-500">
                                        In use
                                    </span>
                                    <span v-else class="text-xs text-gray-500">
                                        Unavailable
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="px-5 py-10 text-center text-sm text-gray-400">
                    No supported PHP versions were detected for this platform.
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    installedVersions: Array,
    availableVersions: Array,
    versionUsage: Object,
    accountsByNode: Object,
    canManage: Boolean
})

const processingVersion = ref(null)

const allVersions = computed(() => {
    return [...new Set([...(props.availableVersions || []), ...(props.installedVersions || [])])].sort((a, b) => {
        return a.localeCompare(b, undefined, { numeric: true })
    })
})

const isInstalled = (version) => (props.installedVersions || []).includes(version)

const usageCount = (version) => props.versionUsage?.[version] || 0

const installVersion = (version) => {
    if (!confirm(`Install PHP ${version} and its managed extensions on the primary server?`)) {
        return
    }

    processingVersion.value = version

    router.post(route('admin.php-versions.install', { version }), {}, {
        onFinish: () => {
            processingVersion.value = null
        }
    })
}

const enableVersion = (version) => {
    router.post(route('admin.php-versions.enable', { version }), {}, {
        onSuccess: () => {
            // Refresh the page to update status
            window.location.reload()
        }
    })
}

const disableVersion = (version) => {
    if (!confirm(`Are you sure you want to disable PHP ${version}?`)) {
        return
    }
    
    router.post(route('admin.php-versions.disable', { version }), {}, {
        onSuccess: () => {
            // Refresh the page to update status
            window.location.reload()
        }
    })
}
</script>
