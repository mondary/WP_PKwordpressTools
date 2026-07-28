(function (wp, settings) {
	'use strict';
	var el = wp.element.createElement;
	var requested = false;
	function meaningfulSchedule(post) {
		if (post.status === 'future') return true;
		if (!post.date) return false;
		return new Date(post.date).getTime() > Date.now() + 60000;
	}
	function CalendarPanel() {
		var post = wp.data.useSelect(function (select) { return select('core/editor').getCurrentPost(); }, []);
		var state = wp.element.useState('');
		wp.element.useEffect(function () {
			if (!post || requested || !['draft', 'auto-draft', 'future'].includes(post.status) || meaningfulSchedule(post)) return;
			requested = true;
			fetch(settings.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: new URLSearchParams({ action: 'pkwt_calendar_next_slot', nonce: settings.nonce }) })
				.then(function (response) { return response.json(); })
				.then(function (response) {
					var date = response.success && response.data.date;
					if (!date) { state[1](settings.empty); return; }
					wp.data.dispatch('core/editor').editPost({ date: date });
					state[1](settings.label + ': ' + date.replace('T', ' '));
				});
		}, [post && post.id]);
		return el(wp.editPost.PluginDocumentSettingPanel, { name: 'pkwt-calendar-slot', title: 'Calendrier', className: 'pkwt-calendar-editor-panel' }, el('p', null, state[0] || settings.label));
	}
	wp.plugins.registerPlugin('pkwt-calendar-slot', { render: CalendarPanel });
}(window.wp, window.PKWTCalendarEditor));
