import { client } from '../client'
import { API } from '../endpoints'

export interface FbrHsCodeOption {
  hs_code: string
  description: string
  uoms: string[]
}

export interface FbrHsCodeSearchResponse {
  data: FbrHsCodeOption[]
}

export interface FbrUomsResponse {
  data: string[]
}

export const fbrReferenceService = {
  async searchHsCodes(search: string): Promise<FbrHsCodeOption[]> {
    const { data } = await client.get<FbrHsCodeSearchResponse>(API.FBR_REFERENCE_HS_CODES, {
      params: search ? { search } : {},
    })
    return data.data
  },

  async getUoms(): Promise<string[]> {
    const { data } = await client.get<FbrUomsResponse>(API.FBR_REFERENCE_UOMS)
    return data.data
  },
}