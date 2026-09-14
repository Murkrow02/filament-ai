{{--
    Plain CSS on purpose: the panel's stylesheet only contains the Tailwind
    classes Filament itself uses, so arbitrary utility classes here would
    silently render unstyled in a host panel.
--}}
<style>
    .rag-assistant { display: grid; grid-template-columns: 15rem minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    @media (max-width: 64rem) { .rag-assistant { grid-template-columns: minmax(0, 1fr); } }

    .rag-assistant-history { display: flex; flex-direction: column; gap: .375rem; }
    .rag-assistant-thread { display: flex; flex-direction: column; align-items: flex-start; gap: .125rem; width: 100%; padding: .5rem .625rem; border-radius: .5rem; text-align: left; font-size: .875rem; }
    .rag-assistant-thread:hover, .rag-assistant-thread.is-active { background: rgb(0 0 0 / .05); }
    .dark .rag-assistant-thread:hover, .dark .rag-assistant-thread.is-active { background: rgb(255 255 255 / .06); }
    .rag-assistant-thread-title { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%; }

    .rag-assistant-main { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
    .rag-assistant-messages { display: flex; flex-direction: column; gap: 1rem; }

    .rag-assistant-message { max-width: 48rem; padding: .75rem 1rem; border-radius: .75rem; background: rgb(0 0 0 / .03); }
    .dark .rag-assistant-message { background: rgb(255 255 255 / .04); }
    .rag-assistant-message.is-user { align-self: flex-end; background: rgb(0 0 0 / .07); }
    .dark .rag-assistant-message.is-user { background: rgb(255 255 255 / .09); }

    .rag-assistant-label { margin: 0 0 .25rem; font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; opacity: .6; }
    .rag-assistant-muted { margin: 0; font-size: .8125rem; opacity: .65; }
    .rag-assistant-tools { display: flex; flex-wrap: wrap; gap: .375rem; margin-bottom: .5rem; }
    .rag-assistant-content { font-size: .9375rem; line-height: 1.6; overflow-wrap: anywhere; }
    .rag-assistant-content p { margin: 0 0 .5rem; }
    .rag-assistant-content p:last-child { margin-bottom: 0; }
    .rag-assistant-content ul, .rag-assistant-content ol { margin: 0 0 .5rem 1.25rem; list-style: revert; }
    .rag-assistant-content a { text-decoration: underline; }
    .rag-assistant-content code { font-size: .875em; }
    .rag-assistant-plain { white-space: pre-wrap; }

    .rag-assistant-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
    .rag-assistant-error { margin: 0; font-size: .875rem; color: rgb(220 38 38); }
    .rag-assistant-thinking { align-items: center; gap: .5rem; font-size: .875rem; opacity: .7; }
    .rag-assistant-spinner { width: 1.25rem; height: 1.25rem; }

    .rag-assistant-empty { display: flex; flex-direction: column; align-items: center; gap: .5rem; padding: 3rem 1rem; text-align: center; }
    .rag-assistant-empty-icon { width: 2.5rem; height: 2.5rem; opacity: .5; }
    .rag-assistant-empty-title { margin: 0; font-size: 1.125rem; font-weight: 600; }

    .rag-assistant-composer { display: flex; gap: .5rem; align-items: center; }
    .rag-assistant-input { flex: 1; }
</style>
