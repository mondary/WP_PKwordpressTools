(function () {
	'use strict';
	document.addEventListener('DOMContentLoaded', function () {
		var wrap = document.querySelector('.pkwt-calendar-wrap');
		if (!wrap) return;
		var months = wrap.querySelector('.pkwt-calendar-months');
		var status = wrap.querySelector('.pkwt-calendar-status');
		var monthInput = wrap.querySelector('.pkwt-calendar-month');
		var yearInput = wrap.querySelector('.pkwt-calendar-year');
		var visibleMonths = 2;
		var lastTrigger = null;

		function request(action, values) {
			var body = new URLSearchParams(Object.assign({ action: action, nonce: PKWTCalendar.nonce }, values));
			return fetch(PKWTCalendar.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body }).then(function (response) { return response.json(); });
		}
		function announce(message) { status.textContent = message || ''; }
		function load() {
			months.setAttribute('aria-busy', 'true'); announce(PKWTCalendar.i18n.loading);
			return request('pkwt_calendar_data', { month: monthInput.value, year: yearInput.value, months: visibleMonths, category: wrap.querySelector('[name="category"]').value, search: wrap.querySelector('[name="s"]').value })
				.then(function (data) { if (!data.success) throw new Error(data.data && data.data.message); months.innerHTML = data.data.html; announce(''); bindCards(); })
				.catch(function (error) { announce(error.message || PKWTCalendar.i18n.error); })
				.finally(function () { months.setAttribute('aria-busy', 'false'); });
		}
		function openDialog(dialog, trigger) { lastTrigger = trigger; dialog.showModal(); }
		function closeDialog(dialog) { dialog.close(); if (lastTrigger) lastTrigger.focus(); }
		function setError(dialog, message) { dialog.querySelector('.pkwt-dialog-error').textContent = message || ''; }
		function cardData(card, form) { form.postId.value = card.dataset.postId; if (form.title) form.title.value = card.dataset.title; form.date.value = card.dataset.date; if (form.time) form.time.value = card.dataset.time; }
		function bindCards() {
			var dragged = null;
			months.querySelectorAll('.pkwt-calendar-post[draggable="true"]').forEach(function (card) {
				card.addEventListener('dragstart', function () { dragged = card; });
				card.addEventListener('dragend', function () { dragged = null; });
				card.querySelector('[data-quick-edit]').addEventListener('click', function (event) { var dialog = document.getElementById('pkwt-calendar-edit-dialog'); cardData(card, dialog.querySelector('form')); setError(dialog, ''); openDialog(dialog, event.currentTarget); });
				card.querySelector('[data-move-date]').addEventListener('click', function (event) { var dialog = document.getElementById('pkwt-calendar-move-dialog'); cardData(card, dialog.querySelector('form')); setError(dialog, ''); openDialog(dialog, event.currentTarget); });
			});
			months.querySelectorAll('.pkwt-calendar-day[data-date]').forEach(function (day) {
				day.addEventListener('dragover', function (event) { event.preventDefault(); });
				day.addEventListener('drop', function (event) { event.preventDefault(); if (!dragged) return; request('pkwt_calendar_move_post', { postId: dragged.dataset.postId, date: day.dataset.date, time: dragged.dataset.time }).then(function (data) { if (!data.success) throw new Error(data.data && data.data.message); announce(data.data.message); return load(); }).catch(function (error) { announce(error.message || PKWTCalendar.i18n.moveError); }); });
			});
		}
		wrap.querySelectorAll('[data-calendar-nav]').forEach(function (button) { button.addEventListener('click', function () { var date = new Date(Number(yearInput.value), Number(monthInput.value) - 1, 1); date.setMonth(date.getMonth() + (button.dataset.calendarNav === 'next' ? 1 : -1)); monthInput.value = date.getMonth() + 1; yearInput.value = date.getFullYear(); visibleMonths = 2; load(); }); });
		wrap.querySelectorAll('[data-calendar-view]').forEach(function (button) { button.addEventListener('click', function () { if (button.dataset.calendarView === 'year') { monthInput.value = 1; visibleMonths = 12; } else { visibleMonths = Math.min(12, visibleMonths + 1); } load(); }); });
		monthInput.addEventListener('change', function () { visibleMonths = 2; load(); });
		yearInput.addEventListener('change', function () { visibleMonths = 2; load(); });
		wrap.querySelector('.pkwt-calendar-filters').addEventListener('submit', function (event) { event.preventDefault(); visibleMonths = 2; load(); });
		wrap.querySelectorAll('[data-open-dialog]').forEach(function (button) { button.addEventListener('click', function (event) { openDialog(document.getElementById(button.dataset.openDialog), event.currentTarget); }); });
		document.querySelectorAll('.pkwt-calendar-dialog').forEach(function (dialog) {
			dialog.addEventListener('close', function () { if (lastTrigger) lastTrigger.focus(); });
			dialog.querySelector('form').addEventListener('submit', function (event) {
				event.preventDefault(); if (event.submitter && event.submitter.value === 'cancel') { closeDialog(dialog); return; } var form = event.currentTarget; var id = dialog.id; if (!form.reportValidity()) return; setError(dialog, '');
				var action = id === 'pkwt-calendar-edit-dialog' ? 'pkwt_calendar_update_post' : id === 'pkwt-calendar-move-dialog' ? 'pkwt_calendar_move_post' : 'pkwt_calendar_reallocate';
				var values = {}; new FormData(form).forEach(function (value, key) { values[key] = value; });
				request(action, values).then(function (data) { if (!data.success) throw new Error(data.data && data.data.message); closeDialog(dialog); announce(data.data.message); if (action === 'pkwt_calendar_reallocate') { showResults(data.data.results); } return load(); }).catch(function (error) { setError(dialog, error.message || PKWTCalendar.i18n.error); });
			});
		});
		function showResults(results) { var dialog = document.getElementById('pkwt-calendar-results-dialog'); var target = dialog.querySelector('.pkwt-calendar-results'); target.innerHTML = ''; [[PKWTCalendar.i18n.moved, results.moved], [PKWTCalendar.i18n.normalized, results.normalized], [PKWTCalendar.i18n.skipped, results.skipped]].forEach(function (group) { var heading = document.createElement('h3'); heading.textContent = group[0] + ' (' + group[1].length + ')'; target.appendChild(heading); var list = document.createElement('ul'); group[1].forEach(function (item) { var li = document.createElement('li'); li.textContent = item; list.appendChild(li); }); target.appendChild(list); }); openDialog(dialog, null); }
		bindCards();
	});
}());
