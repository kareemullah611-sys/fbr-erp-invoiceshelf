import type { Company } from './company'
import type { Currency } from './currency'
import type { Tax } from './tax'

export interface Unit {
  id: number
  name: string
  company_id: number
  company?: Company
}

export interface Item {
  id: number
  name: string
  description: string | null
  price: number
  unit_id: number | null
  company_id: number
  creator_id: number
  currency_id: number | null
  fbr_hs_code: string | null
  fbr_uom: string | null
  fbr_sale_type: string | null
  fbr_sro_no: string | null
  fbr_sro_item_no: string | null
  fbr_fixed_notified_value: number | null
  fbr_sales_tax_withheld: number | null
  fbr_further_tax: number | null
  fbr_extra_tax: number | null
  fbr_fed_payable: number | null
  created_at: string
  updated_at: string
  tax_per_item: string | null
  formatted_created_at: string
  unit?: Unit
  company?: Company
  taxes?: Tax[]
  currency?: Currency
}
