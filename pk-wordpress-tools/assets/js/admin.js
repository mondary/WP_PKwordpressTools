/* WP PK Tools — manager + lab interactions */
(function ($) {
	'use strict';

	function debounce(fn, ms) {
		var t;
		return function () {
			var ctx = this, args = arguments;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(ctx, args); }, ms);
		};
	}

	$(function () {
		// ---------- Manager & Lab search filter ----------
		function wireSearch($input, $cards) {
			if (!$input.length || !$cards.length) return;
			$input.on('input', debounce(function () {
				var q = $input.val().toString().toLowerCase().trim();
				$cards.each(function () {
					var name = ($(this).data('name') || '').toString();
					var show = !q || name.indexOf(q) !== -1;
					$(this).toggle(show);
				});
			}, 150));
		}
		wireSearch($('#pkwt-lab-search'), $('.pkwt-lab-card'));
		wireSearch($('#pkwt-manager-search'), $('.pkwt-mgr-card'));

		// ---------- Manager: editable notes (AJAX save) ----------
		$('.pkwt-mgr-note__text').each(function () {
			var $el = $(this);
			var pluginFile = $el.data('plugin-file');
			var nonce = $el.data('nonce');
			var ajaxUrl = $el.data('ajax-url');
			var $hint = $el.siblings('.pkwt-mgr-note__hint');
			var $saved = $hint.find('.pkwt-mgr-note__saved');
			var $spinner = $hint.find('.pkwt-mgr-note__spinner');
			var orig = $el.text();

			$el.attr('data-placeholder', (window.PKWT_i18n && PKWT_i18n.placeholder) || 'Ajouter une note...');
			if (!orig) $el.empty();

			var save = debounce(function () {
				var val = $el.html();
				if (val === orig) return;
				orig = val;
				$saved.removeClass('is-visible');
				$spinner.addClass('is-active');
				$.post(ajaxUrl, {
					action: 'pkwt_save_note',
					nonce: nonce,
					plugin: pluginFile,
					note: val
				}, function (res) {
					$spinner.removeClass('is-active');
					if (res && res.success) {
						$saved.text((window.PKWT_i18n && PKWT_i18n.saved) || 'Enregistré').addClass('is-visible');
						setTimeout(function () { $saved.removeClass('is-visible'); }, 1800);
					} else {
						$saved.text((window.PKWT_i18n && PKWT_i18n.error) || 'Erreur').addClass('is-visible');
					}
				}).fail(function () {
					$spinner.removeClass('is-active');
					$saved.text((window.PKWT_i18n && PKWT_i18n.error) || 'Erreur').addClass('is-visible');
				});
			}, 500);

			$el.on('input', save);
			$el.on('blur', function () { $saved.removeClass('is-visible'); });
		});

		// ---------- Lab: copy-to-clipboard ----------
		$('.pkwt-copy-btn').on('click', function () {
			var code = $(this).data('code') || '';
			var $btn = $(this);
			var orig = $btn.text();
			var done = function () {
				$btn.text((window.PKWT_i18n && PKWT_i18n.copied) || 'Copié !').addClass('is-copied');
				setTimeout(function () { $btn.text(orig).removeClass('is-copied'); }, 1400);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(code).then(done).catch(function () { fallbackCopy(code, done); });
			} else {
				fallbackCopy(code, done);
			}
		});

		function fallbackCopy(text, cb) {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy'); cb(); } catch (e) {}
			document.body.removeChild(ta);
		}

		// ---------- Feature toggle switches (built-in features) ----------
		$('.pkwt-feature-toggle').on('change', function () {
			var feature = $(this).data('feature');
			var enabled = $(this).is(':checked');
			var ajaxUrl = (window.PKWT && PKWT.ajaxUrl) || '';
			var nonce = (window.PKWT && PKWT.nonce) || '';
			$.post(ajaxUrl, {
				action: 'pkwt_toggle_feature',
				nonce: nonce,
				feature: feature,
				enabled: enabled
			}, function () {}.bind(this)).fail(function () {
				$(this).prop('checked', !enabled);
			}.bind(this));
		});

		// ---------- Preset toggle switches ----------
		$('.pkwt-preset-toggle').on('change', function () {
			var slug = $(this).data('slug');
			var enabled = $(this).is(':checked');
			var ajaxUrl = (window.PKWT && PKWT.ajaxUrl) || '';
			var nonce = (window.PKWT && PKWT.nonce) || '';
			var $item = $(this).closest('.pkwt-lab-item');
			$item.toggleClass('is-on', enabled);
			$.post(ajaxUrl, {
				action: 'pkwt_toggle_preset',
				nonce: nonce,
				slug: slug,
				enabled: enabled
			}).fail(function () {
				$item.toggleClass('is-on', !enabled);
				$(this).prop('checked', !enabled);
			}.bind(this));
		});

		// ---------- Expand/collapse code ----------
		$('.pkwt-lab-code-toggle').on('click', function () {
			var $item = $(this).closest('.pkwt-lab-item');
			var $code = $item.find('.pkwt-lab-item__code');
			var is_open = $code.is(':visible');
			$code.slideToggle(150);
			$(this).text(is_open ? 'Voir le code' : 'Masquer');
		});

		// ---------- Confirm delete ----------
		$('.pkwt-delete').on('click', function () {
			var msg = (window.PKWT && PKWT.i18n && PKWT.i18n.confirmDelete) || 'Supprimer ?';
			return window.confirm(msg);
		});

		// ---------- Magnetic hover on topbar tabs ----------
		$('.pkwt-topbar__link').each(function () {
			var $tab = $(this);
			$tab.on('mousemove', function (e) {
				var r = this.getBoundingClientRect();
				var dx = (e.clientX - r.left - r.width / 2) * 0.18;
				var dy = (e.clientY - r.top - r.height / 2) * 0.18;
				$tab.css('transform', 'translate(' + dx + 'px, ' + dy + 'px)');
			}).on('mouseleave', function () {
				$tab.css('transform', '');
			});
		});
	});
})(jQuery);
