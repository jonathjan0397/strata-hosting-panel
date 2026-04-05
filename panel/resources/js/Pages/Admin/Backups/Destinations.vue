<template>
    <AppLayout title="Remote Backup Destinations">
        <div class="mb-6">
            <h2 class="text-lg font-semibold text-gray-100">Remote Backup Destinations</h2>
            <p class="text-sm text-gray-400 mt-1">Backup files are automatically pushed to active destinations after each backup.</p>
        </div>

        <div v-if="$page.props.flash?.success" class="mb-4 rounded-lg bg-emerald-900/30 border border-emerald-800 px-4 py-3 text-sm text-emerald-400">{{ $page.props.flash.success }}</div>
        <div v-if="$page.props.flash?.error" class="mb-4 rounded-lg bg-red-900/30 border border-red-800 px-4 py-3 text-sm text-red-400">{{ $page.props.flash.error }}</div>

        <!-- Destinations list -->
        <div class="rounded-xl border border-gray-800 bg-gray-900 overflow-hidden mb-6">
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-800">
                <h3 class="text-sm font-semibold text-gray-200">Destinations</h3>
                <button @click="showAdd = !showAdd" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">+ Add Destination</button>
            </div>

            <!-- Add form -->
            <div v-if="showAdd" class="border-b border-gray-800 px-5 py-4 bg-gray-800/30">
                <form @submit.prevent="submitDest" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <input v-model="form.name" type="text" placeholder="Destination name" class="field" required />
                        <select v-model="form.type" class="field">
                            <option value="sftp">SFTP</option>
                            <option value="s3">S3-compatible</option>
                        </select>
                    </div>

                    <!-- SFTP fields -->
                    <template v-if="form.type === 'sftp'">
                        <div class="grid grid-cols-3 gap-2">
                            <input v-model="form.config.host" type="text" placeholder="Host" class="field col-span-2" required />
                            <input v-model="form.config.port" type="number" placeholder="Port (22)" class="field" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model="form.config.remote_user" type="text" placeholder="Username" class="field" required />
                            <input v-model="form.config.remote_path" type="text" placeholder="Remote path (/backups)" class="field" required />
                        </div>
                        <textarea v-model="form.config.ssh_private_key" rows="5" placeholder="SSH private key (PEM)" class="field font-mono text-xs" required></textarea>
                    </template>

                    <!-- S3 fields -->
                    <template v-if="form.type === 's3'">
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model="form.config.s3_bucket" type="text" placeholder="Bucket name" class="field" required />
                            <input v-model="form.config.s3_region" type="text" placeholder="Region (us-east-1)" class="field" />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model="form.config.s3_key_id" type="text" placeholder="Access Key ID" class="field" required />
                            <input v-model="form.config.s3_key_secret" type="password" placeholder="Secret Access Key" class="field" required />
                        </div>
                    </template>

                    <div class="flex gap-2">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 transition-colors">Save Destination</button>
                        <button type="button" @click="showAdd = false" class="text-xs text-gray-500 hover:text-gray-300">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="divide-y divide-gray-800">
                <div v-for="dest in destinations" :key="dest.id" class="flex items-center gap-4 px-5 py-3">
                    <span class="rounded px-2 py-0.5 text-xs font-mono uppercase" :class="dest.type === 's3' ? 'bg-amber-900/40 text-amber-300' : 'bg-blue-900/40 text-blue-300'">{{ dest.type }}</span>
                    <span class="flex-1 text-sm text-gray-200">{{ dest.name }}</span>
                    <span class="text-xs" :class="dest.active ? 'text-emerald-400' : 'text-gray-500'">{{ dest.active ? 'Active' : 'Inactive' }}</span>
                    <Link :href="route('admin.backups.destinations.toggle', dest.id)" method="post" as="button" class="text-xs text-gray-400 hover:text-gray-200 transition-colors">Toggle</Link>
                    <ConfirmButton
                        :href="route('admin.backups.destinations.destroy', dest.id)"
                        method="delete"
                        label="Remove"
                        :confirm-message="`Remove destination '${dest.name}'?`"
                        color="red"
                    />
                </div>
                <div v-if="destinations.length === 0" class="px-5 py-8 text-center text-sm text-gray-500">No remote destinations configured.</div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmButton from '@/Components/ConfirmButton.vue';

defineProps({ destinations: Array });

const showAdd = ref(false);
const form = ref({ name: '', type: 'sftp', config: {} });

function submitDest() {
    router.post(route('admin.backups.destinations.store'), form.value, {
        onSuccess: () => { form.value = { name: '', type: 'sftp', config: {} }; showAdd.value = false; },
    });
}
</script>

<style scoped>
@reference "tailwindcss";
.field { @apply block w-full rounded-lg border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:outline-none; }
</style>
