<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotificationStore } from '@/scripts/stores/notification.store'
import { useCompanyStore } from '@/scripts/stores/company.store'
import { useGlobalStore } from '@/scripts/stores/global.store'
import { handleApiError } from '@/scripts/utils/error-handling'
import FbrConfigurationForm from '@/scripts/features/company/settings/components/FbrConfigurationForm.vue'

const { t } = useI18n()
const notificationStore = useNotificationStore()
const companyStore = useCompanyStore()
const globalStore = useGlobalStore()

const isSaving = ref(false)
const isFetchingInitialData = ref(false)
const configData = ref<Record<string, string>>({})

const FBR_SETTING_KEYS = [
  'fbr_enabled',
  'fbr_environment',
  'fbr_sandbox_token',
  'fbr_production_token',
  'fbr_seller_ntn',
  'fbr_seller_business_name',
  'fbr_seller_province',
  'fbr_seller_address',
  'fbr_default_hs_code',
  'fbr_default_uom',
  'fbr_default_sale_type',
  'fbr_default_buyer_registration_type',
  'fbr_sandbox_scenario_id',
]

const scenarioOptions = computed(() =>
  Object.entries(globalStore.fbrReference.scenarios).map(([key, label]) => ({
    value: key,
    label: `${key} — ${label}`,
  })),
)

loadData()

async function loadData(): Promise<void> {
  isFetchingInitialData.value = true
  try {
    configData.value = await companyStore.fetchCompanySettings(FBR_SETTING_KEYS)
  } catch (error: unknown) {
    const normalizedError = handleApiError(error)
    notificationStore.showNotification({
      type: 'error',
      message: normalizedError.message,
    })
  } finally {
    isFetchingInitialData.value = false
  }
}

async function saveConfig(value: Record<string, string>): Promise<void> {
  isSaving.value = true
  try {
    await companyStore.updateCompanySettings({
      data: { settings: value },
      message: 'settings.fbr.saved',
    })
    configData.value = { ...configData.value, ...value }
  } catch (error: unknown) {
    const normalizedError = handleApiError(error)
    notificationStore.showNotification({
      type: 'error',
      message: normalizedError.message,
    })
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <BaseSettingCard :title="$t('settings.fbr.title')" :description="$t('settings.fbr.description')">
    <FbrConfigurationForm
      :config-data="configData"
      :is-saving="isSaving"
      :is-fetching-initial-data="isFetchingInitialData"
      :scenario-options="scenarioOptions"
      @submit-data="saveConfig"
    />
  </BaseSettingCard>
</template>