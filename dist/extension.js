import { ConfirmDialog as e, Icon as t, defineExtension as n, useI18n as r, useJsonRequest as i, useToasts as a } from "larsadmin";
import { Fragment as o, computed as s, createBlock as c, createCommentVNode as l, createElementBlock as u, createElementVNode as d, createTextVNode as f, createVNode as p, normalizeClass as m, onBeforeUnmount as h, openBlock as g, ref as _, renderList as v, toDisplayString as y, unref as b, watch as x, withModifiers as S } from "vue";
import { router as C } from "@inertiajs/vue3";
// resources/js/fields/media.js
function w(e) {
	let t = e?.value ?? {}, n = Array.isArray(t.known) ? [...t.known] : [];
	return {
		present: 1,
		known: n,
		keep: Array.isArray(t.keep) ? [...t.keep] : [...n],
		upload: []
	};
}
function T(e, t) {
	return !e || typeof e != "object" ? w(t) : {
		present: 1,
		known: Array.isArray(e.known) ? e.known : w(t).known,
		keep: Array.isArray(e.keep) ? e.keep : [],
		upload: Array.isArray(e.upload) ? e.upload : []
	};
}
function E(e, t) {
	let n = e.keep.includes(t) ? e.keep.filter((e) => e !== t) : [...e.keep, t];
	return {
		...e,
		keep: n
	};
}
function D(e, t, n) {
	let r = [...e.keep], i = r.indexOf(t), a = i + n;
	return i === -1 || a < 0 || a >= r.length ? e : (r.splice(i, 1), r.splice(a, 0, t), {
		...e,
		keep: r
	});
}
function O(e, t) {
	return {
		...e,
		upload: e.upload.filter((e, n) => n !== t)
	};
}
function k(e, t, n) {
	return n?.multiple ? {
		...e,
		upload: [...e.upload, ...t]
	} : {
		...e,
		upload: t.slice(0, 1)
	};
}
function A(e) {
	let t = String(e ?? "").lastIndexOf(".");
	return t === -1 ? "" : String(e).slice(t + 1).toLowerCase();
}
function j(e, t, n) {
	let r = [], i = [], a = Array.isArray(t?.types) ? t.types : [], o = t?.multiple ? t.maxFiles ?? Infinity : 1, s = t?.multiple ? n.keep.length + n.upload.length : 0;
	for (let n of e) t?.maxSize && n.size > t.maxSize ? i.push({
		file: n,
		reason: "too_large"
	}) : a.length && !a.includes(A(n.name)) ? i.push({
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
var M = [
	"byte",
	"kilobyte",
	"megabyte",
	"gigabyte"
];
function N(e, t = "en") {
	if (!Number.isFinite(e) || e < 0) return "—";
	let n = e, r = 0;
	for (; n >= 1024 && r < M.length - 1;) n /= 1024, r++;
	return new Intl.NumberFormat(t, {
		style: "unit",
		unit: M[r],
		unitDisplay: "short",
		maximumFractionDigits: r === 0 ? 0 : 1
	}).format(n);
}
function P(e, t) {
	let n = `${t}.upload.`, r = {};
	for (let [t, i] of Object.entries(e ?? {})) {
		let e = t.startsWith(n) ? t.slice(n.length) : "";
		/^\d+$/.test(e) && (r[Number(e)] = i);
	}
	return r;
}
function F(e, t) {
	let n = /* @__PURE__ */ new Set([
		t,
		`${t}.upload`,
		`${t}.keep`,
		`${t}.present`
	]), r = `${t}.upload.`, i = Object.entries(e ?? {}).filter(([e]) => !n.has(e) && !(e.startsWith(r) && /^\d+$/.test(e.slice(r.length)))).map(([, e]) => e);
	return [...new Set(i)];
}
function I(e, t = Date.now(), n = 5e3) {
	if (!e?.urlsExpireAt) return !1;
	let r = Date.parse(e.urlsExpireAt);
	return Number.isFinite(r) && r - n <= t;
}
// resources/js/fields/MediaInput.vue
var ee = {
	key: 0,
	class: "divide-y divide-muted"
}, te = ["src", "alt"], ne = { class: "text-xs text-fg-muted" }, re = [
	"disabled",
	"aria-label",
	"onClick"
], ie = [
	"disabled",
	"aria-label",
	"onClick"
], ae = ["aria-label", "onClick"], oe = { class: "min-w-0 flex-1 truncate text-sm line-through" }, se = { class: "sr-only" }, ce = ["aria-label", "onClick"], le = ["data-upload-error"], ue = { class: "flex items-center gap-3" }, de = ["src", "alt"], fe = { class: "min-w-0 flex-1 truncate text-sm text-fg" }, pe = { class: "larsadmin-label larsadmin-label-success" }, me = ["aria-label", "onClick"], he = ["id"], ge = {
	key: 1,
	class: "px-3 py-2 text-sm text-fg-muted"
}, _e = {
	key: 2,
	class: "flex flex-wrap items-center gap-3 border-t border-muted px-3 py-2"
}, ve = ["for"], ye = [
	"id",
	"accept",
	"multiple",
	"aria-invalid",
	"aria-describedby"
], be = ["id"], xe = {
	key: 3,
	class: "border-t border-muted px-3 py-2 text-xs text-danger-fg",
	role: "alert"
}, Se = {
	class: "sr-only",
	"aria-live": "polite"
}, L = {
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
		let i = e, a = n, { t: S, locale: C } = r(), w = (e, t) => S(`larsadmin-medialibrary::${e}`, t), A = s(() => T(i.modelValue, i.field)), M = s(() => i.field.media ?? []), I = s(() => new Map(M.value.map((e) => [e.id, e]))), L = s(() => A.value.keep.map((e) => I.value.get(e)).filter(Boolean)), R = s(() => M.value.filter((e) => !A.value.keep.includes(e.id))), z = _([]), B = _(""), V = _(null), H = s(() => `${i.field.attribute}-media-hint`), U = s(() => [i.describedBy, H.value].filter(Boolean).join(" ")), W = s(() => !!i.field.readonly), G = s(() => !i.field.multiple && A.value.upload.length > 0), K = s(() => P(i.errorsByKey, i.field.attribute)), q = s(() => F(i.errorsByKey, i.field.attribute)), J = /* @__PURE__ */ new Map();
		function Y(e) {
			return !/^image\/(jpeg|png|gif|webp|avif)$/.test(e.type) || typeof URL?.createObjectURL != "function" ? null : (J.has(e) || J.set(e, URL.createObjectURL(e)), J.get(e));
		}
		x(() => A.value.upload, (e) => {
			for (let [t, n] of J) e.includes(t) || (URL.revokeObjectURL?.(n), J.delete(t));
		}), h(() => {
			for (let e of J.values()) URL.revokeObjectURL?.(e);
			J.clear();
		});
		function X(e, t = "") {
			a("update:modelValue", e), B.value = t;
		}
		function Z(e) {
			let { accepted: t, rejected: n } = j(Array.from(e.target?.files ?? []), i.field, A.value);
			z.value = n, t.length && X(k(A.value, t, i.field), t.map((e) => e.name).join(", ")), e.target && (e.target.value = "");
		}
		function Q(e) {
			let t = N(i.field.maxSize, C.value);
			return w(e.reason, {
				name: e.file.name,
				max: e.reason === "too_large" ? t : i.field.maxFiles
			});
		}
		let Ce = s(() => {
			let e = [];
			return i.field.maxSize && e.push(w("limits", { size: N(i.field.maxSize, C.value) })), i.field.types?.length && e.push(w("types", { types: i.field.types.join(", ") })), i.field.multiple && e.push(w("too_many", { max: i.field.maxFiles })), e.join(" · ");
		});
		return (n, r) => (g(), u("div", { class: m(["rounded-md border border-default bg-canvas", { "border-danger-fg": e.invalid }]) }, [
			L.value.length || R.value.length || A.value.upload.length ? (g(), u("ul", ee, [
				(g(!0), u(o, null, v(L.value, (n, r) => (g(), u("li", {
					key: `m${n.id}`,
					class: m(["flex items-center gap-3 px-3 py-2", { "opacity-50": G.value }])
				}, [
					n.isImage && n.thumbnailUrl ? (g(), u("img", {
						key: 0,
						src: n.thumbnailUrl,
						alt: w("preview_alt", { name: n.name }),
						width: "40",
						height: "40",
						loading: "lazy",
						decoding: "async",
						class: "size-10 rounded object-cover"
					}, null, 8, te)) : (g(), c(b(t), {
						key: 1,
						name: "file",
						class: "size-4 text-fg-muted"
					})),
					d("span", { class: m(["min-w-0 flex-1 truncate text-sm text-fg", { "line-through": G.value }]) }, y(n.name), 3),
					d("span", ne, y(b(N)(n.size, b(C))), 1),
					!W.value && e.field.multiple ? (g(), u(o, { key: 2 }, [d("button", {
						type: "button",
						class: "larsadmin-btn larsadmin-btn-sm",
						disabled: r === 0,
						"aria-label": w("move_up", { name: n.name }),
						onClick: (e) => X(b(D)(A.value, n.id, -1))
					}, [p(b(t), { name: "arrow-up" })], 8, re), d("button", {
						type: "button",
						class: "larsadmin-btn larsadmin-btn-sm",
						disabled: r === L.value.length - 1,
						"aria-label": w("move_down", { name: n.name }),
						onClick: (e) => X(b(D)(A.value, n.id, 1))
					}, [p(b(t), { name: "arrow-down" })], 8, ie)], 64)) : l("", !0),
					!W.value && e.field.removable ? (g(), u("button", {
						key: 3,
						type: "button",
						class: "larsadmin-btn larsadmin-btn-sm larsadmin-btn-danger",
						"aria-label": w("remove", { name: n.name }),
						onClick: (e) => X(b(E)(A.value, n.id), w("removed_on_save", { name: n.name }))
					}, [p(b(t), { name: "trash" })], 8, ae)) : l("", !0)
				], 2))), 128)),
				(g(!0), u(o, null, v(R.value, (e) => (g(), u("li", {
					key: `r${e.id}`,
					class: "flex items-center gap-3 px-3 py-2 text-fg-muted"
				}, [
					p(b(t), {
						name: "x",
						class: "size-4"
					}),
					d("span", oe, y(e.name), 1),
					d("span", se, y(w("removed_on_save", { name: e.name })), 1),
					d("button", {
						type: "button",
						class: "larsadmin-btn larsadmin-btn-sm",
						"aria-label": w("undo", { name: e.name }),
						onClick: (t) => X(b(E)(A.value, e.id))
					}, [p(b(t), { name: "history" })], 8, ce)
				]))), 128)),
				(g(!0), u(o, null, v(A.value.upload, (n, r) => (g(), u("li", {
					key: `u${r}-${n.name}`,
					class: "px-3 py-2",
					"data-upload-error": K.value[r] ? "true" : void 0
				}, [d("div", ue, [
					Y(n) ? (g(), u("img", {
						key: 0,
						src: Y(n),
						alt: w("preview_alt", { name: n.name }),
						width: "40",
						height: "40",
						class: "size-10 rounded object-cover"
					}, null, 8, de)) : (g(), c(b(t), {
						key: 1,
						name: "upload",
						class: "size-4 text-fg-muted"
					})),
					d("span", fe, y(n.name), 1),
					d("span", pe, y(w("new")), 1),
					d("button", {
						type: "button",
						class: "larsadmin-btn larsadmin-btn-sm",
						"aria-label": w("remove", { name: n.name }),
						onClick: (e) => X(b(O)(A.value, r))
					}, [p(b(t), { name: "x" })], 8, me)
				]), K.value[r] ? (g(), u("p", {
					key: 0,
					id: `${e.field.attribute}-upload-${r}-error`,
					class: "mt-1 text-xs text-danger-fg"
				}, y(K.value[r]), 9, he)) : l("", !0)], 8, le))), 128))
			])) : (g(), u("p", ge, y(w("none")), 1)),
			W.value ? l("", !0) : (g(), u("div", _e, [d("label", {
				for: e.field.attribute,
				class: "larsadmin-btn larsadmin-btn-sm larsadmin-btn-secondary cursor-pointer focus-within:outline-2"
			}, [
				p(b(t), { name: "upload" }),
				f(" " + y(e.field.multiple ? w("choose_multiple") : M.value.length || A.value.upload.length ? w("replace") : w("choose")) + " ", 1),
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
					onChange: Z
				}, null, 40, ye)
			], 8, ve), d("span", {
				id: H.value,
				class: "text-xs text-fg-muted"
			}, y(Ce.value), 9, be)])),
			z.value.length || q.value.length ? (g(), u("ul", xe, [(g(!0), u(o, null, v(z.value, (e) => (g(), u("li", { key: `x${e.file.name}` }, y(Q(e)), 1))), 128)), (g(!0), u(o, null, v(q.value, (e) => (g(), u("li", { key: e }, y(e), 1))), 128))])) : l("", !0),
			d("p", Se, y(B.value), 1)
		], 2));
	}
}, R = {
	key: 0,
	class: "inline-flex items-center gap-1"
}, z = ["href"], B = ["src", "alt"], V = {
	key: 1,
	class: "max-w-40 truncate text-sm text-fg"
}, H = {
	key: 2,
	class: "larsadmin-counter"
}, U = {
	key: 1,
	class: "text-fg-muted"
}, W = { key: 1 }, G = {
	key: 0,
	class: "text-sm text-fg-muted"
}, K = {
	key: 1,
	class: "flex flex-wrap gap-3"
}, q = ["href", "onClick"], J = ["src", "alt"], Y = {
	key: 1,
	class: "flex h-27 w-36 items-center justify-center rounded bg-canvas-subtle"
}, X = ["title"], Z = { class: "text-xs text-fg-muted" }, Q = { class: "flex items-center gap-1" }, Ce = [
	"href",
	"aria-label",
	"onClick"
], we = [
	"disabled",
	"aria-label",
	"onClick"
], Te = [
	"disabled",
	"aria-label",
	"onClick"
], Ee = [
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
		let f = n, { t: m, locale: h } = r(), w = (e, t) => m(`larsadmin-medialibrary::${e}`, t), { send: T } = i(), E = a(), D = _([...f.field.media ?? []]);
		x(() => f.field.media, (e) => D.value = [...e ?? []]);
		let O = s(() => f.context === "index"), k = s(() => f.field.count ?? D.value.length), A = _(!1), j = _(null);
		function M(e, t) {
			I(f.field) && (e.preventDefault(), C.reload({
				preserveScroll: !0,
				onSuccess: () => window.open(t, "_blank", "noopener,noreferrer")
			}));
		}
		async function P(e, t) {
			let n = [...D.value], [r] = n.splice(e, 1);
			n.splice(e + t, 0, r), A.value = !0;
			let i = await T("POST", f.field.reorderUrl, { ids: n.map((e) => e.id) });
			A.value = !1, i.ok ? (D.value = n, E.push({
				type: "success",
				message: w("reordered")
			})) : (E.push({
				type: "error",
				message: i.data?.message ?? w("failed")
			}), C.reload({ preserveScroll: !0 }));
		}
		async function F(e) {
			j.value = null, A.value = !0;
			let t = await T("DELETE", e.destroyUrl);
			A.value = !1, t.ok ? (D.value = D.value.filter((t) => t.id !== e.id), E.push({
				type: "success",
				message: w("removed", { name: e.name })
			})) : E.push({
				type: "error",
				message: t.data?.message ?? w("failed")
			});
		}
		return (r, i) => O.value ? (g(), u("span", R, [D.value.length ? (g(), u(o, { key: 0 }, [D.value[0].url && D.value[0].isImage && D.value[0].thumbnailUrl ? (g(), u("a", {
			key: 0,
			href: D.value[0].url,
			target: "_blank",
			rel: "noopener noreferrer",
			onClick: i[0] ||= S((e) => M(e, D.value[0].url), ["stop"])
		}, [d("img", {
			src: D.value[0].thumbnailUrl,
			alt: w("preview_alt", { name: D.value[0].name }),
			width: "32",
			height: "32",
			loading: "lazy",
			decoding: "async",
			class: "size-8 rounded object-cover"
		}, null, 8, B)], 8, z)) : (g(), u("span", V, y(D.value[0].name), 1)), k.value > 1 ? (g(), u("span", H, y(w("more", { count: k.value - 1 })), 1)) : l("", !0)], 64)) : (g(), u("span", U, "—"))])) : (g(), u("div", W, [D.value.length ? (g(), u("ul", K, [(g(!0), u(o, null, v(D.value, (e, r) => (g(), u("li", {
			key: e.id,
			class: "flex w-40 flex-col gap-1 rounded-md border border-default bg-canvas p-2"
		}, [
			e.url ? (g(), u("a", {
				key: 0,
				href: e.url,
				target: "_blank",
				rel: "noopener noreferrer",
				class: "block",
				onClick: (t) => M(t, e.url)
			}, [e.isImage && e.thumbnailUrl ? (g(), u("img", {
				key: 0,
				src: e.thumbnailUrl,
				alt: w("preview_alt", { name: e.name }),
				width: "144",
				height: "108",
				loading: "lazy",
				decoding: "async",
				class: "h-27 w-36 rounded object-cover"
			}, null, 8, J)) : (g(), u("span", Y, [p(b(t), { name: "file" })]))], 8, q)) : l("", !0),
			d("span", {
				class: "truncate text-xs text-fg",
				title: e.name
			}, y(e.name), 9, X),
			d("span", Z, y(b(N)(e.size, b(h))), 1),
			d("div", Q, [
				e.downloadUrl ? (g(), u("a", {
					key: 0,
					href: e.downloadUrl,
					download: "",
					class: "larsadmin-btn larsadmin-btn-sm",
					"aria-label": w("download", { name: e.name }),
					onClick: (t) => M(t, e.downloadUrl)
				}, [p(b(t), { name: "download" })], 8, Ce)) : l("", !0),
				n.field.reorderUrl ? (g(), u(o, { key: 1 }, [d("button", {
					type: "button",
					class: "larsadmin-btn larsadmin-btn-sm",
					disabled: A.value || r === 0,
					"aria-label": w("move_up", { name: e.name }),
					onClick: (e) => P(r, -1)
				}, [p(b(t), { name: "arrow-up" })], 8, we), d("button", {
					type: "button",
					class: "larsadmin-btn larsadmin-btn-sm",
					disabled: A.value || r === D.value.length - 1,
					"aria-label": w("move_down", { name: e.name }),
					onClick: (e) => P(r, 1)
				}, [p(b(t), { name: "arrow-down" })], 8, Te)], 64)) : l("", !0),
				e.destroyUrl ? (g(), u("button", {
					key: 2,
					type: "button",
					class: "larsadmin-btn larsadmin-btn-sm larsadmin-btn-danger",
					disabled: A.value,
					"aria-label": w("remove", { name: e.name }),
					onClick: (t) => j.value = e
				}, [p(b(t), { name: "trash" })], 8, Ee)) : l("", !0)
			])
		]))), 128))])) : (g(), u("p", G, y(w("none")), 1)), j.value ? (g(), c(b(e), {
			key: 2,
			message: w("remove_confirm", { name: j.value.name }),
			onConfirm: i[1] ||= (e) => F(j.value),
			onCancel: i[2] ||= (e) => j.value = null
		}, null, 8, ["message"])) : l("", !0)]));
	}
}, De = n({
	name: "maxcxam/larsadmin-medialibrary",
	requires: "^1.5",
	fields: {
		"maxcxam/media": {
			form: L,
			display: $
		},
		"maxcxam/media-gallery": {
			form: L,
			display: $
		}
	}
});
export { De as default };
