<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGlobalStore } from '@/scripts/stores/global.store'

interface ScenarioOption {
  value: string
  label: string
}

const props = withDefaults(
  defineProps<{
    configData?: Record<string, string>
    isSaving?: boolean
    isFetchingInitialData?: boolean
    scenarioOptions?: ScenarioOption[]
  }>(),
  {
    configData: () => ({}),
    isSaving: false,
    isFetchingInitialData: false,
    scenarioOptions: () => [],
  },
)

const emit = defineEmits<{
  'submit-data': [config: Record<string, string>]
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

      <div class="border-t border-line-default pt-6">
        <h3 class="text-sm font-semibold text-heading mb-3">{{ $t('settings.fbr.seller_section') }}</h3>
        <p class="text-xs text-muted mb-4">{{ $t('settings.fbr.seller_section_help') }}</p>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <BaseInputGroup
            :label="$t('settings.fbr.seller_ntn')"
            :content-loading="isFetchingInitialData"
            required
          >
            <BaseInput v-model="form.fbr_seller_ntn" type="text" />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('settings.fbr.seller_business_name')"
            :content-loading="isFetchingInitialData"
            required
          >
            <BaseInput v-model="form.fbr_seller_business_name" type="text" />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('settings.fbr.seller_province')"
            :content-loading="isFetchingInitialData"
            required
          >
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

          <BaseInputGroup
            :label="$t('settings.fbr.seller_address')"
            :content-loading="isFetchingInitialData"
            required
          >
            <BaseInput v-model="form.fbr_seller_address" type="text" />
          </BaseInputGroup>
        </div>
      </div>

      <div class="border-t border-line-default pt-6">
        <h3 class="text-sm font-semibold text-heading mb-3">{{ $t('settings.fbr.defaults_section') }}</h3>
        <p class="text-xs text-muted mb-4">{{ $t('settings.fbr.defaults_section_help') }}</p>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <BaseInputGroup
            :label="$t('settings.fbr.default_hs_code')"
            :content-loading="isFetchingInitialData"
          >
            <BaseInput v-model="form.fbr_default_hs_code" type="text" placeholder="8311.1000" />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('settings.fbr.default_uom')"
            :content-loading="isFetchingInitialData"
          >
            <BaseInput v-model="form.fbr_default_uom" type="text" placeholder="Numbers, pieces, units" />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('settings.fbr.default_sale_type')"
            :content-loading="isFetchingInitialData"
          >
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

          <BaseInputGroup
            :label="$t('settings.fbr.default_buyer_registration_type')"
            :content-loading="isFetchingInitialData"
          >
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