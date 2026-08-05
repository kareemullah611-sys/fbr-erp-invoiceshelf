<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGlobalStore } from '@/scripts/stores/global.store'

interface ScenarioOption {
  value: string
  label: string
}

interface ReducedRateEntry {
  rate: string
  sroScheduleNo: string
  sroItemSerialNo: string
}

const props = withDefaults(
  defineProps<{
    configData?: Record<string, string>
    isSaving?: boolean
    isFetchingInitialData?: boolean
    scenarioOptions?: ScenarioOption[]
    scenarios?: Record<string, string>
    reducedRateCatalog?: Record<string, ReducedRateEntry>
  }>(),
  {
    configData: () => ({}),
    isSaving: false,
    isFetchingInitialData: false,
    scenarioOptions: () => [],
    scenarios: () => ({}),
    reducedRateCatalog: () => ({}),
  },
)

const emit = defineEmits<{
  'submit-data': [config: Record<string, string>]
  'add-scenario': []
  'remove-scenario': [code: string]
  'rename-scenario': [code: string, newCode: string]
  'add-rate-entry': []
  'remove-rate-entry': [hs: string]
  'rename-rate-entry': [hs: string, newHs: string]
}>()

const { t } = useI18n()
const globalStore = useGlobalStore()

const form = reactive<Record<string, string>>(createDefaults())
const showSandboxToken = ref(false)
const showProductionToken = ref(false)

const isEnabled = computed(() => form.fbr_enabled === 'YES')
const isSandbox = computed(() => form.fbr_environment !== 'production')

const saleTypeOptions = computed(() =>
  globalStore.fbrReference.sale_types.map((value) => ({ value, label: value })),
)

const provinceOptions = [
  { value: 'Punjab', label: 'Punjab' },
  { value: 'Sindh', label: 'Sindh' },
  { value: 'Khyber Pakhtunkhwa', label: 'Khyber Pakhtunkhwa' },
  { value: 'Balochistan', label: 'Balochistan' },
  { value: 'Islamabad Capital Territory', label: 'Islamabad Capital Territory' },
]

const buyerRegistrationOptions = [
  { value: 'Registered', label: 'Registered' },
  { value: 'Unregistered', label: 'Unregistered' },
]

function createDefaults(): Record<string, string> {
  return {
    fbr_enabled: 'NO',
    fbr_environment: 'sandbox',
    fbr_sandbox_token: '',
    fbr_production_token: '',
    fbr_seller_ntn: '',
    fbr_seller_business_name: '',
    fbr_seller_province: '',
    fbr_seller_address: '',
    fbr_default_hs_code: '',
    fbr_default_uom: '',
    fbr_default_sale_type: 'Goods at standard rate (default)',
    fbr_default_buyer_registration_type: 'Unregistered',
    fbr_sandbox_scenario_id: '',
  }
}

function hydrateFromProps() {
  for (const key of Object.keys(form)) {
    const value = props.configData[key]
    if (value !== undefined && value !== null) {
      form[key] = String(value)
    }
  }
}

watch(() => props.configData, hydrateFromProps, { immediate: true, deep: true })

function onSubmit() {
  const payload: Record<string, string> = { ...form }
  if (!isEnabled.value) {
    payload.fbr_environment = 'sandbox'
  }
  emit('submit-data', payload)
}
</script>

<template>
  <form class="mt-8" @submit.prevent="onSubmit">
    <div class="mb-8">
      <BaseSwitch
        :model-value="isEnabled"
        class="flex"
        :label-right="$t('settings.fbr.enable')"
        @update:model-value="form.fbr_enabled = $event ? 'YES' : 'NO'"
      />
      <p class="mt-2 text-xs text-muted">{{ $t('settings.fbr.enable_help') }}</p>
    </div>

    <div v-if="isEnabled" class="space-y-6">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <BaseInputGroup
          :label="$t('settings.fbr.environment')"
          :content-loading="isFetchingInitialData"
          required
        >
          <BaseMultiselect
            v-model="form.fbr_environment"
            :options="[
              { value: 'sandbox', label: $t('settings.fbr.environment_sandbox') },
              { value: 'production', label: $t('settings.fbr.environment_production') },
            ]"
            :content-loading="isFetchingInitialData"
            value-prop="value"
            label="label"
            track-by="label"
            :can-deselect="false"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.fbr.scenario')"
          :content-loading="isFetchingInitialData"
        >
          <BaseMultiselect
            v-model="form.fbr_sandbox_scenario_id"
            :options="scenarioOptions"
            :content-loading="isFetchingInitialData"
            value-prop="value"
            label="label"
            track-by="label"
            :can-deselect="true"
            :placeholder="$t('settings.fbr.scenario_placeholder')"
          />
        </BaseInputGroup>
      </div>

      <!-- Seller -->
      <div class="border-t border-line-default pt-6">
        <h3 class="text-sm font-semibold text-heading mb-3">{{ $t('settings.fbr.seller_section') }}</h3>
        <p class="text-xs text-muted mb-4">{{ $t('settings.fbr.seller_section_help') }}</p>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <BaseInputGroup :label="$t('settings.fbr.seller_ntn')" :content-loading="isFetchingInitialData" required>
            <BaseInput v-model="form.fbr_seller_ntn" type="text" />
          </BaseInputGroup>
          <BaseInputGroup :label="$t('settings.fbr.seller_business_name')" :content-loading="isFetchingInitialData" required>
            <BaseInput v-model="form.fbr_seller_business_name" type="text" />
          </BaseInputGroup>
          <BaseInputGroup :label="$t('settings.fbr.seller_province')" :content-loading="isFetchingInitialData" required>
            <BaseMultiselect
              v-model="form.fbr_seller_province"
              :options="provinceOptions"
              :content-loading="isFetchingInitialData"
              value-prop="value"
              label="label"
              track-by="label"
              :can-deselect="true"
            />
          </BaseInputGroup>
          <BaseInputGroup :label="$t('settings.fbr.seller_address')" :content-loading="isFetchingInitialData" required>
            <BaseInput v-model="form.fbr_seller_address" type="text" />
          </BaseInputGroup>
        </div>
      </div>

      <!-- Defaults -->
      <div class="border-t border-line-default pt-6">
        <h3 class="text-sm font-semibold text-heading mb-3">{{ $t('settings.fbr.defaults_section') }}</h3>
        <p class="text-xs text-muted mb-4">{{ $t('settings.fbr.defaults_section_help') }}</p>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <BaseInputGroup :label="$t('settings.fbr.default_hs_code')" :content-loading="isFetchingInitialData">
            <BaseInput v-model="form.fbr_default_hs_code" type="text" placeholder="8311.1000" />
          </BaseInputGroup>
          <BaseInputGroup :label="$t('settings.fbr.default_uom')" :content-loading="isFetchingInitialData">
            <BaseInput v-model="form.fbr_default_uom" type="text" placeholder="Numbers, pieces, units" />
          </BaseInputGroup>
          <BaseInputGroup :label="$t('settings.fbr.default_sale_type')" :content-loading="isFetchingInitialData">
            <BaseMultiselect
              v-model="form.fbr_default_sale_type"
              :options="saleTypeOptions"
              :content-loading="isFetchingInitialData"
              value-prop="value"
              label="label"
              track-by="label"
              :can-deselect="true"
            />
          </BaseInputGroup>
          <BaseInputGroup :label="$t('settings.fbr.default_buyer_registration_type')" :content-loading="isFetchingInitialData">
            <BaseMultiselect
              v-model="form.fbr_default_buyer_registration_type"
              :options="buyerRegistrationOptions"
              :content-loading="isFetchingInitialData"
              value-prop="value"
              label="label"
              track-by="label"
              :can-deselect="true"
            />
          </BaseInputGroup>
        </div>
      </div>

      <!-- Tokens -->
      <div class="border-t border-line-default pt-6">
        <h3 class="text-sm font-semibold text-heading mb-3">{{ $t('settings.fbr.tokens_section') }}</h3>
        <p class="text-xs text-muted mb-4">{{ $t('settings.fbr.tokens_section_help') }}</p>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <BaseInputGroup
            v-if="isSandbox"
            :label="$t('settings.fbr.sandbox_token')"
            :content-loading="isFetchingInitialData"
            required
          >
            <div class="flex gap-2">
              <BaseInput
                v-model="form.fbr_sandbox_token"
                :content-loading="isFetchingInitialData"
                :type="showSandboxToken ? 'text' : 'password'"
                class="flex-1"
              />
              <BaseButton type="button" variant="primary-outline" @click="showSandboxToken = !showSandboxToken">
                {{ showSandboxToken ? $t('general.hide') : $t('general.show') }}
              </BaseButton>
            </div>
          </BaseInputGroup>

          <BaseInputGroup
            v-if="!isSandbox"
            :label="$t('settings.fbr.production_token')"
            :content-loading="isFetchingInitialData"
            required
          >
            <div class="flex gap-2">
              <BaseInput
                v-model="form.fbr_production_token"
                :content-loading="isFetchingInitialData"
                :type="showProductionToken ? 'text' : 'password'"
                class="flex-1"
              />
              <BaseButton type="button" variant="primary-outline" @click="showProductionToken = !showProductionToken">
                {{ showProductionToken ? $t('general.hide') : $t('general.show') }}
              </BaseButton>
            </div>
          </BaseInputGroup>
        </div>
      </div>

      <!-- Scenarios (admin-editable per company) -->
      <div class="border-t border-line-default pt-6">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold text-heading">{{ $t('settings.fbr.scenarios_section') }}</h3>
          <BaseButton type="button" variant="primary-outline" size="sm" @click="emit('add-scenario')">
            + {{ $t('settings.fbr.add_scenario') }}
          </BaseButton>
        </div>
        <p class="text-xs text-muted mb-4">{{ $t('settings.fbr.scenarios_section_help') }}</p>

        <div v-for="(label, code) in scenarios" :key="code" class="grid grid-cols-1 gap-3 md:grid-cols-[2fr_3fr_auto] mb-3">
          <BaseInput
            :model-value="code"
            :placeholder="$t('settings.fbr.scenario_code_placeholder')"
            @update:model-value="(val: string) => emit('rename-scenario', code, val)"
          />
          <BaseInput v-model="scenarios[code]" :placeholder="$t('settings.fbr.scenario_label_placeholder')" />
          <BaseButton type="button" variant="danger-outline" @click="emit('remove-scenario', code)">
            {{ $t('general.remove') }}
          </BaseButton>
        </div>
      </div>

      <!-- Reduced-rate HS catalog (admin-editable per company) -->
      <div class="border-t border-line-default pt-6">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold text-heading">{{ $t('settings.fbr.reduced_rate_section') }}</h3>
          <BaseButton type="button" variant="primary-outline" size="sm" @click="emit('add-rate-entry')">
            + {{ $t('settings.fbr.add_reduced_entry') }}
          </BaseButton>
        </div>
        <p class="text-xs text-muted mb-4">{{ $t('settings.fbr.reduced_rate_section_help') }}</p>

        <div v-for="(entry, hs) in reducedRateCatalog" :key="hs" class="border border-line-light rounded p-3 mb-3">
          <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <BaseInputGroup :label="$t('settings.fbr.hs_code')">
              <BaseInput
                :model-value="hs"
                :placeholder="'0101.2100'"
                @update:model-value="(val: string) => emit('rename-rate-entry', hs, val)"
              />
            </BaseInputGroup>
            <BaseInputGroup :label="$t('settings.fbr.rate')">
              <BaseInput v-model="reducedRateCatalog[hs].rate" placeholder="1%" />
            </BaseInputGroup>
            <BaseInputGroup :label="$t('settings.fbr.sro_schedule')">
              <BaseInput v-model="reducedRateCatalog[hs].sroScheduleNo" placeholder="EIGHTH SCHEDULE Table 1" />
            </BaseInputGroup>
            <BaseInputGroup :label="$t('settings.fbr.sro_serial')">
              <BaseInput v-model="reducedRateCatalog[hs].sroItemSerialNo" placeholder="70" />
            </BaseInputGroup>
          </div>
          <div class="mt-2 text-right">
            <BaseButton type="button" variant="danger-outline" size="sm" @click="emit('remove-rate-entry', hs)">
              {{ $t('general.remove') }}
            </BaseButton>
          </div>
        </div>
      </div>
    </div>

    <div class="flex items-center gap-3 mt-8">
      <BaseButton :loading="isSaving" :disabled="isSaving" variant="primary" type="submit">
        <template #left="slotProps">
          <BaseIcon v-if="!isSaving" name="ArrowDownOnSquareIcon" :class="slotProps.class" />
        </template>
        {{ $t('general.save') }}
      </BaseButton>
    </div>
  </form>
</template>