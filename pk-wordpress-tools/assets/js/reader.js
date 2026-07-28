(function () {
	'use strict';

	function pkwtIsEditable(element) {
		return element && (element.isContentEditable || /^(INPUT|TEXTAREA|SELECT|BUTTON)$/i.test(element.tagName));
	}

	function pkwtInitReader() {
		var pkwtLaunch = document.querySelector('.pkwt-reader-launch');
		var pkwtDialog = document.querySelector('.pkwt-reader');
		if (!pkwtLaunch || !pkwtDialog || !window.PKWTReader) return;

		var pkwtArticle = pkwtDialog.querySelector('.pkwt-reader__article');
		var pkwtStatus = pkwtDialog.querySelector('.pkwt-reader__status');
		var pkwtClose = pkwtDialog.querySelector('.pkwt-reader__close');
		var pkwtPrevious = pkwtDialog.querySelector('.pkwt-reader__previous');
		var pkwtNext = pkwtDialog.querySelector('.pkwt-reader__next');
		var pkwtPosition = pkwtDialog.querySelector('.pkwt-reader__position');
		var pkwtPosts = [];
		var pkwtIndex = 0;
		var pkwtReturnFocus;
		var pkwtRequested = false;

		function pkwtSetStatus(message) { pkwtStatus.textContent = message; }

		function pkwtRenderPost() {
			var pkwtPost = pkwtPosts[pkwtIndex];
			if (!pkwtPost) return;
			var pkwtImage = pkwtPost.featuredImage ? '<img class="pkwt-reader__featured-image" src="' + pkwtEscapeAttribute(pkwtPost.featuredImage) + '" alt="">' : '';
			var pkwtDate = pkwtPost.date ? new Date(pkwtPost.date).toLocaleDateString() : '';
			var pkwtMeta = [pkwtDate, pkwtPost.author].filter(Boolean).join(' · ');
			pkwtArticle.innerHTML = pkwtImage + '<h1 id="pkwt-reader-title">' + pkwtPost.title + '</h1><p class="pkwt-reader__meta">' + pkwtEscapeHtml(pkwtMeta) + '</p>' + (pkwtPost.excerpt ? '<div class="pkwt-reader__excerpt">' + pkwtPost.excerpt + '</div>' : '') + '<div class="pkwt-reader__content">' + pkwtPost.content + '</div>';
			pkwtPrevious.disabled = pkwtIndex === 0;
			pkwtNext.disabled = pkwtIndex === pkwtPosts.length - 1;
			pkwtPosition.textContent = (pkwtIndex + 1) + ' / ' + pkwtPosts.length;
			pkwtSetStatus('');
			pkwtDialog.scrollTop = 0;
		}

		function pkwtEscapeAttribute(value) {
			return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
		}

		function pkwtEscapeHtml(value) {
			return pkwtEscapeAttribute(value).replace(/>/g, '&gt;');
		}

		function pkwtLoadPosts() {
			if (pkwtRequested) return;
			pkwtRequested = true;
			pkwtArticle.setAttribute('aria-busy', 'true');
			pkwtSetStatus(PKWTReader.i18n.loading);
			window.fetch(PKWTReader.apiUrl, { credentials: 'same-origin' })
				.then(function (response) { if (!response.ok) throw new Error('Reader request failed'); return response.json(); })
				.then(function (posts) {
					pkwtPosts = Array.isArray(posts) ? posts : [];
					if (pkwtPosts.length) pkwtRenderPost(); else pkwtSetStatus(PKWTReader.i18n.empty);
				})
				.catch(function () { pkwtSetStatus(PKWTReader.i18n.error); })
				.finally(function () { pkwtArticle.setAttribute('aria-busy', 'false'); });
		}

		function pkwtOpen() {
			pkwtReturnFocus = document.activeElement;
			pkwtDialog.hidden = false;
			pkwtDialog.setAttribute('aria-hidden', 'false');
			document.documentElement.classList.add('pkwt-reader-open');
			pkwtClose.focus();
			pkwtLoadPosts();
		}

		function pkwtCloseReader() {
			if (pkwtDialog.hidden) return;
			pkwtDialog.hidden = true;
			pkwtDialog.setAttribute('aria-hidden', 'true');
			document.documentElement.classList.remove('pkwt-reader-open');
			if (pkwtReturnFocus && document.contains(pkwtReturnFocus)) pkwtReturnFocus.focus();
		}

		function pkwtChangePost(direction) {
			var pkwtNextIndex = pkwtIndex + direction;
			if (pkwtNextIndex >= 0 && pkwtNextIndex < pkwtPosts.length) { pkwtIndex = pkwtNextIndex; pkwtRenderPost(); }
		}

		pkwtLaunch.addEventListener('click', pkwtOpen);
		pkwtClose.addEventListener('click', pkwtCloseReader);
		pkwtPrevious.addEventListener('click', function () { pkwtChangePost(-1); });
		pkwtNext.addEventListener('click', function () { pkwtChangePost(1); });
		document.addEventListener('keydown', function (event) {
			if (pkwtDialog.hidden) return;
			if (event.key === 'Escape') { event.preventDefault(); pkwtCloseReader(); return; }
			if (event.key === 'ArrowLeft' && !pkwtIsEditable(event.target)) { event.preventDefault(); pkwtChangePost(-1); }
			if (event.key === 'ArrowRight' && !pkwtIsEditable(event.target)) { event.preventDefault(); pkwtChangePost(1); }
			if (event.key === 'Tab') {
				var pkwtFocusable = Array.prototype.filter.call(pkwtDialog.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'), function (element) { return element.offsetParent !== null; });
				if (!pkwtFocusable.length) return;
				var pkwtFirst = pkwtFocusable[0];
				var pkwtLast = pkwtFocusable[pkwtFocusable.length - 1];
				if (event.shiftKey && document.activeElement === pkwtFirst) { event.preventDefault(); pkwtLast.focus(); }
				if (!event.shiftKey && document.activeElement === pkwtLast) { event.preventDefault(); pkwtFirst.focus(); }
			}
		});
	}

	function pkwtInitSearchShortcut() {
		document.addEventListener('keydown', function (event) {
			if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || event.key.length !== 1 || pkwtIsEditable(event.target)) return;
			var pkwtToggle = document.querySelector('.search-toggle-open');
			if (!pkwtToggle) return;
			var pkwtDrawer = pkwtToggle;
			var pkwtControls = pkwtToggle.getAttribute('aria-controls');
			if (pkwtControls) pkwtDrawer = document.getElementById(pkwtControls) || pkwtToggle;
			var pkwtInput = pkwtDrawer.matches('input[type="search"], input[type="text"]') ? pkwtDrawer : pkwtDrawer.querySelector('input[type="search"], input[type="text"]');
			if (!pkwtInput) return;
			event.preventDefault();
			pkwtToggle.click();
			pkwtInput.focus();
			pkwtInput.value += event.key;
			pkwtInput.dispatchEvent(new Event('input', { bubbles: true }));
		});
	}

	function pkwtInitProgress() {
		var pkwtBar = document.querySelector('.pkwt-reading-progress__bar');
		if (!pkwtBar) return;
		var pkwtFrame = 0;
		function pkwtUpdateProgress() {
			pkwtFrame = 0;
			var pkwtDocument = document.documentElement;
			var pkwtHeight = Math.max(pkwtDocument.scrollHeight, document.body.scrollHeight) - window.innerHeight;
			var pkwtProgress = pkwtHeight > 0 ? Math.min(1, Math.max(0, window.scrollY / pkwtHeight)) : 0;
			pkwtBar.style.width = (pkwtProgress * 100) + '%';
		}
		window.addEventListener('scroll', function () { if (!pkwtFrame) pkwtFrame = window.requestAnimationFrame(pkwtUpdateProgress); }, { passive: true });
		window.addEventListener('resize', pkwtUpdateProgress, { passive: true });
		pkwtUpdateProgress();
	}

	document.addEventListener('DOMContentLoaded', function () { pkwtInitReader(); pkwtInitSearchShortcut(); pkwtInitProgress(); });
}());
