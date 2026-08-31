import { apiClient, unwrapData } from '../../lib/api/apiClient.js'
export async function updateMyProfile({ displayName, username }) { return unwrapData(await apiClient.patch('/me/account', { action: 'profile', displayName, username })) }
export async function updateMyEmail({ email, currentPassword }) { return unwrapData(await apiClient.patch('/me/account', { action: 'email', email, currentPassword })) }
export async function updateMyPassword({ currentPassword, password }) { return unwrapData(await apiClient.patch('/me/account', { action: 'password', currentPassword, password, password_confirmation: password })) }
