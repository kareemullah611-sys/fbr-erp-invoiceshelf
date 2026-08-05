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
  'fbr_scenarios',
  'fbr_reduced_rate_hs',
]

interface ReducedRateEntry {
  rate: string
  sroScheduleNo: string
  sroItemSerialNo: string
}

const scenarios = ref<Record<string, string>>({})
const reducedRateCatalog = ref<Record<string, ReducedRateEntry>>({})

const scenarioOptions = computed(() =>
  Object.entries(scenarios.value).map(([key, label]) => ({ value: key, label: `${key} — ${label}` })),
)

loadData()

async function loadData(): Promise<void> {
  isFetchingInitialData.value = true
  try {
    configData.value = await companyStore.fetchCompanySettings(FBR_SETTING_KEYS)
    hydraulicScenarioList()
    hydraulicRateCatalog()
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

function hydraulicScenarioList(): void {
  const raw = configData.value.fbr_scenarios
  const fallback = globalStore.fbrReference.scenarios
  let source: Record<string, string> = fallback
  if (raw) {
    try {
      const parsed = JSON.parse(String(raw))
      if (typeof parsed === 'object' && parsed !== null) source = parsed
    } catch {
      source = fallback
    }
  }
  resetObject(scenarios, source)
}

function hydraulicRateCatalog(): void {
  const raw = configData.value.fbr_reduced_rate_hs
  let source: Record<string, ReducedRateEntry> = {}
  if (raw) {
    try {
      const parsed = JSON.parse(String(raw))
      if (typeof parsed === 'object' && parsed !== null) source = parsed
    } catch {
      source = {}
    }
  }
  resetObject(reducedRateCatalog, source)
}

function resetObject<T>(target: { value: Record<string, T> }, source: Record<string, T>): void {
  Object.keys(target.value).forEach((key) => {
    delete target.value[key]
  })
  Object.entries(source).forEach(([key, value]) => {
    target.value[key] = value
  })
}

function addScenario(): void {
  scenarios.value[''] = ''
}

function removeScenario(code: string): void {
  delete scenarios.value[code]
}

function renameScenario(oldCode: string, newCode: string): void {
  if (oldCode === newCode || !newCode) return
  const label = scenarios.value[oldCode]
  delete scenarios.value[oldCode]
  scenarios.value[newCode] = label
}

function addRateEntry(): void {
  reducedRateCatalog.value[''] = { rate: '', sroScheduleNo: 'EIGHTH SCHEDULE Table 1', sroItemSerialNo: '' }
}

function removeRateEntry(hs: string): void {
  delete reducedRateCatalog.value[hs]
}

function renameRateEntry(oldHs: string, newHs: string): void {
  if (oldHs === newHs || !newHs) return
  const entry = reducedRateCatalog.value[oldHs]
  delete reducedRateCatalog.value[oldHs]
  reducedRateCatalog.value[newHs] = entry
}

async function saveConfig(value: Record<string, string>): Promise<void> {
  isSaving.value = true
  try {
    const structured: Record<string, string> = {
      ...value,
      fbr_scenarios: JSON.stringify(scenarios.value),
      fbr_reduced_rate_hs: JSON.stringify(reducedRateCatalog.value),
    }
    await companyStore.updateCompanySettings({
      data: { settings: structured },
      message: 'settings.fbr.saved',
    })
    configData.value = { ...configData.value, ...structured }
    globalStore.fbrReference.scenarios = { ...scenarios.value }
    globalStore.fbrReference.reduced_rate_hs = { ...reducedRateCatalog.value }
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
      :scenarios="scenarios"
      :reduced-rate-catalog="reducedRateCatalog"
      @submit-data="saveConfig"
      @add-scenario="addScenario"
      @remove-scenario="removeScenario"
      @rename-scenario="renameScenario"
      @add-rate-entry="addRateEntry"
      @remove-rate-entry="removeRateEntry"
      @rename-rate-entry="renameRateEntry"
    />
  </BaseSettingCard>
</template>