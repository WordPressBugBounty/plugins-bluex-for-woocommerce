/**
 * BlueX PUDO integration — classic (shortcode) checkout.
 *
 * Unified with the Blocks flow on the server side: the native WC shipping
 * method radios are the single source of truth. On the client side legacy
 * uses a modal-based UX:
 *
 *   1. Customer selects the bluex-pudo radio with no agency yet → modal
 *      auto-opens (the iframe lives in a body-level <dialog>).
 *   2. Customer picks a point inside the iframe → modal closes, rate label
 *      flips to "Retiro en <agency>" via the next update_checkout AJAX,
 *      and a "Cambiar punto Blue Express" link renders below the rate.
 *   3. Customer can click the link to reopen the modal with `?id=` so the
 *      previously-chosen point comes pre-selected.
 *   4. Customer switches to a non-PUDO rate → modal closes, link is
 *      removed, the original (pre-PUDO) address is restored.
 *
 * The dialog is appended to document.body so WC's AJAX rebuilds of
 * #order_review (which fire on every update_checkout) never touch it. The
 * "change point" link DOES live inside the rate <li>, so it is re-injected
 * on every updated_checkout pass via syncFromNativeRate.
 *
 * Canonical classic-checkout lifecycle events (verified against WC core
 * client/legacy/js/frontend/checkout.js):
 *   - update_checkout  → client requests a refresh (fires AJAX)
 *   - updated_checkout → AJAX response applied, DOM rebuilt
 */

const BLUEX_PUDO_DIALOG_ID = "bluex-pudo-dialog";
const BLUEX_PUDO_IFRAME_ID = "bluex-pudo-iframe";
const BLUEX_PUDO_CHANGE_LINK_ID = "bluex-pudo-change-link";
const BLUEX_PUDO_ADDRESS_SNAPSHOT_KEY = "bluex_pudo_address_snapshot_classic";

// Tracks whether the previous render pass had bluex-pudo active so we can
// detect transitions (entering/leaving PUDO) and only auto-open the modal
// on entry, not on every updated_checkout tick.
let lastWasPudo = false;

// Boot pattern: support BOTH early-loaded scripts (DOMContentLoaded pending)
// and late-loaded scripts (DOMContentLoaded already fired, e.g. when the
// tag is injected `in_footer=true` by wp_enqueue_script). Without this
// fallback the listener registers too late and `init` never runs.
function bluexPudoBoot() {
  clearWooCommerceShippingCache();
  handleMessagesFromWindow();

  if (typeof jQuery !== "undefined") {
    jQuery(document.body).on("updated_checkout", function () {
      syncFromNativeRate();
    });
  }

  syncFromNativeRate();
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", bluexPudoBoot);
} else {
  bluexPudoBoot();
}

// -----------------------------------------------------------------------------
// Native rate detection

function getSelectedShippingRateId() {
  const checked = document.querySelector(
    'input[name^="shipping_method"]:checked'
  );
  return checked ? checked.value : null;
}

function isBluexPudoSelected() {
  const rateId = getSelectedShippingRateId();
  return typeof rateId === "string" && rateId.indexOf("bluex-pudo") === 0;
}

// Find the WC-rendered <li> element that contains the bluex-pudo radio. The
// "change point" link is appended inside it so it sits under the rate label.
function getPudoRateElement() {
  const input = document.querySelector(
    'input[name^="shipping_method"][value^="bluex-pudo"]'
  );
  if (!input) return null;
  return input.closest("li") || input.parentElement;
}

// -----------------------------------------------------------------------------
// Modal (native <dialog> in document.body)

// Lazy-create the dialog once and reuse it. Living in document.body means
// WC AJAX rebuilds of #order_review do not destroy it, so the iframe state
// is preserved across updated_checkout passes.
function ensurePudoDialog() {
  let dialog = document.getElementById(BLUEX_PUDO_DIALOG_ID);
  if (dialog) return dialog;

  dialog = document.createElement("dialog");
  dialog.id = BLUEX_PUDO_DIALOG_ID;
  dialog.className = "bluex-pudo-dialog";

  const header = document.createElement("div");
  header.className = "bluex-pudo-dialog__header";

  const title = document.createElement("h3");
  title.className = "bluex-pudo-dialog__title";
  title.textContent = "Seleccioná tu punto Blue Express";

  const closeBtn = document.createElement("button");
  closeBtn.type = "button";
  closeBtn.className = "bluex-pudo-dialog__close";
  closeBtn.setAttribute("aria-label", "Cerrar");
  closeBtn.innerHTML = "&times;";
  closeBtn.addEventListener("click", function () {
    closePudoModal();
  });

  header.appendChild(title);
  header.appendChild(closeBtn);

  const body = document.createElement("div");
  body.className = "bluex-pudo-dialog__body";

  const iframe = document.createElement("iframe");
  iframe.id = BLUEX_PUDO_IFRAME_ID;
  iframe.title = "Selector de Punto Blue Express";
  body.appendChild(iframe);

  dialog.appendChild(header);
  dialog.appendChild(body);
  document.body.appendChild(dialog);

  return dialog;
}

function buildWidgetUrl(preselectId) {
  if (typeof bluex_checkout_params === "undefined") {
    console.error("[BlueX PUDO] checkout params not loaded");
    return "";
  }
  const baseUrl = bluex_checkout_params.base_path_url || "";
  let widgetUrl = bluex_checkout_params.widget_base_urls.prod;
  if (baseUrl.indexOf("qa") !== -1) widgetUrl = bluex_checkout_params.widget_base_urls.qa;
  if (baseUrl.indexOf("dev") !== -1) widgetUrl = bluex_checkout_params.widget_base_urls.dev;

  if (preselectId) {
    widgetUrl +=
      (widgetUrl.indexOf("?") >= 0 ? "&" : "?") +
      "id=" +
      encodeURIComponent(preselectId);
  }
  return widgetUrl;
}

function openPudoModal() {
  const dialog = ensurePudoDialog();
  const iframe = document.getElementById(BLUEX_PUDO_IFRAME_ID);
  const agencyIdInput = document.getElementById("agencyId");
  const preselectId = agencyIdInput ? agencyIdInput.value : "";
  const url = buildWidgetUrl(preselectId);
  if (!url) return;

  // Always rebuild src so reopening with a different agencyId pre-selects
  // correctly. Setting iframe.src triggers a reload.
  if (iframe) iframe.src = url;

  if (typeof dialog.showModal === "function" && !dialog.open) {
    try {
      dialog.showModal();
    } catch (e) {
      // showModal throws if the dialog is already open in some browsers;
      // ignore and let the user keep using it.
    }
  } else if (!dialog.open) {
    // Fallback for browsers without <dialog> support — show as block.
    dialog.setAttribute("open", "");
  }
}

function closePudoModal() {
  const dialog = document.getElementById(BLUEX_PUDO_DIALOG_ID);
  if (!dialog) return;
  if (typeof dialog.close === "function" && dialog.open) {
    dialog.close();
  } else {
    dialog.removeAttribute("open");
  }
}

// -----------------------------------------------------------------------------
// "Seleccionar / Cambiar punto" link below the rate label

function renderChangePointLink(mode) {
  const anchor = getPudoRateElement();
  if (!anchor) return;

  let link = document.getElementById(BLUEX_PUDO_CHANGE_LINK_ID);
  if (!link) {
    link = document.createElement("button");
    link.type = "button";
    link.id = BLUEX_PUDO_CHANGE_LINK_ID;
    link.className = "bluex-pudo-change-link";
    link.addEventListener("click", function (e) {
      e.preventDefault();
      openPudoModal();
    });
    anchor.appendChild(link);
  } else if (link.parentElement !== anchor) {
    // WC rebuilt the rate <li>; re-attach the link to the new node.
    anchor.appendChild(link);
  }

  link.textContent =
    mode === "change"
      ? "Cambiar punto Blue Express"
      : "Seleccionar punto Blue Express";
}

function removeChangePointLink() {
  const link = document.getElementById(BLUEX_PUDO_CHANGE_LINK_ID);
  if (link) link.remove();
}

// -----------------------------------------------------------------------------
// Main sync driver — reconciles modal + change-link against the currently
// selected native shipping rate. Called on DOMContentLoaded, on every
// updated_checkout, and after handlePudoSelect.

function syncFromNativeRate() {
  const nowPudo = isBluexPudoSelected();
  const agencyIdInput = document.getElementById("agencyId");
  const hasAgency = !!(agencyIdInput && agencyIdInput.value);

  if (nowPudo && !lastWasPudo) {
    // Transition: entering PUDO.
    // Only snapshot if there is no snapshot yet AND no agency already
    // persisted — if an agency is already in the form (page reload with
    // PUDO mid-flow), the "current" form address is the pickup address
    // and snapshotting would poison the restore.
    if (!readAddressSnapshot() && !hasAgency) {
      saveAddressSnapshot();
    }
    // Auto-open the modal on PUDO entry when no agency is picked yet.
    if (!hasAgency) {
      openPudoModal();
    }
  } else if (!nowPudo && lastWasPudo) {
    // Transition: leaving PUDO. Restore the original address (if any) and
    // blank the hidden agency inputs so the next update_checkout POST
    // triggers the server callback's unset path.
    closePudoModal();
    const snap = readAddressSnapshot();
    if (snap) {
      restoreAddressFromSnapshot(snap);
      clearAddressSnapshot();
    }
    clearAgencyHiddenInputs();
  }

  if (nowPudo) {
    renderChangePointLink(hasAgency ? "change" : "select");
  } else {
    removeChangePointLink();
  }

  lastWasPudo = nowPudo;
}

// -----------------------------------------------------------------------------
// Iframe postMessage handling

function handleMessagesFromWindow() {
  window.addEventListener("message", (event) => {
    const elements = getDOMElements();
    if (event.data && event.data.type === "pudo:select") {
      handlePudoSelect(event, elements);
    }
  });
}

function getDOMElements() {
  return {
    inputState: document.getElementById("billing_state"),
    inputDir: document.getElementById("billing_address_1"),
    inputDir2: document.getElementById("billing_address_2"),
    countryContainer: document.getElementById(
      "select2-billing_country-container"
    ),
    stateContainer: document.querySelector("#select2-billing_state-container"),
    inputCity: document.getElementById("billing_city"),
    agencyIdInput: document.getElementById("agencyId"),
  };
}

function handlePudoSelect(event, elements) {
  const data = event.data.payload;
  if (!data || !data.location) return;

  const {
    street_name = "",
    street_number = "",
    city_name = "",
    country_name = "",
    state_name = "",
  } = data.location;

  const fullStreet = `${street_name} ${street_number}`.trim();
  const agencyName = data.agency_name || "";
  const fullAddress = [fullStreet, city_name]
    .filter(function (s) { return s && s !== ""; })
    .join(", ");

  // Populate the form address fields with the pickup-point location so the
  // customer sees the pickup address in the shipping block. The original
  // customer address is preserved in sessionStorage and restored if they
  // switch away from PUDO — see syncFromNativeRate.
  if (elements.agencyIdInput) elements.agencyIdInput.value = data.agency_id || "";
  if (elements.inputDir) elements.inputDir.value = fullStreet;
  if (elements.inputDir2) elements.inputDir2.value = agencyName;

  const state = getStateDetails(state_name);
  setSelectValueQuietly("billing_state", state.abreviation || "");
  if (elements.inputState && state.fullName) {
    elements.inputState.title = state.fullName;
  }
  setCityQuietly(city_name);

  // Hidden inputs that travel in the update_checkout AJAX post_data
  // alongside agencyId. The server-side callback
  // (update_order_review_callback in class-wc-correios-pudos-map.php)
  // copies all three into the WC session; WC_BlueX_Pudo::calculate_shipping
  // reads `bluex_agency_name` to build the dynamic rate label
  // "Retiro en Punto Blue Express - <agency>".
  const nameInput = document.getElementById("agency_name");
  if (nameInput) nameInput.value = agencyName;
  const addressInput = document.getElementById("agency_address");
  if (addressInput) addressInput.value = fullAddress;

  // Close the modal immediately. The link below the rate will flip to
  // "Cambiar punto Blue Express" on the next syncFromNativeRate (which
  // fires after the update_checkout AJAX completes).
  closePudoModal();

  triggerUpdateCheckout();
}

// -----------------------------------------------------------------------------
// Address snapshot / restore — parity with the Blocks flow.
//
// When the customer enters PUDO for the first time in this browser session,
// we capture the billing address they typed. Once they pick a point, the
// form fields get rewritten with the pickup-point address. If they then
// switch away from PUDO, we restore the captured address so their original
// "Envío a domicilio" form is intact.
//
// Using sessionStorage (not the WC server session) because the snapshot is
// a pure UX concern: the server only needs the current state, not the
// "before-PUDO" state.

function saveAddressSnapshot() {
  try {
    const snap = {
      address_1: valueOf("billing_address_1"),
      address_2: valueOf("billing_address_2"),
      city: valueOf("billing_city"),
      state: valueOf("billing_state"),
      country: valueOf("billing_country"),
      postcode: valueOf("billing_postcode"),
    };
    window.sessionStorage.setItem(
      BLUEX_PUDO_ADDRESS_SNAPSHOT_KEY,
      JSON.stringify(snap)
    );
  } catch (e) {
    /* storage disabled */
  }
}

function readAddressSnapshot() {
  try {
    const raw = window.sessionStorage.getItem(BLUEX_PUDO_ADDRESS_SNAPSHOT_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch (e) {
    return null;
  }
}

function clearAddressSnapshot() {
  try {
    window.sessionStorage.removeItem(BLUEX_PUDO_ADDRESS_SNAPSHOT_KEY);
  } catch (e) {}
}

function valueOf(id) {
  const el = document.getElementById(id);
  return el ? el.value : "";
}

// Set a WC <select> value and ask its select2/selectWoo wrapper to resync
// its visible container WITHOUT firing `change` — that would cascade an
// `update_checkout` AJAX while we are still inside one, causing either a
// recursion or a lost-write race.
//
// The `refresh` event is the canonical signal per WC core
// (client/legacy/js/frontend/country-select.js:118).
function setSelectValueQuietly(id, value) {
  const el = document.getElementById(id);
  if (!el) return;
  el.value = value || "";
  if (typeof jQuery !== "undefined") {
    jQuery(el).trigger("refresh");
  }
}

function restoreAddressFromSnapshot(snap) {
  if (!snap) return;
  const byId = function (id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value || "";
  };
  byId("billing_address_1", snap.address_1);
  byId("billing_address_2", snap.address_2);
  byId("billing_postcode", snap.postcode);
  setSelectValueQuietly("billing_country", snap.country);
  setSelectValueQuietly("billing_state", snap.state);
  const stateEl = document.getElementById("billing_state");
  if (stateEl) stateEl.removeAttribute("title");
  setCityQuietly(snap.city);
}

function clearAgencyHiddenInputs() {
  ["agencyId", "agency_name", "agency_address"].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.value = "";
  });
}

// -----------------------------------------------------------------------------
// Helpers preserved from the previous implementation

// Set #billing_city to a given value without destroying the <select> element.
function setCityQuietly(value) {
  const el = document.getElementById("billing_city");
  if (!el) return;
  const wanted = value || "";

  if (el.tagName === "INPUT") {
    el.value = wanted;
    return;
  }

  if (el.tagName === "SELECT") {
    const options = Array.from(el.options);
    const byValue = options.find(function (o) { return o.value === wanted; });
    const byText = options.find(function (o) {
      return o.textContent.trim().toLowerCase() === wanted.trim().toLowerCase();
    });
    let target = byValue || byText;

    if (!target && wanted !== "") {
      target = document.createElement("option");
      target.value = wanted;
      target.textContent = wanted;
      el.appendChild(target);
    }

    el.value = target ? target.value : "";
    if (typeof jQuery !== "undefined") {
      jQuery(el).trigger("refresh");
    }
    return;
  }

  if ("value" in el) el.value = wanted;
}

function triggerUpdateCheckout() {
  if (typeof jQuery !== "undefined") {
    jQuery(document.body).trigger("update_checkout");
    return;
  }
  document.body.dispatchEvent(new CustomEvent("update_checkout"));
}

function getStateDetails(name) {
  const states = [
    { abreviation: "CL-AI", fullName: "Aisén del General Carlos Ibañez del Campo", nameFromIframe: "Aysén" },
    { abreviation: "CL-AN", fullName: "Antofagasta", nameFromIframe: "Antofagasta" },
    { abreviation: "CL-AP", fullName: "Arica y Parinacota", nameFromIframe: "Arica y Parinacota" },
    { abreviation: "CL-AR", fullName: "La Araucanía", nameFromIframe: "Araucanía" },
    { abreviation: "CL-AT", fullName: "Atacama", nameFromIframe: "Atacama" },
    { abreviation: "CL-BI", fullName: "Biobío", nameFromIframe: "Bío - Bío" },
    { abreviation: "CL-CO", fullName: "Coquimbo", nameFromIframe: "Coquimbo" },
    { abreviation: "CL-LI", fullName: "Libertador General Bernardo O'Higgins", nameFromIframe: "Libertador General Bernardo O`Higgins" },
    { abreviation: "CL-LL", fullName: "Los Lagos", nameFromIframe: "Los Lagos" },
    { abreviation: "CL-LR", fullName: "Los Ríos", nameFromIframe: "Los Ríos" },
    { abreviation: "CL-MA", fullName: "Magallanes", nameFromIframe: "Magallanes y la Antartica Chilena" },
    { abreviation: "CL-ML", fullName: "Maule", nameFromIframe: "Maule" },
    { abreviation: "CL-NB", fullName: "Ñuble", nameFromIframe: "Ñuble" },
    { abreviation: "CL-RM", fullName: "Región Metropolitana de Santiago", nameFromIframe: "Metropolitana de Santiago" },
    { abreviation: "CL-TA", fullName: "Tarapacá", nameFromIframe: "Tarapacá" },
    { abreviation: "CL-VS", fullName: "Valparaíso", nameFromIframe: "Valparaiso" },
    { abreviation: "", fullName: "", nameFromIframe: "" },
  ];
  return states.find((state) => state.nameFromIframe === name) || {};
}

function clearWooCommerceShippingCache() {
  if (typeof bluex_checkout_params === "undefined") {
    console.error("[BlueX PUDO] checkout params not loaded");
    return;
  }
  const data = new FormData();
  data.append("action", "clear_shipping_cache");
  data.append("nonce", bluex_checkout_params.nonce);
  fetch(bluex_checkout_params.ajax_url, { method: "POST", body: data })
    .then((response) => response.json())
    .then((result) => {
      if (result && result.success) triggerUpdateCheckout();
    })
    .catch((error) => {
      console.error("[BlueX PUDO] clear_shipping_cache failed:", error);
    });
}
