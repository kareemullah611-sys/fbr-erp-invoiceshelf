import type { Currency } from './currency'
import type { Company } from './company'
import type { Address } from './user'
import type { CustomFieldValue } from './custom-field'

export interface Country {
  id: number
  code: string
  name: string
  phone_code: number
}

export interface Customer {
  id: number
  name: string
  email: string | null
  phone: string | null
  contact_name: string | null
  company_name: string | null
  website: string | null
  enable_portal: boolean
  password_added: boolean
  currency_id: number | null
  company_id: number
  facebook_id: string | null
  google_id: string | null
  github_id: string | null
  created_at: string
  updated_at: string
  formatted_created_at: string
  avatar: string | number
  due_amount: number | null
  base_due_amount: number | null
  prefix: string | null
  tax_id: string | null
  fbr_ntn: string | null
  fbr_cnic: string | null
  fbr_registration_type: string | null
  fbr_sale_channel: string | null
  fbr_payment_mode: string | null
  fbr_payment_terms: string | null
  billing?: Address
  shipping?: Address
  fields?: CustomFieldValue[]
  company?: Company
  currency?: Currency
}

export interface CreateCustomerPayload {
  name: string
  contact_name?: string
  email?: string
  phone?: string | null
  password?: string
  confirm_password?: string
  currency_id: number | null
  website?: string | null
  tax_id?: string | null
  billing?: Partial<Address>
  shipping?: Partial<Address>
  enable_portal?: boolean
  fbr_ntn?: string | null
  fbr_cnic?: string | null
  fbr_registration_type?: string | null
  fbr_sale_channel?: string | null
  fbr_payment_mode?: string | null
  fbr_payment_terms?: string | null
  customFields?: CustomFieldValue[]
  fields?: CustomFieldValue[]
}
