(function (window, document) {
  'use strict';

  const config = window.JSRayWP || {};
  const LANGUAGE_RE = /(?:^|\s)(?:language|lang)-([A-Za-z0-9_-]+)/;

  function sanitizeLanguage(value) {
    return String(value || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
  }

  function classLanguage(element) {
    if (!element || !element.className) return '';
    const match = String(element.className).match(LANGUAGE_RE);
    return match ? sanitizeLanguage(match[1]) : '';
  }

  function dataLanguage(element) {
    if (!element || !element.dataset) return '';
    return sanitizeLanguage(element.dataset.language || element.dataset.lang || '');
  }

  function autoDetectLanguage(code) {
    const autoDetect = config.autoDetectLanguage !== false && config.autoDetectLanguage !== '0';
    const renderer = activeRenderer();

    if (!autoDetect || !renderer || typeof renderer.detectLanguage !== 'function') {
      return '';
    }

    return sanitizeLanguage(renderer.detectLanguage(code.textContent || ''));
  }

  function findLanguage(code) {
    const pre = code.closest ? code.closest('pre') : null;
    return (
      classLanguage(code) ||
      dataLanguage(code) ||
      classLanguage(pre) ||
      dataLanguage(pre) ||
      autoDetectLanguage(code) ||
      sanitizeLanguage(config.fallbackLanguage)
    );
  }

  function hasLanguageClass(code) {
    return /\blanguage-[A-Za-z0-9_-]+\b/.test(String(code.className || ''));
  }

  function languageLabel(language) {
    const labels = config.languages || {};
    return labels[language] || language.toUpperCase();
  }

  /**
   * Fill in a header that PHP could only render as a placeholder.
   *
   * The server cannot detect a language — it would have to read the code and
   * guess, which is what this runtime does. So a block left on "Auto detect"
   * ships with a placeholder label, and it stays wrong on screen unless the
   * detected language is written back.
   */
  function updateHeader(code, language) {
    const block = code.closest ? code.closest('[data-jsray-block]') : null;

    if (!block || !language) return;

    block.setAttribute('data-language', language);

    const label = languageLabel(language);
    const languageSpan = block.querySelector('[data-jsray-language-label]');
    const title = block.querySelector('[data-jsray-title-language]');

    if (languageSpan) languageSpan.textContent = label;
    // Only when the title is the language placeholder — never overwrite a
    // filename the author typed.
    if (title) title.textContent = label;
  }

  function normalizeCodeBlock(code) {
    const language = findLanguage(code);
    const pre = code.closest ? code.closest('pre') : null;

    if (!language) return false;

    if (!hasLanguageClass(code)) {
      code.classList.add('language-' + language);
    }

    code.dataset.jsrayLang = language;

    if (pre) {
      pre.classList.add('jsray');
      pre.dataset.jsrayLang = language;
    }

    updateHeader(code, language);

    return true;
  }

  function candidates(root) {
    const scope = root && root.querySelectorAll ? root : document;

    // Blocks that declare a language, plus JSRay's own markup. These are
    // always ours to render.
    const marked = [
      'code[class*="language-"]',
      'code[class*="lang-"]',
      'pre[class*="language-"] > code',
      'pre[class*="lang-"] > code',
      'pre[data-language] > code',
      'pre[data-lang] > code',
      'pre.jsray > code',
      '[data-jsray-block] pre > code'
    ];

    // Unmarked blocks are opt-out: a site that already renders code with
    // another plugin should be able to stop JSRay from taking them over.
    const unmarked = ['.wp-block-code > code', 'pre > code'];
    const selector = (config.scanAllCode === false ? marked : marked.concat(unmarked)).join(',');

    const found = Array.prototype.slice.call(scope.querySelectorAll(selector));

    if (scope.matches && scope.matches(selector)) {
      found.unshift(scope);
    }

    return found;
  }

  function applyTheme() {
    const theme = sanitizeLanguage(config.theme);

    if (theme !== 'dark' && theme !== 'light') return;

    document.documentElement.setAttribute('data-theme', theme);

    if (document.body) {
      document.body.setAttribute('data-theme', theme);
    }
  }

  function activeRenderer() {
    if (
      window.JSRayWP &&
      window.JSRayWP.renderer &&
      typeof window.JSRayWP.renderer.highlightElement === 'function'
    ) {
      return window.JSRayWP.renderer;
    }

    if (window.JSRay && typeof window.JSRay.highlightElement === 'function') {
      return window.JSRay;
    }

    return null;
  }

  function highlight(root) {
    const renderer = activeRenderer();

    if (!renderer) return;

    applyTheme();

    candidates(root).forEach(function (code) {
      if (normalizeCodeBlock(code)) {
        renderer.highlightElement(code);
      }
    });

    bindCopyButtons(root);
  }

  function setCopyState(button, label) {
    const original = button.dataset.jsrayCopyLabel || button.textContent;

    button.dataset.jsrayCopyLabel = original;
    button.textContent = label;

    window.setTimeout(function () {
      button.textContent = original;
    }, 1600);
  }

  function fallbackCopy(text) {
    const textarea = document.createElement('textarea');

    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '-999px';
    document.body.appendChild(textarea);
    textarea.select();

    try {
      document.execCommand('copy');
      return Promise.resolve();
    } catch (error) {
      return Promise.reject(error);
    } finally {
      document.body.removeChild(textarea);
    }
  }

  function label(key, fallback) {
    const strings = config.i18n || {};
    return strings[key] || fallback;
  }

  function copyCode(button) {
    const block = button.closest('[data-jsray-block]');
    const code = block ? block.querySelector('pre code') : null;
    const text = code ? code.textContent : '';
    // The async clipboard API is preferred, but it rejects in plenty of
    // ordinary situations — a permissions policy, an embedding iframe, a
    // click that the browser does not count as user activation. Falling back
    // to the legacy path there is the difference between a working button and
    // one that just says "Failed".
    const copy = navigator.clipboard && navigator.clipboard.writeText
      ? navigator.clipboard.writeText(text).catch(function () {
        return fallbackCopy(text);
      })
      : fallbackCopy(text);

    copy.then(function () {
      setCopyState(button, label('copied', 'Copied'));
    }).catch(function () {
      setCopyState(button, label('failed', 'Failed'));
    });
  }

  function bindCopyButtons(root) {
    const scope = root && root.querySelectorAll ? root : document;
    const buttons = Array.prototype.slice.call(scope.querySelectorAll('[data-jsray-copy]'));

    if (scope.matches && scope.matches('[data-jsray-copy]')) {
      buttons.unshift(scope);
    }

    buttons.forEach(function (button) {
      if (button.dataset.jsrayCopyBound) return;

      button.dataset.jsrayCopyBound = '1';
      button.addEventListener('click', function () {
        copyCode(button);
      });
    });
  }

  function observe() {
    if (!window.MutationObserver || !document.body) return;

    const observer = new MutationObserver(function (records) {
      records.forEach(function (record) {
        Array.prototype.forEach.call(record.addedNodes, function (node) {
          if (node.nodeType === 1) {
            highlight(node);
          }
        });
      });
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  function boot() {
    highlight(document);
    bindCopyButtons(document);
    observe();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  window.JSRayWP = Object.assign({}, config, {
    highlight: highlight
  });
})(window, document);
