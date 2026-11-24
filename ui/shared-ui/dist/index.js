var mt = Object.defineProperty;
var vt = (o, t, e) => t in o ? mt(o, t, { enumerable: !0, configurable: !0, writable: !0, value: e }) : o[t] = e;
var P = (o, t, e) => vt(o, typeof t != "symbol" ? t + "" : t, e);
import { inject as yt, ref as $, shallowRef as j, watch as at, onBeforeUnmount as Z, onMounted as tt, toValue as lt, defineComponent as D, computed as k, openBlock as l, createElementBlock as r, unref as s, createElementVNode as i, toDisplayString as m, Fragment as F, createCommentVNode as N, createTextVNode as S, renderList as G, withModifiers as bt, withDirectives as K, vModelText as rt, normalizeClass as gt, vModelCheckbox as wt, vModelSelect as $t, createVNode as dt } from "vue";
const ot = "0.8.0", Tt = "v1", O = 1, kt = [
  "currentUser",
  "request",
  "loginUrl",
  "logout",
  "navigate",
  "capabilities"
], Ct = ["media", "atproto"];
function It(o) {
  if (typeof o.apiBase != "string" || o.apiBase.length === 0)
    throw new Error(
      `HostBridge.apiBase must be a non-empty string; @alohasocial/ui ${ot} implements contract ${O}.`
    );
  for (const a of kt)
    if (typeof o[a] != "function")
      throw new Error(
        `HostBridge is missing the required "${a}" method; @alohasocial/ui ${ot} implements contract ${O}.`
      );
  const t = o.contractVersion === void 0 ? O : o.contractVersion;
  if (!Number.isInteger(t) || t !== O)
    throw new Error(
      `HostBridge contract ${String(t)} is not supported by @alohasocial/ui ${ot} (contract ${O}). Upgrade @alohasocial/ui and alohasocial/core together.`
    );
  const e = o.capabilities();
  for (const a of Ct)
    if (typeof (e == null ? void 0 : e[a]) != "boolean")
      throw new Error(
        `HostBridge.capabilities() must return a boolean "${a}" field; use false when the host does not provide it.`
      );
}
const ft = Symbol("alohasocial.host-bridge");
function Co(o) {
  if (!o.bridge)
    throw new Error("createAlohaUi() needs a HostBridge.");
  return It(o.bridge), {
    install(t) {
      t.provide(ft, o.bridge);
    }
  };
}
function st() {
  const o = yt(ft);
  if (!o)
    throw new Error("No Aloha Social HostBridge was provided; install createAlohaUi() at the page boundary.");
  return o;
}
const I = `/api/${Tt}`, L = {
  publicTimeline: `${I}/timelines/public`,
  hashtagTimelinePattern: `${I}/timelines/tag/{tag}`,
  hashtagTimeline: (o) => `${I}/timelines/tag/${encodeURIComponent(o)}`,
  posts: `${I}/posts`,
  postPattern: `${I}/posts/{id}`,
  post: (o) => `${I}/posts/${encodeURIComponent(o)}`,
  accountPattern: `${I}/accounts/{id}`,
  account: (o) => `${I}/accounts/${encodeURIComponent(o)}`,
  accountFollowPattern: `${I}/accounts/{id}/follow`,
  accountFollow: (o) => `${I}/accounts/${encodeURIComponent(o)}/follow`,
  notifications: `${I}/notifications`,
  notificationDismissPattern: `${I}/notifications/{id}/dismiss`,
  notificationDismiss: (o) => `${I}/notifications/${encodeURIComponent(o)}/dismiss`,
  notificationsClear: `${I}/notifications/clear`,
  notificationsUnreadCount: `${I}/notifications/unread_count`
};
class g extends Error {
  constructor(e, a, n, c = {}) {
    super(n);
    P(this, "status");
    P(this, "code");
    P(this, "fields");
    this.name = "UiApiError", this.status = e, this.code = a, this.fields = c;
  }
}
class W extends Error {
  constructor(t = "The server could not be reached.") {
    super(t), this.name = "UiTransportError";
  }
}
function nt(o) {
  return o instanceof g || o instanceof W;
}
const At = 20, Nt = 40, Pt = 20, Ut = 40, Q = 5e3, Y = 200;
function ht(o) {
  return o === void 0 || !Number.isFinite(o) ? At : Math.min(Nt, Math.max(1, Math.trunc(o)));
}
function pt(o) {
  return o === void 0 || !Number.isFinite(o) ? Pt : Math.min(Ut, Math.max(1, Math.trunc(o)));
}
class Et {
  constructor(t) {
    this.bridge = t;
  }
  async publicTimeline(t = {}) {
    return this.timeline(L.publicTimeline, t);
  }
  async hashtagTimeline(t, e = {}) {
    const a = t.trim().replace(/^#/, "");
    if (a === "")
      throw new g(400, "invalid_tag", "A hashtag is required.");
    return this.timeline(L.hashtagTimeline(a), e);
  }
  async getPost(t) {
    if (t === "")
      throw new g(400, "invalid_post_id", "A post id is required.");
    const e = await this.request({
      method: "GET",
      path: L.post(t)
    });
    if (!A(e) || !A(e.post))
      throw new g(502, "invalid_response", "The post response has no post object.");
    return e.post;
  }
  async getAccount(t) {
    if (t.trim() === "")
      throw new g(400, "invalid_account_id", "An account id is required.");
    const e = await this.request({
      method: "GET",
      path: L.account(t)
    });
    if (!A(e) || !A(e.account))
      throw new g(502, "invalid_response", "The account response has no account object.");
    return e.account;
  }
  async followAccount(t) {
    if (t.trim() === "")
      throw new g(400, "invalid_account_id", "An account id is required.");
    const e = await this.request({
      method: "POST",
      path: L.accountFollow(t)
    });
    return ct(e);
  }
  async unfollowAccount(t) {
    if (t.trim() === "")
      throw new g(400, "invalid_account_id", "An account id is required.");
    const e = await this.request({
      method: "DELETE",
      path: L.accountFollow(t)
    });
    return ct(e);
  }
  async notifications(t = {}) {
    const e = {
      limit: String(pt(t.limit))
    };
    t.before !== void 0 && t.before !== "" && (e.before = t.before);
    const a = await this.request({
      method: "GET",
      path: L.notifications,
      query: e
    });
    if (!A(a) || !Array.isArray(a.notifications) || typeof a.unread_count != "number")
      throw new g(502, "invalid_response", "The notification response is incomplete.");
    return {
      notifications: a.notifications,
      unreadCount: a.unread_count
    };
  }
  async dismissNotification(t) {
    if (t.trim() === "")
      throw new g(400, "invalid_notification_id", "A notification id is required.");
    await this.request({
      method: "POST",
      path: L.notificationDismiss(t)
    });
  }
  async clearNotifications() {
    const t = await this.request({
      method: "POST",
      path: L.notificationsClear
    });
    if (!A(t) || typeof t.cleared != "number")
      throw new g(502, "invalid_response", "The clear response has no count.");
    return t.cleared;
  }
  async notificationUnreadCount() {
    const t = await this.request({
      method: "GET",
      path: L.notificationsUnreadCount
    });
    if (!A(t) || typeof t.count != "number")
      throw new g(502, "invalid_response", "The unread-count response has no count.");
    return t.count;
  }
  async createPost(t) {
    var f;
    const e = t.content.trim(), a = Array.from(e).length;
    if (a === 0)
      throw new g(400, "invalid_post", "A post needs text.");
    if (a > Q)
      throw new g(
        400,
        "invalid_post",
        `A post may be at most ${Q} characters long.`
      );
    const n = ((f = t.contentWarning) == null ? void 0 : f.trim()) ?? "";
    if (Array.from(n).length > Y)
      throw new g(
        400,
        "invalid_post",
        `A content warning may be at most ${Y} characters long.`
      );
    const c = {
      content: t.content,
      visibility: t.visibility ?? "public"
    };
    n !== "" && (c.content_warning = n), t.inReplyToId !== void 0 && t.inReplyToId !== null && t.inReplyToId !== "" && (c.in_reply_to_id = t.inReplyToId);
    const u = await this.request({
      method: "POST",
      path: L.posts,
      body: c
    });
    if (!A(u) || !A(u.post))
      throw new g(502, "invalid_response", "The post response has no post object.");
    return u.post;
  }
  async timeline(t, e) {
    const a = {
      limit: String(ht(e.limit))
    };
    e.before !== void 0 && e.before !== "" && (a.before = e.before);
    const n = await this.request({
      method: "GET",
      path: t,
      query: a
    });
    if (!n || !Array.isArray(n.posts))
      throw new g(502, "invalid_response", "The timeline response has no posts array.");
    return n.posts;
  }
  async request(t) {
    let e;
    try {
      e = await this.bridge.request(t);
    } catch {
      throw new W();
    }
    if (e.status < 200 || e.status >= 300)
      throw Mt(e);
    if (e.body === "")
      return;
    let a;
    try {
      a = JSON.parse(e.body);
    } catch {
      throw new g(502, "invalid_response", "The server returned invalid JSON.");
    }
    return a;
  }
}
function ct(o) {
  if (!A(o) || !A(o.relationship))
    throw new g(502, "invalid_response", "The relationship response is missing.");
  const t = o.relationship;
  if (typeof t.following != "boolean" || typeof t.followed_by != "boolean" || typeof t.blocking != "boolean" || typeof t.muting != "boolean")
    throw new g(502, "invalid_response", "The relationship response is incomplete.");
  return {
    following: t.following,
    followed_by: t.followed_by,
    blocking: t.blocking,
    muting: t.muting
  };
}
function Mt(o) {
  let t;
  try {
    t = JSON.parse(o.body);
  } catch {
    return new g(o.status, "http_error", `The request failed with status ${o.status}.`);
  }
  const e = A(t) && A(t.error) ? t.error : null, a = typeof (e == null ? void 0 : e.code) == "string" && e.code !== "" ? e.code : "http_error", n = typeof (e == null ? void 0 : e.message) == "string" && e.message !== "" ? e.message : `The request failed with status ${o.status}.`, c = A(e == null ? void 0 : e.fields) ? Lt(e.fields) : {};
  return new g(o.status, a, n, c);
}
function Lt(o) {
  const t = {};
  for (const [e, a] of Object.entries(o))
    typeof a == "string" && (t[e] = a);
  return t;
}
function A(o) {
  return typeof o == "object" && o !== null && !Array.isArray(o);
}
function z() {
  return new Et(st());
}
function B(o) {
  return nt(o) ? o : new W();
}
function Rt(o) {
  const t = z(), e = $(null), a = $(!0), n = $(!1), c = j(null);
  let u = 0, f = !1;
  async function h() {
    var p;
    const d = o.value.trim(), y = ++u;
    if (c.value = null, d === "") {
      e.value = null, a.value = !1, c.value = new g(400, "invalid_account_id", "An account id is required.");
      return;
    }
    a.value = !0, ((p = e.value) == null ? void 0 : p.id) !== d && (e.value = null);
    try {
      const _ = await t.getAccount(d);
      !f && y === u && (e.value = _);
    } catch (_) {
      !f && y === u && (c.value = B(_));
    } finally {
      !f && y === u && (a.value = !1);
    }
  }
  async function v() {
    var y;
    const d = e.value;
    if (!(d === null || d.relationship === null || n.value)) {
      n.value = !0, c.value = null;
      try {
        const p = d.relationship.following ? await t.unfollowAccount(d.id) : await t.followAccount(d.id);
        ((y = e.value) == null ? void 0 : y.id) === d.id && (e.value = { ...e.value, relationship: p });
      } catch (p) {
        c.value = B(p);
      } finally {
        n.value = !1;
      }
    }
  }
  return at(o, () => void h(), { immediate: !0 }), Z(() => {
    f = !0;
  }), {
    account: e,
    loading: a,
    busy: n,
    error: c,
    toggleFollow: v,
    reload: h
  };
}
function Ft() {
  const o = z(), t = $(!1), e = j(null);
  async function a(n) {
    if (t.value)
      return null;
    t.value = !0, e.value = null;
    try {
      return await o.createPost(n);
    } catch (c) {
      return e.value = B(c), null;
    } finally {
      t.value = !1;
    }
  }
  return {
    submitting: t,
    error: e,
    submit: a,
    resetError: () => {
      e.value = null;
    }
  };
}
function St(o, t) {
  const e = z(), a = pt(o), n = $([]), c = $(0), u = $(!0), f = $(!1), h = j(null), v = $(!1);
  let d = !1, y = !1;
  async function p(w) {
    var V;
    if (w) {
      if (y && u.value)
        return;
      y = !0;
    } else if (u.value || !v.value)
      return;
    const U = w || (V = n.value.at(-1)) == null ? void 0 : V.id;
    if (!w && U === void 0) {
      v.value = !1;
      return;
    }
    u.value = !0, h.value = null;
    try {
      const E = await e.notifications(
        U === void 0 ? { limit: a } : { limit: a, before: U }
      );
      if (d)
        return;
      n.value = w ? E.notifications : Vt(n.value, E.notifications), c.value = E.unreadCount, v.value = E.notifications.length === a;
    } catch (E) {
      d || (h.value = B(E));
    } finally {
      d || (u.value = !1);
    }
  }
  async function _(w) {
    if (!f.value) {
      f.value = !0, h.value = null;
      try {
        if (await e.dismissNotification(w), d)
          return;
        n.value = n.value.filter((U) => U.id !== w), c.value = Math.max(0, c.value - 1);
      } catch (U) {
        d || (h.value = B(U));
      } finally {
        f.value = !1;
      }
    }
  }
  async function b() {
    if (!f.value) {
      f.value = !0, h.value = null;
      try {
        if (await e.clearNotifications(), d)
          return;
        n.value = [], c.value = 0, v.value = !1;
      } catch (w) {
        d || (h.value = B(w));
      } finally {
        f.value = !1;
      }
    }
  }
  return t === void 0 ? tt(() => {
    p(!0);
  }) : at(t, (w) => {
    w && !y && p(!0);
  }, { immediate: !0 }), Z(() => {
    d = !0;
  }), {
    notifications: n,
    unreadCount: c,
    loading: u,
    mutating: f,
    error: h,
    hasMore: v,
    loadMore: () => p(!1),
    reload: () => p(!0),
    dismiss: _,
    clear: b
  };
}
function Vt(o, t) {
  const e = /* @__PURE__ */ new Map();
  for (const a of o)
    e.set(a.id, a);
  for (const a of t)
    e.set(a.id, a);
  return [...e.values()];
}
function xt(o) {
  const t = z(), e = $(null), a = $(!0), n = j(null);
  let c = !1, u = 0;
  async function f() {
    const h = ++u, v = lt(o);
    a.value = !0, n.value = null, e.value = null;
    try {
      const d = await t.getPost(v);
      if (c || h !== u)
        return;
      e.value = d;
    } catch (d) {
      !c && h === u && (n.value = qt(d));
    } finally {
      !c && h === u && (a.value = !1);
    }
  }
  return at(() => lt(o), () => {
    f();
  }, { immediate: !0 }), Z(() => {
    c = !0, u++;
  }), {
    post: e,
    loading: a,
    error: n,
    reload: f
  };
}
function qt(o) {
  return nt(o) ? o : new W();
}
function Ot(o, t) {
  const e = z(), a = ht(o), n = $([]), c = $(!0), u = j(null), f = $(!1);
  let h = !1, v = !1;
  async function d(y) {
    if (y) {
      if (v && c.value)
        return;
      v = !0;
    } else if (c.value || !f.value)
      return;
    const p = y ? void 0 : ut(n.value);
    if (!y && p === void 0) {
      f.value = !1;
      return;
    }
    c.value = !0, u.value = null;
    try {
      const _ = p === void 0 ? { limit: a } : { limit: a, before: p }, b = t === void 0 || t.trim() === "" ? await e.publicTimeline(_) : await e.hashtagTimeline(t, _);
      if (h)
        return;
      n.value = y ? b : Bt(n.value, b);
      const w = ut(n.value);
      f.value = b.length === a && w !== void 0 && w !== p;
    } catch (_) {
      h || (u.value = Dt(_));
    } finally {
      h || (c.value = !1);
    }
  }
  return tt(() => {
    d(!0);
  }), Z(() => {
    h = !0;
  }), {
    posts: n,
    loading: c,
    error: u,
    hasMore: f,
    loadMore: () => d(!1),
    reload: () => d(!0)
  };
}
function ut(o) {
  var t;
  return (t = o.at(-1)) == null ? void 0 : t.published_at;
}
function Bt(o, t) {
  const e = /* @__PURE__ */ new Map();
  for (const a of o)
    e.set(a.id, a);
  for (const a of t)
    e.set(a.id, a);
  return [...e.values()];
}
function Dt(o) {
  return nt(o) ? o : new W();
}
const Ht = ["aria-busy"], Gt = {
  key: 0,
  class: "aloha-actor__status",
  role: "status",
  "aria-live": "polite"
}, jt = {
  key: 1,
  class: "aloha-actor__error",
  role: "alert"
}, Wt = ["disabled"], zt = {
  key: 2,
  class: "aloha-actor__status",
  role: "status",
  "aria-live": "polite"
}, Xt = { class: "aloha-actor__header" }, Jt = {
  class: "aloha-actor__avatar",
  "aria-hidden": "true"
}, Kt = { class: "aloha-actor__identity" }, Qt = { class: "aloha-actor__name" }, Yt = { class: "aloha-actor__handle" }, Zt = ["disabled", "aria-pressed"], te = { class: "aloha-actor__counts" }, ee = {
  key: 0,
  class: "aloha-actor__error",
  role: "alert"
}, oe = /* @__PURE__ */ D({
  __name: "ActorHeader",
  props: {
    accountId: {}
  },
  setup(o) {
    const t = o, e = k(() => t.accountId), { account: a, loading: n, busy: c, error: u, toggleFollow: f, reload: h } = Rt(e), v = k(() => {
      var _, b;
      const p = ((b = (_ = a.value) == null ? void 0 : _.display_name) == null ? void 0 : b.trim()) ?? "";
      return p === "" ? "?" : Array.from(p).slice(0, 2).join("").toUpperCase();
    }), d = k(() => {
      var p;
      return ((p = a.value) == null ? void 0 : p.relationship) != null;
    }), y = k(() => {
      var p, _;
      return ((_ = (p = a.value) == null ? void 0 : p.relationship) == null ? void 0 : _.following) === !0;
    });
    return (p, _) => (l(), r("section", {
      class: "aloha-actor",
      "aria-label": "Account profile",
      "aria-busy": s(n)
    }, [
      s(n) && s(a) === null ? (l(), r("p", Gt, " Loading profile… ")) : s(u) && s(a) === null ? (l(), r("div", jt, [
        i("p", null, m(s(u).message), 1),
        i("button", {
          type: "button",
          disabled: s(n),
          onClick: _[0] || (_[0] = //@ts-ignore
          (...b) => s(h) && s(h)(...b))
        }, "Try again", 8, Wt)
      ])) : s(a) === null ? (l(), r("p", zt, " Account not found. ")) : (l(), r(F, { key: 3 }, [
        i("header", Xt, [
          i("span", Jt, m(v.value), 1),
          i("div", Kt, [
            i("h2", Qt, m(s(a).display_name), 1),
            i("p", Yt, "@" + m(s(a).handle), 1)
          ]),
          d.value ? (l(), r("button", {
            key: 0,
            class: "aloha-actor__follow",
            type: "button",
            disabled: s(c),
            "aria-pressed": y.value,
            onClick: _[1] || (_[1] = //@ts-ignore
            (...b) => s(f) && s(f)(...b))
          }, m(y.value ? "Unfollow" : "Follow"), 9, Zt)) : N("", !0)
        ]),
        i("p", te, [
          i("span", null, [
            i("strong", null, m(s(a).followers_count), 1),
            _[2] || (_[2] = S(" followers", -1))
          ]),
          i("span", null, [
            i("strong", null, m(s(a).following_count), 1),
            _[3] || (_[3] = S(" following", -1))
          ])
        ]),
        s(u) ? (l(), r("p", ee, m(s(u).message), 1)) : N("", !0)
      ], 64))
    ], 8, Ht));
  }
}), H = (o, t) => {
  const e = o.__vccOpts || o;
  for (const [a, n] of t)
    e[a] = n;
  return e;
}, Io = /* @__PURE__ */ H(oe, [["__scopeId", "data-v-f529dfce"]]), ae = ["aria-busy"], se = { class: "aloha-notifications__header" }, ne = {
  key: 0,
  class: "aloha-notifications__badge",
  "aria-live": "polite"
}, ie = {
  key: 0,
  class: "aloha-notifications__status",
  role: "status",
  "aria-live": "polite"
}, le = {
  key: 1,
  class: "aloha-notifications__status"
}, re = ["href"], ce = {
  key: 2,
  class: "aloha-notifications__status",
  role: "status",
  "aria-live": "polite"
}, ue = {
  key: 3,
  class: "aloha-notifications__error",
  role: "alert"
}, de = ["disabled"], fe = {
  key: 4,
  class: "aloha-notifications__status",
  role: "status",
  "aria-live": "polite"
}, he = {
  class: "aloha-notifications__list",
  "aria-label": "Notification events"
}, pe = { class: "aloha-notifications__text" }, _e = ["onClick"], me = ["datetime"], ve = { class: "aloha-notifications__actions" }, ye = ["onClick"], be = ["disabled", "aria-label", "onClick"], ge = {
  key: 0,
  class: "aloha-notifications__error",
  role: "alert"
}, we = ["disabled"], $e = { class: "aloha-notifications__footer" }, Te = ["disabled"], ke = ["disabled"], Ce = /* @__PURE__ */ D({
  __name: "NotificationList",
  emits: ["open-post", "open-actor"],
  setup(o, { emit: t }) {
    const e = t, a = st(), n = $(null), c = k(() => n.value === !0), {
      notifications: u,
      unreadCount: f,
      loading: h,
      mutating: v,
      error: d,
      hasMore: y,
      loadMore: p,
      reload: _,
      dismiss: b,
      clear: w
    } = St(20, c), U = k(() => a.loginUrl(et())), V = k(() => n.value === !1 || d.value instanceof g && d.value.status === 401), E = {
      favourite: "favourited your post",
      follow: "followed you",
      mention: "mentioned you",
      reblog: "boosted your post"
    };
    tt(async () => {
      try {
        n.value = await a.currentUser() !== null;
      } catch {
        n.value = !1;
      }
    });
    function X(x) {
      return E[x.type];
    }
    function J(x) {
      const C = new Date(x);
      return Number.isNaN(C.getTime()) ? x : new Intl.DateTimeFormat(void 0, {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit"
      }).format(C);
    }
    function et() {
      return typeof window > "u" ? "/" : window.location.pathname + window.location.search;
    }
    return (x, C) => (l(), r("section", {
      class: "aloha-notifications",
      "aria-label": "Notifications",
      "aria-busy": s(h)
    }, [
      i("header", se, [
        C[4] || (C[4] = i("h2", { class: "aloha-notifications__title" }, "Notifications", -1)),
        n.value ? (l(), r("span", ne, m(s(f)) + " unread ", 1)) : N("", !0)
      ]),
      n.value === null ? (l(), r("p", ie, " Checking your session… ")) : V.value ? (l(), r("p", le, [
        i("a", { href: U.value }, "Sign in to see notifications", 8, re)
      ])) : s(h) && s(u).length === 0 ? (l(), r("p", ce, " Loading notifications… ")) : s(d) && s(u).length === 0 ? (l(), r("div", ue, [
        i("p", null, m(s(d).message), 1),
        i("button", {
          type: "button",
          disabled: s(h),
          onClick: C[0] || (C[0] = //@ts-ignore
          (...T) => s(_) && s(_)(...T))
        }, "Try again", 8, de)
      ])) : s(u).length === 0 ? (l(), r("p", fe, " No notifications yet. ")) : (l(), r(F, { key: 5 }, [
        i("ul", he, [
          (l(!0), r(F, null, G(s(u), (T) => (l(), r("li", {
            key: T.id,
            class: "aloha-notifications__item"
          }, [
            i("p", pe, [
              i("button", {
                class: "aloha-notifications__actor",
                type: "button",
                onClick: (q) => e("open-actor", T.actor_id)
              }, " @" + m(T.actor_id), 9, _e),
              S(" " + m(X(T)), 1)
            ]),
            i("time", {
              class: "aloha-notifications__time",
              datetime: T.created_at
            }, m(J(T.created_at)), 9, me),
            i("div", ve, [
              T.post_id ? (l(), r("button", {
                key: 0,
                type: "button",
                onClick: (q) => e("open-post", T.post_id)
              }, " View post ", 8, ye)) : N("", !0),
              i("button", {
                type: "button",
                disabled: s(v),
                "aria-label": `Dismiss notification from ${T.actor_id}`,
                onClick: (q) => s(b)(T.id)
              }, " Dismiss ", 8, be)
            ])
          ]))), 128))
        ]),
        s(d) ? (l(), r("p", ge, [
          S(m(s(d).message) + " ", 1),
          i("button", {
            type: "button",
            disabled: s(h),
            onClick: C[1] || (C[1] = //@ts-ignore
            (...T) => s(p) && s(p)(...T))
          }, "Try again", 8, we)
        ])) : N("", !0),
        i("div", $e, [
          s(y) && !s(d) ? (l(), r("button", {
            key: 0,
            type: "button",
            disabled: s(h),
            onClick: C[2] || (C[2] = //@ts-ignore
            (...T) => s(p) && s(p)(...T))
          }, m(s(h) ? "Loading…" : "Load more"), 9, Te)) : N("", !0),
          i("button", {
            type: "button",
            disabled: s(v),
            onClick: C[3] || (C[3] = //@ts-ignore
            (...T) => s(w) && s(w)(...T))
          }, " Clear all ", 8, ke)
        ])
      ], 64))
    ], 8, ae));
  }
}), Ao = /* @__PURE__ */ H(Ce, [["__scopeId", "data-v-0a02883c"]]), Ie = ["aria-label"], Ae = { class: "aloha-post-card__header" }, Ne = { class: "aloha-post-card__author" }, Pe = ["datetime"], Ue = {
  key: 0,
  class: "aloha-post-card__warning"
}, Ee = { class: "aloha-post-card__content" }, Me = {
  key: 1,
  class: "aloha-post-card__content"
}, Le = {
  key: 2,
  class: "aloha-post-card__poll"
}, Re = {
  key: 3,
  class: "aloha-post-card__attachments",
  "aria-label": "Attachments"
}, Fe = ["src", "alt"], Se = /* @__PURE__ */ D({
  __name: "PostCard",
  props: {
    post: {}
  },
  setup(o) {
    const t = o, e = k(() => a(t.post.published_at));
    function a(n) {
      const c = new Date(n);
      return Number.isNaN(c.getTime()) ? n : new Intl.DateTimeFormat(void 0, {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit"
      }).format(c);
    }
    return (n, c) => {
      var u;
      return l(), r("article", {
        class: "aloha-post-card",
        "aria-label": `Post by ${o.post.author_id}`
      }, [
        i("header", Ae, [
          i("span", Ne, "@" + m(o.post.author_id), 1),
          i("time", {
            class: "aloha-post-card__time",
            datetime: o.post.published_at
          }, m(e.value), 9, Pe)
        ]),
        o.post.content_warning ? (l(), r("details", Ue, [
          i("summary", null, "Content warning: " + m(o.post.content_warning), 1),
          i("p", Ee, m(o.post.content), 1)
        ])) : (l(), r("p", Me, m(o.post.content), 1)),
        o.post.poll ? (l(), r("fieldset", Le, [
          i("legend", null, m(o.post.poll.multiple ? "Poll (multiple choice)" : "Poll"), 1),
          i("ul", null, [
            (l(!0), r(F, null, G(o.post.poll.options, (f, h) => (l(), r("li", { key: h }, [
              S(m(f.title) + " ", 1),
              i("span", null, "(" + m(f.votes_count) + " votes)", 1)
            ]))), 128))
          ])
        ])) : N("", !0),
        (u = o.post.attachments) != null && u.length ? (l(), r("ul", Re, [
          (l(!0), r(F, null, G(o.post.attachments, (f) => (l(), r("li", {
            key: f.id
          }, [
            i("img", {
              src: f.thumbnail_url ?? f.url,
              alt: f.alt_text ?? "",
              loading: "lazy",
              decoding: "async"
            }, null, 8, Fe)
          ]))), 128))
        ])) : N("", !0)
      ], 8, Ie);
    };
  }
}), _t = /* @__PURE__ */ H(Se, [["__scopeId", "data-v-4c582831"]]), Ve = ["aria-busy"], xe = {
  key: 0,
  class: "aloha-composer__status",
  role: "status",
  "aria-live": "polite"
}, qe = {
  key: 1,
  class: "aloha-composer__status"
}, Oe = ["href"], Be = ["for"], De = ["id", "aria-invalid", "aria-describedby"], He = ["id"], Ge = { class: "aloha-composer__toggle" }, je = {
  key: 0,
  class: "aloha-composer__field"
}, We = ["for"], ze = ["id", "aria-invalid"], Xe = { class: "aloha-composer__count" }, Je = { class: "aloha-composer__field" }, Ke = ["for"], Qe = ["id"], Ye = {
  key: 1,
  class: "aloha-composer__error",
  role: "alert"
}, Ze = {
  key: 2,
  class: "aloha-composer__success",
  role: "status",
  "aria-live": "polite"
}, to = ["disabled"];
let eo = 0;
const oo = /* @__PURE__ */ D({
  __name: "PostComposer",
  props: {
    inReplyToId: { default: null },
    defaultVisibility: { default: "public" }
  },
  emits: ["created"],
  setup(o, { emit: t }) {
    const e = o, a = t, n = st(), { submitting: c, error: u, submit: f, resetError: h } = Ft(), v = `aloha-composer-${++eo}`, d = $(null), y = $(""), p = $(""), _ = $(!1), b = $(
      e.defaultVisibility === "direct" ? "public" : e.defaultVisibility
    ), w = $(!1), U = k(() => Array.from(y.value).length), V = k(() => Array.from(p.value).length), E = k(() => U.value > Q), X = k(() => V.value > Y), J = k(() => d.value === !0 && y.value.trim() !== "" && !E.value && !X.value && !c.value), et = k(() => u.value instanceof g ? u.value.fields : {}), x = k(() => n.loginUrl(T()));
    tt(async () => {
      try {
        d.value = await n.currentUser() !== null;
      } catch {
        d.value = !1;
      }
    });
    async function C() {
      if (w.value = !1, h(), !J.value)
        return;
      const q = await f({
        content: y.value,
        visibility: b.value,
        contentWarning: _.value ? p.value : null,
        inReplyToId: e.inReplyToId
      });
      q !== null && (y.value = "", p.value = "", _.value = !1, w.value = !0, a("created", q));
    }
    function T() {
      return typeof window > "u" ? "/" : window.location.pathname + window.location.search;
    }
    return (q, M) => (l(), r("section", {
      class: "aloha-composer",
      "aria-label": "Write a post",
      "aria-busy": s(c)
    }, [
      d.value === null ? (l(), r("p", xe, " Checking your session… ")) : d.value === !1 ? (l(), r("p", qe, [
        i("a", { href: x.value }, "Sign in to post", 8, Oe)
      ])) : (l(), r("form", {
        key: 2,
        class: "aloha-composer__form",
        onSubmit: bt(C, ["prevent"])
      }, [
        i("label", {
          for: `${v}-content`,
          class: "aloha-composer__label"
        }, "What's new?", 8, Be),
        K(i("textarea", {
          id: `${v}-content`,
          "onUpdate:modelValue": M[0] || (M[0] = (R) => y.value = R),
          class: "aloha-composer__content",
          rows: "4",
          "aria-invalid": E.value,
          "aria-describedby": `${v}-count`
        }, null, 8, De), [
          [rt, y.value]
        ]),
        i("p", {
          id: `${v}-count`,
          class: gt(["aloha-composer__count", { "aloha-composer__count--over": E.value }])
        }, m(U.value) + " / " + m(s(Q)), 11, He),
        i("label", Ge, [
          K(i("input", {
            "onUpdate:modelValue": M[1] || (M[1] = (R) => _.value = R),
            type: "checkbox"
          }, null, 512), [
            [wt, _.value]
          ]),
          M[4] || (M[4] = S(" Content warning ", -1))
        ]),
        _.value ? (l(), r("div", je, [
          i("label", {
            for: `${v}-warning`
          }, "Content warning", 8, We),
          K(i("input", {
            id: `${v}-warning`,
            "onUpdate:modelValue": M[2] || (M[2] = (R) => p.value = R),
            type: "text",
            "aria-invalid": X.value
          }, null, 8, ze), [
            [rt, p.value]
          ]),
          i("p", Xe, m(V.value) + " / " + m(s(Y)), 1)
        ])) : N("", !0),
        i("div", Je, [
          i("label", {
            for: `${v}-visibility`
          }, "Visibility", 8, Ke),
          K(i("select", {
            id: `${v}-visibility`,
            "onUpdate:modelValue": M[3] || (M[3] = (R) => b.value = R)
          }, [...M[5] || (M[5] = [
            i("option", { value: "public" }, "Public", -1),
            i("option", { value: "unlisted" }, "Unlisted", -1),
            i("option", { value: "followers" }, "Followers only", -1)
          ])], 8, Qe), [
            [$t, b.value]
          ])
        ]),
        s(u) ? (l(), r("p", Ye, [
          S(m(s(u).message) + " ", 1),
          (l(!0), r(F, null, G(et.value, (R, it) => (l(), r("span", {
            key: it,
            class: "aloha-composer__field-error"
          }, m(it) + ": " + m(R), 1))), 128))
        ])) : N("", !0),
        w.value ? (l(), r("p", Ze, " Posted. ")) : N("", !0),
        i("button", {
          class: "aloha-composer__submit",
          type: "submit",
          disabled: !J.value
        }, m(s(c) ? "Posting…" : "Post"), 9, to)
      ], 32))
    ], 8, Ve));
  }
}), No = /* @__PURE__ */ H(oo, [["__scopeId", "data-v-e635fdb6"]]), ao = ["aria-busy"], so = {
  key: 0,
  class: "aloha-post-detail__status",
  role: "status",
  "aria-live": "polite"
}, no = {
  key: 1,
  class: "aloha-post-detail__error",
  role: "alert"
}, io = ["disabled"], lo = {
  key: 2,
  class: "aloha-post-detail__status",
  role: "status",
  "aria-live": "polite"
}, ro = {
  key: 0,
  class: "aloha-post-detail__error",
  role: "alert"
}, co = ["disabled"], uo = /* @__PURE__ */ D({
  __name: "PostDetail",
  props: {
    postId: {}
  },
  setup(o) {
    const t = o, e = xt(k(() => t.postId)), { post: a, loading: n, error: c, reload: u } = e;
    return (f, h) => (l(), r("section", {
      class: "aloha-post-detail",
      "aria-label": "Post detail",
      "aria-busy": s(n)
    }, [
      s(n) && s(a) === null ? (l(), r("p", so, " Loading post… ")) : s(c) && s(a) === null ? (l(), r("div", no, [
        i("p", null, m(s(c).message), 1),
        i("button", {
          type: "button",
          disabled: s(n),
          onClick: h[0] || (h[0] = //@ts-ignore
          (...v) => s(u) && s(u)(...v))
        }, "Try again", 8, io)
      ])) : s(a) === null ? (l(), r("p", lo, " Post not found. ")) : (l(), r(F, { key: 3 }, [
        dt(_t, { post: s(a) }, null, 8, ["post"]),
        s(c) ? (l(), r("p", ro, [
          S(m(s(c).message) + " ", 1),
          i("button", {
            type: "button",
            disabled: s(n),
            onClick: h[1] || (h[1] = //@ts-ignore
            (...v) => s(u) && s(u)(...v))
          }, "Try again", 8, co)
        ])) : N("", !0)
      ], 64))
    ], 8, ao));
  }
}), Po = /* @__PURE__ */ H(uo, [["__scopeId", "data-v-4a8b69ef"]]), fo = ["aria-label", "aria-busy"], ho = {
  key: 0,
  class: "aloha-timeline__status",
  role: "status",
  "aria-live": "polite"
}, po = {
  key: 1,
  class: "aloha-timeline__error",
  role: "alert"
}, _o = ["disabled"], mo = {
  key: 2,
  class: "aloha-timeline__status",
  role: "status",
  "aria-live": "polite"
}, vo = {
  class: "aloha-timeline__list",
  "aria-label": "Posts"
}, yo = {
  key: 0,
  class: "aloha-timeline__error",
  role: "alert"
}, bo = ["disabled"], go = ["disabled"], wo = /* @__PURE__ */ D({
  __name: "PublicTimeline",
  props: {
    limit: { default: 20 },
    tag: {}
  },
  setup(o) {
    const t = o, e = k(() => {
      var p;
      return ((p = t.tag) == null ? void 0 : p.trim().replace(/^#/, "")) ?? "";
    }), a = k(() => e.value === "" ? "Public timeline" : `Hashtag #${e.value} timeline`), n = k(() => e.value === "" ? "Loading public timeline…" : `Loading hashtag #${e.value}…`), c = k(() => e.value === "" ? "No posts yet." : `No posts for #${e.value} yet.`), { posts: u, loading: f, error: h, hasMore: v, loadMore: d, reload: y } = Ot(t.limit, t.tag);
    return (p, _) => (l(), r("section", {
      class: "aloha-timeline",
      "aria-label": a.value,
      "aria-busy": s(f)
    }, [
      s(f) && s(u).length === 0 ? (l(), r("p", ho, m(n.value), 1)) : s(h) && s(u).length === 0 ? (l(), r("div", po, [
        i("p", null, m(s(h).message), 1),
        i("button", {
          type: "button",
          disabled: s(f),
          onClick: _[0] || (_[0] = //@ts-ignore
          (...b) => s(y) && s(y)(...b))
        }, "Try again", 8, _o)
      ])) : s(u).length === 0 ? (l(), r("p", mo, m(c.value), 1)) : (l(), r(F, { key: 3 }, [
        i("ul", vo, [
          (l(!0), r(F, null, G(s(u), (b) => (l(), r("li", {
            key: b.id
          }, [
            dt(_t, { post: b }, null, 8, ["post"])
          ]))), 128))
        ]),
        s(h) ? (l(), r("p", yo, [
          S(m(s(h).message) + " ", 1),
          i("button", {
            type: "button",
            disabled: s(f),
            onClick: _[1] || (_[1] = //@ts-ignore
            (...b) => s(d) && s(d)(...b))
          }, "Try again", 8, bo)
        ])) : N("", !0),
        s(v) && !s(h) ? (l(), r("button", {
          key: 1,
          class: "aloha-timeline__more",
          type: "button",
          disabled: s(f),
          onClick: _[2] || (_[2] = //@ts-ignore
          (...b) => s(d) && s(d)(...b))
        }, m(s(f) ? "Loading…" : "Load more"), 9, go)) : N("", !0)
      ], 64))
    ], 8, fo));
  }
}), Uo = /* @__PURE__ */ H(wo, [["__scopeId", "data-v-9e35c466"]]);
class Eo {
  constructor(t = {}) {
    P(this, "apiBase");
    P(this, "contractVersion");
    P(this, "calls", []);
    P(this, "loginUrls", []);
    P(this, "navigations", []);
    P(this, "logoutCalls", 0);
    P(this, "user");
    P(this, "capabilitiesValue");
    P(this, "responses");
    this.apiBase = t.apiBase ?? "/api/v1", this.contractVersion = t.contractVersion ?? O, this.user = t.user ?? null, this.capabilitiesValue = t.capabilities ?? { media: !1, atproto: !1 }, this.responses = { ...t.responses ?? {} };
  }
  async currentUser() {
    return this.user;
  }
  async request(t) {
    const e = {
      method: t.method,
      path: t.path
    };
    t.query !== void 0 && (e.query = { ...t.query }), t.body !== void 0 && (e.body = t.body), this.calls.push(e);
    const a = this.responses[$o(t)] ?? this.responses[`${t.method} ${t.path}`];
    if (a === void 0)
      throw new Error(`FakeHostBridge has no response for ${t.method} ${t.path}.`);
    const n = typeof a == "function" ? await a(t) : a;
    return {
      status: n.status ?? 200,
      headers: n.headers ?? {},
      body: n.body ?? ""
    };
  }
  loginUrl(t) {
    return this.loginUrls.push(t), `/login?return_to=${encodeURIComponent(t)}`;
  }
  async logout() {
    ++this.logoutCalls;
  }
  navigate(t) {
    this.navigations.push(t);
  }
  capabilities() {
    return { ...this.capabilitiesValue };
  }
  setResponse(t, e) {
    this.responses[t] = e;
  }
}
function $o(o) {
  const t = o.query === void 0 ? "" : new URLSearchParams(
    Object.entries(o.query).sort(([e], [a]) => e.localeCompare(a))
  ).toString();
  return `${o.method} ${o.path}${t === "" ? "" : `?${t}`}`;
}
export {
  Io as ActorHeader,
  Tt as CORE_API_VERSION,
  Et as CoreApi,
  Pt as DEFAULT_NOTIFICATION_LIMIT,
  At as DEFAULT_TIMELINE_LIMIT,
  Eo as FakeHostBridge,
  O as HOST_BRIDGE_CONTRACT_VERSION,
  Y as MAX_CONTENT_WARNING_LENGTH,
  Ut as MAX_NOTIFICATION_LIMIT,
  Q as MAX_POST_LENGTH,
  Nt as MAX_TIMELINE_LIMIT,
  Ao as NotificationList,
  _t as PostCard,
  No as PostComposer,
  Po as PostDetail,
  Uo as PublicTimeline,
  ot as UI_VERSION,
  g as UiApiError,
  W as UiTransportError,
  It as assertHostBridgeCompatible,
  L as coreRoutes,
  Co as createAlohaUi,
  $o as fakeRequestKey,
  ft as hostBridgeKey,
  nt as isUiFailure,
  pt as normalizeNotificationLimit,
  ht as normalizeTimelineLimit,
  Rt as useActorProfile,
  Ft as useComposer,
  z as useCoreApi,
  st as useHostBridge,
  St as useNotifications,
  xt as usePostDetail,
  Ot as usePublicTimeline
};
