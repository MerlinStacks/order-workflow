document.addEventListener('DOMContentLoaded', function () {
  var tabStorageKey = 'ck_ows_settings_active_tab';
  var tabQueryKey = 'ck_ows_tab';
  var tabButtons = Array.prototype.slice.call(document.querySelectorAll('.ck-ows-tab'));
  var tabPanels = Array.prototype.slice.call(document.querySelectorAll('.ck-ows-panel'));

  if (!tabButtons.length || !tabPanels.length) {
    return;
  }

  var activateTab = function (target) {
    if (!target) {
      return;
    }

    tabButtons.forEach(function (button) {
      var isActive = button.getAttribute('data-target') === target;
      button.classList.toggle('nav-tab-active', isActive);
      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
      button.setAttribute('tabindex', isActive ? '0' : '-1');
    });

    tabPanels.forEach(function (panel) {
      var isActive = panel.id === 'ck-ows-panel-' + target;
      panel.classList.toggle('is-active', isActive);
      panel.hidden = !isActive;
    });

    try {
      window.localStorage.setItem(tabStorageKey, target);
    } catch (error) {
      // Ignore storage errors in restricted browser contexts.
    }

    try {
      var nextUrl = new URL(window.location.href);
      nextUrl.searchParams.set(tabQueryKey, target);
      window.history.replaceState(null, '', nextUrl.toString());
    } catch (error) {
      // Ignore URL rewrite errors.
    }
  };

  tabButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      activateTab(button.getAttribute('data-target'));
    });
  });

  var hasTab = function (target) {
    return tabButtons.some(function (button) {
      return button.getAttribute('data-target') === target;
    });
  };

  var savedTab = '';
  var queryTab = '';

  try {
    queryTab = new URL(window.location.href).searchParams.get(tabQueryKey) || '';
  } catch (error) {
    queryTab = '';
  }

  if (!queryTab && window.location.hash.indexOf('#ck-ows-tab=') === 0) {
    queryTab = decodeURIComponent(window.location.hash.replace('#ck-ows-tab=', ''));
  }

  try {
    savedTab = window.localStorage.getItem(tabStorageKey) || '';
  } catch (error) {
    savedTab = '';
  }

  if (hasTab(queryTab)) {
    activateTab(queryTab);
    return;
  }

  if (hasTab(savedTab)) {
    activateTab(savedTab);
  }
});
