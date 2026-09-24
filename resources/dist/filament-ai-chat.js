/*
 * The assistant chat, with no framework.
 *
 * Everything the page needs -- the thread list, the streaming answer, the
 * approval cards -- fits in a few hundred lines of DOM code, and shipping it
 * that way means the package works in a host that has no build step, no npm
 * dependency and no Alpine on the page. The bootstrap payload in
 * `#fai-chat-payload` is the single source of truth for what this user may
 * see; nothing here re-decides that.
 *
 * The same file runs standalone and embedded in a Filament page: the only
 * difference is which stylesheet variables are in force and which url the
 * open thread is written into.
 */
(function () {
  'use strict';

  var root = document.getElementById('fai-chat');
  if (!root) return;

  var payload = JSON.parse(document.getElementById('fai-chat-payload').textContent);
  var t = payload.strings;
  var can = payload.abilities;

  /**
   * One exchange on screen: what was asked, what came back, what the
   * assistant reached for on the way, and anything still waiting for a
   * decision.
   */
  function blankTurn(question) {
    return {
      question: question || '',
      answer: '',
      tools: [],
      approvals: [],
      decisions: {},
      passages: [],
      solve: null
    };
  }

  /**
   * laravel/ai stores a conversation as a flat list of messages; the page
   * shows exchanges. A user message opens a turn and everything the assistant
   * says until the next question belongs to it.
   */
  function turnsFromTranscript(rows, pending) {
    var turns = [];
    var current = null;

    (rows || []).forEach(function (row) {
      if (row.role === 'user') {
        current = blankTurn(row.content);
        turns.push(current);
        return;
      }

      if (!current) {
        current = blankTurn('');
        turns.push(current);
      }

      if (row.content) current.answer += (current.answer ? '\n\n' : '') + row.content;

      // A turn that died is stored with what it managed; the page says so,
      // and the debug ability gets the stored error.
      if (row.failed) {
        current.error = true;
        current.answer = row.failure || t.failed;
        current.detail = row.error || '';
      }

      (row.tools || []).forEach(function (tool) { current.tools.push(tool); });
      (row.passages || []).forEach(function (passage) { current.passages.push(passage); });
    });

    // Whatever is still waiting belongs to the last thing the assistant said.
    if (pending && pending.length) {
      if (!current) { current = blankTurn(''); turns.push(current); }
      current.approvals = pending;
    }

    return turns;
  }

  var state = {
    conversation: payload.current ? payload.current.uuid : null,
    title: payload.current ? payload.current.title : null,
    threads: payload.conversations || [],
    messages: payload.current ? turnsFromTranscript(payload.current.messages, payload.current.pending) : [],
    settings: { model: payload.currentModel },
    solve: false,
    streaming: false,
    abort: null,
    filter: ''
  };

  // ---------------------------------------------------------------- helpers

  function el(id) { return document.getElementById(id); }

  // Below this width the sidebar is an overlay, not a column, so it has to
  // start closed and get out of the way as soon as it has been used.
  var narrow = window.matchMedia('(max-width: 900px)');

  function closeSidebarOnNarrow() {
    if (narrow.matches) root.dataset.sidebar = 'closed';
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function url(template, uuid) { return template.replace('__UUID__', encodeURIComponent(uuid)); }

  var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

  /*
   * The thread id is a continuation hint, and a hint that got corrupted must
   * never be allowed to block a question. Anything that is not a uuid is
   * dropped here and the thread starts over, rather than being posted for the
   * server to reject.
   */
  function conversationId() {
    if (!state.conversation) return null;
    if (UUID.test(state.conversation)) return state.conversation;

    if (window.console) console.warn('filament-ai-chat: discarding a malformed conversation id', state.conversation);
    state.conversation = null;

    return null;
  }

  function money(value) {
    if (value == null) return null;
    if (value === 0) return '$0';
    return '$' + (value < 0.01 ? value.toFixed(4) : value.toFixed(3));
  }

  /*
   * The address bar follows the open thread, so a reload -- or a link somebody
   * pasted to a colleague -- reopens what was on screen. Inside a panel the
   * page is a Livewire component whose url parameter is named `conversation`;
   * standalone it is a route of its own.
   */
  function syncUrl() {
    try {
      if (payload.embedded) {
        var next = new URL(window.location.href);

        if (state.conversation) next.searchParams.set('conversation', state.conversation);
        else next.searchParams.delete('conversation');

        history.replaceState({}, '', next.toString());
        return;
      }

      if (state.conversation && payload.endpoints.show) {
        history.replaceState({}, '', url(payload.endpoints.show, state.conversation));
      } else if (payload.endpoints.index) {
        history.replaceState({}, '', payload.endpoints.index);
      }
    } catch (error) { /* an opaque origin, or no history to write */ }
  }

  /**
   * The approvals of the last turn that nobody has answered yet. While there
   * are any, the conversation cannot move: laravel/ai resumes from the latest
   * stored turn, so a new question would bury the pause.
   */
  function awaitingDecision() {
    var last = state.messages[state.messages.length - 1];

    return last && last.approvals && last.approvals.length ? last : null;
  }

  /*
   * A failed request has to explain itself. Throwing the bare status turns a
   * validation error -- which names the offending field -- into "HTTP 422",
   * which names nothing and cannot be acted on by anybody.
   */
  function describeFailure(status, body) {
    if (body) {
      if (body.errors) {
        var messages = [];
        Object.keys(body.errors).forEach(function (field) {
          messages.push([].concat(body.errors[field]).join(' '));
        });
        if (messages.length) return messages.join(' ');
      }
      if (body.message) return body.message;
    }
    return status === 403 ? t.forbidden : t.failed;
  }

  /*
   * What the reader is told is the server's own sentence. The status code and
   * whatever the server added for the debug ability travel as the detail,
   * which only that ability ever gets to see.
   */
  function failureFrom(response) {
    return response.json()
      .catch(function () { return null; })
      .then(function (body) {
        var error = new Error(describeFailure(response.status, body));
        error.detail = can.debug ? ('HTTP ' + response.status + (body && body.detail ? ' - ' + body.detail : '')) : '';
        throw error;
      });
  }

  /*
   * The panel and tenant the page belongs to. The chat's routes live outside
   * the panel, so every request names them; the server checks both before
   * letting the assistant near a resource.
   */
  function scoped(endpoint) {
    var scope = payload.scope || {};
    var params = [];
    if (scope.panel) params.push('panel=' + encodeURIComponent(scope.panel));
    if (scope.tenant) params.push('tenant=' + encodeURIComponent(scope.tenant));
    if (!params.length) return endpoint;
    return endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + params.join('&');
  }

  /*
   * A page opened before a deploy runs the old script against new endpoints,
   * which shows up as steps without names and failures that make no sense.
   * Every response names the server's version: when it is not ours, ask for
   * a reload instead of carrying on.
   */
  function checkVersion(response) {
    var served = response.headers.get('X-Filament-Ai-Version');

    if (!served || !payload.version || served === payload.version || document.getElementById('fai-outdated')) return;

    var banner = document.createElement('div');
    banner.id = 'fai-outdated';
    banner.className = 'fai-outdated';
    banner.innerHTML = '<span>' + escapeHtml(t.outdated) + '</span>' +
      '<button type="button" class="fai-chip">' + escapeHtml(t.reload) + '</button>';
    banner.querySelector('button').addEventListener('click', function () { window.location.reload(); });
    root.insertBefore(banner, root.firstChild);
  }

  function request(method, endpoint, body) {
    return fetch(scoped(endpoint), {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': payload.csrf
      },
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body)
    }).then(function (response) {
      checkVersion(response);
      if (!response.ok) return failureFrom(response);
      return response.status === 204 ? null : response.json();
    });
  }

  /*
   * The Markdown a language model actually writes: paragraphs, headings,
   * nested lists, tables, block quotes, rules, fenced and inline code, bold,
   * italic, strikethrough and links. No library: every piece of text is
   * escaped before any tag is produced, so the only HTML that can reach the
   * page is the handful of tags written right here -- a full Markdown parser
   * would be a larger dependency and a larger attack surface for text that
   * arrives from a language model.
   *
   * Block structure is read line by line first and inline formatting applied
   * to each block's text afterwards, so an asterisk that starts a list item
   * can never be taken for the start of an italic run.
   */
  function markdown(source) {
    var lines = String(source || '').replace(/\r\n?/g, '\n').split('\n');
    var html = '';
    var paragraph = [];
    var i = 0;

    function flushParagraph() {
      if (paragraph.length) { html += '<p>' + paragraph.map(inline).join('<br>') + '</p>'; paragraph = []; }
    }

    while (i < lines.length) {
      var line = lines[i];
      var trimmed = line.trim();
      var match;

      if (trimmed === '') { flushParagraph(); i++; continue; }

      // Fenced code: everything up to the closing fence, verbatim.
      if ((match = /^(\s*)(`{3,}|~{3,})\s*([\w+#.-]*)\s*$/.exec(line))) {
        flushParagraph();
        var fence = match[2];
        var code = [];
        i++;
        while (i < lines.length && lines[i].trim().indexOf(fence) !== 0) { code.push(lines[i]); i++; }
        i++;
        html += '<pre><code' + (match[3] ? ' data-lang="' + escapeHtml(match[3]) + '"' : '') + '>' +
          escapeHtml(code.join('\n')) + '</code></pre>';
        continue;
      }

      if ((match = /^(#{1,6})\s+(.*?)\s*#*\s*$/.exec(trimmed))) {
        flushParagraph();
        // A chat answer is not a document: its top heading is a section of
        // the reply, so levels start at h3.
        var level = Math.min(6, match[1].length + 2);
        html += '<h' + level + '>' + inline(match[2]) + '</h' + level + '>';
        i++;
        continue;
      }

      if (/^([-*_])(\s*\1){2,}$/.test(trimmed)) { flushParagraph(); html += '<hr>'; i++; continue; }

      if (/^>/.test(trimmed)) {
        flushParagraph();
        var quoted = [];
        while (i < lines.length && /^\s*>/.test(lines[i])) { quoted.push(lines[i].replace(/^\s*>\s?/, '')); i++; }
        html += '<blockquote>' + markdown(quoted.join('\n')) + '</blockquote>';
        continue;
      }

      if (trimmed.indexOf('|') !== -1 && i + 1 < lines.length && isTableRule(lines[i + 1])) {
        flushParagraph();
        var header = cells(line);
        var aligns = cells(lines[i + 1]).map(function (cell) {
          var left = cell.charAt(0) === ':';
          var right = cell.charAt(cell.length - 1) === ':';
          return left && right ? 'center' : (right ? 'right' : (left ? 'left' : ''));
        });
        i += 2;
        var body = '';
        while (i < lines.length && lines[i].trim() !== '' && lines[i].indexOf('|') !== -1) {
          body += '<tr>' + cells(lines[i]).map(function (cell, index) { return cellHtml('td', cell, aligns[index]); }).join('') + '</tr>';
          i++;
        }
        html += '<div class="fai-table"><table><thead><tr>' +
          header.map(function (cell, index) { return cellHtml('th', cell, aligns[index]); }).join('') +
          '</tr></thead><tbody>' + body + '</tbody></table></div>';
        continue;
      }

      if (listItem(line)) {
        flushParagraph();
        var consumed = list(lines, i);
        html += consumed.html;
        i = consumed.next;
        continue;
      }

      paragraph.push(trimmed);
      i++;
    }

    flushParagraph();

    return html;
  }

  function listItem(line) {
    var match = /^(\s*)([-*+]|\d{1,9}[.)])\s+(.*)$/.exec(line);
    if (!match) return null;
    return { indent: match[1].replace(/\t/g, '    ').length, ordered: /\d/.test(match[2]), start: parseInt(match[2], 10), text: match[3] };
  }

  /*
   * A list and everything nested in it. A deeper item opens a list inside the
   * previous item; an indented line that is not an item continues it.
   */
  function list(lines, from) {
    var first = listItem(lines[from]);
    var tag = first.ordered ? 'ol' : 'ul';
    var items = [];
    var i = from;

    while (i < lines.length) {
      var line = lines[i];

      if (line.trim() === '') {
        // A blank line inside a list only continues it if the list goes on.
        var next = i + 1 < lines.length ? listItem(lines[i + 1]) : null;
        if (next && next.indent >= first.indent) { i++; continue; }
        break;
      }

      var item = listItem(line);

      if (item && item.indent < first.indent) break;

      if (item && item.indent === first.indent) {
        if (item.ordered !== first.ordered) break;
        items.push(inline(item.text));
        i++;
        continue;
      }

      if (item && items.length) {
        var nested = list(lines, i);
        items[items.length - 1] += nested.html;
        i = nested.next;
        continue;
      }

      if (/^\s+/.test(line) && items.length) {
        items[items.length - 1] += '<br>' + inline(line.trim());
        i++;
        continue;
      }

      break;
    }

    var start = first.ordered && first.start > 1 ? ' start="' + first.start + '"' : '';

    return { html: '<' + tag + start + '>' + items.map(function (item) { return '<li>' + item + '</li>'; }).join('') + '</' + tag + '>', next: i };
  }

  function isTableRule(line) {
    return /^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/.test(line) && line.indexOf('|') !== -1;
  }

  function cells(line) {
    return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (cell) { return cell.trim(); });
  }

  function cellHtml(tag, text, align) {
    return '<' + tag + (align ? ' style="text-align:' + align + '"' : '') + '>' + inline(text) + '</' + tag + '>';
  }

  /*
   * Inline formatting for one block's text. Code spans and links are set
   * aside first, so nothing inside them is taken for emphasis.
   */
  function inline(source) {
    var kept = [];

    function keep(html) {
      kept.push(html);
      return '\u0000' + (kept.length - 1) + '\u0000';
    }

    var text = escapeHtml(source);

    text = text.replace(/`([^`]+)`/g, function (whole, code) { return keep('<code>' + code + '</code>'); });

    // Links, because the assistant is told to link every record it mentions.
    // Only http(s) and same-site paths (not //host, which is another site):
    // the text is already escaped, so the url cannot close the attribute, and
    // nothing else -- javascript:, data: -- becomes a link at all.
    text = text.replace(/\[([^\]\n]+)\]\(((?:https?:\/\/|\/(?!\/))[^\s)]+)\)/g, function (whole, label, href) {
      return keep('<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + label + '</a>');
    });

    text = text.replace(/(^|[\s(])(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g, function (whole, before, href) {
      return before + keep('<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + href + '</a>');
    });

    text = text.replace(/\*\*(?=\S)([\s\S]*?\S)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/(^|[^\w])__(?=\S)([\s\S]*?\S)__(?!\w)/g, '$1<strong>$2</strong>');
    text = text.replace(/(^|[^\w*])\*(?=[^\s*])([^*]*?[^\s*])\*(?![\w*])/g, '$1<em>$2</em>');
    // Not inside a word: snake_case tool and column names stay as they are.
    text = text.replace(/(^|[^\w])_(?=[^\s_])([^_]*?[^\s_])_(?!\w)/g, '$1<em>$2</em>');
    text = text.replace(/~~(?=\S)([\s\S]*?\S)~~/g, '<del>$1</del>');

    // Citation markers become buttons that open the sources under the answer
    // on that passage. They are never stripped: the assistant is told to cite
    // what it read, and an answer that hides its citations is worth less than
    // one that never had them.
    text = text.replace(/\[#(\d+)\]/g, function (whole, marker) {
      return '<button type="button" class="fai-cite" data-marker="' + marker + '">' + marker + '</button>';
    });

    return text.replace(/\u0000(\d+)\u0000/g, function (whole, index) { return kept[Number(index)]; });
  }

  function icon(name) {
    var paths = {
      plus: '<path d="M12 5v14M5 12h14"/>',
      menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
      gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
      send: '<path d="M12 19V5M5 12l7-7 7 7"/>',
      stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>',
      copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
      up: '<path d="M7 10v11M14 3l-2 7h7.5a2 2 0 0 1 2 2.4l-1.4 6A2 2 0 0 1 18 20H7V10z"/>',
      down: '<path d="M17 14V3M10 21l2-7H4.5a2 2 0 0 1-2-2.4l1.4-6A2 2 0 0 1 6 4h11v10z"/>',
      book: '<path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z"/><path d="M4 17h16"/>',
      pin: '<path d="M12 17v5M9 3h6l-1 6 3 3v2H7v-2l3-3z"/>',
      pencil: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
      trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
      close: '<path d="M18 6L6 18M6 6l12 12"/>',
      moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
      link: '<path d="M14 3h7v7"/><path d="M10 14L21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
      tool: '<path d="M14.7 6.3a4 4 0 0 1-5 5L4 17v3h3l5.7-5.7a4 4 0 0 0 5-5z"/>',
      shield: '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M12 9v4"/><path d="M12 16h.01"/>',
      waves: '<path d="M3 8c2.5-2 4.5-2 7 0s4.5 2 7 0"/><path d="M3 14c2.5-2 4.5-2 7 0s4.5 2 7 0"/><path d="M3 20c2.5-2 4.5-2 7 0s4.5 2 7 0"/>'
    };

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[name] || '') + '</svg>';
  }

  function groupOf(iso) {
    if (!iso) return t.groups.older;

    var date = new Date(iso);
    var today = new Date();
    today.setHours(0, 0, 0, 0);

    var days = Math.floor((today - date) / 86400000);

    if (days < 0) return t.groups.today;
    if (days < 1) return t.groups.yesterday;
    if (days < 7) return t.groups.week;

    return t.groups.older;
  }
  // ---------------------------------------------------------------- sidebar

  function renderThreads() {
    var list = el('fai-threads');
    if (!list) return;

    var filter = state.filter.toLowerCase();
    var visible = state.threads.filter(function (thread) {
      return !filter || (thread.title || '').toLowerCase().indexOf(filter) !== -1;
    });

    if (!visible.length) {
      list.innerHTML = '<p class="fai-threads__group">' + escapeHtml(filter ? t.noResults : t.noThreads) + '</p>';
      return;
    }

    var html = '';
    var group = null;

    visible.forEach(function (thread) {
      var label = groupOf(thread.last_message_at);

      if (label !== group) {
        group = label;
        html += '<p class="fai-threads__group">' + escapeHtml(label) + '</p>';
      }

      html += '<div class="fai-thread" role="button" tabindex="0" data-uuid="' + escapeHtml(thread.uuid) + '"' +
        ' aria-current="' + (thread.uuid === state.conversation) + '">' +
        '<span class="fai-thread__title">' + escapeHtml(thread.title || t.untitled) + '</span>' +
        (can['delete'] ? '<span class="fai-thread__actions">' +
          '<button type="button" class="fai-icon-btn" data-action="rename" title="' + escapeHtml(t.rename) + '">' + icon('pencil') + '</button>' +
          '<button type="button" class="fai-icon-btn" data-action="delete" title="' + escapeHtml(t['delete']) + '">' + icon('trash') + '</button>' +
          '</span>' : '') +
        '</div>';
    });

    list.innerHTML = html;
  }

  function threadTitle(uuid) {
    var thread = state.threads.filter(function (item) { return item.uuid === uuid; })[0];

    return thread ? thread.title : null;
  }

  /**
   * Keep the sidebar honest about a thread the assistant just created or
   * wrote to. The real title is laravel/ai's to write, so this only puts the
   * row where it belongs until the next page load.
   */
  function rememberThread() {
    if (!state.conversation || !can.history) return;

    var existing = state.threads.filter(function (thread) { return thread.uuid === state.conversation; })[0];

    if (existing) {
      existing.last_message_at = new Date().toISOString();
      state.threads = [existing].concat(state.threads.filter(function (thread) { return thread !== existing; }));
    } else {
      state.threads.unshift({
        uuid: state.conversation,
        title: state.title || t.untitled,
        last_message_at: new Date().toISOString()
      });
    }

    renderThreads();
  }

  // --------------------------------------------------------------- messages

  /**
   * What the assistant did on the way to the answer: one chip per tool call,
   * plus the wave counter of an iterative run. A worked answer and a guess
   * look different here, which is the point of showing it at all.
   */
  function stepsHtml(message) {
    var html = '';

    (message.tools || []).forEach(function (tool) {
      var template = tool.status === 'denied' ? t.toolDenied
        : (tool.status === 'failed' ? t.toolFailed
          : (tool.status === 'running' ? t.toolRunning : t.toolDone));

      // The label is the reader's; the tool's own name and error are only
      // ever sent to the debug ability, and shown on hover.
      var title = [tool.name, tool.error].filter(Boolean).join(' - ');

      html += '<span class="fai-step" data-status="' + escapeHtml(tool.status || 'done') + '"' +
        (title ? ' title="' + escapeHtml(title) + '"' : '') + '>' +
        icon('tool') + escapeHtml(String(template).replace(':tool', tool.label || tool.name || '')) + '</span>';
    });

    if (message.solve) {
      var parts = [
        String(t.solveWave).replace(':wave', message.solve.wave).replace(':waves', message.solve.waves),
        String(t.solveAttempts).replace(':done', message.solve.attempts).replace(':total', message.solve.attempts_total)
      ];

      if (message.solve.best_score) {
        parts.push(String(t.solveBest).replace(':score', message.solve.best_score));
      }

      html += '<span class="fai-step" data-status="' + (message.pending ? 'solving' : 'done') + '">' + icon('waves') +
        escapeHtml(parts.join(' · ')) + '</span>';

      // The whole story -- every attempt, and why each was turned down --
      // lives in the panel, for whoever may read it.
      if (message.solve.url) {
        html += '<a class="fai-step" href="' + escapeHtml(message.solve.url) + '" target="_blank" rel="noopener">' +
          icon('link') + escapeHtml(t.solveOpen) + '</a>';
      }
    }

    return html;
  }

  /**
   * A change the assistant wants to make: what kind, on which record, and
   * each field's current and new value in the form's own labels. Nothing runs
   * until one of the two buttons is pressed, and the server checks the
   * decision against what is actually pending.
   */
  function approvalsHtml(message) {
    if (!message.approvals || !message.approvals.length) return '';

    var undecided = message.approvals.filter(function (call) {
      return !(message.decisions && Object.prototype.hasOwnProperty.call(message.decisions, call.id));
    });

    var html = '<div class="fai-approvals"><div class="fai-approvals__head"><p>' +
      icon('shield') + escapeHtml(t.approvalHeading) + '</p>';

    // Several changes proposed at once -- "close these twelve tasks" -- are
    // decided in one click, each still shown on its own card above the rest.
    if (undecided.length > 1) {
      html += '<div class="fai-approvals__bulk">' +
        '<button type="button" class="fai-chip" data-bulk="approve">' +
          escapeHtml(String(t.approveAll).replace(':count', undecided.length)) + '</button>' +
        '<button type="button" class="fai-chip" data-bulk="reject">' + escapeHtml(t.rejectAll) + '</button>' +
        '</div>';
    }

    html += '</div>';

    message.approvals.forEach(function (call) {
      var decided = message.decisions && Object.prototype.hasOwnProperty.call(message.decisions, call.id);
      var changes = call.changes || [];
      var editing = changes.some(function (change) { return change.before != null; });

      html += '<div class="fai-approval"><p class="fai-approval__tool">' + escapeHtml(call.title || call.tool || '') + '</p>';

      if (call.summary) html += '<p class="fai-approval__reason">' + escapeHtml(call.summary) + '</p>';

      if (changes.length) {
        html += '<table class="fai-approval__changes"><thead><tr><th>' + escapeHtml(t.field) + '</th>' +
          (editing ? '<th>' + escapeHtml(t.before) + '</th>' : '') +
          '<th>' + escapeHtml(editing ? t.after : t.value) + '</th></tr></thead><tbody>';

        changes.forEach(function (change) {
          html += '<tr><th scope="row">' + escapeHtml(change.label) + '</th>' +
            (editing ? '<td class="fai-approval__before">' + escapeHtml(change.before == null ? '' : change.before) + '</td>' : '') +
            '<td class="fai-approval__after">' + escapeHtml(change.after == null ? '' : change.after) + '</td></tr>';
        });

        html += '</tbody></table>';
      }

      if (call.tool || call.arguments) {
        html += technicalHtml([call.tool, call.arguments ? JSON.stringify(call.arguments, null, 2) : ''].filter(Boolean).join('\n'));
      }

      html += decided
        ? '<span class="fai-approval__decided">' + escapeHtml(message.decisions[call.id] ? t.approved : t.rejected) + '</span>'
        : '<div class="fai-approval__actions">' +
          '<button type="button" class="fai-chip" data-action="approve" data-call="' + escapeHtml(call.id) + '">' +
            escapeHtml(t.approve) + '</button>' +
          '<button type="button" class="fai-chip" data-action="reject" data-call="' + escapeHtml(call.id) + '">' +
            escapeHtml(t.reject) + '</button>' +
          '</div>';

      html += '</div>';
    });

    return html + '</div>';
  }

  /**
   * Whatever the server sent for the debug ability -- a raw error, a tool's
   * arguments -- folded away under the plain-language version.
   */
  function technicalHtml(text) {
    if (!text) return '';

    return '<details class="fai-technical"><summary>' + escapeHtml(t.technical) + '</summary>' +
      '<pre>' + escapeHtml(text) + '</pre></details>';
  }

  /**
   * What the assistant read to write this answer. Rendered under it rather
   * than in a drawer: the passage a citation points at belongs next to the
   * sentence that cites it, and a panel that opens elsewhere is a panel
   * nobody opens.
   */
  function sourcesHtml(message) {
    if (!message.passages || !message.passages.length) return '';

    var html = '<details class="fai-sources"><summary>' + icon('book') +
      escapeHtml(String(t.sourcesCount).replace(':count', message.passages.length)) + '</summary>';

    message.passages.forEach(function (passage) {
      html += '<div class="fai-source" data-marker="' + escapeHtml(passage.marker) + '">' +
        '<p class="fai-source__head">' +
          '<span class="fai-source__marker">' + escapeHtml(passage.marker) + '</span>' +
          '<span class="fai-source__label">' + escapeHtml(passage.label || '') + '</span>' +
          (passage.score != null ? '<span class="fai-source__score">' + escapeHtml(passage.score) + '</span>' : '') +
          (passage.url ? '<a class="fai-source__link" href="' + escapeHtml(passage.url) + '" target="_blank" rel="noopener">' + icon('link') + '</a>' : '') +
        '</p>' +
        '<p class="fai-source__text">' + escapeHtml(passage.content || '') + '</p>' +
        '</div>';
    });

    return html + '</details>';
  }

  function metaLine(message) {
    var parts = [];

    // The server sends these only to whoever may see them.
    if (message.model) parts.push(message.model);
    if (message.tokens) parts.push(String(t.tokens).replace(':input', message.tokens.input).replace(':output', message.tokens.output));
    if (message.cost_usd != null) parts.push(money(message.cost_usd));

    return parts.filter(Boolean).join(' · ');
  }

  function toolbar(message) {
    if (message.pending || message.error) return '';
    if (message.approvals && message.approvals.length) return '';

    var html = can.export
      ? '<button type="button" class="fai-chip" data-action="copy">' + icon('copy') + escapeHtml(t.copy) + '</button>'
      : '';

    var meta = metaLine(message);
    if (meta) html += '<span class="fai-tools__meta">' + escapeHtml(meta) + '</span>';

    return html;
  }

  function renderMessage(message, index) {
    var node = document.createElement('article');
    node.className = 'fai-turn';
    // Addressed by position, not by id: a turn that is still streaming has no
    // id, and neither does one read back out of the store.
    node.dataset.index = index;

    var body = message.error
      ? '<div class="fai-error">' + escapeHtml(message.answer) + technicalHtml(message.detail) + '</div>'
      : markdown(message.answer);

    node.innerHTML =
      '<div class="fai-ask">' + escapeHtml(message.question) + '</div>' +
      '<div class="fai-steps">' + stepsHtml(message) + '</div>' +
      '<div class="fai-answer">' + body + sourcesHtml(message) + approvalsHtml(message) + '</div>' +
      '<div class="fai-tools"></div>';

    node.querySelector('.fai-tools').innerHTML = toolbar(message);

    return node;
  }

  function renderMessages() {
    var stream = el('fai-stream');
    var empty = el('fai-empty');

    if (!state.messages.length) {
      stream.innerHTML = '';
      stream.hidden = true;
      if (empty) empty.hidden = false;
      el('fai-title').textContent = t.newChat;
      return;
    }

    if (empty) empty.hidden = true;
    stream.hidden = false;
    stream.innerHTML = '';

    state.messages.forEach(function (message, index) { stream.appendChild(renderMessage(message, index)); });

    el('fai-title').textContent = state.title || t.untitled;
  }

  /**
   * Repaint one turn in place while it streams, so a tool call or an approval
   * appears without redrawing the whole thread and losing the scroll.
   */
  function paintTurn(index, caret) {
    var node = el('fai-stream').querySelector('.fai-turn[data-index="' + index + '"]');
    if (!node) return;

    var message = state.messages[index];
    if (!message) return;

    node.querySelector('.fai-steps').innerHTML = stepsHtml(message);

    var answer = node.querySelector('.fai-answer');
    var body = message.answer ? markdown(message.answer) : '';

    answer.innerHTML = (body || (caret ? '<span class="fai-thinking"><i></i><i></i><i></i></span>' : '')) +
      (caret && body ? '<span class="fai-caret"></span>' : '') +
      sourcesHtml(message) +
      approvalsHtml(message);

    node.querySelector('.fai-tools').innerHTML = toolbar(message);
  }

  function scrollDown() {
    var scroll = el('fai-scroll');
    scroll.scrollTop = scroll.scrollHeight;
  }

  // -------------------------------------------------------------- threading

  function loadConversation(uuid) {
    if (state.streaming) return;

    request('GET', url(payload.endpoints.messages, uuid)).then(function (data) {
      state.conversation = data.uuid;
      state.title = data.title || threadTitle(uuid);
      state.messages = turnsFromTranscript(data.messages, data.pending);
      renderMessages();
      renderThreads();
      syncUrl();
      scrollDown();
    }).catch(showFailure);
  }

  function newConversation() {
    if (state.streaming) return;

    state.conversation = null;
    state.title = null;
    state.messages = [];

    renderMessages();
    renderThreads();
    syncUrl();
    el('fai-input').focus();
  }

  function showFailure(error) {
    var node = document.createElement('div');
    node.className = 'fai-error';
    node.textContent = t.failed + ' ' + (error && error.message ? error.message : '');

    el('fai-stream').appendChild(node);
    scrollDown();
  }

  // -------------------------------------------------------------------- ask

  function send() {
    var input = el('fai-input');
    var question = input.value.trim();

    if (!question || state.streaming) return;

    // A turn paused for approval has to be answered first, or the pause is
    // buried where nobody can reach it.
    if (awaitingDecision()) { showFailure({ message: t.decideFirst }); return; }

    input.value = '';
    autosize(input);

    var message = blankTurn(question);
    message.pending = true;
    state.messages.push(message);

    var index = state.messages.length - 1;

    renderMessages();
    paintTurn(index, true);
    scrollDown();

    setStreaming(true);

    var body = { question: question };

    var thread = conversationId();
    if (thread) body.conversation = thread;
    if (can.model && state.settings.model) body.model = state.settings.model;
    if (state.solve) body.solve = true;

    // The record the user came from, so "this order" resolves. Checked again
    // server-side against the resource's own policies.
    if (payload.context && payload.context.resource) {
      body.resource = payload.context.resource;
      if (payload.context.record) body.record = payload.context.record;
    }

    run(function () { return streamInto(payload.endpoints.ask, body, message, index); }, message, index);
  }

  /**
   * One request that writes into one turn, however it ends. Asking and
   * resuming after an approval are the same thing from here on: the turn is
   * already on screen and the stream keeps filling it in.
   */
  function run(attempt, message, index) {
    return attempt()
      .catch(function (error) {
        if (error && error.name === 'AbortError') {
          message.answer = message.answer || t.stopped;
        } else {
          message.error = true;
          message.answer = error && error.message ? error.message : t.failed;
          message.detail = error && error.detail ? error.detail : '';
        }
      })
      .then(function () {
        setStreaming(false);
        message.pending = false;
        renderMessages();
        scrollDown();
      });
  }

  /**
   * Approve or reject everything the assistant is waiting on, then keep
   * streaming into the same turn. The decision is checked against the store on
   * the server: a call that is no longer pending decides nothing.
   */
  function submitDecisions(message, index) {
    if (state.streaming || !state.conversation) return;

    var body = { decisions: message.decisions };

    if (payload.context && payload.context.resource) {
      body.resource = payload.context.resource;
      if (payload.context.record) body.record = payload.context.record;
    }

    message.approvals = [];
    message.pending = true;
    setStreaming(true);
    paintTurn(index, true);

    run(function () {
      return streamInto(url(payload.endpoints.decide, state.conversation), body, message, index);
    }, message, index);
  }

  /*
   * Server-sent events over fetch rather than EventSource: EventSource cannot
   * POST, and the question does not belong in a query string.
   */
  function streamInto(endpoint, body, message, index) {
    var controller = new AbortController();
    state.abort = controller;

    return fetch(scoped(endpoint), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'text/event-stream',
        'X-CSRF-TOKEN': payload.csrf,
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
      signal: controller.signal
    }).then(function (response) {
      checkVersion(response);
      if (!response.ok) return failureFrom(response);
      if (!response.body) throw new Error('HTTP ' + response.status);

      var reader = response.body.getReader();
      var decoder = new TextDecoder();
      var buffer = '';

      function handle(frame) {
        var event = /^event: (.*)$/m.exec(frame);
        var data = /^data: (.*)$/m.exec(frame);

        if (!event || !data) return;

        var parsed = JSON.parse(data[1]);

        if (event[1] === 'delta') {
          message.answer += parsed.text;
          paintTurn(index, true);
          scrollDown();
        } else if (event[1] === 'tool') {
          // Same id, two events: the call and its result. The chip changes
          // state instead of appearing twice.
          var existing = (message.tools || []).filter(function (tool) { return tool.id === parsed.id; })[0];

          if (existing) {
            existing.status = parsed.status;
            if (parsed.label) existing.label = parsed.label;
            if (parsed.name) existing.name = parsed.name;
            if (parsed.error) existing.error = parsed.error;
          } else {
            message.tools.push({ id: parsed.id, label: parsed.label, name: parsed.name, status: parsed.status, error: parsed.error });
          }

          paintTurn(index, true);
          scrollDown();
        } else if (event[1] === 'approval') {
          message.approvals = parsed.calls || [];
          message.decisions = {};
          paintTurn(index, true);
          scrollDown();
        } else if (event[1] === 'sources') {
          (parsed.passages || []).forEach(function (passage) { message.passages.push(passage); });
          paintTurn(index, true);
        } else if (event[1] === 'solve') {
          message.solve = parsed;
          paintTurn(index, true);
          scrollDown();
        } else if (event[1] === 'done') {
          applyDone(parsed, message);
          paintTurn(index, false);
        } else if (event[1] === 'error') {
          message.error = true;
          message.answer = parsed.message || t.failed;
          message.detail = parsed.detail || '';
        }
      }

      function pump() {
        return reader.read().then(function (chunk) {
          if (chunk.done) return;

          buffer += decoder.decode(chunk.value, { stream: true });

          var frames = buffer.split('\n\n');
          buffer = frames.pop();
          frames.forEach(handle);

          return pump();
        });
      }

      return pump();
    });
  }

  function applyDone(data, message) {
    if (data.answer) message.answer = data.answer;
    if (data.solve) message.solve = data.solve;
    if (data.passages && data.passages.length) message.passages = data.passages;

    message.model = data.model;
    message.tokens = data.tokens;
    message.cost_usd = data.cost_usd;
    message.approvals = data.pending || [];
    message.decisions = {};

    if (data.conversation && data.conversation !== state.conversation) {
      state.conversation = data.conversation;
      syncUrl();
    }

    rememberThread();
  }

  function setStreaming(active) {
    state.streaming = active;
    if (!active) state.abort = null;

    var button = el('fai-send');
    button.innerHTML = icon(active ? 'stop' : 'send');
    button.setAttribute('aria-label', active ? t.stop : t.send);
  }

  // ----------------------------------------------------------------- events

  function autosize(input) {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, window.innerHeight * 0.4) + 'px';
  }

  function messageFor(node) {
    var turn = node.closest('.fai-turn');

    return turn ? (state.messages[Number(turn.dataset.index)] || null) : null;
  }

  root.addEventListener('click', function (event) {
    var bulk = event.target.closest('[data-bulk]');

    if (bulk) {
      var bulkIndex = Number(bulk.closest('.fai-turn').dataset.index);
      var bulkMessage = state.messages[bulkIndex];

      if (!bulkMessage || !bulkMessage.approvals.length) return;

      // Only what is still undecided: a card the user already answered keeps
      // its answer.
      bulkMessage.approvals.forEach(function (call) {
        if (!Object.prototype.hasOwnProperty.call(bulkMessage.decisions, call.id)) {
          bulkMessage.decisions[call.id] = bulk.dataset.bulk === 'approve';
        }
      });

      paintTurn(bulkIndex, false);
      submitDecisions(bulkMessage, bulkIndex);

      return;
    }

    var decision = event.target.closest('.fai-approval__actions button');

    if (decision) {
      var turn = decision.closest('.fai-turn');
      var index = Number(turn.dataset.index);
      var message = state.messages[index];

      if (!message || !message.approvals.length) return;

      message.decisions[decision.dataset.call] = decision.dataset.action === 'approve';
      paintTurn(index, false);

      // The turn resumes only once every waiting call has an answer: a
      // half-decided pause cannot be resumed at all.
      var undecided = message.approvals.filter(function (call) {
        return !Object.prototype.hasOwnProperty.call(message.decisions, call.id);
      });

      if (!undecided.length) submitDecisions(message, index);

      return;
    }

    var citation = event.target.closest('.fai-cite');

    if (citation) {
      var turn = citation.closest('.fai-turn');
      var sources = turn ? turn.querySelector('.fai-sources') : null;

      if (sources) {
        sources.open = true;

        var source = sources.querySelector('.fai-source[data-marker="' + citation.dataset.marker + '"]');

        if (source) {
          source.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          source.classList.add('fai-source--flash');
          setTimeout(function () { source.classList.remove('fai-source--flash'); }, 1200);
        }
      }

      return;
    }

    // The pill above the composer reports which model will answer. It looks
    // like a control, so it behaves like one.
    if (event.target.closest('.fai-pills .fai-chip')) { openSettings(); return; }

    var chip = event.target.closest('.fai-tools .fai-chip');

    if (chip && chip.dataset.action === 'copy') {
      var target = messageFor(chip);
      if (!target) return;

      navigator.clipboard.writeText(target.answer);
      chip.setAttribute('aria-pressed', 'true');
      setTimeout(function () { chip.setAttribute('aria-pressed', 'false'); }, 1200);

      return;
    }

    var action = event.target.closest('.fai-thread__actions button');

    if (action) {
      event.stopPropagation();

      var uuid = action.closest('.fai-thread').dataset.uuid;
      var thread = state.threads.filter(function (item) { return item.uuid === uuid; })[0];

      if (action.dataset.action === 'delete') {
        if (!window.confirm(t.confirmDelete)) return;

        request('DELETE', url(payload.endpoints.destroy, uuid)).then(function () {
          state.threads = state.threads.filter(function (item) { return item.uuid !== uuid; });

          if (state.conversation === uuid) newConversation();
          else renderThreads();
        }).catch(showFailure);
      } else if (action.dataset.action === 'rename') {
        var title = window.prompt(t.rename, thread.title || '');
        if (title === null) return;

        request('PATCH', url(payload.endpoints.update, uuid), { title: title }).then(function (data) {
          thread.title = data.title || t.untitled;

          if (state.conversation === uuid) {
            state.title = thread.title;
            el('fai-title').textContent = thread.title;
          }

          renderThreads();
        }).catch(showFailure);
      }

      return;
    }

    var row = event.target.closest('.fai-thread');
    if (row) { loadConversation(row.dataset.uuid); closeSidebarOnNarrow(); return; }

    // Tapping the conversation dismisses the overlay, the way every mobile
    // drawer behaves.
    if (narrow.matches && event.target.closest('.fai-main')) closeSidebarOnNarrow();

    var suggestion = event.target.closest('.fai-suggestion');

    if (suggestion) {
      el('fai-input').value = suggestion.dataset.prompt;
      autosize(el('fai-input'));
      send();
    }
  });

  el('fai-send').addEventListener('click', function () {
    if (state.streaming) {
      if (state.abort) state.abort.abort();
      return;
    }

    send();
  });

  el('fai-input').addEventListener('input', function () { autosize(this); });

  el('fai-input').addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      send();
    }
  });

  el('fai-new').addEventListener('click', function () { newConversation(); closeSidebarOnNarrow(); });

  var search = el('fai-search');
  if (search) search.addEventListener('input', function () { state.filter = this.value; renderThreads(); });

  el('fai-sidebar-toggle').addEventListener('click', function () {
    root.dataset.sidebar = root.dataset.sidebar === 'closed' ? 'open' : 'closed';
    try { localStorage.setItem('filament-ai-chat-sidebar', root.dataset.sidebar); } catch (error) { /* private mode */ }
  });

  var solveBox = el('fai-solve');

  if (solveBox) {
    solveBox.addEventListener('change', function () { state.solve = this.checked; renderPills(); });
  }

  var themeButton = el('fai-theme');

  if (themeButton) themeButton.addEventListener('click', function () {
    // The palette is keyed off [data-fai-theme] on <html>, which is where the
    // pre-paint script puts it. Embedded in a panel the theme is the panel's
    // and this button is not rendered at all.
    var html = document.documentElement;
    var next = html.dataset.faiTheme === 'dark' ? 'light' : 'dark';
    html.dataset.faiTheme = next;
    try { localStorage.setItem('filament-ai-chat-theme', next); } catch (error) { /* private mode */ }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeSettings();
  });

  // --------------------------------------------------------------- settings

  var modal = el('fai-settings');

  function openSettings() {
    if (!modal) return;

    if (can.model && el('fai-set-model')) el('fai-set-model').value = state.settings.model || '';

    modal.hidden = false;
    el('fai-backdrop').hidden = false;
  }

  function closeSettings() {
    if (!modal) return;

    modal.hidden = true;
    el('fai-backdrop').hidden = true;
  }

  if (modal) {
    el('fai-settings-open').addEventListener('click', openSettings);
    el('fai-settings-cancel').addEventListener('click', closeSettings);
    el('fai-backdrop').addEventListener('click', closeSettings);

    el('fai-settings-save').addEventListener('click', function () {
      if (can.model && el('fai-set-model')) state.settings.model = el('fai-set-model').value || null;

      renderPills();
      closeSettings();
    });

    el('fai-settings-reset').addEventListener('click', function () {
      state.settings.model = payload.currentModel;
      openSettings();
    });
  }

  function renderPills() {
    var pills = el('fai-pills');
    if (!pills) return;

    var html = '';

    if (can.model && Object.keys(payload.models).length) {
      html += '<button type="button" class="fai-chip" data-action="settings">' +
        escapeHtml(payload.models[state.settings.model] || state.settings.model || t.defaultModel) + '</button>';
    }

    if (state.solve) {
      html += '<button type="button" class="fai-chip" aria-pressed="true">' +
        icon('waves') + escapeHtml(t.iterative) + '</button>';
    }

    pills.innerHTML = html;
  }

  // ------------------------------------------------------------------ start

  try {
    // The theme already sits on <html>: the inline script in the head applies
    // it before first paint, so there is nothing to do here.
    var storedSidebar = localStorage.getItem('filament-ai-chat-sidebar');
    if (storedSidebar && !payload.embedded) root.dataset.sidebar = storedSidebar;
  } catch (error) { /* storage unavailable */ }

  renderThreads();
  renderMessages();
  renderPills();
  autosize(el('fai-input'));
  scrollDown();
})();
