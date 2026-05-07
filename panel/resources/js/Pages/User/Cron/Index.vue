<template>
    <AppLayout title="Cron Jobs">
        <CronJobsManager
            :account="account"
            :jobs="jobs"
            eyebrow="Developer Tools"
            title="Cron Jobs"
            description="Schedule recurring commands for this hosting account with either a full cron line or field-by-field timing controls."
            :store-url="route('my.cron.store')"
            :sync-url="route('my.cron.sync')"
            update-route-name="my.cron.update"
            destroy-route-name="my.cron.destroy"
        />
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import CronJobsManager from '@/Components/CronJobsManager.vue';

defineProps({
    account: Object,
    jobs: Array,
});
</script>
