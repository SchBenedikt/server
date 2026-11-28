import { ref } from 'vue';

/**
 * Timeline density preference. `null`/unknown values resolve to
 * 'comfortable'; 'compact' tightens card padding and meta spacing via the
 * `data-density` attribute on <html>.
 */
export const density = ref<'comfortable' | 'compact'>('comfortable');

export function applyDensity(value: string | null | undefined): void {
	density.value = value === 'compact' ? 'compact' : 'comfortable';
	document.documentElement.dataset.density = density.value;
}
