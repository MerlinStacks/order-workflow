(() => {
  const MODAL_ID = "ck-ows-logout-confirm";
  const i18n =
    typeof window.ckOwsLogoutConfirm === "object" && window.ckOwsLogoutConfirm
      ? window.ckOwsLogoutConfirm
      : {};

  const text = {
    title: i18n.title || "Log out?",
    message: i18n.message || "Are you sure you want to log out of your account?",
    cancel: i18n.cancel || "Cancel",
    confirm: i18n.confirm || "Confirm and log out",
  };
  const directLogoutUrl = typeof i18n.logoutUrl === "string" ? i18n.logoutUrl : "";

  const getLogoutLink = (target) => {
    if (!(target instanceof Element)) {
      return null;
    }

    const link = target.closest("a");
    if (!(link instanceof HTMLAnchorElement)) {
      return null;
    }

    const href = link.getAttribute("href") || "";
    if (href.includes("customer-logout")) {
      return link;
    }

    const navItem = link.closest(".woocommerce-MyAccount-navigation-link--customer-logout, li.logout");
    return navItem ? link : null;
  };

  const ensureModal = () => {
    const existing = document.getElementById(MODAL_ID);
    if (existing instanceof HTMLElement) {
      return existing;
    }

    const wrapper = document.createElement("div");
    wrapper.id = MODAL_ID;
    wrapper.className = "ck-ows-modal";
    wrapper.setAttribute("aria-hidden", "true");
    wrapper.innerHTML =
      '<div class="ck-ows-modal__backdrop" data-ck-ows-close="1"></div>' +
      '<div class="ck-ows-modal__panel" role="dialog" aria-modal="true" aria-labelledby="ck-ows-logout-title">' +
      `<h3 id="ck-ows-logout-title" class="ck-ows-modal__title">${text.title}</h3>` +
      `<p class="ck-ows-modal__text">${text.message}</p>` +
      '<div class="ck-ows-modal__actions">' +
      `<button type="button" class="ck-ows-modal__button ck-ows-modal__button--ghost" data-ck-ows-close="1">${text.cancel}</button>` +
      `<a href="#" class="ck-ows-modal__button ck-ows-modal__button--danger" data-ck-ows-confirm="1">${text.confirm}</a>` +
      "</div></div>";

    document.body.appendChild(wrapper);
    return wrapper;
  };

  const openModal = (href) => {
    const modal = ensureModal();
    const confirmLink = modal.querySelector("[data-ck-ows-confirm='1']");
    if (confirmLink instanceof HTMLAnchorElement) {
      confirmLink.href = directLogoutUrl || href;
    }

    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("ck-ows-modal-open");
  };

  const closeModal = () => {
    const modal = document.getElementById(MODAL_ID);
    if (!(modal instanceof HTMLElement)) {
      return;
    }

    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("ck-ows-modal-open");
  };

  const init = () => {
    document.addEventListener("click", (event) => {
      const target = event.target;

      if (target instanceof Element && target.closest("[data-ck-ows-close='1']")) {
        event.preventDefault();
        closeModal();
        return;
      }

      if (target instanceof Element && target.closest("#" + MODAL_ID + " [data-ck-ows-confirm='1']")) {
        const confirmLink = target.closest("[data-ck-ows-confirm='1']");
        if (confirmLink instanceof HTMLAnchorElement && confirmLink.href) {
          window.location.assign(confirmLink.href);
        }
        closeModal();
        return;
      }

      const logoutLink = getLogoutLink(target);

      if (logoutLink) {
        if (logoutLink.closest("#" + MODAL_ID)) {
          return;
        }

        event.preventDefault();
        openModal(logoutLink.href);
        return;
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        closeModal();
      }
    });
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
