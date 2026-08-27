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
 *   3. Customer can click the link to reopen a neutral modal and choose a
 *      different point explicitly.
 *   4. Customer switches to a non-PUDO rate → modal closes, link is removed,
 *      and the customer address remains untouched throughout the flow.
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
  document.addEventListener("change", handleShippingMethodChange, true);

  if (typeof jQuery !== "undefined") {
    jQuery(document.body).on("updated_checkout", function () {
      syncFromNativeRate();
    });
  }

  syncFromNativeRate();
}

// Clear PUDO metadata only when the shopper explicitly checks another shipping
// radio. An updated_checkout render can temporarily omit PUDO when a quote is
// recalculated; treating that server snapshot as user intent destroys the point
// that was just confirmed. Capture phase runs before Woo serializes the form.
function handleShippingMethodChange(event) {
  const input = event && event.target;
  if (!input || input.type !== "radio" || !input.checked) return;
  if (typeof input.name !== "string" || input.name.indexOf("shipping_method") !== 0) return;
  const rateId = typeof input.value === "string" ? input.value : "";
  if (rateId.indexOf("bluex-pudo") === 0) return;
  closePudoModal();
  clearAgencyHiddenInputs();
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

function getConfirmedAgencyId() {
  const input = document.getElementById("agencyId");
  return input && input.value ? String(input.value) : "";
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
  title.textContent = "Selecciona tu punto Blue Express";

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

function buildWidgetUrl(confirmedAgencyId) {
  if (typeof bluex_checkout_params === "undefined") {
    console.error("[BlueX PUDO] checkout params not loaded");
    return "";
  }
  const baseUrl = bluex_checkout_params.base_path_url || "";
  let widgetUrl = bluex_checkout_params.widget_base_urls.prod;
  if (baseUrl.indexOf("qa") !== -1) widgetUrl = bluex_checkout_params.widget_base_urls.qa;
  if (baseUrl.indexOf("dev") !== -1) widgetUrl = bluex_checkout_params.widget_base_urls.dev;

  if (confirmedAgencyId) {
    widgetUrl +=
      (widgetUrl.indexOf("?") >= 0 ? "&" : "?") +
      "id=" +
      encodeURIComponent(confirmedAgencyId);
  }

  return widgetUrl;
}

function openPudoModal() {
  const dialog = ensurePudoDialog();
  const iframe = document.getElementById(BLUEX_PUDO_IFRAME_ID);
  const url = buildWidgetUrl(getConfirmedAgencyId());
  if (!url) return;

  // First opening is neutral. Once the shopper explicitly confirms a point,
  // reopening uses only that confirmed ID so the widget can display/change it.
  if (iframe) {
    iframe.src = url;
    iframe.setAttribute('data-bluex-url', url);
  }

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
    // Auto-open the modal on PUDO entry when no agency is picked yet.
    if (!hasAgency) {
      openPudoModal();
    }
  } else if (!nowPudo && lastWasPudo) {
    // A recalculation may temporarily remove the rate. Close the modal, but
    // preserve confirmed metadata; only an explicit radio change may clear it.
    closePudoModal();
  }

  if (nowPudo) {
    renderChangePointLink(hasAgency ? "change" : "select");
    const iframe = document.getElementById(BLUEX_PUDO_IFRAME_ID);
    if (iframe) {
      const url = buildWidgetUrl(getConfirmedAgencyId());
      if (url && iframe.getAttribute('data-bluex-url') !== url) {
        iframe.src = url;
        iframe.setAttribute('data-bluex-url', url);
      }
    }
  } else {
    removeChangePointLink();
  }

  lastWasPudo = nowPudo;
}

// -----------------------------------------------------------------------------
// Iframe postMessage handling

function handleMessagesFromWindow() {
  window.addEventListener("message", (event) => {
    const iframe = document.getElementById(BLUEX_PUDO_IFRAME_ID);
    if (
      !window.BluexPudoWidgetSecurity ||
      !window.BluexPudoWidgetSecurity.isTrustedMessage(event, iframe, bluex_checkout_params)
    ) {
      return;
    }
    if (event.data && event.data.type === "pudo:select") {
      handlePudoSelect(event);
    }
  });
}

function handlePudoSelect(event) {
  const data = event.data.payload;
  if (!data || !data.location) return;

  const location = data.location;
  const {
    street_name = "",
    street_number = "",
    city_name = "",
  } = location;
  const stateValue =
    location.state_code ||
    location.region_code ||
    location.state ||
    location.state_name ||
    location.region_name ||
    "";

  const fullStreet = `${street_name} ${street_number}`.trim();
  const agencyName = data.agency_name || "";
  const fullAddress = [fullStreet, city_name]
    .filter(function (s) { return s && s !== ""; })
    .join(", ");

  const state = getStateDetails(stateValue);
  // Only hidden PUDO metadata changes. Billing and shipping address fields
  // remain the customer's actual address.
  const agencyIdInput = document.getElementById("agencyId");
  if (agencyIdInput) agencyIdInput.value = data.agency_id || "";
  const nameInput = document.getElementById("agency_name");
  if (nameInput) nameInput.value = agencyName;
  const addressInput = document.getElementById("agency_address");
  if (addressInput) addressInput.value = fullAddress;
  const cityInput = document.getElementById("agency_city");
  if (cityInput) cityInput.value = city_name;
  const stateInput = document.getElementById("agency_state");
  if (stateInput) stateInput.value = state.abreviation || "";

  // Close the modal immediately. The link below the rate will flip to
  // "Cambiar punto Blue Express" on the next syncFromNativeRate (which
  // fires after the update_checkout AJAX completes).
  closePudoModal();

  triggerUpdateCheckout();
}

function clearAgencyHiddenInputs() {
  ["agencyId", "agency_name", "agency_address", "agency_city", "agency_state"].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.value = "";
  });
}

// -----------------------------------------------------------------------------
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
  const raw = String(name || "").trim().toUpperCase();
  if (/^CL-[A-Z]{2}$/.test(raw)) {
    return states.find((state) => state.abreviation === raw) || {};
  }
  if (/^[A-Z]{2}$/.test(raw)) {
    return states.find((state) => state.abreviation === "CL-" + raw) || {};
  }

  const wanted = normalizePudoRegionName(name);
  return states.find(function (state) {
    return [state.nameFromIframe, state.fullName].some(function (candidate) {
      const normalized = normalizePudoRegionName(candidate);
      return normalized && (
        normalized === wanted ||
        (wanted.length > 4 && normalized.indexOf(wanted) !== -1) ||
        (normalized.length > 4 && wanted.indexOf(normalized) !== -1)
      );
    });
  }) || {};
}

function normalizePudoRegionName(value) {
  return String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "");
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
