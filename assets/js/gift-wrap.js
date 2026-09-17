/* Progressive enhancement: the checkbox and optional message work without JS. */
document.querySelectorAll('.ck-ows-gift-wrap').forEach((card) => {
  const checkbox = card.querySelector('[name="ck_ows_gift_wrap"]');
  const panel = card.querySelector('.ck-ows-gift-wrap__message');
  const update = () => {
    panel.hidden = !checkbox.checked;
    checkbox.setAttribute('aria-expanded', String(checkbox.checked));
    card.classList.toggle('is-selected', checkbox.checked);
  };
  checkbox.addEventListener('change', update);
  update();
});
