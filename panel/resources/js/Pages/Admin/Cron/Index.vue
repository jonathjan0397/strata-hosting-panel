<template>
    <AppLayout :title="`${account.username} Cron Jobs`">
        <CronJobsManager
            :account="account"
            :jobs="jobs"
            eyebrow="Hosting"
            :title="`${account.username} Cron Jobs`"
            description="Manage scheduled commands for this hosting account and reapply them to the assigned node whenever needed."
            :store-url="route('admin.accounts.cron.store', account.id)"
            :sync-url="route('admin.accounts.cron.sync', account.id)"
            update-route-name="admin.cron.update"
            destroy-route-name="admin.cron.destroy"
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
