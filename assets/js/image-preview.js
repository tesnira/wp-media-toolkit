/**
 * 图片预览灯箱前端逻辑。
 */
(function () {
	function boot() {
		var payload = window.WPMT_IMAGE_PREVIEW || {};
		var configs = payload.configs || [];
		var root = document.querySelector('.wpmt-lightbox');
		if (!root || !configs.length) {
			return;
		}

		var stageEl = root.querySelector('[data-role="stage"]');
		var imgEl = root.querySelector('[data-role="img"]');
		var metaTitleEl = root.querySelector('[data-role="title"]');
		var metaEl = root.querySelector('[data-role="meta"]');
		var btnClose = root.querySelector('[data-role="close"]');
		var btnPrev = root.querySelector('[data-role="prev"]');
		var btnNext = root.querySelector('[data-role="next"]');
		var btnZoomIn = root.querySelector('[data-role="zoom-in"]');
		var btnZoomOut = root.querySelector('[data-role="zoom-out"]');
		var btnZoomReset = root.querySelector('[data-role="zoom-reset"]');
		var backdrop = root.querySelector('[data-role="backdrop"]');
		var state = {
			items: [],
			index: 0,
			scale: 1,
			tx: 0,
			ty: 0,
			drag: null,
			skipNextClick: false,
		};
		var MIN_SCALE = 0.5;
		var MAX_SCALE = 4;

		/**
		 * 判断 URL 是否为常见图片。
		 *
		 * @param {string} url
		 * @returns {boolean}
		 */
		function isImageUrl(url) {
			if (!url) {
				return false;
			}
			return /\.(png|jpe?g|webp|gif|svg)(\?.*)?$/i.test(url);
		}

		/**
		 * 优先取包裹链接中的大图地址。
		 *
		 * @param {HTMLImageElement} img
		 * @returns {string}
		 */
		function getFullUrl(img) {
			if (!img) {
				return '';
			}
			var a = img.closest ? img.closest('a') : null;
			if (a && a.getAttribute) {
				var href = a.getAttribute('href') || '';
				if (isImageUrl(href)) {
					return href;
				}
			}
			return img.currentSrc || img.src || '';
		}

		/**
		 * 排除图标 / emoji。
		 *
		 * @param {HTMLImageElement} img
		 * @returns {boolean}
		 */
		function isIconImg(img) {
			if (!img || !img.getAttribute) {
				return false;
			}
			var t = String(img.getAttribute('data-type') || '').toLowerCase();
			if (t === 'icon') {
				return true;
			}
			if (img.classList && (img.classList.contains('emoji') || img.classList.contains('wp-smiley'))) {
				return true;
			}
			var src = String(img.currentSrc || img.src || '');
			if (/https?:\/\/s\.w\.org\/images\/core\/emoji\//i.test(src)) {
				return true;
			}
			return false;
		}

		/**
		 * 收集容器内可预览图片。
		 *
		 * @param {Element} container
		 * @returns {Array<{src:string,alt:string,el:HTMLImageElement}>}
		 */
		function buildItems(container) {
			var imgs = Array.prototype.slice.call(container.querySelectorAll('img')).filter(function (img) {
				return !isIconImg(img);
			});
			return imgs
				.map(function (img) {
					return { src: getFullUrl(img), alt: img.getAttribute('alt') || '', el: img };
				})
				.filter(function (it) {
					return !!it.src;
				});
		}

		/**
		 * 开关灯箱。
		 *
		 * @param {boolean} open
		 */
		function setOpen(open) {
			if (open) {
				root.hidden = false;
				root.setAttribute('aria-hidden', 'false');
				document.documentElement.style.overflow = 'hidden';
				document.body.style.overflow = 'hidden';
			} else {
				root.hidden = true;
				root.setAttribute('aria-hidden', 'true');
				document.documentElement.style.overflow = '';
				document.body.style.overflow = '';
				imgEl.removeAttribute('src');
				imgEl.setAttribute('alt', '');
				metaTitleEl.textContent = '';
				metaEl.textContent = '';
				state.scale = 1;
				state.tx = 0;
				state.ty = 0;
				applyTransform();
			}
		}

		/**
		 * @param {number} i
		 * @returns {number}
		 */
		function clampIndex(i) {
			var len = state.items.length;
			if (!len) {
				return 0;
			}
			if (i < 0) {
				return len - 1;
			}
			if (i >= len) {
				return 0;
			}
			return i;
		}

		function render() {
			var len = state.items.length;
			if (!len) {
				return;
			}
			state.index = clampIndex(state.index);
			var cur = state.items[state.index];
			imgEl.src = cur.src;
			imgEl.alt = cur.alt || '';
			metaTitleEl.textContent = cur.alt || '';
			state.scale = 1;
			state.tx = 0;
			state.ty = 0;
			applyTransform();
			metaEl.textContent = state.index + 1 + ' / ' + len;
			btnPrev.disabled = len <= 1;
			btnNext.disabled = len <= 1;
		}

		/**
		 * @param {Element} container
		 * @param {HTMLImageElement} targetImg
		 */
		function openAt(container, targetImg) {
			state.items = buildItems(container);
			if (!state.items.length) {
				return;
			}
			var idx = -1;
			for (var i = 0; i < state.items.length; i++) {
				if (state.items[i].el === targetImg) {
					idx = i;
					break;
				}
			}
			state.index = idx >= 0 ? idx : 0;
			setOpen(true);
			render();
		}

		function next() {
			if (state.items.length <= 1) {
				return;
			}
			state.index += 1;
			render();
		}

		function prev() {
			if (state.items.length <= 1) {
				return;
			}
			state.index -= 1;
			render();
		}

		/**
		 * @param {number} s
		 * @returns {number}
		 */
		function clampScale(s) {
			if (!isFinite(s)) {
				return 1;
			}
			if (s < MIN_SCALE) {
				return MIN_SCALE;
			}
			if (s > MAX_SCALE) {
				return MAX_SCALE;
			}
			return s;
		}

		function applyTransform() {
			var s = clampScale(state.scale);
			state.scale = s;
			imgEl.style.transform = 'translate(' + state.tx + 'px,' + state.ty + 'px) scale(' + s + ')';
			if (s > 1) {
				imgEl.style.cursor = state.drag ? 'grabbing' : 'grab';
			} else {
				imgEl.style.cursor = 'zoom-in';
			}
			if (btnZoomOut) {
				btnZoomOut.disabled = s <= MIN_SCALE;
			}
			if (btnZoomReset) {
				btnZoomReset.disabled = s === 1 && state.tx === 0 && state.ty === 0;
			}
		}

		/**
		 * @param {number} nextScale
		 */
		function setZoom(nextScale) {
			state.scale = clampScale(nextScale);
			if (state.scale <= 1) {
				state.tx = 0;
				state.ty = 0;
			}
			applyTransform();
			var len = state.items.length;
			metaEl.textContent = state.index + 1 + ' / ' + len + ' · ' + Math.round(state.scale * 100) + '%';
		}

		function resetZoom() {
			state.scale = 1;
			state.tx = 0;
			state.ty = 0;
			applyTransform();
		}

		/**
		 * @param {KeyboardEvent} e
		 */
		function onKeydown(e) {
			if (root.hidden) {
				return;
			}
			if (e.key === 'Escape') {
				setOpen(false);
				return;
			}
			if (e.key === 'ArrowRight') {
				next();
				return;
			}
			if (e.key === 'ArrowLeft') {
				prev();
				return;
			}
			if (e.key === '+' || e.key === '=') {
				setZoom(state.scale * 1.2);
				return;
			}
			if (e.key === '-') {
				setZoom(state.scale / 1.2);
			}
		}

		document.addEventListener('keydown', onKeydown);
		if (btnClose) {
			btnClose.addEventListener('click', function () {
				setOpen(false);
			});
		}
		if (backdrop) {
			backdrop.addEventListener('click', function () {
				setOpen(false);
			});
		}
		if (btnNext) {
			btnNext.addEventListener('click', next);
		}
		if (btnPrev) {
			btnPrev.addEventListener('click', prev);
		}
		if (btnZoomIn) {
			btnZoomIn.addEventListener('click', function () {
				setZoom(state.scale * 1.2);
			});
		}
		if (btnZoomOut) {
			btnZoomOut.addEventListener('click', function () {
				setZoom(state.scale / 1.2);
			});
		}
		if (btnZoomReset) {
			btnZoomReset.addEventListener('click', function () {
				resetZoom();
			});
		}

		if (stageEl) {
			stageEl.addEventListener('click', function (e) {
				if (root.hidden) {
					return;
				}
				if (state.skipNextClick) {
					state.skipNextClick = false;
					return;
				}
				e.preventDefault();
				if (state.scale === 1) {
					setZoom(2);
				} else {
					resetZoom();
				}
			});
			stageEl.addEventListener(
				'wheel',
				function (e) {
					if (root.hidden) {
						return;
					}
					e.preventDefault();
					var delta = e.deltaY || 0;
					var factor = delta > 0 ? 0.9 : 1.1;
					setZoom(state.scale * factor);
				},
				{ passive: false }
			);
		}

		/**
		 * @param {PointerEvent} e
		 */
		function startDrag(e) {
			if (root.hidden || state.scale <= 1) {
				return;
			}
			if (e.button !== undefined && e.button !== 0) {
				return;
			}
			e.preventDefault();
			state.drag = {
				pointerId: e.pointerId,
				startX: e.clientX,
				startY: e.clientY,
				baseTx: state.tx,
				baseTy: state.ty,
				hasMoved: false,
			};
			applyTransform();
			if (imgEl.setPointerCapture && e.pointerId != null) {
				try {
					imgEl.setPointerCapture(e.pointerId);
				} catch (err) {}
			}
		}

		/**
		 * @param {PointerEvent} e
		 */
		function moveDrag(e) {
			if (!state.drag) {
				return;
			}
			if (e.pointerId != null && state.drag.pointerId != null && e.pointerId !== state.drag.pointerId) {
				return;
			}
			e.preventDefault();
			var dx = e.clientX - state.drag.startX;
			var dy = e.clientY - state.drag.startY;
			if (Math.abs(dx) > 3 || Math.abs(dy) > 3) {
				state.drag.hasMoved = true;
			}
			state.tx = state.drag.baseTx + dx;
			state.ty = state.drag.baseTy + dy;
			applyTransform();
		}

		/**
		 * @param {PointerEvent} e
		 */
		function endDrag(e) {
			if (!state.drag) {
				return;
			}
			if (e && e.pointerId != null && state.drag.pointerId != null && e.pointerId !== state.drag.pointerId) {
				return;
			}
			if (state.drag.hasMoved) {
				state.skipNextClick = true;
				setTimeout(function () {
					state.skipNextClick = false;
				}, 50);
			}
			state.drag = null;
			applyTransform();
		}

		imgEl.addEventListener('pointerdown', startDrag);
		imgEl.addEventListener('pointermove', moveDrag);
		imgEl.addEventListener('pointerup', endDrag);
		imgEl.addEventListener('pointercancel', endDrag);
		applyTransform();

		/**
		 * @param {{selector:string}} cfg
		 */
		function bindOne(cfg) {
			var selector = cfg && cfg.selector ? String(cfg.selector) : '';
			if (!selector) {
				return;
			}
			var container = document.querySelector(selector);
			if (!container) {
				return;
			}
			container.addEventListener(
				'click',
				function (e) {
					var t = e.target;
					if (!t || t.tagName !== 'IMG') {
						return;
					}
					if (isIconImg(t)) {
						return;
					}
					if (root.contains(t)) {
						return;
					}
					var full = getFullUrl(t);
					if (!full) {
						return;
					}
					if (t.closest && t.closest('a')) {
						var a = t.closest('a');
						var href = a && a.getAttribute ? a.getAttribute('href') || '' : '';
						if (isImageUrl(href)) {
							e.preventDefault();
						}
					}
					openAt(container, t);
				},
				{ passive: false }
			);
		}

		configs.forEach(bindOne);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
