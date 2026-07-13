import { getJson, patchJson } from './client'

/** Wire shape of GET/PATCH /api/v2/settings (UserSettingsDto). */
export interface UserSettings {
  locale: string
  show_empty_line: boolean
  suggest_time: boolean
  show_future: boolean
  min_entry_duration: number
  personio_sync_enabled: boolean
}

export function fetchSettings(): Promise<UserSettings> {
  return getJson<UserSettings>('/api/v2/settings')
}

/** Partial update: absent fields stay unchanged (server-guaranteed). */
export function patchSettings(patch: Partial<UserSettings>): Promise<UserSettings> {
  return patchJson<UserSettings>('/api/v2/settings', patch)
}
