const themes = {
  editorial: 'A · Тёплая редакционная',
  calm: 'B · Ясная современная',
  studio: 'C · Живая студия',
};

const setTheme = (theme) => {
  if (!themes[theme]) return;
  document.body.dataset.theme = theme;
  document.querySelector('#theme-note').textContent = themes[theme];
  document.querySelectorAll('[data-set-theme]').forEach((button) => {
    const active = button.dataset.setTheme === theme;
    button.classList.toggle('is-active', active);
    button.setAttribute('aria-pressed', String(active));
  });
  history.replaceState(null, '', `#${theme}`);
};

document.querySelectorAll('[data-set-theme]').forEach((button) => {
  button.addEventListener('click', () => setTheme(button.dataset.setTheme));
});

document.querySelectorAll('[data-result-tab]').forEach((button) => {
  button.addEventListener('click', () => {
    const selected = button.dataset.resultTab;
    document.querySelectorAll('[data-result-tab]').forEach((tab) => {
      const active = tab === button;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-pressed', String(active));
    });
    document.querySelectorAll('[data-report-panel]').forEach((panel) => {
      panel.classList.toggle('is-hidden', panel.dataset.reportPanel !== selected);
    });
  });
});

document.querySelectorAll('[data-filter]').forEach((button) => {
  button.addEventListener('click', () => {
    const selected = button.dataset.filter;
    document.querySelectorAll('[data-filter]').forEach((filter) => {
      const active = filter === button;
      filter.classList.toggle('is-active', active);
      filter.setAttribute('aria-pressed', String(active));
    });
    document.querySelectorAll('[data-category]').forEach((card) => {
      card.hidden = selected !== 'all' && card.dataset.category !== selected;
    });
  });
});

const initialTheme = location.hash.slice(1);
if (themes[initialTheme]) setTheme(initialTheme);
