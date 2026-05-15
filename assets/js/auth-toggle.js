(() => {
  const WRAPPER_SELECTOR = ".mfp-wrap #customer_login, .lightbox-content #customer_login";

  const setMode = (wrapper, mode) => {
    wrapper.classList.remove("ck-ows-auth-mode-login", "ck-ows-auth-mode-register");
    wrapper.classList.add(mode === "register" ? "ck-ows-auth-mode-register" : "ck-ows-auth-mode-login");

    const loginButton = wrapper.parentElement?.querySelector("[data-ck-ows-auth='login']");
    const registerButton = wrapper.parentElement?.querySelector("[data-ck-ows-auth='register']");

    if (loginButton && registerButton) {
      loginButton.classList.toggle("is-active", mode !== "register");
      registerButton.classList.toggle("is-active", mode === "register");
    }
  };

  const addToggle = (wrapper) => {
    if (wrapper.dataset.ckOwsAuthReady === "1") {
      return;
    }

    const loginCol = wrapper.querySelector(".u-column1, .col-1");
    const registerCol = wrapper.querySelector(".u-column2, .col-2");
    if (!loginCol || !registerCol) {
      return;
    }

    const toggle = document.createElement("div");
    toggle.className = "ck-ows-auth-toggle";
    toggle.innerHTML =
      '<button type="button" class="ck-ows-auth-toggle__button is-active" data-ck-ows-auth="login">Log in</button>' +
      '<button type="button" class="ck-ows-auth-toggle__button" data-ck-ows-auth="register">Register</button>';

    toggle.addEventListener("click", (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }

      const mode = target.getAttribute("data-ck-ows-auth");
      if (mode !== "login" && mode !== "register") {
        return;
      }

      setMode(wrapper, mode);
    });

    wrapper.parentNode?.insertBefore(toggle, wrapper);
    wrapper.dataset.ckOwsAuthReady = "1";
    setMode(wrapper, "login");
  };

  const init = () => {
    document.querySelectorAll(WRAPPER_SELECTOR).forEach((wrapper) => {
      if (wrapper instanceof HTMLElement) {
        addToggle(wrapper);
      }
    });
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  const observer = new MutationObserver(init);
  observer.observe(document.body, { childList: true, subtree: true });
})();
