/* Matin AI: keeps every app page inside the chat shell.
 * Internal links and forms are loaded in the background and shown in #page-view,
 * so the visitor never leaves the conversation screen. Works without JavaScript too:
 * links then open the normal pages, which redirect into the shell. */
(() => {
	const view = document.getElementById('page-view');
	const body = document.getElementById('page-view-body');
	const chat = document.getElementById('conversation');
	const composer = document.querySelector('.composer-wrap');
	const title = document.getElementById('chat-title');
	if (!view || !body || !chat) return;

	const cfg = window.matinNav || { homePath: '/' };
	const home = cfg.homePath || '/';
	const chatTitle = title ? title.textContent : '';
	const SKIP = /wp-admin|wp-login|admin-post|wp-json|logout|matin_ticket|matin_asset|\.svg(\?|$)/i;

	const withLang = (url) => {
		const u = new URL(url, location.href);
		if (!u.searchParams.has('lang')) u.searchParams.set('lang', document.documentElement.lang || 'fa');
		return u;
	};
	const isHome = (u) => u.pathname === home;

	const showChat = (push) => {
		view.hidden = true;
		body.replaceChildren();
		chat.hidden = false;
		if (composer) composer.hidden = false;
		if (title) title.textContent = chatTitle;
		document.body.classList.remove('sidebar-open');
		if (push) history.pushState(null, '', home + (location.search.includes('lang=') ? location.search : ''));
	};

	const showPage = (doc, finalUrl, hash) => {
		const main = doc.querySelector('main#main');
		if (!main || main.classList.contains('chat-app') || (isHome(finalUrl) && !finalUrl.searchParams.has('matin_view'))) {
			location.href = finalUrl.href; // login/register or a page that is the shell itself: full load
			return false;
		}
		body.replaceChildren(document.importNode(main, true));
		chat.hidden = true;
		if (composer) composer.hidden = true;
		view.hidden = false;
		const h1 = body.querySelector('h1');
		if (title) title.textContent = h1 ? h1.textContent.trim() : chatTitle;
		document.title = (doc.title || document.title);
		document.body.classList.remove('sidebar-open');
		window.scrollTo(0, 0);
		if (hash === '#register' || hash === '#login') {
			body.querySelector(hash === '#register' ? 'label[for="matin-tab-register"]' : 'label[for="matin-tab-login"]')?.click();
		}
		return true;
	};

	const load = async (url, init = {}, push = true) => {
		const target = withLang(url);
		view.classList.add('is-loading');
		try {
			const res = await fetch(target.href, { credentials: 'same-origin', redirect: 'follow', ...init, headers: { 'X-Matin-View': '1', Accept: 'text/html', ...(init.headers || {}) } });
			const finalUrl = new URL(res.url || target.href);
			const html = await res.text();
			const doc = new DOMParser().parseFromString(html, 'text/html');
			if (!showPage(doc, finalUrl, target.hash)) return;
			const path = finalUrl.pathname + finalUrl.search + finalUrl.hash;
			if (push) history.pushState({ matinView: path }, '', home + '?matin_view=' + encodeURIComponent(path));
		} catch (error) {
			location.href = target.href; // network problem: fall back to a normal page load
		} finally {
			view.classList.remove('is-loading');
		}
	};

	document.addEventListener('click', (event) => {
		if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		const link = event.target.closest('a[href]');
		if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
		const u = new URL(link.href, location.href);
		if (u.origin !== location.origin || SKIP.test(u.pathname + u.search)) return;
		if (isHome(u)) {
			if (u.searchParams.has('lang') || u.searchParams.has('event') || u.searchParams.has('matin_view')) return; // language switch, reservation link: normal load
			if (!view.hidden) { event.preventDefault(); showChat(true); }
			return;
		}
		event.preventDefault();
		load(u.href, {}, true);
	});

	view.addEventListener('submit', (event) => {
		if (event.defaultPrevented) return; // e.g. a confirm() that was cancelled
		const form = event.target;
		if (!(form instanceof HTMLFormElement)) return;
		const action = new URL(form.getAttribute('action') || location.href, location.href);
		if (action.origin !== location.origin || SKIP.test(action.pathname)) return;
		event.preventDefault();
		const data = new FormData(form, event.submitter || undefined);
		if ((form.method || 'get').toLowerCase() === 'post') {
			load(action.href, { method: 'POST', body: data }, true);
		} else {
			const q = new URL(action.href);
			for (const [key, value] of data) q.searchParams.set(key, value);
			load(q.href, {}, true);
		}
	});

	document.getElementById('page-back')?.addEventListener('click', () => showChat(true));
	window.addEventListener('popstate', (event) => {
		if (event.state && event.state.matinView) load(event.state.matinView, {}, false);
		else showChat(false);
	});

	// Direct visits to app pages arrive as /?matin_view=/cafes/ and open inside the shell.
	const params = new URLSearchParams(location.search);
	if (params.has('matin_view')) {
		const path = params.get('matin_view');
		if (path && path[0] === '/' && path[1] !== '/') {
			load(path, {}, false); // the URL stays on the main page: /?matin_view=…
		}
	}
})();
