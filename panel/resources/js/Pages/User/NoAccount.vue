<template>
    <AppLayout title="My Hosting">
        <div class="flex flex-col items-center justify-center py-24 text-center">
            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gray-800">
                <svg class="h-7 w-7 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 0 1-3-3m3 3a3 3 0 1 0 0 6h13.5a3 3 0 1 0 0-6m-16.5-3a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3M12 3v4.5" />
                </svg>
            </div>
            <h2 class="text-lg font-semibold text-gray-200">No hosting account</h2>
            <p class="mt-2 text-sm text-gray-500">Your account hasn't been provisioned yet. Contact your administrator.</p>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
</script>
