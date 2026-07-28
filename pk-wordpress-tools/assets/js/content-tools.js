(function () {
	'use strict';

	function confirmDelete(event) {
		var form = event.currentTarget;
		var message = form.getAttribute('data-pkwt-confirm');
		if (message && !window.confirm(message)) {
			event.preventDefault();
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('form[data-pkwt-confirm]').forEach(function (form) {
			form.addEventListener('submit', confirmDelete);
		});
	});
}());
