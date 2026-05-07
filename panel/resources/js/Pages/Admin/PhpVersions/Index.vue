<template>
    <div>
        <div class="mb-6">
            <h1 class="text-2xl font-bold">PHP Versions</h1>
            <p class="text-sm text-muted-foreground">
                Manage which PHP versions are available for use on your server.
            </p>
        </div>

        <div class="space-y-4">
            <!-- Warning if user cannot manage -->
            <div v-if="!canManage" class="p-4 bg-warning/50 border border-warning rounded-md">
                <p class="text-sm">
                    You don't have permission to manage PHP versions.
                </p>
            </div>

            <!-- Installed versions table -->
            <div v-if="installedVersions.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-muted">
                    <thead>
                        <tr class="bg-muted">
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground">
                                PHP Version
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground">
                                Status
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground">
                                Accounts Using
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-muted-foreground">
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-muted">
                        <tr v-for="version in installedVersions" :key="version" class="hover:bg-muted/50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                PHP {{ version }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 py-0.5 bg-success/20 text-success rounded">
                                    Installed
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                {{ versionUsage[version] || 0 }} account(s)
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                <!-- Disable button (only if not in use) -->
                                <button 
                                    v-if="versionUsage[version] === 0 && canManage"
                                    @click="disableVersion(version)"
                                    class="px-3 py-1 bg-destructive text-destructive-foreground text-xs hover:bg-destructive/20 rounded"
                                >
                                    Disable
                                </button>
                                <!-- Cannot disable (in use) -->
                                <span v-else-if="versionUsage[version] > 0" class="text-xs text-muted-foreground">
                                    In use
                                </span>
                                <span v-else class="text-xs text-muted-foreground">
                                    Unavailable
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- No versions installed -->
            <div v-else class="text-center py-8">
                <p class="text-muted-foreground">
                    No PHP versions detected on the system.
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { router } from '@inertiajs/vue3'

const props = defineProps({
    installedVersions: Array,
    versionUsage: Object,
    accountsByNode: Object,
    canManage: Boolean
})

const enableVersion = (version) => {
    router.post(route('php-versions.enable', { version }), {}, {
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
    
    router.post(route('php-versions.disable', { version }), {}, {
        onSuccess: () => {
            // Refresh the page to update status
            window.location.reload()
        }
    })
}
</script>
