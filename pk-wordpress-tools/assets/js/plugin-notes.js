/* WP PK Tools — plugin notes inline-edit on plugins.php */
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
		$('.pkwt-plugin-note__text').each(function () {
			var $el = $(this);
			var pluginFile = $el.data('plugin-file');
			var nonce = $el.data('nonce');
			var ajaxUrl = $el.data('ajax-url');
			var $wrap = $el.closest('.pkwt-plugin-note');
			var $hint = $wrap.find('.pkwt-plugin-note__hint');
			var $saved = $hint.find('.pkwt-plugin-note__saved');
			var $spinner = $hint.find('.pkwt-plugin-note__spinner');
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
					}
				}).fail(function () {
					$spinner.removeClass('is-active');
				});
			}, 500);

			$el.on('input', save);
			$el.on('blur', function () { $saved.removeClass('is-visible'); });
		});
	});
})(jQuery);
