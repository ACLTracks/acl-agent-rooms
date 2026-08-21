(function () {
	'use strict';

	function slugify(value) {
		return String(value || '')
			.toLowerCase()
			.trim()
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-+|-+$/g, '');
	}

	function formField(form, name) {
		var fields = form.elements;
		var i;

		for (i = 0; i < fields.length; i++) {
			if (fields[i].name === name) {
				return fields[i];
			}
		}

		return null;
	}

	function optionIsSpecial(option) {
		return option.value === 'default' || option.dataset.aclArCustomModel === '1';
	}

	function setOptionVisibility(option, visible) {
		option.hidden = !visible;
		option.disabled = !visible;
	}

	function refreshOptgroups(select) {
		Array.prototype.forEach.call(select.querySelectorAll('optgroup'), function (group) {
			var hasVisibleOption = false;

			Array.prototype.forEach.call(group.querySelectorAll('option'), function (option) {
				if (!option.hidden) {
					hasVisibleOption = true;
				}
			});

			group.hidden = !hasVisibleOption;
			group.style.display = hasVisibleOption ? '' : 'none';
		});
	}

	function selectedOptionIsVisible(select) {
		var selected = select.options[select.selectedIndex];
		return selected && !selected.hidden && !selected.disabled;
	}

	function firstVisibleModelValue(select) {
		var i;

		for (i = 0; i < select.options.length; i++) {
			if (!optionIsSpecial(select.options[i]) && !select.options[i].hidden && !select.options[i].disabled) {
				return select.options[i].value;
			}
		}

		return 'default';
	}

	function setupModelSelect(form, select) {
		var provider = formField(form, select.dataset.providerField || '');
		var custom = formField(form, select.dataset.customField || '');
		var warning = select.parentNode.querySelector('.acl-ar-model-no-matches');
		var filterEnabled = select.dataset.providerFilter === '1';
		var initialProvider = provider ? provider.value : '';
		var initialModel = select.dataset.initialModel || select.value;

		function applyFilter() {
			var providerValue = provider ? provider.value : '';
			var providerChanged = providerValue !== initialProvider;
			var keepFullList = !filterEnabled || providerValue === '' || providerValue === 'default';
			var visibleModelCount = 0;

			Array.prototype.forEach.call(select.options, function (option) {
				var owner = option.dataset.provider || '';
				var visible = keepFullList || optionIsSpecial(option) || owner === providerValue || owner === '';

				if (!providerChanged && option.value === initialModel && option.defaultSelected) {
					visible = true;
				}

				setOptionVisibility(option, visible);
				if (visible && !optionIsSpecial(option)) {
					visibleModelCount++;
				}
			});

			refreshOptgroups(select);

			if (!selectedOptionIsVisible(select)) {
				select.value = providerChanged ? firstVisibleModelValue(select) : initialModel;
				if (!selectedOptionIsVisible(select)) {
					select.value = 'default';
				}
			}

			if (custom) {
				custom.hidden = select.value !== '__acl_ar_custom_model__';
				custom.disabled = select.value !== '__acl_ar_custom_model__';
			}

			if (warning) {
				warning.hidden = keepFullList || visibleModelCount > 0;
			}
		}

		if (provider) {
			provider.addEventListener('change', applyFilter);
		}

		select.addEventListener('change', applyFilter);
		applyFilter();
	}

	function setupSlugFields(form) {
		var name = form.querySelector('input[name="name"], input[name="title"]');
		var slug = form.querySelector('input[name="slug"]');

		if (!name || !slug) {
			return;
		}

		slug.addEventListener('input', function () {
			slug.dataset.touched = '1';
		});

		name.addEventListener('input', function () {
			if (!slug.dataset.touched && !slug.value) {
				slug.value = slugify(name.value);
			}
		});
	}

	function copyText(value) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(value);
		}

		return new Promise(function (resolve, reject) {
			var textarea = document.createElement('textarea');
			textarea.value = value;
			textarea.setAttribute('readonly', 'readonly');
			textarea.style.position = 'fixed';
			textarea.style.left = '-9999px';
			document.body.appendChild(textarea);
			textarea.select();

			try {
				if (!document.execCommand('copy')) {
					throw new Error('Copy command was unavailable.');
				}
				resolve();
			} catch (error) {
				reject(error);
			} finally {
				document.body.removeChild(textarea);
			}
		});
	}

	function setupCopyButton(button) {
		var selector = button.dataset.aclArCopy || '';
		var target = selector ? document.querySelector(selector) : null;
		var status = button.parentNode ? button.parentNode.querySelector('.acl-ar-copy-status') : null;

		if (!target) {
			return;
		}

		target.addEventListener('focus', function () {
			if (target.select) {
				target.select();
			}
		});

		button.addEventListener('click', function () {
			var value = target.value || target.textContent || '';
			copyText(value).then(function () {
				if (status) {
					status.textContent = 'Copied';
				}
			}).catch(function () {
				if (target.select) {
					target.select();
				}
				if (status) {
					status.textContent = 'Select and copy manually';
				}
			});
		});
	}

	function setupAvatarUpload(field) {
		var idInput = field.querySelector('[data-acl-ar-avatar-id]');
		var removeInput = field.querySelector('[data-acl-ar-avatar-remove]');
		var preview = field.querySelector('[data-acl-ar-avatar-preview]');
		var selectButton = field.querySelector('[data-acl-ar-avatar-select]');
		var removeButton = field.querySelector('[data-acl-ar-avatar-remove-button]');
		var frame;

		function initials() {
			var form = field.closest('form');
			var name = form ? form.querySelector('input[name="name"]') : null;
			var value = name ? name.value.trim() : '';
			var words = value ? value.split(/\s+/) : [];
			return ((words[0] || 'A').charAt(0) + (words[1] || '').charAt(0)).toUpperCase();
		}

		function setPreview(url, alt) {
			if (!preview) {
				return;
			}

			if (url) {
				preview.innerHTML = '';
				var image = document.createElement('img');
				image.src = url;
				image.alt = alt || '';
				preview.appendChild(image);
			} else {
				preview.innerHTML = '';
				var fallback = document.createElement('span');
				fallback.textContent = initials();
				preview.appendChild(fallback);
			}
		}

		if (!idInput || !selectButton || !preview) {
			return;
		}

		selectButton.addEventListener('click', function () {
			if (!window.wp || !window.wp.media) {
				return;
			}

			if (!frame) {
				frame = window.wp.media({
					title: 'Select agent avatar',
					button: {
						text: 'Use this image'
					},
					library: {
						type: 'image'
					},
					multiple: false
				});

				frame.on('select', function () {
					var attachment = frame.state().get('selection').first();
					var data = attachment ? attachment.toJSON() : null;
					var thumbnail = data && data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : '';

					if (!data || !data.id) {
						return;
					}

					idInput.value = String(data.id);
					if (removeInput) {
						removeInput.value = '0';
					}
					setPreview(thumbnail || data.url || '', data.alt || data.title || '');
					if (removeButton) {
						removeButton.disabled = false;
					}
				});
			}

			frame.open();
		});

		if (removeButton) {
			removeButton.addEventListener('click', function () {
				idInput.value = '0';
				if (removeInput) {
					removeInput.value = '1';
				}
				setPreview('', '');
				removeButton.disabled = true;
			});
		}
	}

	function setupExecutionMode(form) {
		var mode = form.querySelector('[data-acl-ar-execution-mode]');
		var brainRow = form.querySelector('[data-acl-ar-brain-row]');
		var brain = form.querySelector('[data-acl-ar-brain-select]');
		var runtime = form.querySelector('[data-acl-ar-brain-runtime]');
		var independentNames = ['config_mode', 'shared_config_id', 'provider_route', 'model', 'model_custom', 'temperature', 'max_tokens'];

		if (!mode || !brainRow || !brain) {
			return;
		}

		function refresh() {
			var shared = mode.value === 'brain';
			brainRow.hidden = !shared;
			brain.disabled = !shared;
			brain.required = shared;
			independentNames.forEach(function (name) {
				var field = formField(form, name);
				if (field) {
					field.disabled = shared;
					var row = field.closest('tr');
					if (row) {
						row.classList.toggle('acl-ar-runtime-inherited', shared);
					}
				}
			});
			var option = brain.options[brain.selectedIndex];
			if (runtime) {
				runtime.textContent = shared && option && option.value !== '0'
					? 'Inherited runtime: ' + (option.dataset.provider || '') + ' / ' + (option.dataset.model || '')
					: '';
			}
		}

		mode.addEventListener('change', refresh);
		brain.addEventListener('change', refresh);
		refresh();
	}

	function setupConversationMode(form) {
		var mode = form.querySelector('[data-acl-ar-conversation-mode]');
		var rows = form.querySelectorAll('[data-acl-ar-natural-room-row]');
		if (!mode || !rows.length) { return; }
		function refresh() {
			var natural = mode.value === 'natural';
			Array.prototype.forEach.call(rows, function (row) {
				row.hidden = !natural;
				Array.prototype.forEach.call(row.querySelectorAll('input, select, textarea'), function (field) { field.disabled = !natural; });
			});
		}
		mode.addEventListener('change', refresh);
		refresh();
	}

	function setupNaturalPresets(form) {
		var role = form.querySelector('[data-acl-ar-natural-role]');
		if (!role) { return; }
		var presets = {
			quiet: {participation: 30, question: 10, cooldown: 45, limit: 2},
			balanced: {participation: 60, question: 20, cooldown: 20, limit: 4},
			talkative: {participation: 85, question: 25, cooldown: 12, limit: 6},
			facilitator: {participation: 70, question: 65, cooldown: 20, limit: 4}
		};
		function apply(name) {
			var values = presets[name]; if (!values) { return; }
			role.value = name;
			Object.keys(values).forEach(function (key) { var field = form.querySelector('[data-acl-ar-natural-field="' + key + '"]'); if (field) { field.value = String(values[key]); field.dispatchEvent(new Event('input', {bubbles: true})); } });
		}
		form.querySelectorAll('[data-acl-ar-natural-preset]').forEach(function (button) { button.addEventListener('click', function () { apply(button.dataset.aclArNaturalPreset); }); });
	}

	function setupNaturalPairValidation(form) {
		var pairs = [
			['natural_min_responders', 'natural_max_responders', false],
			['natural_initial_delay_min_seconds', 'natural_initial_delay_max_seconds', false],
			['natural_inter_turn_delay_min_seconds', 'natural_inter_turn_delay_max_seconds', false],
			['natural_delay_min_seconds', 'natural_delay_max_seconds', true]
		];
		pairs.forEach(function (pair) {
			var minimum = formField(form, pair[0]);
			var maximum = formField(form, pair[1]);
			if (!minimum || !maximum || !minimum.setCustomValidity || !maximum.setCustomValidity) { return; }
			function validate() {
				var minBlank = minimum.value === '';
				var maxBlank = maximum.value === '';
				var incomplete = pair[2] && minBlank !== maxBlank;
				var reversed = !minBlank && !maxBlank && Number(minimum.value) > Number(maximum.value);
				minimum.setCustomValidity(incomplete ? 'Enter both delay values or leave both blank.' : '');
				maximum.setCustomValidity(incomplete ? 'Enter both delay values or leave both blank.' : (reversed ? 'Maximum must be greater than or equal to minimum.' : ''));
			}
			minimum.addEventListener('input', validate);
			maximum.addEventListener('input', validate);
			validate();
		});
	}

	if (typeof document !== 'undefined') { document.addEventListener('DOMContentLoaded', function () {
			document.querySelectorAll('form').forEach(function (form) {
			setupSlugFields(form);
				setupExecutionMode(form);
				setupConversationMode(form);
				setupNaturalPresets(form);
				setupNaturalPairValidation(form);

			form.querySelectorAll('.acl-ar-model-select').forEach(function (select) {
				setupModelSelect(form, select);
			});
		});

		document.querySelectorAll('[data-acl-ar-copy]').forEach(setupCopyButton);
		document.querySelectorAll('[data-acl-ar-avatar-field]').forEach(setupAvatarUpload);
	}); }

	if (typeof module === 'object' && module.exports) {
		module.exports = {
			setupConversationMode: setupConversationMode,
			setupNaturalPresets: setupNaturalPresets,
			setupNaturalPairValidation: setupNaturalPairValidation
		};
	}
}());
