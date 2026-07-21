/*------------------------------------------------------------------
Import styles
------------------------------------------------------------------*/

import 'styles/index.scss';

/*------------------------------------------------------------------
Export / Import Tabbing + Copy to Clipboard
------------------------------------------------------------------*/

const RESET_MS = 1800;

function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text);
  }

  return new Promise((resolve, reject) => {
    const scratch = document.createElement('textarea');
    scratch.value = text;
    scratch.setAttribute('readonly', 'readonly');
    scratch.style.position = 'fixed';
    scratch.style.top = '-9999px';
    document.body.appendChild(scratch);

    const selection = document.getSelection();
    const previous =
      selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

    scratch.select();

    let ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (e) {
      ok = false;
    }

    document.body.removeChild(scratch);

    if (previous) {
      selection.removeAllRanges();
      selection.addRange(previous);
    }

    ok ? resolve() : reject(new Error('Copy failed'));
  });
}

function flash(button, state, label) {
  const original =
    button.dataset.defaultLabel || button.textContent.trim();

  button.dataset.defaultLabel = original;
  button.textContent = label;

  button.classList.remove('is-copied', 'is-failed');
  button.classList.add(state);

  clearTimeout(button._resetTimer);
  button._resetTimer = setTimeout(() => {
    button.textContent = original;
    button.classList.remove('is-copied', 'is-failed');
  }, RESET_MS);
}

((tab) => {
  if (!tab) return;

  const tabButtons = {
    import: tab.querySelector('#ThemeTransferImportButton'),
    export: tab.querySelector('#ThemeTransferExportButton'),
  };

  const configs = {
    import: tab.querySelector('#Form_EditForm_ThemeTransferPaste'),
    export: tab.querySelector('#Form_EditForm_ThemeTransferExport'),
  };

  const copyButton = tab.querySelector('#ThemeExportCopyButton');

  Object.entries(tabButtons).forEach(([mode, button]) => {
    if (button.classList.contains('active')) {
      tab.classList.add(`${mode}-mode`);
    }

    button.addEventListener('click', (e) => {
      e.preventDefault();

      Object.keys(tabButtons).forEach((m) => {
        tab.classList.toggle(`${m}-mode`, m === mode);
        tabButtons[m].classList.toggle('active', m === mode);
      });
    });
  });

  configs.export.addEventListener('focus', () => {
    configs.export.select();
  });

  configs.export.addEventListener('click', () => {
    configs.export.select();
  });

  copyButton.addEventListener('click', (e) => {
    e.preventDefault();

    copyText(configs.export.value)
      .then(() => {
        flash(copyButton, 'is-copied', 'Copied');
      })
      .catch(() => {
        configs.export.select();
        flash(copyButton, 'is-failed', 'Press Ctrl/Cmd+C');
      });
  });
})(document.getElementById('Root_Customization_set_Transfer'));
