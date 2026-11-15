//#region ../../shared/players/common/facade.ts
var e = class {
	#e;
	#t;
	#n = !1;
	id;
	play;
	pause;
	stop;
	on;
	destroy;
	getNativeInstance;
	constructor(e, t, n) {
		this.#e = e, this.#t = n, this.id = t, this.play = this.#r("play", () => this.#e.play()), this.pause = this.#r("pause", () => this.#e.pause()), this.stop = this.#r("stop", () => this.#e.stop()), this.getNativeInstance = this.#r("getNativeInstance", () => this.#e.getNativeInstance()), this.on = this.#r("on", (e, t) => {
			let n = this.#e.on(e, t);
			return () => {
				this.#n || n();
			};
		}), this.destroy = () => {
			if (!this.#n) {
				this.#n = !0;
				try {
					this.#e.destroy();
				} finally {
					this.#t?.();
				}
			}
		};
	}
	get type() {
		return this.#e.type;
	}
	#r(e, t) {
		return (...n) => {
			if (this.#n) throw Error(`[VideoPlayerFacade] Cannot call "${e}" on a destroyed player.`);
			return t(...n);
		};
	}
};
//#endregion
//#region ../../node_modules/.pnpm/@fandom+odyssey-tracking-client@1.2.1/node_modules/@fandom/odyssey-tracking-client/dist/index.js
function t() {
	return "1.2.1";
}
var n = "odyssey-tracking-client", r = () => {
	var e;
	let r = globalThis;
	typeof r.window > "u" || (r.fandomOdysseyUtils ??= {}, (e = r.fandomOdysseyUtils)[n] ?? (e[n] = { version: t() }));
}, i = () => {
	let e = window.fandomTrack ?? {};
	return Array.isArray(e.q) || (e.q = []), Array.isArray(e.cmd) || (e.cmd = []), (!e.registry || typeof e.registry != "object") && (e.registry = {}), window.fandomTrack = e, r(), e;
}, a = "shortlivedtrackingevent", o = "trackingevent", s = (e, t = {}, n = { requiresConsent: !0 }) => {
	if (e === a) throw Error("shortlivedtrackingevent cannot be re-registered via buildBasicTrackingFunction, use buildShortlivedTrackingFunction instead.");
	if (e === o) throw Error("trackingevent usage is discouraged, use shortlived or register your own custom event type in event catalog.");
	return (r) => {
		let a = i();
		a.registry[e] || a.cmd.push((t) => {
			if (typeof t.createAndRegisterBasicTracker != "function") throw Error("fandomTrack.createAndRegisterBasicTracker is not available");
			t.createAndRegisterBasicTracker(e, n);
		}), a.q.push({
			event: e,
			payload: {
				...t,
				...r
			}
		});
	};
}, c = function() {
	return c = Object.assign || function(e) {
		for (var t, n = 1, r = arguments.length; n < r; n++) for (var i in t = arguments[n], t) Object.prototype.hasOwnProperty.call(t, i) && (e[i] = t[i]);
		return e;
	}, c.apply(this, arguments);
};
typeof window < "u" && window.top;
//#endregion
//#region ../../shared/utils/src/issue-breadcrumbs.ts
var l = 20, u = [];
function d(e) {
	u.push({
		action: e,
		ts: typeof performance < "u" ? performance.now() : -1
	}), u.length > l && u.shift();
}
function f() {
	return u.map((e) => `${Math.round(e.ts)}ms:${e.action}`).join(" → ");
}
//#endregion
//#region ../../shared/utils/src/issue-tracking.ts
var p = "morpheus_performance", m = { requiresConsent: !1 }, h = {
	engine_version: "unknown",
	env: "unknown"
};
function ee(e) {
	h = e;
}
var g, _ = [], te, ne = 0, re = 64, ie = 250, ae = 40;
function oe(e) {
	if (typeof e != "object" || !e) return !1;
	let t = e;
	return Array.isArray(t.q) && Array.isArray(t.cmd) && typeof t.registry == "object" && t.registry !== null;
}
function se() {
	return typeof window < "u" && oe(window.fandomTrack);
}
function ce(e) {
	g ??= s(p, { category: "morpheus" }, m), g(e);
}
function le() {
	if (_.length === 0) return;
	let e = _;
	_ = [];
	for (let t of e) ce(t);
}
function ue() {
	te ||= setTimeout(() => {
		if (te = void 0, _.length !== 0) {
			if (se() || ++ne >= ae) {
				try {
					le();
				} catch {}
				return;
			}
			ue();
		}
	}, ie);
}
function v(e, t = {}) {
	try {
		if (t.error !== void 0 && pe(t.error)) return;
		let n = t.error === void 0 ? void 0 : he(t.error), r = t.error_type ?? n?.error_type, i = t.error_message ?? n?.error_message, a = {
			...h,
			component: "video-engine",
			action: "issue",
			issue_code: e.code,
			constant_name: e.constantName,
			issue_priority: e.priority
		};
		if (t.label && (a.label = t.label), r && (a.error_type = r), i && (a.error_message = me(i, 500)), t.player_type && (a.player_type = t.player_type), t.player_mode && (a.player_mode = t.player_mode), t.exp_bucket && (a.exp_bucket = t.exp_bucket), t.experiment_groups && (a.experiment_groups = t.experiment_groups), e.code.startsWith("U")) {
			let e = f();
			e && (a.label = a.label ? `${a.label} | ${e}` : e);
		}
		se() ? (le(), ce(a)) : (_.push(a), _.length > re && _.shift(), ue()), t.error !== void 0 && fe(t.error);
	} catch {}
}
var de = globalThis[Symbol.for("morpheus.reportedErrors")] ??= /* @__PURE__ */ new WeakSet();
function fe(e) {
	e instanceof Error && de.add(e);
}
function pe(e) {
	return e instanceof Error && de.has(e);
}
function me(e, t) {
	return e.length > t ? e.slice(0, t) : e;
}
function he(e) {
	return e instanceof Error ? {
		error_type: e.constructor.name || "Error",
		error_message: e.message
	} : {
		error_type: "NonError",
		error_message: String(e)
	};
}
var ge = { get: (e) => {
	let t = ("; " + document.cookie).split("; " + e + "=");
	return t.length >= 2 ? t.pop().split(";").shift() : null;
} };
//#endregion
//#region ../../shared/utils/src/experiments.ts
function _e(e, t) {
	let n = e.featureFlags?.get("icExperiments");
	return n ? n.includes(t) : !1;
}
var ve = /^(?:v\d+-)?(\d{2})$/;
function ye() {
	return ge.get("exp_bucket")?.match(ve)?.[1] ?? "";
}
function be() {
	let e = globalThis.googletag?.pubads?.()?.getTargeting?.("experiment_groups");
	return Array.isArray(e) ? e.join(",") : "";
}
//#endregion
//#region ../../packages/contracts/src/issue-codes.ts
var y = /* @__PURE__ */ function(e) {
	return e.P1 = "P1", e.P2 = "P2", e.P3 = "P3", e.P4 = "P4", e;
}({}), xe = {
	code: "E001",
	constantName: "EngineBootstrapFailed",
	priority: y.P1,
	description: "Fatal error during engine bootstrap; runtime never initialized"
}, b = {
	code: "E002",
	constantName: "PluginHookExecutionFailed",
	priority: y.P2,
	description: "A plugin hook threw; isolated so other plugins continue"
}, Se = {
	code: "E003",
	constantName: "PlayerFactoryAttachConfigFailed",
	priority: y.P2,
	description: "factory.attachConfig() threw during factory registration"
}, Ce = {
	code: "E004",
	constantName: "PlayerCreateFailed",
	priority: y.P2,
	description: "Player instance could not be created from the queued command"
}, x = {
	code: "E005",
	constantName: "ConfigInitializationFailed",
	priority: y.P2,
	description: "window.__morpheusConfig missing/invalid; engine cannot configure"
}, S = {
	code: "E006",
	constantName: "InstantConfigFetchFailed",
	priority: y.P3,
	description: "Instant Config (ICBM) flags could not be fetched"
}, we = {
	code: "E007",
	constantName: "TrackingFlushFailed",
	priority: y.P4,
	description: "FTL enqueue/flush threw while reporting telemetry"
}, C = {
	code: "E101",
	constantName: "PlayerScriptLoadFailed",
	priority: y.P2,
	description: "Vendor SDK/script/bundle failed to inject/load"
}, Te = {
	code: "E102",
	constantName: "PlayerSetupFailed",
	priority: y.P2,
	description: "Adapter setup() / vendor init threw or rejected"
}, Ee = {
	code: "E103",
	constantName: "PlayerMountFailed",
	priority: y.P2,
	description: "Target container missing / DOM attach failed"
}, w = {
	code: "E104",
	constantName: "PlayerReadyTimeout",
	priority: y.P3,
	description: "Player never signaled ready within budget"
}, De = {
	code: "E105",
	constantName: "PlayerMediaLoadError",
	priority: y.P2,
	description: "Vendor media/network error (manifest 404, CORS, source unreachable)"
}, Oe = {
	code: "E106",
	constantName: "PlayerPlaybackFatalError",
	priority: y.P2,
	description: "Vendor fatal playback error (decode, unrecoverable buffer/DRM)"
}, T = {
	code: "E107",
	constantName: "PlayerDestroyFailed",
	priority: y.P3,
	description: "Teardown threw (facade isolates, still reported)"
}, ke = {
	code: "E108",
	constantName: "AdCustomElementNotDefined",
	priority: y.P3,
	description: "Custom element wait budget exceeded; element never defined"
}, Ae = {
	code: "D001",
	constantName: "FandomTrackQueueUnavailable",
	priority: y.P3,
	description: "window.fandomTrack never appeared after cold-start poll window; buffered events dropped"
}, E = {
	code: "D002",
	constantName: "PluginWaitingTimeout",
	priority: y.P3,
	description: "onConfigLoaded plugin init exceeded timeout; bootstrap proceeded"
}, D = {
	code: "D003",
	constantName: "PlayerFactoryNotRegistered",
	priority: y.P2,
	description: "Queue drain found no registered factory for requested player type"
}, je = {
	code: "D004",
	constantName: "InstantConfigLocalStorageError",
	priority: y.P3,
	description: "LocalStorage access error while reading/caching instant config"
}, Me = {
	code: "D005",
	constantName: "PerfMarkMissing",
	priority: y.P4,
	description: "No matching start/render mark when measuring a perf duration"
}, O = {
	code: "D101",
	constantName: "PlayerMediaResolveMissing",
	priority: y.P3,
	description: "No mediaId / empty playlist / unresolved source; player skipped"
}, Ne = {
	code: "D102",
	constantName: "PlayerFormatUnsupported",
	priority: y.P3,
	description: "Codec/format/DRM unsupported in env; fallback/skip"
}, Pe = {
	code: "D103",
	constantName: "PlayerAutoplayBlocked",
	priority: y.P4,
	description: "Browser blocked autoplay; degraded to muted / click-to-play"
}, Fe = {
	code: "D104",
	constantName: "PlayerRecoverableMediaError",
	priority: y.P4,
	description: "Vendor recoverable media error, auto-retried"
}, Ie = {
	code: "D201",
	constantName: "AdConsentTimeout",
	priority: y.P3,
	description: "Consent listener timed out; ad plugin degraded"
}, k = {
	code: "D202",
	constantName: "AdSlotRequestRenderFailed",
	priority: y.P3,
	description: "requestRender failed for a slot; slot skipped"
}, A = {
	code: "D204",
	constantName: "BucketExperimentUnknownGroup",
	priority: y.P4,
	description: "setExperimentGroup received unrecognized group; defaulted to control"
}, Le = {
	code: "D205",
	constantName: "AdTagRefreshRenderFailed",
	priority: y.P3,
	description: "requestRender failed during an ad-request tag refresh; next ad keeps the previous tag"
}, j = {
	code: "U001",
	constantName: "UnexpectedRuntimeError",
	priority: y.P2,
	description: "Uncaught error with no specific issue code; indicates a gap in error handling"
}, Re = {
	code: "U002",
	constantName: "UnhandledPromiseRejection",
	priority: y.P2,
	description: "Unhandled promise rejection originating from Morpheus code"
};
new Map([
	[xe.code, xe],
	[b.code, b],
	[Se.code, Se],
	[Ce.code, Ce],
	[x.code, x],
	[S.code, S],
	[we.code, we],
	[C.code, C],
	[Te.code, Te],
	[Ee.code, Ee],
	[w.code, w],
	[De.code, De],
	[Oe.code, Oe],
	[T.code, T],
	[ke.code, ke],
	[Ae.code, Ae],
	[E.code, E],
	[D.code, D],
	[je.code, je],
	[Me.code, Me],
	[O.code, O],
	[Ne.code, Ne],
	[Pe.code, Pe],
	[Fe.code, Fe],
	[Ie.code, Ie],
	[k.code, k],
	[A.code, A],
	[Le.code, Le],
	[j.code, j],
	[Re.code, Re]
]);
//#endregion
//#region ../../shared/utils/src/perf-tracking.ts
var ze = "morpheus_performance", Be = { requiresConsent: !1 }, Ve = {
	engine_version: "unknown",
	env: "unknown"
}, He;
function Ue(e) {
	Ve = e;
}
function We(e) {
	try {
		return e();
	} catch {
		return "";
	}
}
function Ge() {
	if (He) return He;
	let e = {
		exp_bucket: We(ye),
		experiment_groups: We(be)
	};
	return e.experiment_groups && (He = e), e;
}
var Ke, M = [], qe, Je = 0, Ye = 64, Xe = 250, Ze = 40;
function Qe(e) {
	if (typeof e != "object" || !e) return !1;
	let t = e;
	return Array.isArray(t.q) && Array.isArray(t.cmd) && typeof t.registry == "object" && t.registry !== null;
}
function $e() {
	return typeof window < "u" && Qe(window.fandomTrack);
}
function et(e) {
	Ke ??= s(ze, { category: "morpheus" }, Be), Ke(e);
}
function tt() {
	if (M.length === 0) return;
	let e = M;
	M = [];
	for (let t of e) et(t);
}
function nt() {
	qe ||= setTimeout(() => {
		if (qe = void 0, M.length !== 0) {
			if ($e() || ++Je >= Ze) {
				$e() || v(Ae);
				try {
					tt();
				} catch (e) {
					v(we, { error: e });
				}
				return;
			}
			nt();
		}
	}, Xe);
}
function N(e) {
	try {
		let t = {
			...Ve,
			...Ge(),
			...e
		};
		if ($e()) {
			tt(), et(t);
			return;
		}
		M.push(t), M.length > Ye && M.shift(), nt();
	} catch {}
}
function P() {
	return typeof performance < "u" ? performance.now() : NaN;
}
function F(e) {
	let t = P() - e;
	return Number.isFinite(t) && t >= 0 ? t : -1;
}
//#endregion
//#region ../../shared/utils/src/logger.ts
var rt = "morpheus_debug", I = !1;
function it(e) {
	I = e;
}
function L() {
	return I;
}
function at(e = globalThis.location?.search ?? "") {
	let t = new URLSearchParams(e);
	if (!t.has("morpheus_debug")) return !1;
	let n = t.get(rt);
	return n !== "false" && n !== "0";
}
function R(e) {
	let t = `[${e}]`;
	return {
		log: (...e) => {
			I && console.log(t, ...e);
		},
		info: (...e) => {
			I && console.info(t, ...e);
		},
		debug: (...e) => {
			I && console.debug(t, ...e);
		},
		warn: (...e) => {
			console.warn(t, ...e);
		},
		error: (...e) => {
			console.error(t, ...e);
		}
	};
}
//#endregion
//#region runtime/run-hook.ts
var ot = R("RunHook"), st = [];
function z(e, t, ...n) {
	d(`hook:${t}`);
	let r = [], i = [];
	for (let a of e) {
		let e = a.hooks[t];
		if (typeof e != "function") continue;
		let o = (e) => {
			r.push(a.name), ot.error(`Plugin "${a.name}" threw in hook "${t}":`, e), v(b, {
				label: `${a.name}:${t}`,
				error: e
			});
		}, s = Promise.resolve();
		try {
			s = Promise.resolve(e.call(a, ...n)).catch(o);
		} catch (e) {
			o(e);
		}
		i.push(s);
	}
	return L() && ct(t, n, r, i), i;
}
async function B(e, t, ...n) {
	d(`hook:${t}`);
	let r = [];
	for (let i of e) {
		let e = i.hooks[t];
		if (typeof e == "function") try {
			await e.call(i, ...n);
		} catch (e) {
			r.push(i.name), ot.error(`Plugin "${i.name}" threw in hook "${t}":`, e), v(b, {
				label: `${i.name}:${t}`,
				error: e
			});
		}
	}
	L() && ct(t, n, r, []);
}
function ct(e, t, n, r) {
	let i = {
		time: typeof performance < "u" ? performance.now() : -1,
		name: e,
		params: t,
		failures: n
	};
	Promise.allSettled(r).then(() => st.push(i));
}
//#endregion
//#region runtime/create-runtime.ts
var V = "mve-player", lt = "v17";
function ut(t, n = []) {
	let r = R("CreateRuntime"), i = [];
	for (let e of n) try {
		e.setConfig?.(t), e.setPlugins(n), i.push(e);
	} catch (t) {
		r.error(`Plugin "${e.name}" threw in setPlugins(), skipping:`, t), v(b, {
			label: `${e.name}:setPlugins`,
			error: t
		});
	}
	let a = /* @__PURE__ */ new Map(), o = /* @__PURE__ */ new Map(), s = /* @__PURE__ */ new Map();
	return {
		version: lt,
		config: t,
		getPlugin(e) {
			return i.find((t) => t.name === e);
		},
		getPlugins() {
			return [...i];
		},
		registerPlayerFactory(e) {
			d(`factory:register:${e.type}`), a.has(e.type) && r.warn(`Player factory "${e.type}" is already registered; overwriting.`);
			try {
				e.attachConfig?.(t);
			} catch (t) {
				r.error(`Player factory "${e.type}" threw in attachConfig():`, t), v(Se, {
					player_type: e.type,
					error: t
				});
			}
			a.set(e.type, e);
		},
		async createPlayer(t, n, c) {
			d(`player:create:${t}`);
			let l = a.get(t);
			if (!l) throw v(D, { player_type: t }), Error(`[Morpheus] No player factory registered for type "${t}"`);
			let u = P(), f = {
				type: t,
				container: n,
				options: c
			};
			await B(i, "onBeforePlayerCreate", f);
			let p = (e) => {
				B(i, "onPlayerCreateFailed", {
					type: t,
					container: n,
					options: f.options,
					error: e
				});
			}, m;
			try {
				m = await l.create(n, f.options);
			} catch (e) {
				throw v(Ce, {
					player_type: t,
					error: e
				}), p(e), e;
			}
			let h, ee = {
				type: t,
				container: n
			}, g;
			try {
				g = dt(n, t, s, r), ft(o, g);
			} catch (e) {
				try {
					m.destroy();
				} catch (e) {
					v(T, {
						player_type: t,
						error: e
					});
				}
				throw p(e), e;
			}
			return h = new e(m, g, () => {
				d(`player:destroy:${t}`), r.debug(`Destroying player "${g}" (${t})`), n.classList.remove(V, `${V}--${t}`), o.get(g) === h && o.delete(g), B(i, "onAfterPlayerDestroy", ee), n.replaceChildren();
			}), o.set(g, h), N({
				component: "video-engine",
				action: "player-created",
				duration_ms: F(u),
				label: t
			}), n.classList.add(V, `${V}--${t}`), B(i, "onAfterPlayerCreate", {
				type: t,
				container: n,
				player: h,
				options: f.options
			}), h;
		},
		getPlayers() {
			return [...o.values()];
		},
		findPlayer(e) {
			return o.get(e);
		}
	};
}
function dt(e, t, n, r) {
	if (e.id) return e.id;
	let i = (n.get(t) ?? 0) + 1;
	n.set(t, i);
	let a = `${t}-${i}`;
	return r.warn(`Player container has no id; using fallback "${a}". Set container.id for stable, reusable player identifiers.`), a;
}
function ft(e, t) {
	if (e.has(t)) throw Error(`[Morpheus] Duplicate player id "${t}": a player with this id is already tracked. Destroy the existing player before creating a new one with the same id.`);
}
function pt(e, t) {
	let n = e;
	for (let [r, ...i] of t) {
		let t = n[r];
		typeof t == "function" && t.apply(e, i);
	}
}
//#endregion
//#region runtime/initialize-plugins.ts
var mt = R("InitializePlugins");
function ht(e, t) {
	return e.filter((e) => {
		try {
			return e.isEnabled(t);
		} catch (t) {
			return mt.error(`Plugin "${e.name}" threw in isEnabled():`, t), v(j, {
				label: `${e.name}:isEnabled`,
				error: t
			}), !1;
		}
	});
}
//#endregion
//#region ../../shared/instant-config/src/repository.ts
var gt = {}, _t = "icbm__";
function vt(e) {
	if (e === "true" || e === "false") return e === "true";
	let t = parseInt(e, 10);
	if (e === `${t}`) return t;
	try {
		return JSON.parse(e);
	} catch {
		return e;
	}
}
function yt(e, t) {
	let n = new URLSearchParams(t), r = [...n.keys()].filter((e) => e.startsWith(_t) && n.get(e) !== null).reduce((e, t) => (e[t.slice(6)] = vt(n.get(t)), e), {});
	return {
		...e,
		...r
	};
}
//#endregion
//#region ../../shared/instant-config/src/instant-config-service.ts
var bt = R("InstantConfig"), xt = "instantConfigLoaded", St = 2e3, Ct = class {
	repository = { ...gt };
	async init(e) {
		let t = await wt(e), n = {
			...gt,
			...t
		};
		return this.repository = yt(n, e.location?.search ?? ""), bt.info("initialized with", this.repository), this;
	}
	get(e, t) {
		return this.repository[e] ?? t;
	}
	getAll() {
		return { ...this.repository };
	}
	toJSON() {
		return this.getAll();
	}
};
function wt(e) {
	return e.icbm?.config ? Promise.resolve(e.icbm.config) : new Promise((t) => {
		let n = () => {
			clearTimeout(r), t(e.icbm?.config ?? {});
		}, r = setTimeout(() => {
			e.removeEventListener(xt, n), bt.warn("host did not provide instant-config in time; using defaults"), t({});
		}, St);
		e.addEventListener(xt, n, { once: !0 });
	});
}
//#endregion
//#region runtime/initialize-instant-config.ts
var Tt = R("InstantConfig:bootstrap");
async function Et() {
	let e = globalThis.window;
	try {
		return await new Ct().init(e);
	} catch (e) {
		return Tt.error("failed to initialize instant-config; using defaults", e), v(S, { error: e }), new Ct();
	}
}
//#endregion
//#region runtime/attach-debug-info.ts
function Dt(e) {
	L() && (e.debug = {
		version: e.version,
		config: e.config,
		activePlugins: e.getPlugins().map((e) => e.name)
	});
}
//#endregion
//#region runtime/constants.ts
var Ot = 2e3, kt = /* @__PURE__ */ Object.assign({ "../config/default.ts": () => import("./default-BQtT_IEv.js") }), At = R("LoadConfig");
async function jt(e) {
	let t = "../config/default.ts", n = kt[e ? `../config/${e}/${e}.ts` : t] ?? kt[t];
	try {
		return (await n()).default;
	} catch (t) {
		At.error("Could not load config for app", {
			app: e,
			error: t
		});
		let n = Error(`[VideoEngine] [LoadConfig] Could not load config for app: ${e}`, { cause: t });
		throw v(x, {
			label: e ?? "default",
			...he(t),
			error: n
		}), n;
	}
}
//#endregion
//#region ../../shared/utils/src/geo.ts
var H = "ZZ", Mt = {
	country: H,
	region: H,
	continent: H
};
function Nt() {
	let e = window.fandomHost?.geo;
	if (e && e.country) return e;
	let t = ge.get("Geo");
	if (t) try {
		return JSON.parse(decodeURIComponent(t));
	} catch {}
	return Mt;
}
//#endregion
//#region ../../shared/utils/src/device.ts
var Pt = new Set([
	"desktop",
	"smartphone",
	"tablet"
]);
function Ft(e) {
	return Pt.has(e);
}
var It = /Mobile|iPhone|Android|Silk|Kindle|Windows Phone|KFAPWI|(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i, Lt = /1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw-(n|u)|c55\/|capi|ccwa|cdm-|cell|chtm|cldc|cmd-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc-s|devi|dica|dmob|do(c|p)o|ds(12|-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(-|_)|g1 u|g560|gene|gf-5|g-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd-(m|p|t)|hei-|hi(pt|ta)|hp( i|ip)|hs-c|ht(c(-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i-(20|go|ma)|i230|iac( |-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|-[a-w])|libw|lynx|m1-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|-([1-8]|c))|phil|pire|pl(ay|uc)|pn-2|po(ck|rt|se)|prox|psio|pt-g|qa-a|qc(07|12|21|32|60|-[2-7]|i-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h-|oo|p-)|sdk\/|se(c(-|0|1)|47|mc|nd|ri)|sgh-|shar|sie(-|m)|sk-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h-|v-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl-|tdg-|tel(i|m)|tim-|t-mo|to(pl|sh)|ts(70|m-|m3|m5)|tx-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas-|your|zeto|zte-/i, Rt = /iPad|Android(?!.*Mobile)/i;
function zt() {
	return Wt(window.fandomHost?.device, window.navigator.userAgent);
}
function Bt(e) {
	return e ?? zt();
}
function Vt(e) {
	return Bt(e) === "smartphone";
}
function Ht(e) {
	return Bt(e) === "tablet";
}
function Ut(e) {
	return Vt(e) || Ht(e);
}
function Wt(e, t = "") {
	return e && Ft(e) ? e : Gt(t);
}
function Gt(e) {
	return Rt.test(e) ? "tablet" : It.test(e) || Lt.test(e.substring(0, 4)) ? "smartphone" : "desktop";
}
//#endregion
//#region runtime/build-page-context.ts
async function Kt() {
	return {
		geo: Nt(),
		device: zt()
	};
}
//#endregion
//#region runtime/initialize-config.ts
async function qt(e) {
	let t = await jt(), n = await Kt(), r = { ...t.players };
	for (let [n, i] of Object.entries(e.players ?? {})) r[n] = {
		...t.players?.[n],
		...i
	};
	return {
		...t,
		...e,
		players: r,
		pageContext: n
	};
}
//#endregion
//#region ../../shared/plugins/morpheus-plugin/morpheus-plugin.ts
var U = class {
	hooks = {};
	api;
	#e = [];
	#t;
	constructor(e) {
		this.pluginConfig = e;
	}
	isEnabled(e) {
		return this.pluginConfig.enabled !== !1;
	}
	setPlugins(e) {
		this.#e = e;
	}
	setConfig(e) {
		this.#t = e;
	}
	useFeatureFlag(e, t) {
		return this.#t?.featureFlags?.get(e) ?? t;
	}
	usePlugin(e) {
		return this.#e.find((t) => t.name === e);
	}
	useExperiment(e) {
		return this.#e.find((t) => t.experimentName === e.experimentName);
	}
}, Jt = "fandom-video-ad", Yt = R("FandomVideoAdPlugin");
function Xt(e) {
	let t = document.getElementById(e);
	return t && t.localName === "fandom-video-ad" ? t : null;
}
function Zt(e, t) {
	let n = t.parentNode;
	if (!n) return Yt.warn(`Container has no parent; cannot create ad element for slot "${e}".`), null;
	let r = document.createElement(Jt);
	return r.id = e, n.insertBefore(r, t), r;
}
//#endregion
//#region ../../shared/plugins/fandom-video-ad/consents.ts
var W = null;
function Qt(e, t) {
	return t.consentString !== e?.consentString || t.optOut !== e?.optOut;
}
function $t() {
	return W || (window.fandomCmp?.allowed.ads ? Promise.resolve(!0) : (W = new Promise((e) => {
		let t = (n) => {
			(n.detail?.allowed.ads || window.fandomCmp?.allowed.ads) && (window.removeEventListener("fandomConsentUpdate", t), e(!0));
		};
		window.addEventListener("fandomConsentUpdate", t);
	}).finally(() => {
		W = null;
	}), W));
}
//#endregion
//#region ../../shared/plugins/fandom-video-ad/fandom-video-ad-plugin.ts
var en = 1e3, tn = 5e3, nn = 3;
function rn(e, t) {
	let n = new URLSearchParams();
	t && n.set("player", t);
	let r = (e) => {
		if (e) for (let [t, r] of Object.entries(e)) r != null && n.set(t, Array.isArray(r) ? r.join(",") : r);
	};
	return e && (e.vpos && n.set("vpos", e.vpos), r(e.pageTargeting), r(e.slotTargeting)), n.toString() || void 0;
}
function an(e, t, n) {
	let r, i = new Promise((e, i) => {
		r = setTimeout(() => i(/* @__PURE__ */ Error(`${n} timed out after ${t}ms`)), t);
	});
	return Promise.race([e, i]).finally(() => clearTimeout(r));
}
var on = () => !!window.fandomCmp?.allowed.ads;
function G(e) {
	try {
		return e.getNativeInstance(), !1;
	} catch {
		return !0;
	}
}
var sn = class extends U {
	name = "FandomVideoAdPlugin";
	#e = R("FandomVideoAdPlugin");
	#t = /* @__PURE__ */ new WeakMap();
	#n = /* @__PURE__ */ new Map();
	#r = null;
	#i = null;
	hooks = {
		onRuntimeReady: async () => {
			this.#s();
		},
		onBeforePlayerCreate: (e) => this.#o(e),
		onAfterPlayerCreate: async (e) => this.#u(e),
		onPlayerCreateFailed: async (e) => this.#p(e),
		onAfterPlayerDestroy: async (e) => this.#b(e)
	};
	constructor(e = {}) {
		super(e);
	}
	get #a() {
		return this.pluginConfig.requestRenderTimeoutMs ?? tn;
	}
	async #o(e) {
		let t = e.options, n = t.adSlotName;
		if (!n) return;
		let r = e.container;
		this.#n.has(t) && this.#e.warn(`options object reused by overlapping createPlayer calls for slot "${n}" — ad tag refresh tracks only the latest`), this.#n.set(t, {
			container: r,
			options: t,
			slotName: n,
			resolveApplyAdTag: () => r.isConnected ? t.applyAdTag : void 0
		}), on() || (this.#e.log(`ad request deferred for slot "${n}" — waiting for consent`), await $t());
		let i = this.pluginConfig.whenDefinedTimeoutMs ?? en, a = this.#a, o;
		if (!await Promise.race([customElements.whenDefined("fandom-video-ad").then(() => (clearTimeout(o), !0)), new Promise((e) => {
			o = setTimeout(() => e(!1), i);
		})])) {
			this.#e.warn(`"<${Jt}>" not defined within ${i}ms — playing without ads.`), v(ke, { label: n });
			return;
		}
		let s = this.#y(n, e.container);
		if (!s) return;
		let c, l = null;
		try {
			[c, l] = await an(Promise.all([s.requestRender(), Promise.resolve().then(() => s.getAdUnitPath()).catch(() => null)]), a, "requestRender");
		} catch (e) {
			this.#e.warn(`requestRender failed for slot "${n}":`, e), v(k, {
				label: n,
				error: e
			});
			return;
		}
		if (c.vastUrl) {
			t.adVastUrl = c.vastUrl, t.adUnitPath = l, t.adTargetingParams = rn(c.vastParams, e.type);
			try {
				let e = s.getMonetizationTier();
				if (e != null) {
					let n = Number(e);
					Number.isFinite(n) && (t.adTier = n);
				}
			} catch {}
			t.adTier ??= nn, this.#e.log(`slot "${n}" enriched: adTier=${t.adTier}, hasVastUrl=${!!t.adVastUrl}, adUnitPath=${t.adUnitPath ?? "none"}`);
		}
	}
	#s() {
		this.#i || (this.#r = {
			consentString: window.fandomCmp?.consentString,
			optOut: window.fandomCmp?.optOut
		}, this.#i = (e) => {
			this.#c(e).catch((e) => {
				this.#e.warn("consent update handler failed:", e), v(j, {
					label: "FandomVideoAdPlugin:consentUpdate",
					error: e
				});
			});
		}, window.addEventListener("fandomConsentUpdate", this.#i));
	}
	async #c(e) {
		let { consentString: t, optOut: n } = e.detail;
		Qt(this.#r, e.detail) && (this.#r = {
			consentString: t,
			optOut: n
		}, this.#e.log("consent change detected — triggering ad tag renewal"), await Promise.all(this.#l().map((e) => this.#d(e, "consent").catch(this.#v("consent")))));
	}
	#l() {
		let e = /* @__PURE__ */ new Map();
		for (let t of this.#n.values()) {
			if (t.player && G(t.player)) continue;
			let n = e.get(t.container);
			(!n || t.player && !n.player) && e.set(t.container, t);
		}
		return [...e.values()];
	}
	#u({ container: e, player: t, options: n }) {
		let r = this.#n.get(n);
		if (!(!r || r.player)) {
			if (G(t)) {
				this.#n.delete(n), this.#e.log(`player for slot "${r.slotName}" destroyed before activation`);
				return;
			}
			r.player = t, r.resolveApplyAdTag() && (this.#y(r.slotName, e), t.on("adRequest", () => {
				this.#d(r, "adRequest").catch(this.#v("adRequest"));
			}));
		}
	}
	#d(e, t) {
		if (e.refreshInFlight) return t === "consent" && (e.consentRefreshPending = !0), Promise.resolve();
		d(`fandom-video-ad:refresh:${t}`);
		let n = this.#f(e, t).finally(() => {
			e.refreshInFlight = void 0, e.consentRefreshPending && (e.consentRefreshPending = !1, this.#d(e, "consent").catch(this.#v("consent")));
		});
		return e.refreshInFlight = n, n;
	}
	async #f(e, t) {
		if (!this.#m(e)) return;
		let { slotName: n } = e, r = this.#h(e, t);
		if (!r) return;
		if (!on()) {
			this.#e.log(`ad tag ${t} refresh gated for slot "${n}" — consent not granted`);
			return;
		}
		let i = await this.#g(r, n, t), a = this.#m(e);
		!i || !a || !on() || await this.#_(a, i, n, t);
	}
	#p({ options: e }) {
		this.#n.delete(e);
	}
	#m(e) {
		if (this.#n.get(e.options) === e && !(e.player && G(e.player))) return e.resolveApplyAdTag();
	}
	#h(e, t) {
		let n = Xt(e.slotName);
		return n || t !== "consent" ? n : this.#y(e.slotName, e.container);
	}
	async #g(e, t, n) {
		let r = this.#a;
		try {
			return e.invalidateRender?.(), (await an(e.requestRender(), r, "requestRender")).vastUrl || void 0;
		} catch (e) {
			this.#e.warn(`requestRender failed during ${n} refresh for slot "${t}":`, e), v(n === "adRequest" ? Le : k, {
				label: t,
				error: e
			});
			return;
		}
	}
	async #_(e, t, n, r) {
		let i = this.#a;
		try {
			await an(Promise.resolve(e(t)), i, "applyAdTag"), this.#e.log(`ad tag refreshed for slot "${n}" after ${r}`);
		} catch (e) {
			this.#e.warn(`applyAdTag failed during ${r} refresh for slot "${n}":`, e), v(j, {
				label: `FandomVideoAdPlugin:applyAdTag:${n}`,
				error: e
			});
		}
	}
	#v(e) {
		return (t) => {
			this.#e.warn(`${e} refresh failed:`, t), v(j, {
				label: `FandomVideoAdPlugin:${e}Refresh`,
				error: t
			});
		};
	}
	#y(e, t) {
		let n = Xt(e);
		if (n) return n;
		let r = Zt(e, t);
		return r ? (this.#t.set(t, r), r) : null;
	}
	#b({ container: e }) {
		let t = !1;
		for (let [n, r] of this.#n) r.container !== e || !r.player || (G(r.player) ? this.#n.delete(n) : t = !0);
		if (t) return;
		let n = this.#t.get(e);
		n && (this.#t.delete(e), n.isConnected && n.localName === "fandom-video-ad" && n.parentNode && n.remove());
	}
}, K = "jwplayer", q = "jwplayer-dsr", cn = "https://cdn.jwplayer.com/", J = 1e4, Y = "connatix", ln = 1e4, un = {
	floating: "playerFloating",
	closed: "playerFloatingClosed",
	stopped: "playerFloatingStopped"
}, dn = .5, fn = 50, X = "mve-floating", pn = "mve-floating-close", mn = "mve-floating-close-container", hn = "mve-floating-close--ad", gn = ".jwplayer .jw-wrapper", _n = "cnx, [class*=\"cnx-\"]";
function vn(e, t) {
	let n = 0, r, i = () => {
		n = Date.now(), e();
	};
	return {
		run() {
			let e = t - (Date.now() - n);
			e <= 0 ? (r &&= (clearTimeout(r), void 0), i()) : r ||= setTimeout(() => {
				r = void 0, i();
			}, e);
		},
		cancel() {
			r && clearTimeout(r), r = void 0;
		}
	};
}
var yn = class {
	#e;
	#t;
	#n;
	#r;
	#i;
	#a;
	#o;
	#s = vn(() => this.#w(), fn);
	#c;
	#l;
	#u;
	#d;
	#f = !1;
	#p = !0;
	#m = !1;
	#h = !1;
	#g = !1;
	#_ = !1;
	constructor(e) {
		this.#e = e.container, this.#t = e.player, this.#n = e.isMobile, this.#r = e.scrollThreshold, this.#i = e.closeButtonIcon, this.#a = e.logger;
	}
	start() {
		if (!this.#f) {
			if (typeof IntersectionObserver > "u") {
				this.#a.warn("IntersectionObserver unavailable — floating disabled.");
				return;
			}
			if (!this.#e.parentNode) {
				this.#a.warn("Container is detached — floating disabled.");
				return;
			}
			if (this.#o = new IntersectionObserver((e) => this.#S(e), { threshold: dn }), this.#o.observe(this.#e), this.#n) {
				let e = [
					this.#t.on("play", this.#y),
					this.#t.on("adImpression", this.#b),
					this.#t.on("adPlay", this.#b),
					this.#t.on("adComplete", this.#x),
					this.#t.on("adError", this.#x)
				];
				this.#d = () => e.forEach((e) => e());
			}
			window.addEventListener("scroll", this.#v, { passive: !0 }), this.#f = !0, this.#w();
		}
	}
	destroy() {
		this.#o?.disconnect(), this.#o = void 0, this.#f && window.removeEventListener("scroll", this.#v), this.#s.cancel(), this.#d?.(), this.#d = void 0, this.#F(), this.#m &&= (this.#u?.classList.remove(X, this.#j()), this.#u = void 0, !1);
	}
	#v = () => this.#s.run();
	#y = () => {
		this.#g = !0, this.#N(!1), this.#m && this.#M();
	};
	#b = () => {
		this.#g = !0, this.#N(!0), this.#m && this.#M();
	};
	#x = () => this.#N(!1);
	#S(e) {
		let t = e[0];
		t && (this.#p = typeof t.intersectionRatio == "number" ? t.intersectionRatio >= dn : t.isIntersecting, this.#w());
	}
	#C() {
		return !!this.#e.querySelector(gn) && !this.#e.querySelector(_n);
	}
	#w() {
		this.#h || (this.#C() && !this.#p && window.scrollY > this.#r ? this.#E() : this.#D(un.stopped));
	}
	#T() {
		return this.#e.querySelector("div");
	}
	#E() {
		if (this.#m) return;
		let e = this.#T();
		e && (this.#m = !0, this.#u = e, e.classList.add(X, this.#j()), (!this.#n || this.#g) && this.#M(), this.#O(), this.#I(un.floating));
	}
	#D(e) {
		this.#m && (this.#m = !1, this.#u?.classList.remove(X, this.#j()), this.#u = void 0, this.#F(), this.#O(), this.#I(e));
	}
	#O() {
		window.dispatchEvent(new Event("resize"));
	}
	#k() {
		this.#h = !0;
		try {
			this.#t.pause();
		} catch {}
		this.#D(un.closed);
	}
	#A = () => this.#k();
	#j() {
		return `${X}--${this.#n ? "mobile" : "desktop"}`;
	}
	#M() {
		if (this.#c) return;
		let e = this.#e.ownerDocument, t = e.createElement("button");
		if (t.type = "button", t.className = `${pn} ${pn}--${this.#n ? "mobile" : "desktop"}`, t.setAttribute("aria-label", "Close floating video"), this.#i ? t.innerHTML = this.#i : t.textContent = "×", t.addEventListener("click", this.#A), this.#n) {
			let n = e.createElement("div");
			n.className = mn, n.appendChild(t), (this.#e.parentElement ?? e.body).appendChild(n), this.#l = n;
		} else {
			let e = this.#u?.firstElementChild;
			(e instanceof HTMLElement ? e : this.#u)?.appendChild(t);
		}
		this.#c = t, this.#P();
	}
	#N(e) {
		this.#_ !== e && (this.#_ = e, this.#P());
	}
	#P() {
		this.#c?.classList.toggle(hn, this.#n && this.#_);
	}
	#F() {
		this.#c && (this.#c.removeEventListener("click", this.#A), this.#c.remove(), this.#c = void 0, this.#l?.remove(), this.#l = void 0);
	}
	#I(e) {
		this.#e.dispatchEvent(new CustomEvent(e, { bubbles: !0 }));
	}
};
function bn(e, t) {
	let n = e?.scrollThresholds;
	return (t ? n?.mobile : n?.desktop) ?? (t ? 300 : 580);
}
//#endregion
//#region ../../shared/plugins/custom-floating/custom-floating-plugin.ts
var xn = R("CustomFloatingPlugin"), Sn = class extends U {
	name = "CustomFloatingPlugin";
	#e;
	#t;
	#n;
	#r = /* @__PURE__ */ new WeakMap();
	constructor(e = {}) {
		super(e);
	}
	isEnabled(e) {
		let t = e;
		return super.isEnabled(e) && t.customFloating?.enabled === !0;
	}
	hooks = {
		onConfigLoaded: async (e) => {
			this.#e = e.pageContext?.device, this.#n = e.players;
			let t = e.customFloating?.closeButtonIcon;
			this.#t = typeof t == "string" ? t : void 0;
		},
		onAfterPlayerCreate: async (e) => {
			e.type === "jwplayer" && this.#i(e);
		},
		onAfterPlayerDestroy: async (e) => {
			let t = this.#r.get(e.container);
			t && (this.#r.delete(e.container), t.destroy());
		}
	};
	#i(e) {
		this.#r.get(e.container)?.destroy();
		let t = Ut(this.#e), n = this.#n?.[e.type], r = typeof n == "object" && n ? n.customFloating : void 0, i = new yn({
			container: e.container,
			player: e.player,
			isMobile: t,
			scrollThreshold: bn(r, t),
			closeButtonIcon: this.#t,
			logger: xn
		});
		this.#r.set(e.container, i), i.start();
	}
}, Cn = "control", wn = R("BucketExperimentPlugin"), Tn = class extends U {
	static experimentName = "missing";
	experimentName;
	currentGroup = Cn;
	constructor(e = {}) {
		super(e), this.experimentName = this.constructor.experimentName;
	}
	setExperimentGroup(e) {
		["control", ...this.variantNames].includes(e) ? this.currentGroup = e : (wn.warn(`Unknown experiment group "${e}" for ${this.experimentName}; defaulting to control`), v(A, { label: `${this.experimentName}:${e}` }), this.currentGroup = Cn);
	}
	getDebugState() {
		return {
			experimentName: this.experimentName,
			currentGroup: this.currentGroup,
			variantNames: [...this.variantNames],
			codeDeletionDate: this.codeDeletionDate.toISOString()
		};
	}
}, En = /^sse-(?<experimentString>.+)$/, Dn = /^(?<team>[a-zA-Z]+)-(?<ticketId>[0-9]+)-(?<mode>variant|control)-?(?<variant>[1-6])?$/;
function On(e) {
	let t = e.toLowerCase().match(Dn);
	if (!t?.groups) return;
	let { team: n, ticketId: r, mode: i, variant: a } = t.groups;
	if (i === "variant" && !a) return;
	let o = a ? `${i}${a}` : i;
	return {
		name: `${n}-${r}`,
		group: o
	};
}
function kn() {
	let { classList: e } = document.documentElement;
	for (let t of e) {
		let e = t.toLowerCase().match(En);
		if (e?.groups) return e.groups.experimentString;
	}
}
function An() {
	let e = kn();
	if (e) return On(e);
}
//#endregion
//#region ../../shared/plugins/bucket-experiment/bucket-experiment-manager.ts
var jn = R("BucketExperimentManager");
function Mn() {
	return new URLSearchParams(globalThis.location?.search ?? "").get("force-sse");
}
var Nn = class {
	#e;
	constructor(e, t) {
		let n = this.#t(t);
		if (!n) return;
		let r = this.#r(e, n.name);
		if (!r || r.isEligible && !r.isEligible(t)) return;
		let i = new r({});
		i.setExperimentGroup(n.group), t.experiments ??= {}, t.experiments[n.name] = i.currentGroup;
		try {
			i.adjustRuntimeConfig?.(t);
		} catch (e) {
			jn.error(`adjustRuntimeConfig failed for ${n.name}:`, e), v(j, {
				label: `${n.name}:adjustRuntimeConfig`,
				error: e
			});
		}
		this.#e = i;
	}
	#t(e) {
		let t = Mn();
		if (t) {
			let e = On(t);
			if (e) return e;
		}
		return An() || this.#n(e);
	}
	#n(e) {
		let t = e.experiments;
		if (!t) return;
		let [n, r] = Object.entries(t)[0] ?? [];
		if (!(!n || !r)) return {
			name: n,
			group: r
		};
	}
	#r(e, t) {
		return Object.values(e).find((e) => e.experimentName === t);
	}
	getActivePlugin() {
		return this.#e;
	}
}, Pn = R("ExampleExperiment"), Fn = { ExampleExperiment: class extends Tn {
	name = "ExampleExperiment";
	static experimentName = "fake-1234";
	variantNames = ["variant1", "variant2"];
	codeDeletionDate = /* @__PURE__ */ new Date("2045-08-01");
	isEnabled(e) {
		return super.isEnabled(e) && _e(e, this.name);
	}
	adjustRuntimeConfig(e) {
		this.isEnabled(e) && (e.exampleFeatureEnabled = this.currentGroup === "variant1");
	}
	hooks = { onRuntimeReady: async () => {
		Pn.log("onRuntimeReady from an example experiment", { group: this.currentGroup });
	} };
} };
//#endregion
//#region runtime/load-plugins-list.ts
function In(e) {
	let t = new Nn(Fn, e).getActivePlugin();
	return [
		...t ? [t] : [],
		new sn(),
		new Sn()
	];
}
//#endregion
//#region ../../shared/players/define-player-factory.ts
function Ln(e, t) {
	let n = {};
	return {
		type: e,
		attachConfig(t) {
			let r = t.players?.[e];
			if (r === void 0) {
				n = {};
				return;
			}
			if (typeof r != "object" || !r) {
				console.warn(`[${e}] config.players.${e} is not an object; ignoring.`), n = {};
				return;
			}
			n = r;
		},
		create: (e, r) => {
			for (let e of Object.keys(n)) {
				let t = n[e];
				t !== void 0 && (r[e] ??= t);
			}
			return t(e, r);
		}
	};
}
//#endregion
//#region ../../shared/players/common/ad-slot-registry.ts
var Rn = /* @__PURE__ */ new Set();
function zn(e, t) {
	if (Rn.has(t)) throw Error(`[${e}] cannot create ${e} with ads — slot "${t}" is already reserved by another pending/live player`);
	Rn.add(t);
}
function Z(e) {
	Rn.delete(e);
}
//#endregion
//#region ../../shared/players/jwplayer/jwplayer-adapter.ts
var Bn = class {
	type = K;
	#e = /* @__PURE__ */ new Set();
	#t = () => {
		this.instance.off("idle", this.#t), this.#r();
	};
	#n = () => {
		this.instance.off("idle", this.#t), this.instance.on("idle", this.#t);
	};
	constructor(e, t) {
		this.instance = e, this.onDestroy = t, this.instance.on("idle", this.#t), this.instance.on("play", this.#n);
	}
	getNativeInstance() {
		return this.instance;
	}
	play() {
		this.instance.play();
	}
	pause() {
		this.instance.pause();
	}
	stop() {
		this.instance.off("idle", this.#t), this.instance.stop(), this.instance.getState() === "idle" ? this.#r() : this.instance.on("idle", this.#t);
	}
	#r() {
		for (let e of this.#e) try {
			e();
		} catch (e) {
			v(T, {
				player_type: K,
				error: e
			});
		}
	}
	on(e, t) {
		let n = t;
		return e === "stop" ? (this.#e.add(n), () => {
			this.#e.delete(n);
		}) : (this.instance.on(e, n), () => this.instance.off(e, n));
	}
	destroy() {
		this.#e.clear(), this.instance.off("idle", this.#t), this.instance.off("play", this.#n), this.onDestroy();
	}
}, Vn = /* @__PURE__ */ new Map();
function Hn(e, t) {
	Vn.set(e, t);
}
function Un(e) {
	let t = window.jwDsrPartners?.[e]?.partner ?? "jwplayer", n = Vn.get(t);
	if (!n) throw Error(`[${q}] No adapter registered for DSR partner "${t}". Register one with registerPartnerAdapter() or add support for this partner.`);
	return n;
}
Hn(K, Bn);
//#endregion
//#region ../../shared/players/jwplayer/modes/dsr.ts
function Wn(e) {
	return (t) => {
		let n = window.jwDataStore ??= { custom: {} };
		n.custom ??= {}, n.custom[e] ??= {}, n.custom[e].preroll_ad_tag = t;
	};
}
var Gn = `${K}-dsr`, Kn = (e, t) => `${cn}v2/sites/${e}/placements/${t}/embed.js`, qn = {
	name: "dsr",
	async bootstrap(e, t) {
		let { siteId: n, placementId: r, playlistId: i, mediaId: a, adVastUrl: o, adTier: s, recommendationsPlaylistId: c, sponsoredPlaylistId: l } = t;
		if (d(`dsr:bootstrap:${r}`), t.applyAdTag ??= Wn(r), !i && !a) {
			let e = /* @__PURE__ */ Error(`[${q}] At least one of \`playlistId\` or \`mediaId\` is required — without it the DSR bundle has nothing to play and aborts.`);
			throw v(O, {
				player_type: K,
				player_mode: "dsr",
				label: r,
				error: e
			}), e;
		}
		let u = {};
		i && (u.playlist_id = i), a && (u.media_id = a), o && (u.preroll_ad_tag = o), s != null && Number.isFinite(s) && (u.tier = s), u.recommendations_playlist_id = c, l && (u.sponsored_playlist_id = l);
		let f = Object.keys(u), p;
		try {
			Xn(r, u);
			let t = `player_${r}`, i = document.getElementById(t);
			if (i && i !== e) throw Error(`[${q}] A DSR player for placement "${r}" is already mounted in another container. Re-use the existing container or provide a different placementId.`);
			e.id = t;
			let a = P(), o = (e) => N({
				component: Gn,
				action: e,
				duration_ms: F(a),
				label: Gn,
				placement_id: r
			});
			p = Zn(e, Kn(n, r)), d("dsr:script-inject"), o("player-script-inject");
			let s = await Qn(r);
			d("dsr:placement-ready"), o("player-placement-ready"), await Jn(s, J, r), d("dsr:player-ready"), o("player-ready");
			let c = Un(r);
			return {
				instance: s,
				script: p,
				destroyPlayer: () => {
					s.stop();
				},
				Adapter: c,
				cleanup: () => {
					Yn(r, f), delete window.jwplacements, delete window.jwplacementsMap;
				}
			};
		} catch (e) {
			v(C, {
				player_type: K,
				player_mode: "dsr",
				label: r,
				error: e
			});
			try {
				p?.remove(), Yn(r, f);
			} catch {}
			throw e;
		}
	}
};
function Jn(e, t, n) {
	return e.getState() == null ? new Promise((r, i) => {
		let a = setTimeout(() => {
			let e = /* @__PURE__ */ Error(`[${q}] Player did not emit 'ready' within ${t}ms — JW registered the placement but its underlying player never finished booting.`);
			v(w, {
				player_type: K,
				player_mode: "dsr",
				label: n,
				error: e
			}), i(e);
		}, t);
		e.once("ready", () => {
			clearTimeout(a), r();
		});
	}) : Promise.resolve();
}
function Yn(e, t) {
	let n = window.jwDataStore?.custom?.[e];
	if (n) {
		for (let e of t) delete n[e];
		Object.keys(n).length === 0 && delete window.jwDataStore.custom[e];
	}
}
function Xn(e, t) {
	let n = window.jwDataStore ??= { custom: {} };
	n.custom ??= {}, n.custom[e] = {
		...n.custom[e],
		...t
	};
}
function Zn(e, t) {
	let n = e.querySelector(`script[src="${t}"]`);
	if (n) return n;
	let r = document.createElement("script");
	return r.src = t, r.async = !0, e.appendChild(r), r;
}
async function Qn(e) {
	let t = Date.now();
	for (; Date.now() - t < J;) {
		let t = window.jwplacements?._getPlacementReadyPromise(e);
		if (t) return (await t).player;
		await new Promise((e) => setTimeout(e, 100));
	}
	throw Error(`[${q}] placement "${e}" did not become ready within ${J}ms`);
}
//#endregion
//#region ../../shared/players/jwplayer/modes/index.ts
var $n = /* @__PURE__ */ new Map();
function er(e) {
	$n.set(e.name, {
		name: e.name,
		bootstrap: (t, n) => e.bootstrap(t, n)
	});
}
er(qn);
function tr(e) {
	let t = $n.get(e);
	if (!t) throw Error(`[${K}] Unknown mode "${e}". Registered modes: ${[...$n.keys()].join(", ") || "(none)"}`);
	return t;
}
//#endregion
//#region ../../shared/players/common/safe-instance.ts
function nr(e, t, n) {
	let r = R(n);
	return new Proxy(e, { get(e, n, i) {
		if (typeof n == "string" && t.has(n)) return (...e) => {
			r.warn(`"${n}()" is blocked on the safe instance. Use VideoPlayer.destroy() instead to tear the player down cleanly.`);
		};
		let a = Reflect.get(e, n, i);
		return typeof a == "function" ? (...t) => {
			let n = a.apply(e, t);
			return n === e ? i : n;
		} : a;
	} });
}
//#endregion
//#region ../../shared/players/jwplayer/jwplayer.ts
var rr = new Set(["remove"]), ir = class e {
	type = K;
	#e;
	#t;
	#n;
	#r;
	#i;
	#a;
	#o;
	#s = !1;
	constructor(e, t, n, r = {}) {
		this.#e = e, this.#t = t, this.#n = nr(t, rr, K), this.#i = r.cleanup, this.#a = r.destroyPlayer, this.#o = r.onDestroy, this.#r = new n(this.#n, () => this.destroy());
	}
	get adapter() {
		return this.#r;
	}
	static async create(t, n, r) {
		let { instance: i, script: a, cleanup: o, destroyPlayer: s, Adapter: c } = await tr(n.mode).bootstrap(t, n);
		return new e(a, i, c, {
			cleanup: o,
			destroyPlayer: s,
			onDestroy: r
		});
	}
	destroy() {
		if (!this.#s) {
			this.#s = !0;
			try {
				this.#a ? this.#a() : this.#t.remove();
			} catch (e) {
				v(T, {
					player_type: K,
					error: e
				});
			}
			this.#e.remove();
			try {
				this.#i?.();
			} catch (e) {
				v(j, {
					label: `${K}:cleanup`,
					player_type: K,
					error: e
				});
			}
			this.#o?.();
		}
	}
}, ar = Ln(K, async (e, t) => {
	let n = t.adSlotName;
	n && zn(K, n);
	try {
		return (await ir.create(e, t, n ? () => Z(n) : void 0)).adapter;
	} catch (e) {
		throw n && Z(n), e;
	}
}), or = new Set(["destroy"]), sr = {
	play: "play",
	pause: "pause",
	stop: "stopped",
	complete: "videoCompleted100",
	error: "playError",
	adImpression: "adImpression",
	adPlay: "adPlay",
	adPause: "adPause",
	adComplete: "adCompleted100",
	adError: "adError",
	ready: "videoStarted"
}, cr = class {
	type = Y;
	#e;
	#t;
	#n;
	#r;
	#i = !1;
	constructor(e, t, n) {
		this.#e = e, this.#t = nr(e, or, Y), this.#n = t, this.#r = n;
	}
	getNativeInstance() {
		return this.#t;
	}
	play = () => {
		this.#e.play();
	};
	pause = () => {
		this.#e.pause();
	};
	stop = () => {
		this.#e.pause(), this.#e.setVideoPosition(0);
	};
	on = (e, t) => {
		let n = sr[e];
		return n && this.#e.on(n, t), () => {};
	};
	destroy = () => {
		if (!this.#i) {
			this.#i = !0;
			try {
				this.#e.destroy();
			} catch (e) {
				v(T, {
					player_type: Y,
					error: e
				});
			}
			this.#n.remove(), this.#r?.();
		}
	};
}, lr = (e) => (function(t) {
	if (!window.cnx) {
		window.cnx = {}, window.cnx.cmd = [];
		var n = t.createElement("iframe");
		n.src = "javascript:false", n.display = "none", n.onload = function() {
			var t = n.contentWindow.document, r = t.createElement("script");
			r.src = `//cd.connatix.com/connatix.player.js?cid=${e}`, r.setAttribute("async", "1"), r.setAttribute("type", "text/javascript"), t.body.appendChild(r);
		}, t.head.appendChild(n);
	}
})(document);
//#endregion
//#region ../../shared/players/connatix/render.ts
function ur(e, t, n) {
	window.cnx?.cmd?.push(function() {
		window.cnx?.(t).render(e, n);
	});
}
//#endregion
//#region ../../shared/players/connatix/connatix.ts
var dr = 0;
function fr(e) {
	return {
		renderId: `${e.renderId ?? "cnx"}-${++dr}`,
		playerId: e.playerId ?? ""
	};
}
function pr(e, t) {
	let n = {
		playerId: e,
		settings: t.adVastUrl ? {
			disableAdvertising: !1,
			advertising: { lineItems: [{
				id: e,
				url: t.adVastUrl
			}] }
		} : { disableAdvertising: !0 }
	};
	return t.mediaId && (n.mediaId = t.mediaId), t.playlistId && (n.playlistId = t.playlistId), n;
}
function mr(e, t) {
	return new Promise((n, r) => {
		let i = !1, a = setTimeout(() => {
			if (i) return;
			i = !0;
			let e = /* @__PURE__ */ Error(`[${Y}] render timed out after ${ln}ms — SDK may not have loaded`);
			v(w, {
				player_type: Y,
				error: e
			}), r(e);
		}, ln);
		ur(e, t, (e, t) => {
			if (!i) if (i = !0, clearTimeout(a), e) {
				let t = /* @__PURE__ */ Error(`[${Y}] ${e.type}: ${e.message}`);
				v(C, {
					player_type: Y,
					error_type: e.type,
					error_message: e.message,
					error: t
				}), r(t);
			} else n(t);
		});
	});
}
function hr(e, t) {
	let n = {};
	t.adUnitPath && (n.path = t.adUnitPath), t.adTargetingParams && (n.custmac = t.adTargetingParams), Object.keys(n).length > 0 && e.setMacros(n);
}
var gr = Ln(Y, async (e, t) => {
	let n = t.customerId;
	if (!n) throw Error(`[${Y}] customerId is required — set it in window.__morpheusConfig.players.${Y}.customerId`);
	let { renderId: r, playerId: i } = fr(t);
	if (!i) {
		let e = /* @__PURE__ */ Error(`[${Y}] playerId is required — set players.${Y}.playerId in config or pass it directly`);
		throw v(O, {
			player_type: Y,
			error: e
		}), e;
	}
	let a = t.adSlotName;
	a && zn(Y, a);
	let o = P(), s = (e) => N({
		component: Y,
		action: e,
		duration_ms: F(o),
		label: Y
	}), c;
	try {
		lr(n), s("player-script-inject"), c = document.createElement("div"), c.id = r, e.appendChild(c);
		let o = await mr(r, pr(i, t));
		return s("player-ready"), hr(o, t), new cr(o, c, a ? () => Z(a) : void 0);
	} catch (e) {
		throw c?.remove(), a && Z(a), e;
	}
}), _r = "v17";
function vr(e, t) {
	if (typeof t.env == "string" && t.env) return t.env;
	let n = e.fandomContext?.app?.env;
	return typeof n == "string" && n ? n : "unknown";
}
var yr = P(), Q = (e, t) => {
	d(`bootstrap:${e}`), N({
		component: "video-engine",
		action: e,
		duration_ms: F(yr),
		...t
	});
};
it(at()), window.addEventListener("unhandledrejection", (e) => {
	let t = e.reason, n = t instanceof Error ? t.message : String(t);
	!n.includes("[Morpheus]") && !n.includes("[jwplayer") && !n.includes("[VideoEngine]") || v(Re, { error: t });
});
var $ = R("VideoEngine"), br = window, xr, Sr;
br.MorpheusReady = new Promise((e, t) => {
	xr = e, Sr = t;
}), (async function(e) {
	$.log("bootstrap starting");
	let t = await qt(e.__morpheusConfig ?? {}), n = {
		engine_version: _r,
		env: vr(e, t)
	};
	Ue(n), ee(n), N({
		component: "video-engine",
		action: "script-execution-start",
		duration_ms: yr >= 0 ? yr : -1
	}), Q("config-fetch"), $.log("config initialized", t);
	let r = await Et();
	t.featureFlags = r, $.log("instant-config resolved", r.getAll());
	let i = Array.isArray(e.Morpheus) ? e.Morpheus : [], a = ht(In(t), t);
	Q("plugin-init"), $.log("active plugins", a.map((e) => e.name));
	let o = !1, s = Promise.allSettled(z(a, "onConfigLoaded", t)).then(() => {
		o = !0;
	});
	await Promise.race([s, new Promise((e) => setTimeout(e, Ot))]), o || (Q("plugin-waiting-timeout"), v(E)), Q("config-loaded-hook");
	let c = ut(t, a);
	Q("runtime-create"), Dt(c), z(a, "onRuntimeReady", c);
	let l = [ar, gr];
	for (let e of l) c.registerPlayerFactory(e);
	Q("player-factory-register"), z(a, "onPlayersRegistered", c), e.Morpheus = c, pt(c, i), Q("queue-drain"), z(a, "onQueueDrained"), xr(c), z(a, "onInitializationEnded"), Q("bootstrap-complete", { active_plugins: a.map((e) => e.name) }), $.log("runtime ready", t);
})(br).catch((e) => {
	$.error("fatal init error:", e), v(xe, { error: e }), Sr(e);
});
//#endregion
