// Runs the chat's Markdown renderer outside the browser and checks what it
// produces. Called by tests/Unit/ChatMarkdownTest.php; exits non-zero on the
// first mismatch.
const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '../../resources/dist/filament-ai-chat.js'), 'utf8');
const start = source.indexOf('  function markdown(source)');
const end = source.indexOf('  function icon(name)');

if (start < 0 || end < 0) {
  console.error('markdown() not found in filament-ai-chat.js');
  process.exit(1);
}

const escapeHtml = (value) => String(value)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

const markdown = new Function('escapeHtml', source.slice(start, end) + '; return markdown;')(escapeHtml);

const cases = [
  ['a bullet whose text has emphasis stays a bullet', '* voce con *enfasi*\n* seconda', '<ul><li>voce con <em>enfasi</em></li><li>seconda</li></ul>'],
  ['nested lists', '- a\n  - b\n- c', '<ul><li>a<ul><li>b</li></ul></li><li>c</li></ul>'],
  ['ordered lists keep their start', '3. tre\n4. quattro', '<ol start="3"><li>tre</li><li>quattro</li></ol>'],
  ['headings start at h3', '# Titolo\n## Sotto', '<h3>Titolo</h3><h4>Sotto</h4>'],
  ['tables with alignment', '| A | B |\n|---|--:|\n| 1 | 2 |', '<div class="fai-table"><table><thead><tr><th>A</th><th style="text-align:right">B</th></tr></thead><tbody><tr><td>1</td><td style="text-align:right">2</td></tr></tbody></table></div>'],
  ['block quotes', '> citato', '<blockquote><p>citato</p></blockquote>'],
  ['rules', 'a\n\n---\n\nb', '<p>a</p><hr><p>b</p>'],
  ['fenced code is verbatim and escaped', '```php\n<b>*x*</b>\n```', '<pre><code data-lang="php">&lt;b&gt;*x*&lt;/b&gt;</code></pre>'],
  ['snake_case survives', 'usa `test_books_list` e test_books_view', '<p>usa <code>test_books_list</code> e test_books_view</p>'],
  ['links: only http(s) and same-site paths', '[ok](/admin/x) [no](javascript:alert(1))', '<p><a href="/admin/x" target="_blank" rel="noopener noreferrer">ok</a> [no](javascript:alert(1))</p>'],
  ['html is escaped', '<script>alert(1)</script>', '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'],
  ['citations become buttons', 'vedi [#2]', '<p>vedi <button type="button" class="fai-cite" data-marker="2">2</button></p>'],
  ['bold, strikethrough and bare urls', '**sì** ~~no~~ https://example.com/a.', '<p><strong>sì</strong> <del>no</del> <a href="https://example.com/a" target="_blank" rel="noopener noreferrer">https://example.com/a</a>.</p>'],
];

let failed = 0;

for (const [name, input, expected] of cases) {
  const actual = markdown(input);

  if (actual !== expected) {
    failed++;
    console.error(`FAIL ${name}\n  expected: ${expected}\n  actual:   ${actual}`);
  }
}

process.exit(failed === 0 ? 0 : 1);
