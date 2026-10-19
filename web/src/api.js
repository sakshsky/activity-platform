import axios from 'axios'

export const api = axios.create({
  baseURL: '/',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
  timeout: 30000,
})

export function errorMessage(error) {
  const data = error.response?.data
  return Object.values(data?.errors ?? {}).flat().join(' ') || data?.message || error.message || 'The request failed.'
}

export function localTime(value) {
  return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'Never'
}

export function filterParams(filters) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value != null))
}
