(function (wp, window) {
  'use strict';

  if (!wp || !wp.blocks || !wp.element || !wp.blockEditor || !wp.components) {
    return;
  }

  const el = wp.element.createElement;
  const __ = wp.i18n.__;
  const registerBlockType = wp.blocks.registerBlockType;
  const useBlockProps = wp.blockEditor.useBlockProps;
  const InspectorControls = wp.blockEditor.InspectorControls;
  const PanelBody = wp.components.PanelBody;
  const SelectControl = wp.components.SelectControl;
  const TextControl = wp.components.TextControl;
  const TextareaControl = wp.components.TextareaControl;
  const ToggleControl = wp.components.ToggleControl;

  const settings = window.JSRayBlockSettings || {};
  const languages = settings.languages || {};

  function sanitizeLanguage(value) {
    return String(value || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
  }

  function languageOptions() {
    return [{
      label: __('Auto detect', 'jsray'),
      value: ''
    }].concat(Object.keys(languages).map(function (value) {
      return {
        label: languages[value],
        value: value
      };
    }));
  }

  function languageLabel(language) {
    const normalized = sanitizeLanguage(language);

    if (!normalized) return __('Auto detect', 'jsray');

    return languages[normalized] || normalized.toUpperCase();
  }

  function activeRenderer() {
    if (
      window.JSRayWP &&
      window.JSRayWP.renderer &&
      typeof window.JSRayWP.renderer.highlight === 'function'
    ) {
      return window.JSRayWP.renderer;
    }

    if (window.JSRay && typeof window.JSRay.highlight === 'function') {
      return window.JSRay;
    }

    return null;
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"]/g, function (char) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;'
      }[char];
    });
  }

  function highlight(code, language) {
    const renderer = activeRenderer();
    const resolvedLanguage = sanitizeLanguage(language) || detectLanguage(code);

    if (renderer) {
      return renderer.highlight(code, resolvedLanguage);
    }

    return escapeHtml(code);
  }

  function detectLanguage(code) {
    const renderer = activeRenderer();

    if (renderer && typeof renderer.detectLanguage === 'function') {
      return sanitizeLanguage(renderer.detectLanguage(code));
    }

    return '';
  }

  function countLines(code) {
    if (!code) return 1;
    return String(code).split('\n').length;
  }

  function lineNumberList(code) {
    const lines = [];
    const count = countLines(code);

    for (let i = 0; i < count; i++) {
      lines.push(el('li', { key: i }));
    }

    return el('ol', {
      className: 'jsray-block__gutter',
      'aria-hidden': 'true'
    }, lines);
  }

  function Preview(attributes) {
    // Sample code, not a UI string — deliberately not translatable.
    const code = attributes.code || 'const value = 42;';
    const selectedLanguage = sanitizeLanguage(attributes.language);
    const detectedLanguage = selectedLanguage ? '' : detectLanguage(code);
    const language = selectedLanguage || detectedLanguage;
    const languageText = selectedLanguage
      ? languageLabel(selectedLanguage)
      : (detectedLanguage ? languageLabel(detectedLanguage) : __('Auto detect', 'jsray'));
    const filename = attributes.filename || languageText;
    const classes = [
      'jsray-block',
      'jsray-block-editor__preview',
      language ? 'language-' + language : '',
      attributes.showLineNumbers ? 'has-line-numbers' : ''
    ].filter(Boolean).join(' ');

    return el('div', {
      className: classes,
      'data-language': language
    }, [
      el('div', {
        className: 'jsray-block__header',
        key: 'header'
      }, [
        el('span', {
          className: 'jsray-block__title',
          key: 'title'
        }, filename),
        el('span', {
          className: 'jsray-block__language',
          key: 'language'
        }, languageText),
        attributes.showCopyButton ? el('button', {
          className: 'jsray-block__copy',
          disabled: true,
          key: 'copy',
          type: 'button'
        }, __('Copy', 'jsray')) : null
      ]),
      el('div', {
        className: 'jsray-block__body',
        key: 'body'
      }, [
        attributes.showLineNumbers ? lineNumberList(code) : null,
        el('pre', {
          className: ['jsray', language ? 'language-' + language : ''].filter(Boolean).join(' '),
          key: 'pre'
        }, el('code', {
          className: language ? 'language-' + language : '',
          dangerouslySetInnerHTML: {
            __html: highlight(code, language)
          }
        }))
      ])
    ]);
  }

  // Name, attributes, and supports come from block.json (inlined by PHP), so
  // the editor and the server can never disagree about the block's shape.
  const metadata = settings.metadata && settings.metadata.name
    ? settings.metadata
    : { name: 'jsray/code', title: 'JSRay Code', category: 'text', icon: 'editor-code' };

  registerBlockType(metadata, {
    edit: function (props) {
      const attributes = props.attributes;
      const setAttributes = props.setAttributes;
      const language = sanitizeLanguage(attributes.language);
      const blockProps = useBlockProps({
        className: 'jsray-block-editor'
      });

      return el('div', blockProps, [
        el(InspectorControls, {
          key: 'inspector'
        }, el(PanelBody, {
          title: __('JSRay', 'jsray'),
          initialOpen: true
        }, [
          el(SelectControl, {
            label: __('Language', 'jsray'),
            value: language,
            options: languageOptions(),
            onChange: function (value) {
              setAttributes({ language: value });
            },
            key: 'language'
          }),
          el(TextControl, {
            label: __('Filename', 'jsray'),
            value: attributes.filename || '',
            placeholder: 'example.js',
            onChange: function (value) {
              setAttributes({ filename: value });
            },
            key: 'filename'
          }),
          el(ToggleControl, {
            label: __('Copy button', 'jsray'),
            checked: attributes.showCopyButton !== false,
            onChange: function (value) {
              setAttributes({ showCopyButton: value });
            },
            key: 'copy'
          }),
          el(ToggleControl, {
            label: __('Line numbers', 'jsray'),
            checked: !!attributes.showLineNumbers,
            onChange: function (value) {
              setAttributes({ showLineNumbers: value });
            },
            key: 'lines'
          })
        ])),
        el('div', {
          className: 'jsray-block-editor__toolbar',
          key: 'toolbar'
        }, [
          el(SelectControl, {
            label: __('Language', 'jsray'),
            hideLabelFromVision: true,
            value: language,
            options: languageOptions(),
            onChange: function (value) {
              setAttributes({ language: value });
            },
            key: 'toolbar-language'
          }),
          el(TextControl, {
            label: __('Filename', 'jsray'),
            hideLabelFromVision: true,
            value: attributes.filename || '',
            placeholder: __('Filename', 'jsray'),
            onChange: function (value) {
              setAttributes({ filename: value });
            },
            key: 'toolbar-filename'
          })
        ]),
        el(TextareaControl, {
          className: 'jsray-block-editor__textarea',
          label: __('Code', 'jsray'),
          hideLabelFromVision: true,
          value: attributes.code || '',
          placeholder: __('Paste or type code here...', 'jsray'),
          onChange: function (value) {
            setAttributes({ code: value });
          },
          rows: Math.max(8, Math.min(24, countLines(attributes.code || '') + 2)),
          key: 'textarea'
        }),
        el(Preview, Object.assign({
          key: 'preview'
        }, attributes, {
          language: language
        }))
      ]);
    },
    save: function () {
      return null;
    }
  });
})(window.wp, window);
