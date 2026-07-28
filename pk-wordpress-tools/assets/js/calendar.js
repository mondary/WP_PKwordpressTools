(function () {
	'use strict';
	document.addEventListener('DOMContentLoaded', function () {
		var dragged;
		document.querySelectorAll('.pkwt-calendar-post').forEach(function (card) {
			card.addEventListener('dragstart', function () { dragged = card; });
			card.addEventListener('dragend', function () { dragged = null; });
		});
		document.querySelectorAll('.pkwt-calendar-day[data-date]').forEach(function (day) {
			day.addEventListener('dragover', function (event) { event.preventDefault(); });
			day.addEventListener('drop', function (event) {
				event.preventDefault();
				if (!dragged) return;
				fetch(PKWTCalendar.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: new URLSearchParams({ action: 'pkwt_calendar_move_post', nonce: PKWTCalendar.nonce, postId: dragged.dataset.postId, date: day.dataset.date }) })
					.then(function (response) { return response.json(); })
					.then(function (data) { if (data.success) day.appendChild(dragged); else window.alert(data.data && data.data.message || PKWTCalendar.i18n.moveError); })
					.catch(function () { window.alert(PKWTCalendar.i18n.moveError); });
			});
		});
		var form = document.querySelector('.pkwt-calendar-reallocate');
		if (form) form.addEventListener('submit', function (event) { if (!window.confirm(PKWTCalendar.i18n.confirmReallocate)) event.preventDefault(); });
	});
}());
