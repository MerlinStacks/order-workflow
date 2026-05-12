document.addEventListener('DOMContentLoaded', function () {
  var tabButtons = Array.prototype.slice.call(document.querySelectorAll('.ck-ows-tab'));
  var tabPanels = Array.prototype.slice.call(document.querySelectorAll('.ck-ows-panel'));

  if (!tabButtons.length || !tabPanels.length) {
    return;
  }

  var activateTab = function (target) {
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
  };

  tabButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      activateTab(button.getAttribute('data-target'));
    });
  });
});
