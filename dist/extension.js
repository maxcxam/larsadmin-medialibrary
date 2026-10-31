import { ConfirmDialog as e, Icon as t, defineExtension as n, useI18n as r, useJsonRequest as i, useToasts as a } from "larsadmin";
import { Fragment as o, computed as s, createBlock as c, createCommentVNode as l, createElementBlock as u, createElementVNode as d, createTextVNode as f, createVNode as p, normalizeClass as m, onBeforeUnmount as h, openBlock as g, ref as _, renderList as v, toDisplayString as y, unref as b, watch as ee, withModifiers as te } from "vue";
import { router as x } from "@inertiajs/vue3";
// resources/js/fields/media.js
function S(e) {
	let t = e?.value ?? {}, n = Array.isArray(t.known) ? [...t.known] : [];
	return {
		present: 1,
		known: n,
		keep: Array.isArray(t.keep) ? [...t.keep] : [...n],
		upload: []
	};
}
function C(e, t) {
	return !e || typeof e != "object" ? S(t) : {
		present: 1,
		known: Array.isArray(e.known) ? e.known : S(t).known,
		keep: Array.isArray(e.keep) ? e.keep : [],
		upload: Array.isArray(e.upload) ? e.upload : []
	};
}
function w(e, t) {
	let n = e.keep.includes(t) ? e.keep.filter((e) => e !== t) : [...e.keep, t];
	return {
		...e,
		keep: n
	};
}
function T(e, t, n) {
	let r = [...e.keep], i = r.indexOf(t), a = i + n;
	return i === -1 || a < 0 || a >= r.length ? e : (r.splice(i, 1), r.splice(a, 0, t), {
		...e,
		keep: r
	});
}
function E(e, t) {
	return {
		...e,
		upload: e.upload.filter((e, n) => n !== t)
	};
}
function D(e, t, n) {
	return n?.multiple ? {
		...e,
		upload: [...e.upload, ...t]
	} : {
		...e,
		upload: t.slice(0, 1)
	};
}
function O(e) {
	let t = String(e ?? "").lastIndexOf(".");
	return t === -1 ? "" : String(e).slice(t + 1).toLowerCase();
}
function k(e, t, n) {
	let r = [], i = [], a = Array.isArray(t?.types) ? t.types : [], o = t?.multiple ? t.maxFiles ?? Infinity : 1, s = t?.multiple ? n.keep.length + n.upload.length : 0;
	for (let n of e) t?.maxSize && n.size > t.maxSize ? i.push({
		file: n,
		reason: "too_large"
	}) : a.length && !a.includes(O(n.name)) ? i.push({
		file: n,
		reason: "type_not_allowed"
	}) : s >= o ? i.push({
		file: n,
		reason: "too_many"
	}) : (r.push(n), s++);
	return {
		accepted: r,
		rejected: i
	};
}
var A = [
	"byte",
	"kilobyte",
	"megabyte",
	"gigabyte"
];
function j(e, t = "en") {
	if (!Number.isFinite(e) || e < 0) return "—";
	let n = e, r = 0;
	for (; n >= 1024 && r < A.length - 1;) n /= 1024, r++;
	return new Intl.NumberFormat(t, {
		style: "unit",
		unit: A[r],
		unitDisplay: "short",
		maximumFractionDigits: r === 0 ? 0 : 1
	}).format(n);
}
function M(e, t) {
	let n = `${t}.upload.`, r = {};
	for (let [t, i] of Object.entries(e ?? {})) {
		let e = t.startsWith(n) ? t.slice(n.length) : "";
		/^\d+$/.test(e) && (r[Number(e)] = i);
	}
	return r;
}
function N(e, t) {
	let n = /* @__PURE__ */ new Set([
		t,
		`${t}.upload`,
		`${t}.keep`,
		`${t}.present`
	]), r = `${t}.upload.`, i = Object.entries(e ?? {}).filter(([e]) => !n.has(e) && !(e.startsWith(r) && /^\d+$/.test(e.slice(r.length)))).map(([, e]) => e);
	return [...new Set(i)];
}
function ne(e, t = Date.now(), n = 5e3) {
	if (!e?.urlsExpireAt) return !1;
	let r = Date.parse(e.urlsExpireAt);
	return Number.isFinite(r) && r - n <= t;
}
function re(e) {
	return e?.previewPending === !0;
}
function P(e, t, n) {
	return (Array.isArray(e) ? e : []).find((e) => e.id === t)?.[n] ?? null;
}
// resources/js/fields/MediaInput.vue
var F = {
	key: 0,
	class: "divide-y divide-muted"
}, I = [
	"src",
	"alt",
	"onError"
], L = {
	key: 2,
	class: "text-xs text-fg-muted",
	"data-preview-pending": ""
}, ie = { class: "text-xs text-fg-muted" }, ae = [
	"disabled",
	"aria-label",
	"onClick"
], oe = [
	"disabled",
	"aria-label",
	"onClick"
], se = ["aria-label", "onClick"], ce = { class: "min-w-0 flex-1 truncate text-sm line-through" }, le = { class: "sr-only" }, ue = ["aria-label", "onClick"], de = ["data-upload-error"], fe = { class: "flex items-center gap-3" }, pe = ["src", "alt"], me = { class: "min-w-0 flex-1 truncate text-sm text-fg" }, he = { class: "larsadmin-label larsadmin-label-success" }, ge = ["aria-label", "onClick"], _e = ["id"], ve = {
	key: 1,
	class: "px-3 py-2 text-sm text-fg-muted"
}, ye = {
	key: 2,
	class: "flex flex-wrap items-center gap-3 border-t border-muted px-3 py-2"
}, be = ["for"], xe = [
	"id",
	"accept",
	"multiple",
	"aria-invalid",
	"aria-describedby"
], Se = ["id"], Ce = {
	key: 3,
	class: "border-t border-muted px-3 py-2 text-xs text-danger-fg",
	role: "alert"
}, we = {
	class: "sr-only",
	"aria-live": "polite"
}, R = {
	__name: "MediaInput",
	props: {
		field: {
			type: Object,
			required: !0
		},
		modelValue: {
			type: Object,
			default: null
		},
		invalid: {
			type: Boolean,
			default: !1
		},
		errors: {
			type: Array,
			default: () => []
		},
		errorsByKey: {
			type: Object,
			default: () => ({})
		},
		describedBy: {
			type: String,
			default: null
		},
		context: {
			type: String,
			default: "update"
		},
		resourceId: {
			type: [String, Number],
			default: null
		}
	},
	emits: ["update:modelValue"],
	setup(e, { emit: n }) {
		let i = e, a = n, { t: te, locale: x } = r(), S = (e, t) => te(`larsadmin-medialibrary::${e}`, t), O = s(() => C(i.modelValue, i.field)), A = s(() => i.field.media ?? []), ne = s(() => new Map(A.value.map((e) => [e.id, e]))), P = s(() => O.value.keep.map((e) => ne.value.get(e)).filter(Boolean)), R = s(() => A.value.filter((e) => !O.value.keep.includes(e.id))), z = _([]), B = _(""), V = _(null), H = s(() => `${i.field.attribute}-media-hint`), U = s(() => [i.describedBy, H.value].filter(Boolean).join(" ")), W = s(() => !!i.field.readonly), G = s(() => !i.field.multiple && O.value.upload.length > 0), K = s(() => M(i.errorsByKey, i.field.attribute)), q = s(() => N(i.errorsByKey, i.field.attribute)), J = _(/* @__PURE__ */ new Set()), Y = (e) => e.isImage && e.thumbnailUrl && !J.value.has(e.id) ? e.thumbnailUrl : null, Te = (e) => J.value = new Set(J.value).add(e.id), X = /* @__PURE__ */ new Map();
		function Z(e) {
			return !/^image\/(jpeg|png|gif|webp|avif)$/.test(e.type) || typeof URL?.createObjectURL != "function" ? null : (X.has(e) || X.set(e, URL.createObjectURL(e)), X.get(e));
		}
		ee(() => O.value.upload, (e) => {
			for (let [t, n] of X) e.includes(t) || (URL.revokeObjectURL?.(n), X.delete(t));
		}), h(() => {
			for (let e of X.values()) URL.revokeObjectURL?.(e);
			X.clear();
		});
		function Q(e, t = "") {
			a("update:modelValue", e), B.value = t;
		}
		function Ee(e) {
			let { accepted: t, rejected: n } = k(Array.from(e.target?.files ?? []), i.field, O.value);
			z.value = n, t.length && Q(D(O.value, t, i.field), t.map((e) => e.name).join(", ")), e.target && (e.target.value = "");
		}
		function De(e) {
			let t = j(i.field.maxSize, x.value);
			return S(e.reason, {
				name: e.file.name,
				max: e.reason === "too_large" ? t : i.field.maxFiles
			});
		}
		let Oe = s(() => {
			let e = [];
			return i.field.maxSize && e.push(S("limits", { size: j(i.field.maxSize, x.value) })), i.field.types?.length && e.push(S("types", { types: i.field.types.join(", ") })), i.field.multiple && e.push(S("too_many", { max: i.field.maxFiles })), e.join(" · ");
		});
		return (n, r) => (g(), u("div", { class: m(["rounded-md border border-default bg-canvas", { "border-danger-fg": e.invalid }]) }, [
			P.value.length || R.value.length || O.value.upload.length ? (g(), u("ul", F, [
				(g(!0), u(o, null, v(P.value, (n, r) => (g(), u("li", {
					key: `m${n.id}`,
					class: m(["flex items-center gap-3 px-3 py-2", { "opacity-50": G.value }])
				}, [
					Y(n) ? (g(), u("img", {
						key: 0,
						src: Y(n),
						alt: S("preview_alt", { name: n.name }),
						width: "40",
						height: "40",
						loading: "lazy",
						decoding: "async",
						class: "size-10 rounded object-cover",
						onError: (e) => Te(n)
					}, null, 40, I)) : (g(), c(b(t), {
						key: 1,
						name: "file",
						class: "size-4 text-fg-muted"
					})),
					d("span", { class: m(["min-w-0 flex-1 truncate text-sm text-fg", { "line-through": G.value }]) }, y(n.name), 3),
					b(re)(n) ? (g(), u("span", L, y(S("preview_pending")), 1)) : l("", !0),
					d("span", ie, y(b(j)(n.size, b(x))), 1),
					!W.value && e.field.multiple ? (g(), u(o, { key: 3 }, [d("button", {
						type: "button",
						class: "larsadmin-btn larsadmin-btn-sm",
						disabled: r === 0,
						"aria-label": S("move_up", { name: n.name }),
						onClick: (e) => Q(b(T)(O.value, n.id, -1))
					}, [p(b(t), { name: "arrow-up" })], 8, ae), d("button", {
						type: "button",
						class: "larsadmin-btn larsadmin-btn-sm",
						disabled: r === P.value.length - 1,
						"aria-label": S("move_down", { name: n.name }),
						onClick: (e) => Q(b(T)(O.value, n.id, 1))
					}, [p(b(t), { name: "arrow-down" })], 8, oe)], 64)) : l("", !0),
					!W.value && e.field.removable ? (g(), u("button", {
						key: 4,
						type: "button",
						class: "larsadmin-btn larsadmin-btn-sm larsadmin-btn-danger",
						"aria-label": S("remove", { name: n.name }),
						onClick: (e) => Q(b(w)(O.value, n.id), S("removed_on_save", { name: n.name }))
					}, [p(b(t), { name: "trash" })], 8, se)) : l("", !0)
				], 2))), 128)),
				(g(!0), u(o, null, v(R.value, (e) => (g(), u("li", {
					key: `r${e.id}`,
					class: "flex items-center gap-3 px-3 py-2 text-fg-muted"
				}, [
					p(b(t), {
						name: "x",
						class: "size-4"
					}),
					d("span", ce, y(e.name), 1),
					d("span", le, y(S("removed_on_save", { name: e.name })), 1),
					d("button", {
						type: "button",
						class: "larsadmin-btn larsadmin-btn-sm",
						"aria-label": S("undo", { name: e.name }),
						onClick: (t) => Q(b(w)(O.value, e.id))
					}, [p(b(t), { name: "history" })], 8, ue)
				]))), 128)),
				(g(!0), u(o, null, v(O.value.upload, (n, r) => (g(), u("li", {
					key: `u${r}-${n.name}`,
					class: "px-3 py-2",
					"data-upload-error": K.value[r] ? "true" : void 0
				}, [d("div", fe, [
					Z(n) ? (g(), u("img", {
						key: 0,
						src: Z(n),
						alt: S("preview_alt", { name: n.name }),
						width: "40",
						height: "40",
						class: "size-10 rounded object-cover"
					}, null, 8, pe)) : (g(), c(b(t), {
						key: 1,
						name: "upload",
						class: "size-4 text-fg-muted"
					})),
					d("span", me, y(n.name), 1),
					d("span", he, y(S("new")), 1),
					d("button", {
						type: "button",
						class: "larsadmin-btn larsadmin-btn-sm",
						"aria-label": S("remove", { name: n.name }),
						onClick: (e) => Q(b(E)(O.value, r))
					}, [p(b(t), { name: "x" })], 8, ge)
				]), K.value[r] ? (g(), u("p", {
					key: 0,
					id: `${e.field.attribute}-upload-${r}-error`,
					class: "mt-1 text-xs text-danger-fg"
				}, y(K.value[r]), 9, _e)) : l("", !0)], 8, de))), 128))
			])) : (g(), u("p", ve, y(S("none")), 1)),
			W.value ? l("", !0) : (g(), u("div", ye, [d("label", {
				for: e.field.attribute,
				class: "larsadmin-btn larsadmin-btn-sm larsadmin-btn-secondary cursor-pointer focus-within:outline-2"
			}, [
				p(b(t), { name: "upload" }),
				f(" " + y(e.field.multiple ? S("choose_multiple") : A.value.length || O.value.upload.length ? S("replace") : S("choose")) + " ", 1),
				d("input", {
					id: e.field.attribute,
					ref_key: "input",
					ref: V,
					type: "file",
					class: "sr-only",
					accept: e.field.accept || void 0,
					multiple: !!e.field.multiple,
					"aria-invalid": e.invalid ? "true" : void 0,
					"aria-describedby": U.value || void 0,
					onChange: Ee
				}, null, 40, xe)
			], 8, be), d("span", {
				id: H.value,
				class: "text-xs text-fg-muted"
			}, y(Oe.value), 9, Se)])),
			z.value.length || q.value.length ? (g(), u("ul", Ce, [(g(!0), u(o, null, v(z.value, (e) => (g(), u("li", { key: `x${e.file.name}` }, y(De(e)), 1))), 128)), (g(!0), u(o, null, v(q.value, (e) => (g(), u("li", { key: e }, y(e), 1))), 128))])) : l("", !0),
			d("p", we, y(B.value), 1)
		], 2));
	}
}, z = {
	key: 0,
	class: "inline-flex items-center gap-1"
}, B = ["href"], V = ["src", "alt"], H = {
	key: 1,
	class: "max-w-40 truncate text-sm text-fg"
}, U = {
	key: 2,
	class: "larsadmin-counter"
}, W = {
	key: 1,
	class: "text-fg-muted"
}, G = { key: 1 }, K = {
	key: 0,
	class: "text-sm text-fg-muted"
}, q = {
	key: 1,
	class: "flex flex-wrap gap-3"
}, J = ["href", "onClick"], Y = [
	"src",
	"alt",
	"onError"
], Te = {
	key: 1,
	class: "flex h-27 w-36 items-center justify-center rounded bg-canvas-subtle"
}, X = ["title"], Z = {
	key: 1,
	class: "text-xs text-fg-muted",
	"data-preview-pending": ""
}, Q = { class: "text-xs text-fg-muted" }, Ee = { class: "flex items-center gap-1" }, De = [
	"href",
	"aria-label",
	"onClick"
], Oe = [
	"disabled",
	"aria-label",
	"onClick"
], ke = [
	"disabled",
	"aria-label",
	"onClick"
], Ae = [
	"disabled",
	"aria-label",
	"onClick"
], $ = {
	__name: "MediaDisplay",
	props: {
		field: {
			type: Object,
			required: !0
		},
		context: {
			type: String,
			default: "detail"
		}
	},
	setup(n) {
		let f = n, { t: m, locale: h } = r(), S = (e, t) => m(`larsadmin-medialibrary::${e}`, t), { send: C } = i(), w = a(), T = _([...f.field.media ?? []]);
		ee(() => f.field.media, (e) => {
			T.value = [...e ?? []], A.value = /* @__PURE__ */ new Set();
		});
		let E = s(() => f.context === "index"), D = s(() => f.field.count ?? T.value.length), O = _(!1), k = _(null), A = _(/* @__PURE__ */ new Set()), M = (e) => e.isImage && e.thumbnailUrl && !A.value.has(e.id) ? e.thumbnailUrl : null, N = (e) => A.value = new Set(A.value).add(e.id);
		function F(e, t, n) {
			ne(f.field) && (e.preventDefault(), x.reload({
				preserveScroll: !0,
				onSuccess: () => {
					let e = P(f.field.media, t.id, n);
					e && window.open(e, "_blank", "noopener,noreferrer");
				}
			}));
		}
		async function I(e, t) {
			let n = [...T.value], [r] = n.splice(e, 1);
			n.splice(e + t, 0, r), O.value = !0;
			let i = await C("POST", f.field.reorderUrl, { ids: n.map((e) => e.id) });
			O.value = !1, i.ok ? (T.value = n, w.push({
				type: "success",
				message: S("reordered")
			})) : (w.push({
				type: "error",
				message: i.data?.message ?? S("failed")
			}), x.reload({ preserveScroll: !0 }));
		}
		async function L(e) {
			k.value = null, O.value = !0;
			let t = await C("DELETE", e.destroyUrl);
			O.value = !1, t.ok ? (T.value = T.value.filter((t) => t.id !== e.id), w.push({
				type: "success",
				message: S("removed", { name: e.name })
			})) : w.push({
				type: "error",
				message: t.data?.message ?? S("failed")
			});
		}
		return (r, i) => E.value ? (g(), u("span", z, [T.value.length ? (g(), u(o, { key: 0 }, [T.value[0].url && M(T.value[0]) ? (g(), u("a", {
			key: 0,
			href: T.value[0].url,
			target: "_blank",
			rel: "noopener noreferrer",
			onClick: i[1] ||= te((e) => F(e, T.value[0], "url"), ["stop"])
		}, [d("img", {
			src: M(T.value[0]),
			alt: S("preview_alt", { name: T.value[0].name }),
			width: "32",
			height: "32",
			loading: "lazy",
			decoding: "async",
			class: "size-8 rounded object-cover",
			onError: i[0] ||= (e) => N(T.value[0])
		}, null, 40, V)], 8, B)) : (g(), u("span", H, y(T.value[0].name), 1)), D.value > 1 ? (g(), u("span", U, y(S("more", { count: D.value - 1 })), 1)) : l("", !0)], 64)) : (g(), u("span", W, "—"))])) : (g(), u("div", G, [T.value.length ? (g(), u("ul", q, [(g(!0), u(o, null, v(T.value, (e, r) => (g(), u("li", {
			key: e.id,
			class: "flex w-40 flex-col gap-1 rounded-md border border-default bg-canvas p-2"
		}, [
			e.url ? (g(), u("a", {
				key: 0,
				href: e.url,
				target: "_blank",
				rel: "noopener noreferrer",
				class: "block",
				onClick: (t) => F(t, e, "url")
			}, [M(e) ? (g(), u("img", {
				key: 0,
				src: M(e),
				alt: S("preview_alt", { name: e.name }),
				width: "144",
				height: "108",
				loading: "lazy",
				decoding: "async",
				class: "h-27 w-36 rounded object-cover",
				onError: (t) => N(e)
			}, null, 40, Y)) : (g(), u("span", Te, [p(b(t), { name: "file" })]))], 8, J)) : l("", !0),
			d("span", {
				class: "truncate text-xs text-fg",
				title: e.name
			}, y(e.name), 9, X),
			b(re)(e) ? (g(), u("span", Z, y(S("preview_pending")), 1)) : l("", !0),
			d("span", Q, y(b(j)(e.size, b(h))), 1),
			d("div", Ee, [
				e.downloadUrl ? (g(), u("a", {
					key: 0,
					href: e.downloadUrl,
					download: "",
					class: "larsadmin-btn larsadmin-btn-sm",
					"aria-label": S("download", { name: e.name }),
					onClick: (t) => F(t, e, "downloadUrl")
				}, [p(b(t), { name: "download" })], 8, De)) : l("", !0),
				n.field.reorderUrl ? (g(), u(o, { key: 1 }, [d("button", {
					type: "button",
					class: "larsadmin-btn larsadmin-btn-sm",
					disabled: O.value || r === 0,
					"aria-label": S("move_up", { name: e.name }),
					onClick: (e) => I(r, -1)
				}, [p(b(t), { name: "arrow-up" })], 8, Oe), d("button", {
					type: "button",
					class: "larsadmin-btn larsadmin-btn-sm",
					disabled: O.value || r === T.value.length - 1,
					"aria-label": S("move_down", { name: e.name }),
					onClick: (e) => I(r, 1)
				}, [p(b(t), { name: "arrow-down" })], 8, ke)], 64)) : l("", !0),
				e.destroyUrl ? (g(), u("button", {
					key: 2,
					type: "button",
					class: "larsadmin-btn larsadmin-btn-sm larsadmin-btn-danger",
					disabled: O.value,
					"aria-label": S("remove", { name: e.name }),
					onClick: (t) => k.value = e
				}, [p(b(t), { name: "trash" })], 8, Ae)) : l("", !0)
			])
		]))), 128))])) : (g(), u("p", K, y(S("none")), 1)), k.value ? (g(), c(b(e), {
			key: 2,
			message: S("remove_confirm", { name: k.value.name }),
			onConfirm: i[2] ||= (e) => L(k.value),
			onCancel: i[3] ||= (e) => k.value = null
		}, null, 8, ["message"])) : l("", !0)]));
	}
}, je = n({
	name: "maxcxam/larsadmin-medialibrary",
	requires: "^1.6",
	fields: {
		"maxcxam/media": {
			form: R,
			display: $
		},
		"maxcxam/media-gallery": {
			form: R,
			display: $
		}
	}
});
export { je as default };
