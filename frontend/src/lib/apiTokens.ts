/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { getJson, postJson } from '../api/client'

/** A user's API token as returned by the management list (never the secret). */
export interface ApiToken {
  id: number
  name: string
  scopes: string[]
  createdAt: string
  lastUsedAt: string | null
  expiresAt: string | null
  revokedAt: string | null
}

/** GET /settings/api-tokens — the user's tokens plus the scope-picker taxonomy. */
export interface ApiTokenList {
  tokens: ApiToken[]
  resources: string[]
  actions: string[]
  wildcard: string
}

export interface CreateApiTokenInput {
  name: string
  scopes: string[]
  /** ISO date (YYYY-MM-DD) or empty for no expiry. */
  expiresAt?: string
}

/** The create response — carries the plaintext token, shown exactly once. */
export interface CreatedApiToken {
  token: string
  id: number
  name: string
  scopes: string[]
  createdAt: string
  expiresAt: string | null
}

export function listApiTokens(): Promise<ApiTokenList> {
  return getJson<ApiTokenList>('/settings/api-tokens')
}

export function createApiToken(input: CreateApiTokenInput): Promise<CreatedApiToken> {
  return postJson<CreatedApiToken>('/settings/api-tokens/create', { ...input })
}

export async function revokeApiToken(id: number): Promise<void> {
  await postJson('/settings/api-tokens/revoke', { id })
}
