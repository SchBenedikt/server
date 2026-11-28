<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { computed, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { ApiError, loadErrorMessage } from '../lib/api';
import {
	REPORT_CATEGORIES,
	getAdminApi,
	type ReportCategory,
} from '../lib/admin';
import { showToast } from '../lib/state';
import { t } from '../lib/i18n';

const props = withDefaults(defineProps<{
	open: boolean;
	targetType: 'post' | 'account';
	targetId: string;
	targetLabel?: string;
}>(), {
	targetLabel: '',
});

const emit = defineEmits<{ close: []; submitted: [] }>();

const category = ref<ReportCategory>('spam');
const comment = ref('');
const saving = ref(false);
const error = ref('');
const fieldErrors = ref<Record<string, string>>({});

const targetName = computed(() => (
	props.targetType === 'post' ? t('report.introPost') : t('report.introProfile')
));
const label = computed(() => props.targetLabel !== '' ? props.targetLabel : props.targetId);

watch(() => props.open, (open) => {
	if (open) {
		category.value = 'spam';
		comment.value = '';
		error.value = '';
		fieldErrors.value = {};
	}
});

function close(): void {
	if (saving.value) {
		return;
	}

	emit('close');
}

async function submit(): Promise<void> {
	if (saving.value) {
		return;
	}
	if (props.targetId.trim() === '') {
		error.value = t('report.targetMissing');
		return;
	}
	if (comment.value.length > 1000) {
		error.value = t('report.commentTooLong');
		return;
	}

	saving.value = true;
	error.value = '';
	fieldErrors.value = {};
	try {
		await getAdminApi().createReport({
			target_type: props.targetType,
			target_id: props.targetId,
			category: category.value,
			comment: comment.value.trim() === '' ? undefined : comment.value.trim(),
		});
		showToast(t('report.received'), 'success');
		emit('submitted');
		emit('close');
	} catch (caught) {
		if (caught instanceof ApiError) {
			error.value = loadErrorMessage(caught, caught.message);
			fieldErrors.value = caught.fields;
		} else {
			error.value = loadErrorMessage(caught, t('report.sendFailed'));
		}
	} finally {
		saving.value = false;
	}
}
</script>

<template>
	<Teleport to="body">
		<div
			v-if="open"
			class="a-modal-overlay"
			role="presentation"
			@click.self="close"
			@keydown.esc="close"
		>
			<form
				class="a-management-dialog a-admin-report-dialog"
				role="dialog"
				aria-modal="true"
				aria-labelledby="a-report-title"
				@submit.prevent="submit"
			>
				<header class="a-profile-edit__head">
					<div>
						<p class="a-profile-edit__eyebrow">{{ t('report.eyebrow') }}</p>
						<h2 id="a-report-title">{{ targetType === 'post' ? t('report.titlePost') : t('report.titleProfile') }}</h2>
					</div>
					<button type="button" class="a-icon-btn" :aria-label="t('common.closeDialog')" :disabled="saving" @click="close">
						<AppIcon name="x" :size="19" />
					</button>
				</header>

				<p class="a-admin-report-dialog__intro">
					{{ targetName }} <strong>{{ label }}</strong>{{ t('report.introSuffix') }}
				</p>

				<fieldset class="a-admin-report-dialog__categories">
					<legend class="a-profile-edit__label">{{ t('report.reason') }}</legend>
					<label
						v-for="entry in REPORT_CATEGORIES"
						:key="entry.id"
						class="a-admin-report-choice"
						:class="{ 'a-admin-report-choice--active': category === entry.id }"
					>
						<input v-model="category" type="radio" name="report-category" :value="entry.id">
						<span>
							<strong>{{ t(`report.cat.${entry.id}.label`) }}</strong>
							<small>{{ t(`report.cat.${entry.id}.hint`) }}</small>
						</span>
					</label>
				</fieldset>
				<p v-if="fieldErrors.category" class="a-form-error" role="alert">{{ fieldErrors.category }}</p>

				<label class="a-profile-edit__label" for="a-report-comment">{{ t('report.details') }} <span class="a-admin-muted">{{ t('report.optional') }}</span></label>
				<textarea
					id="a-report-comment"
					v-model="comment"
					class="a-admin-textarea"
					maxlength="1000"
					rows="4"
					:placeholder="t('report.detailsHint')"
				/>
				<p class="a-profile-edit__hint">{{ t('report.chars', { count: comment.length }) }}</p>
				<p v-if="fieldErrors.comment" class="a-form-error" role="alert">{{ fieldErrors.comment }}</p>
				<p v-if="error !== ''" class="a-form-error" role="alert">{{ error }}</p>

				<footer class="a-profile-edit__actions">
					<button type="button" class="a-btn a-btn--ghost a-btn--small" :disabled="saving" @click="close">
						{{ t('common.cancel') }}
					</button>
					<button type="submit" class="a-btn a-btn--primary a-btn--small" :disabled="saving || targetId === ''">
						<AppIcon v-if="saving" name="refresh" :size="15" class="a-spin" />
						<AppIcon v-else name="flag" :size="15" />
						{{ saving ? t('report.sending') : t('report.send') }}
					</button>
				</footer>
			</form>
		</div>
	</Teleport>
</template>
